<?php

declare(strict_types=1);

namespace App\Services\Search;

use App\Models\DataRemoval;
use App\Models\Investigation;
use App\Models\SafetyCase;
use App\Models\Sanction;
use App\Models\Subject;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

final class SearchDocuments
{
    public const KIND_CASE = 'case';

    public const KIND_INVESTIGATION = 'investigation';

    public const KIND_SUBJECT = 'subject';

    public const KIND_SANCTION = 'sanction';

    public const KIND_REMOVAL = 'removal';

    public const KINDS = [
        self::KIND_CASE,
        self::KIND_INVESTIGATION,
        self::KIND_SUBJECT,
        self::KIND_SANCTION,
        self::KIND_REMOVAL,
    ];

    public const LABELS = [
        self::KIND_CASE => ['one' => 'Report', 'many' => 'Reports'],
        self::KIND_INVESTIGATION => ['one' => 'Investigation', 'many' => 'Investigations'],
        self::KIND_SUBJECT => ['one' => 'Account', 'many' => 'Accounts'],
        self::KIND_SANCTION => ['one' => 'Action', 'many' => 'Actions'],
        self::KIND_REMOVAL => ['one' => 'Erasure', 'many' => 'Erasures'],
    ];

    public const ROUTES = [
        self::KIND_CASE => 'case',
        self::KIND_INVESTIGATION => 'investigation',
        self::KIND_SUBJECT => 'subject',
        self::KIND_SANCTION => 'sanctions',
        self::KIND_REMOVAL => 'data-removals',
    ];

    public const MODELS = [
        self::KIND_CASE => SafetyCase::class,
        self::KIND_INVESTIGATION => Investigation::class,
        self::KIND_SUBJECT => Subject::class,
        self::KIND_SANCTION => Sanction::class,
        self::KIND_REMOVAL => DataRemoval::class,
    ];

    public static function label(string $kind, bool $plural = false): string
    {
        return self::LABELS[$kind][$plural ? 'many' : 'one'] ?? $kind;
    }

    public static function route(string $kind): ?string
    {
        return self::ROUTES[$kind] ?? null;
    }

    /**
     * @return Builder<Model>
     */
    public function source(string $kind): Builder
    {
        return match ($kind) {
            self::KIND_CASE => SafetyCase::query()
                ->with(['assignee', 'reporter', 'subjects', 'comments', 'categories', 'investigation']),

            self::KIND_INVESTIGATION => Investigation::query()
                ->with(['assignee', 'opener', 'subjects', 'notes']),

            self::KIND_SUBJECT => Subject::query(),

            self::KIND_SANCTION => Sanction::query()->with(['subject', 'issuer']),

            self::KIND_REMOVAL => DataRemoval::query()->with(['subject', 'requester']),

            default => throw new SearchUnavailable('There is no '.$kind.' index.'),
        };
    }

    /**
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    public function onlyChangedSince(string $kind, Builder $query, string $since): Builder
    {
        return match ($kind) {
            self::KIND_CASE => $query->where(fn (Builder $q) => $q
                ->where('cases.updated_at', '>=', $since)
                ->orWhereHas('comments', fn (Builder $c) => $c->where('created_at', '>=', $since))),

            self::KIND_INVESTIGATION => $query->where(fn (Builder $q) => $q
                ->where('investigations.updated_at', '>=', $since)
                ->orWhereHas('notes', fn (Builder $n) => $n->where('created_at', '>=', $since))),

            default => $query->where('updated_at', '>=', $since),
        };
    }

    public function build(string $kind, Model $row): array
    {
        $document = match ($kind) {
            self::KIND_CASE => $this->fromCase($row),
            self::KIND_INVESTIGATION => $this->fromInvestigation($row),
            self::KIND_SUBJECT => $this->fromSubject($row),
            self::KIND_SANCTION => $this->fromSanction($row),
            self::KIND_REMOVAL => $this->fromRemoval($row),
            default => throw new SearchUnavailable('There is no '.$kind.' index.'),
        };

        return array_merge([
            'kind' => $kind,
            'id' => (int) $row->getKey(),
            'reference' => null,
            'title' => '',
            'subtitle' => null,
            'body' => '',
            'accounts' => [],
            'status' => null,
            'type' => null,
            'priority' => null,
            'wiki' => null,
            'assignee' => null,
            'assignee_id' => null,
            'created_at' => $row->created_at?->toIso8601String(),
            'updated_at' => $row->updated_at?->toIso8601String(),
        ], $document);
    }

    private function fromCase(Model $case): array
    {
        return [
            'reference' => $case->reference,
            'title' => (string) ($case->subject_line ?: $case->reference),
            'subtitle' => $case->investigation?->title,
            'status' => $case->status,
            'type' => $case->type,
            'priority' => $case->priority,
            'wiki' => $case->wiki,
            'assignee' => $case->assignee?->username,
            'assignee_id' => $case->assigned_to,

            'accounts' => $this->names([
                $case->anonymous ? null : $case->reporter?->username,
                ...$case->subjects->pluck('username')->all(),
                ...array_values((array) ($case->about ?? [])),
            ]),

            'body' => $this->text([
                $case->summary,
                $case->resolution,
                $this->flatten($case->answers),
                $this->flatten($case->about),
                $case->categories->pluck('label')->all(),
                $case->comments->pluck('body')->all(),
            ]),
        ];
    }

    private function fromInvestigation(Model $investigation): array
    {
        return [
            'reference' => $investigation->reference,
            'title' => (string) ($investigation->title ?: $investigation->reference),
            'subtitle' => $investigation->outcome,
            'status' => $investigation->status,
            'priority' => $investigation->priority,
            'assignee' => $investigation->assignee?->username,
            'assignee_id' => $investigation->assigned_to,

            'accounts' => $this->names($investigation->subjects->pluck('username')->all()),

            'body' => $this->text([
                $investigation->premise,
                $investigation->findings,
                $investigation->notes->pluck('body')->all(),
            ]),
        ];
    }

    private function fromSubject(Model $subject): array
    {
        return [
            'title' => (string) $subject->username,
            'subtitle' => $subject->wiki_username,
            'status' => $subject->standing,
            'accounts' => $this->names([$subject->username, $subject->wiki_username]),
            'body' => $this->text([$subject->notes]),
        ];
    }

    private function fromSanction(Model $sanction): array
    {
        return [
            'reference' => $sanction->reference,
            'title' => (string) ($sanction->subject?->username ?: $sanction->label ?: $sanction->reference),
            'subtitle' => $sanction->label,
            'status' => $sanction->isInForce() ? 'in-force' : 'lifted',
            'type' => $sanction->type,
            'assignee' => $sanction->issuer?->username,
            'assignee_id' => $sanction->issued_by,

            'accounts' => $this->names([$sanction->subject?->username]),

            'body' => $this->text([
                $sanction->reason,
                $sanction->internal_reason,
                $sanction->lift_reason,
                $sanction->scope,
                $sanction->wikis,
            ]),
        ];
    }

    private function fromRemoval(Model $removal): array
    {
        return [
            'reference' => $removal->reference,
            'title' => (string) ($removal->target_username ?: $removal->reference),
            'subtitle' => $removal->label(),
            'status' => $removal->state,
            'assignee' => $removal->requester?->username,
            'assignee_id' => $removal->requested_by,

            'accounts' => $this->names([
                $removal->target_username,
                $removal->previous_username,
                $removal->subject?->username,
            ]),

            'body' => $this->text([
                $removal->reason,
                $removal->refusal_reason,
                $removal->legal_basis,
                $removal->wikis,
            ]),
        ];
    }

    /**
     * @param  list<mixed>  $parts
     * @return list<string>
     */
    private function names(array $parts): array
    {
        $names = [];

        foreach ($parts as $part) {
            $name = is_string($part) ? trim($part) : '';
            if ($name !== '' && ! in_array($name, $names, true)) {
                $names[] = $name;
            }
        }

        return $names;
    }

