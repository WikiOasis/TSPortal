<?php

declare(strict_types=1);

namespace App\Services\Safety;

use App\Models\SafetyCase;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

final class CaseSearch
{
    private const FILTERS = [
        'type' => 'type', 'kind' => 'type',
        'status' => 'status', 'state' => 'status',
        'with' => 'with', 'assigned' => 'with', 'assignee' => 'with',
        'priority' => 'priority', 'p' => 'priority',
        'file' => 'file', 'investigation' => 'file', 'inv' => 'file',
        'wiki' => 'wiki',
        'about' => 'about', 'account' => 'about', 'user' => 'about',
        'from' => 'from', 'reporter' => 'from',
        'is' => 'is',
    ];

    private const TYPES = [
        'report' => SafetyCase::TYPE_REPORT,
        'reports' => SafetyCase::TYPE_REPORT,
        'appeal' => SafetyCase::TYPE_APPEAL,
        'appeals' => SafetyCase::TYPE_APPEAL,
        'contact' => SafetyCase::TYPE_CONTACT,
        'message' => SafetyCase::TYPE_CONTACT,
        'messages' => SafetyCase::TYPE_CONTACT,
        'data' => SafetyCase::TYPE_DATA,
        'dpa' => SafetyCase::TYPE_DATA,
        'gdpr' => SafetyCase::TYPE_DATA,
        'privacy' => SafetyCase::TYPE_DATA,
        'protection' => SafetyCase::TYPE_DATA,
    ];

    /**
     * @param  Builder<SafetyCase>  $query
     * @return array<string, mixed>
     */
    public function apply(Builder $query, string $search, ?User $viewer = null): array
    {
        [$terms, $filters] = $this->parse($search);

        foreach ($filters as $key => $values) {
            $this->applyFilter($query, $key, $values, $viewer);
        }

        if ($terms !== '') {
            $this->applyTerms($query, $terms);
        }

        return ['terms' => $terms, 'filters' => $filters];
    }

    /**
     * @return array{0: string, 1: array<string, list<string>>}
     */
    public function parse(string $search): array
    {
        $filters = [];
        $words = [];

        preg_match_all('/(?:([a-z]+):)?(?:"([^"]*)"|(\S+))/i', trim($search), $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $prefix = strtolower($match[1] ?? '');
            $value = ($match[2] ?? '') !== '' ? $match[2] : ($match[3] ?? '');

            if ($value === '') {
                continue;
            }

            $field = self::FILTERS[$prefix] ?? null;

            if ($field === null) {
                $words[] = $prefix !== '' ? $prefix.':'.$value : $value;

                continue;
            }

            foreach (explode(',', $value) as $one) {
                $one = trim($one);
                if ($one !== '') {
                    $filters[$field][] = $one;
                }
            }
        }

        return [implode(' ', $words), $filters];
    }

    /**
     * @param  Builder<SafetyCase>  $query
     * @param  list<string>  $values
     */
    private function applyFilter(Builder $query, string $key, array $values, ?User $viewer): void
    {
        match ($key) {
            'type' => $query->whereIn('type', array_values(array_filter(array_map(
                fn (string $v) => self::TYPES[strtolower($v)] ?? null,
                $values,
            )))),

            'status' => in_array('open', array_map('strtolower', $values), true)
                ? $query->whereIn('status', SafetyCase::OPEN_STATUSES)
                : $query->whereIn('status', $values),

            'priority' => $query->whereIn('priority', array_map('strtolower', $values)),

            'wiki' => $query->whereIn('wiki', $values),

            'with' => $this->applyAssignee($query, $values, $viewer),

            'file' => $this->applyFile($query, $values),

            'about' => $query->where(function (Builder $q) use ($values) {
                foreach ($values as $name) {
                    $q->orWhereHas('subjects', fn (Builder $s) => $s->where('username_key', Subject::key($name)))
                        ->orWhere('about', 'like', '%'.$name.'%');
                }
            }),

            'from' => $query->whereHas(
                'reporter',
                fn (Builder $q) => $q->whereIn('username_key', array_map(
                    fn (string $v) => Subject::key($v),
                    $values,
                )),
            ),

            'is' => $this->applyIs($query, array_map('strtolower', $values), $viewer),

            default => null,
        };
    }

    /**
     * @param  Builder<SafetyCase>  $query
     * @param  list<string>  $values
     */
    private function applyAssignee(Builder $query, array $values, ?User $viewer): void
    {
        $query->where(function (Builder $q) use ($values, $viewer) {
            foreach ($values as $value) {
                $lower = strtolower($value);

                if (in_array($lower, ['me', 'mine'], true) && $viewer !== null) {
                    $q->orWhere('assigned_to', $viewer->id);

                    continue;
                }

                if (in_array($lower, ['none', 'nobody', 'unassigned'], true)) {
                    $q->orWhereNull('assigned_to');

                    continue;
                }

                $q->orWhereHas('assignee', fn (Builder $u) => $u->where('username', 'like', $value.'%'));
            }
        });
    }

