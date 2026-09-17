<?php

declare(strict_types=1);

namespace App\Services\Safety;

use App\Models\CaseCategory;
use App\Models\CaseComment;
use App\Models\SafetyCase;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class DuplicateReports
{
    private const MAX_CHAIN = 10;

    public function __construct(private readonly CaseService $cases) {}

    public function merge(SafetyCase $duplicate, SafetyCase $canonical, User $actor, ?string $note = null): SafetyCase
    {
        $canonical = $this->rootOf($canonical);

        if ($canonical->id === $duplicate->id) {
            throw new InvalidArgumentException('A report cannot be a duplicate of itself.');
        }

        if ($duplicate->duplicate_of_id === $canonical->id) {
            return $duplicate;
        }

        if ($duplicate->type !== $canonical->type) {
            throw new InvalidArgumentException(sprintf(
                '%s is a %s and %s is a %s. Only two of the same kind can be merged.',
                $duplicate->reference,
                $duplicate->type,
                $canonical->reference,
                $canonical->type,
            ));
        }

        $moved = DB::transaction(function () use ($duplicate, $canonical, $actor, $note) {
            $moved = $this->carryOver($duplicate, $canonical);

            SafetyCase::query()
                ->where('duplicate_of_id', $duplicate->id)
                ->whereKeyNot($canonical->id)
                ->update(['duplicate_of_id' => $canonical->id]);

            $duplicate->forceFill([
                'duplicate_of_id' => $canonical->id,
                'duplicate_note' => $note,
                'duplicate_marked_by' => $actor->id,
                'duplicate_marked_at' => now(),
            ])->save();

            return $moved;
        });

        Audit::log('case.duplicate-merged', $duplicate, [
            'of' => $canonical->reference,
            'of_id' => $canonical->id,
            'note' => $note,
            'accounts_carried_over' => $moved->pluck('username')->all(),
        ]);

        Audit::log('case.duplicate-received', $canonical, [
            'case' => $duplicate->reference,
            'case_id' => $duplicate->id,
            'note' => $note,
        ]);

        $this->cases->comment(
            $canonical,
            $this->carriedOverSentence($duplicate, $moved, $note),
            CaseComment::VISIBILITY_INTERNAL,
            $actor,
        );

        $this->cases->comment(
            $duplicate,
            sprintf('Merged into %s as a duplicate.%s', $canonical->reference, $note !== null && trim($note) !== '' ? ' '.trim($note) : ''),
            CaseComment::VISIBILITY_INTERNAL,
            $actor,
        );

        $this->cases->setStatus(
            $duplicate,
            SafetyCase::STATUS_DUPLICATE,
            $actor,
            sprintf('Duplicate of %s.', $canonical->reference),
        );

        return $duplicate->refresh();
    }

    public function unmerge(SafetyCase $duplicate, User $actor, ?string $status = null): SafetyCase
    {
        if ($duplicate->duplicate_of_id === null && $duplicate->status !== SafetyCase::STATUS_DUPLICATE) {
            throw new InvalidArgumentException("{$duplicate->reference} is not marked as a duplicate.");
        }

        $was = $duplicate->duplicateOf?->reference;

        $status ??= SafetyCase::STATUS_IN_REVIEW;

        if (! in_array($status, SafetyCase::STATUSES, true) || $status === SafetyCase::STATUS_DUPLICATE) {
            throw new InvalidArgumentException("A report cannot be taken out of a merge and into '{$status}'.");
        }

        Audit::log('case.duplicate-unmerged', $duplicate, ['was' => $was, 'to' => $status]);

        if ($duplicate->status === $status) {
            $duplicate->forceFill([
                'duplicate_of_id' => null,
                'duplicate_note' => null,
                'duplicate_marked_by' => null,
                'duplicate_marked_at' => null,
            ])->save();

            return $duplicate->refresh();
        }

        return $this->cases->setStatus($duplicate, $status, $actor)->refresh();
    }

    /**
     * @return Collection<int, SafetyCase>
     */
    public function candidatesFor(SafetyCase $case, int $limit = 8): Collection
    {
        $case->loadMissing('subjects', 'categories');

        $subjectIds = $case->subjects->pluck('id')->all();
        $categories = $case->categories->pluck('category')->all();
        $about = array_map('mb_strtolower', (array) ($case->about ?? []));

        $pool = SafetyCase::query()
            ->whereKeyNot($case->id)
            ->where('type', $case->type)
            ->whereNull('duplicate_of_id')
            ->where('status', '!=', SafetyCase::STATUS_DUPLICATE)
            ->where(function ($query) use ($subjectIds, $categories, $case) {
                $query->whereIn('status', SafetyCase::OPEN_STATUSES);

                if ($subjectIds !== []) {
                    $query->orWhereHas('subjects', fn ($q) => $q->whereIn('subjects.id', $subjectIds));
                }
                if ($categories !== []) {
                    $query->orWhereHas('categories', fn ($q) => $q->whereIn('category', $categories));
                }
                if ($case->reporter_subject_id !== null) {
                    $query->orWhere('reporter_subject_id', $case->reporter_subject_id);
                }
            })
            ->with(['subjects', 'categories', 'reporter', 'assignee'])
            ->orderByDesc('created_at')
            ->limit(200)
            ->get();

        return $pool
            ->map(function (SafetyCase $other) use ($case, $subjectIds, $categories, $about) {
                $shared = array_values(array_intersect($subjectIds, $other->subjects->pluck('id')->all()));
                $overlap = array_values(array_intersect($categories, $other->categories->pluck('category')->all()));

                $names = array_intersect($about, array_map('mb_strtolower', (array) ($other->about ?? [])));

                $score = count($shared) * 5
                    + count($overlap) * 2
                    + count($names) * 3
                    + ($other->reporter_subject_id !== null && $other->reporter_subject_id === $case->reporter_subject_id ? 4 : 0)
                    + ($other->investigation_id !== null && $other->investigation_id === $case->investigation_id ? 3 : 0)
                    + ($other->isOpen() ? 1 : 0);

                $other->setAttribute('duplicate_score', $score);
                $other->setAttribute('duplicate_because', $this->because($shared, $overlap, $names, $other, $case));

                return $other;
            })
            ->filter(fn (SafetyCase $other) => $other->getAttribute('duplicate_score') > 1)
            ->sortByDesc(fn (SafetyCase $other) => $other->getAttribute('duplicate_score'))
            ->take($limit)
            ->values();
    }

    private function rootOf(SafetyCase $case): SafetyCase
    {
        $seen = [$case->id => true];

        while ($case->duplicate_of_id !== null) {
            $next = $case->duplicateOf;

            if ($next === null || isset($seen[$next->id]) || count($seen) >= self::MAX_CHAIN) {
                throw new InvalidArgumentException(sprintf(
                    '%s is on a merge chain that does not lead anywhere. Take it out of the merge first.',
                    $case->reference,
                ));
            }

            $seen[$next->id] = true;
            $case = $next;
        }

        return $case;
    }

    /**
     * @return Collection<int, Subject>
     */
    private function carryOver(SafetyCase $duplicate, SafetyCase $canonical): Collection
    {
        $duplicate->loadMissing('subjects', 'categories');
        $canonical->loadMissing('subjects', 'categories', 'investigation');

        $already = $canonical->subjects->pluck('id')->all();
        $moved = $duplicate->subjects->reject(fn (Subject $s) => in_array($s->id, $already, true))->values();

        foreach ($moved as $subject) {
            $canonical->subjects()->syncWithoutDetaching([
                $subject->id => ['role' => $subject->pivot->role ?? 'reported'],
            ]);
        }

        if ($canonical->investigation !== null && $moved->isNotEmpty()) {
            foreach ($moved as $subject) {
                $canonical->investigation->subjects()->syncWithoutDetaching([
                    $subject->id => ['role' => 'subject'],
                ]);
            }
        }

        $known = $canonical->categories->pluck('category')->all();
        $position = $canonical->categories->count();

        foreach ($duplicate->categories as $category) {
            if (in_array($category->category, $known, true)) {
                continue;
            }

            CaseCategory::create([
                'case_id' => $canonical->id,
                'category' => $category->category,
                'label' => $category->label,
                'group' => $category->group,
                'source_field' => $category->source_field,
                'is_primary' => $position === 0,
            ]);

            $position++;
        }

        return $moved;
    }

    /**
     * @param  Collection<int, Subject>  $moved
     */
    private function carriedOverSentence(SafetyCase $duplicate, Collection $moved, ?string $note): string
    {
        $parts = [sprintf('%s was merged into this one as a duplicate.', $duplicate->reference)];

        if ($moved->isNotEmpty()) {
            $parts[] = sprintf(
                'It brought %s with it.',
                implode(', ', $moved->pluck('username')->all()),
            );
        }

        $parts[] = sprintf('Anything attached to %s is still on that report.', $duplicate->reference);

        if ($note !== null && trim($note) !== '') {
            $parts[] = trim($note);
        }

        return implode(' ', $parts);
    }

    /**
     * @param  list<int>  $shared
     * @param  list<string>  $overlap
     * @param  array<int, string>  $names
     */
    private function because(array $shared, array $overlap, array $names, SafetyCase $other, SafetyCase $case): string
    {
        $because = [];

        if ($shared !== []) {
            $because[] = sprintf('%s of the same account%s', count($shared) === 1 ? 'about one' : 'about '.count($shared), count($shared) === 1 ? '' : 's');
        }
        if ($names !== []) {
            $because[] = 'names the same people';
        }
        if ($overlap !== []) {
            $because[] = 'filed under the same category';
        }
        if ($other->reporter_subject_id !== null && $other->reporter_subject_id === $case->reporter_subject_id) {
            $because[] = 'from the same reporter';
        }
        if ($other->investigation_id !== null && $other->investigation_id === $case->investigation_id) {
            $because[] = 'on the same investigation';
        }

        return $because === [] ? 'open at the same time' : implode(', ', $because);
    }
}