    private function text(array $parts): string
    {
        $pieces = [];

        foreach ($parts as $part) {
            foreach ($this->flatten($part) as $piece) {
                $pieces[] = $piece;
            }
        }

        return trim(implode("\n\n", $pieces));
    }

    private function flatten(mixed $value): array
    {
        if ($value === null || $value === '' || $value === []) {
            return [];
        }

        if (is_string($value)) {
            return [trim($value)];
        }

        if (is_bool($value)) {
            return [];
        }

        if (is_scalar($value)) {
            return [(string) $value];
        }

        if (! is_iterable($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $key => $item) {
            $label = is_string($key) ? str_replace(['_', '-'], ' ', $key).': ' : '';

            foreach ($this->flatten($item) as $piece) {
                $out[] = $label.$piece;
            }
        }

        return $out;
    }

    public function settings(): array
    {
        return [
            'settings' => [
                'index' => [
                    'number_of_shards' => (int) config('opensearch.shards'),
                    'number_of_replicas' => (int) config('opensearch.replicas'),
                ],
                'analysis' => [
                    'normalizer' => [
                        'ts_key' => [
                            'type' => 'custom',
                            'filter' => ['lowercase', 'asciifolding'],
                        ],
                    ],
                    'analyzer' => [
                        'ts_text' => [
                            'type' => 'custom',
                            'tokenizer' => 'standard',
                            'filter' => ['lowercase', 'asciifolding'],
                        ],
                    ],
                ],
            ],

            'mappings' => [
                'dynamic' => 'strict',
                'properties' => [
                    'kind' => ['type' => 'keyword'],
                    'id' => ['type' => 'long'],

                    'reference' => [
                        'type' => 'keyword',
                        'normalizer' => 'ts_key',
                        'fields' => ['text' => ['type' => 'text', 'analyzer' => 'ts_text']],
                    ],

                    'title' => [
                        'type' => 'text',
                        'analyzer' => 'ts_text',
                        'fields' => ['raw' => ['type' => 'keyword', 'normalizer' => 'ts_key', 'ignore_above' => 256]],
                    ],

                    'subtitle' => ['type' => 'text', 'analyzer' => 'ts_text'],
                    'body' => ['type' => 'text', 'analyzer' => 'ts_text'],

                    'accounts' => [
                        'type' => 'text',
                        'analyzer' => 'ts_text',
                        'fields' => ['raw' => ['type' => 'keyword', 'normalizer' => 'ts_key', 'ignore_above' => 256]],
                    ],

                    'status' => ['type' => 'keyword'],
                    'type' => ['type' => 'keyword'],
                    'priority' => ['type' => 'keyword'],
                    'wiki' => ['type' => 'keyword'],
                    'assignee' => ['type' => 'keyword', 'normalizer' => 'ts_key'],
                    'assignee_id' => ['type' => 'long'],

                    'created_at' => ['type' => 'date'],
                    'updated_at' => ['type' => 'date'],

                    'indexed_at' => ['type' => 'date'],
                    'run' => ['type' => 'keyword'],
                ],
            ],
        ];
    }
}
