<?php

declare(strict_types=1);

namespace App\Services\Safety;

use App\Models\SafetyCase;
use App\Models\Sanction;
use App\Models\Subject;
use Illuminate\Support\Collection;

final class AppealParser
{
    private const REFERENCE = '/\b([A-Z]{2,4})-(\d{4})-(\d{1,6})\b/i';

    private const CONSIDERED_LIMIT = 12;

    /**
     * @param  string|null  $stated
     * @param  string|null  $body
     */
    public function parse(?Subject $subject, ?string $stated, ?string $body): AppealMatch
    {
        $actions = $this->actionsOnFile($subject);
        $considered = $this->considered($actions);
        $notes = [];

        if (is_string($stated) && trim($stated) !== '') {
            $found = $this->resolve($stated, $subject, $notes);

            if ($found !== null) {
                return new AppealMatch(
                    $found,
                    AppealMatch::SOURCE_STATED,
                    AppealMatch::CERTAIN,
                    $considered,
                    $notes,
                );
            }
        }

        $quoted = $this->quoted($body, $stated);
        $mine = [];

        foreach ($quoted as $reference) {
            $found = $this->resolve($reference, $subject, $notes);
            if ($found !== null) {
                $mine[$found->id] = $found;
            }
        }

        if ($mine !== []) {
            $chosen = collect($mine)->sortByDesc(fn (Sanction $s) => $s->issued_at)->first();

            if (count($mine) > 1) {
                $notes[] = sprintf(
                    'They quoted %d of their own actions (%s). This is the most recent of them.',
                    count($mine),
                    implode(', ', array_map(fn (Sanction $s) => $s->reference, $mine)),
                );
            }

            return new AppealMatch(
                $chosen,
                AppealMatch::SOURCE_QUOTED,
                count($mine) === 1 ? AppealMatch::LIKELY : AppealMatch::GUESS,
                $considered,
                $notes,
            );
        }

        if ($actions->isEmpty()) {
            $notes[] = $subject === null
                ? 'The appeal is not attached to an account, so there is no file to look in.'
                : sprintf('There are no actions on file against %s at all.', $subject->username);

            return AppealMatch::nothing($considered, $notes);
        }

        $appealable = $actions->filter(fn (Sanction $s) => $s->isInForce() && $s->appealable)->values();
        $pool = $appealable;

        if ($pool->isEmpty()) {
            $pool = $actions;
            $notes[] = 'Nothing is in force against this account at the moment, so this is read '
                .'against what is on the record. People do appeal actions that have already '
                .'expired — a lapsed block is still something other people can read.';
        }

        $chosen = $pool->first();

        if ($pool->count() === 1) {
            return new AppealMatch(
                $chosen,
                AppealMatch::SOURCE_ONLY,
                $appealable->count() === 1 ? AppealMatch::LIKELY : AppealMatch::GUESS,
                $considered,
                $notes,
            );
        }

        $notes[] = sprintf(
            '%d actions were in the running (%s) and nothing in the appeal says which. '
            .'This is simply the most recent.',
            $pool->count(),
            $pool->take(5)->map(fn (Sanction $s) => $s->reference)->implode(', '),
        );

        return new AppealMatch(
            $chosen,
            AppealMatch::SOURCE_LATEST,
            AppealMatch::GUESS,
            $considered,
            $notes,
        );
    }

    /**
     * @param  list<string>  $notes
     */
    private function resolve(string $reference, ?Subject $subject, array &$notes): ?Sanction
    {
        $reference = strtoupper(trim($reference));
        $resolved = References::resolve($reference);

        if ($resolved === null || $resolved['id'] === null) {
            $notes[] = sprintf('%s was quoted, and there is nothing here under that reference.', $reference);

            return null;
        }

        $candidates = match ($resolved['type']) {
            'Sanction' => Sanction::query()->whereKey($resolved['id'])->get(),

            'SafetyCase' => SafetyCase::query()->whereKey($resolved['id'])->first()?->sanctionsIssued()->get()
                ?? new Collection,

            default => new Collection,
        };

        if ($candidates->isEmpty()) {
            $notes[] = sprintf(
                '%s resolves to a %s, which is not an action to appeal against.',
                $reference,
                strtolower((string) $resolved['type']),
            );

            return null;
        }

        $ours = $subject === null
            ? new Collection
            : $candidates->filter(fn (Sanction $s) => $s->subject_id === $subject->id);

        if ($ours->isEmpty()) {
            $notes[] = sprintf(
                '%s was quoted but it is against %s, not the account appealing. Not linked.',
                $reference,
                $candidates->first()?->subject?->username ?? 'another account',
            );

            return null;
        }

        return $ours->sortByDesc(fn (Sanction $s) => $s->issued_at)->first();
    }

    /**
     * @return list<string>
     */
    private function quoted(?string $body, ?string $stated): array
    {
        if (! is_string($body) || trim($body) === '') {
            return [];
        }

        preg_match_all(self::REFERENCE, $body, $matches);

        $already = strtoupper(trim((string) $stated));

        return array_values(array_filter(
            array_unique(array_map('strtoupper', $matches[0] ?? [])),
            fn (string $reference) => $reference !== $already,
        ));
    }

    /**
     * @return Collection<int, Sanction>
     */
    private function actionsOnFile(?Subject $subject): Collection
    {
        if ($subject === null) {
            return new Collection;
        }

        return $subject->sanctions()
            ->orderByDesc('issued_at')
            ->limit(self::CONSIDERED_LIMIT)
            ->get();
    }

    /**
     * @param  Collection<int, Sanction>  $actions
     * @return list<array<string, mixed>>
     */
    private function considered(Collection $actions): array
    {
        return $actions->map(fn (Sanction $s) => [
            'reference' => $s->reference,
            'label' => $s->label,
            'type' => $s->type,
            'reason_category' => $s->reason_category,
            'issued' => $s->issued_at?->toIso8601String(),
            'in_force' => $s->isInForce(),
            'appealable' => (bool) $s->appealable,
        ])->values()->all();
    }
}