    /**
     * @param  Builder<SafetyCase>  $query
     * @param  list<string>  $values
     */
    private function applyFile(Builder $query, array $values): void
    {
        $query->where(function (Builder $q) use ($values) {
            foreach ($values as $value) {
                $lower = strtolower($value);

                if (in_array($lower, ['none', 'no', 'nobody', 'unfiled'], true)) {
                    $q->orWhereNull('investigation_id');

                    continue;
                }

                if (in_array($lower, ['any', 'some', 'yes'], true)) {
                    $q->orWhereNotNull('investigation_id');

                    continue;
                }

                $reference = strtoupper($value);
                $q->orWhereHas(
                    'investigation',
                    fn (Builder $i) => $i->where('reference', $reference)
                        ->orWhere('title', 'like', '%'.$value.'%'),
                );
            }
        });
    }

    /**
     * @param  Builder<SafetyCase>  $query
     * @param  list<string>  $values
     */
    private function applyIs(Builder $query, array $values, ?User $viewer): void
    {
        foreach ($values as $value) {
            match ($value) {
                'anonymous', 'anon' => $query->where('anonymous', true),
                'named' => $query->where('anonymous', false),
                'open' => $query->whereIn('status', SafetyCase::OPEN_STATUSES),
                'closed', 'done' => $query->whereNotIn('status', SafetyCase::OPEN_STATUSES),
                'unfiled' => $query->whereNull('investigation_id'),
                'filed' => $query->whereNotNull('investigation_id'),
                'mine' => $viewer === null ? null : $query->where('assigned_to', $viewer->id),
                'unassigned' => $query->whereNull('assigned_to'),

                'stale' => $query->whereIn('status', SafetyCase::OPEN_STATUSES)
                    ->where('updated_at', '<', now()->subDays(14)),

                'unsynced' => $query->whereNotNull('sync_error'),
                default => null,
            };
        }
    }

    /**
     * @param  Builder<SafetyCase>  $query
     */
    private function applyTerms(Builder $query, string $terms): void
    {
        foreach (preg_split('/\s+/u', $terms) ?: [] as $word) {
            if ($word === '') {
                continue;
            }

            $like = '%'.$word.'%';

            $query->where(function (Builder $q) use ($word, $like) {
                $q->where('reference', 'like', $like)
                    ->orWhere('subject_line', 'like', $like)
                    ->orWhere('summary', 'like', $like)
                    ->orWhere('resolution', 'like', $like)
                    ->orWhere('about', 'like', $like)
                    ->orWhere('answers', 'like', $like)
                    ->orWhereHas('comments', fn (Builder $c) => $c->where('body', 'like', $like))
                    ->orWhereHas('subjects', fn (Builder $s) => $s->where('username_key', 'like', '%'.Subject::key($word).'%'))
                    ->orWhereHas('reporter', fn (Builder $s) => $s->where('username_key', 'like', '%'.Subject::key($word).'%'))
                    ->orWhereHas('investigation', fn (Builder $i) => $i->where('reference', 'like', $like)
                        ->orWhere('title', 'like', $like));
            });
        }
    }

    /**
     * @return list<array{prefix: string, example: string, what: string}>
     */
    public static function help(): array
    {
        return [
            ['prefix' => 'type:', 'example' => 'type:report,data', 'what' => 'Reports, appeals, messages, data protection'],
            ['prefix' => 'status:', 'example' => 'status:open', 'what' => 'Or a specific status'],
            ['prefix' => 'with:', 'example' => 'with:me', 'what' => 'Or a name, or nobody'],
            ['prefix' => 'priority:', 'example' => 'priority:urgent', 'what' => 'urgent, high, normal, low'],
            ['prefix' => 'file:', 'example' => 'file:none', 'what' => 'A reference, none, or any'],
            ['prefix' => 'about:', 'example' => 'about:"Quiet Marlin"', 'what' => 'An account the case is about'],
            ['prefix' => 'from:', 'example' => 'from:"Halcyon Reed"', 'what' => 'Who filed it'],
            ['prefix' => 'wiki:', 'example' => 'wiki:oasis.example', 'what' => 'Which wiki it came from'],
            ['prefix' => 'is:', 'example' => 'is:stale', 'what' => 'stale, anonymous, unfiled, unassigned, unsynced'],
        ];
    }
}
