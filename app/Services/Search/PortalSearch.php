<?php

declare(strict_types=1);

namespace App\Services\Search;

use App\Models\DataRemoval;
use App\Models\Investigation;
use App\Models\SafetyCase;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

final class PortalSearch
{
    public const ENGINE_OPENSEARCH = 'opensearch';

    public const ENGINE_DATABASE = 'database';

    private const HIGHLIGHT_OPEN = "\u{2062}[";

    private const HIGHLIGHT_CLOSE = "]\u{2063}";

    public function __construct(
        private readonly OpenSearch $search,
        private readonly SearchDocuments $documents,
    ) {}

    public function fullTextAvailable(): bool
    {
        return $this->search->configured();
    }

    /**
     * @param  array{kinds?: list<string>, titles_only?: bool, mine?: bool, open_only?: bool, limit?: int}  $options
     * @return array{rows: list<array<string, mixed>>, engine: string, total: int, degraded: bool}
     */
    public function find(string $terms, array $options = [], ?User $viewer = null): array
    {
        $terms = trim($terms);
        $kinds = $this->kinds($options['kinds'] ?? []);
        $limit = max(1, min(50, (int) ($options['limit'] ?? 20)));

        if ($terms === '') {
            return ['rows' => [], 'engine' => self::ENGINE_DATABASE, 'total' => 0, 'degraded' => false];
        }

        if ($this->fullTextAvailable()) {
            try {
                return $this->throughOpenSearch($terms, $kinds, $limit, $options, $viewer);
            } catch (SearchUnavailable $e) {
                report($e);

                return $this->throughDatabase($terms, $kinds, $limit, $options, $viewer, degraded: true);
            }
        }

        return $this->throughDatabase($terms, $kinds, $limit, $options, $viewer, degraded: false);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function recents(?User $viewer, int $limit = 12): array
    {
        $cases = SafetyCase::query()
            ->with($this->eagerLoads(SearchDocuments::KIND_CASE))
            ->when($viewer !== null, fn (Builder $q) => $q->where('assigned_to', $viewer->id))
            ->whereIn('status', SafetyCase::OPEN_STATUSES)
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->get();

        $investigations = Investigation::query()
            ->with($this->eagerLoads(SearchDocuments::KIND_INVESTIGATION))
            ->when($viewer !== null, fn (Builder $q) => $q->where('assigned_to', $viewer->id))
            ->whereIn('status', Investigation::LIVE_STATUSES)
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->get();

        $rows = $cases->map(fn (SafetyCase $c) => $this->row(SearchDocuments::KIND_CASE, $c))
            ->concat($investigations->map(
                fn (Investigation $i) => $this->row(SearchDocuments::KIND_INVESTIGATION, $i),
            ))
            ->sortByDesc('updated')
            ->values();

        if ($rows->count() >= 4 || $viewer === null) {
            return $rows->take($limit)->all();
        }

        return $rows->concat($this->latest($limit))
            ->unique(fn (array $row) => $row['kind'].':'.$row['id'])
            ->take($limit)
            ->values()
            ->all();
    }

    private function latest(int $limit): Collection
    {
        return SafetyCase::query()
            ->with($this->eagerLoads(SearchDocuments::KIND_CASE))
            ->whereIn('status', SafetyCase::OPEN_STATUSES)
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->get()
            ->map(fn (SafetyCase $case) => $this->row(SearchDocuments::KIND_CASE, $case));
    }

    /**
     * @param  list<string>  $kinds
     * @param  array<string, mixed>  $options
     * @return array{rows: list<array<string, mixed>>, engine: string, total: int, degraded: bool}
     */
    private function throughOpenSearch(
        string $terms,
        array $kinds,
        int $limit,
        array $options,
        ?User $viewer,
    ): array {
        $titlesOnly = (bool) ($options['titles_only'] ?? false);

        $fields = $titlesOnly
            ? ['title^6', 'title.raw^8', 'reference.text^8', 'accounts^4', 'subtitle^2']
            : ['title^6', 'title.raw^8', 'reference.text^8', 'accounts^4', 'subtitle^2', 'body'];

        $should = [
            ['multi_match' => [
                'query' => $terms,
                'fields' => $fields,
                'type' => 'best_fields',
                'operator' => 'and',
            ]],
            ['multi_match' => [
                'query' => $terms,
                'fields' => ['title^4', 'accounts^3', 'subtitle'],
                'type' => 'phrase_prefix',
            ]],
            ['prefix' => ['reference' => ['value' => $terms, 'boost' => 12]]],
        ];

        if (! $titlesOnly) {
            $should[] = ['match_phrase' => ['body' => ['query' => $terms, 'boost' => 3]]];
        }

        $body = [
            'size' => $limit,
            'track_total_hits' => true,
            'query' => [
                'bool' => [
                    'should' => $should,
                    'minimum_should_match' => 1,
                    'filter' => $this->filters($options, $viewer),
                ],
            ],
            '_source' => ['includes' => ['kind', 'id']],
            'sort' => ['_score', ['updated_at' => 'desc']],
        ];

        if (! $titlesOnly) {
            $body['highlight'] = [
                'pre_tags' => [self::HIGHLIGHT_OPEN],
                'post_tags' => [self::HIGHLIGHT_CLOSE],
                'fragment_size' => (int) config('opensearch.fragment_size'),
                'number_of_fragments' => 2,
                'fields' => ['body' => new \stdClass],
            ];
        }

        $indices = implode(',', array_map(
            fn (string $kind) => $this->search->indexName($kind),
            $kinds,
        ));

        $response = $this->search->search($indices, $body);

        $hits = $response['hits']['hits'] ?? [];
        $wanted = [];
        $highlights = [];

        foreach ($hits as $position => $hit) {
            $kind = $hit['_source']['kind'] ?? null;
            $id = $hit['_source']['id'] ?? null;

            if (! is_string($kind) || $id === null || ! in_array($kind, $kinds, true)) {
                continue;
            }

            $wanted[$kind][(int) $id] = $position;
            $highlights[$kind.':'.(int) $id] = $hit['highlight']['body'] ?? [];
        }

        $rows = [];

        foreach ($wanted as $kind => $positions) {
            foreach ($this->hydrate($kind, array_keys($positions)) as $model) {
                $key = $kind.':'.$model->getKey();

                $rows[$positions[$model->getKey()]] = $this->row(
                    $kind,
                    $model,
                    $this->snippet($highlights[$key] ?? []),
                );
            }
        }

        ksort($rows);

        return [
            'rows' => array_values($rows),
            'engine' => self::ENGINE_OPENSEARCH,
            'total' => (int) ($response['hits']['total']['value'] ?? count($rows)),
            'degraded' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return list<array<string, mixed>>
     */
    private function filters(array $options, ?User $viewer): array
    {
        $filters = [];

        if (($options['mine'] ?? false) && $viewer !== null) {
            $filters[] = ['term' => ['assignee_id' => $viewer->id]];
        }

        if ($options['open_only'] ?? false) {
            $filters[] = ['bool' => [
                'minimum_should_match' => 1,
                'should' => [
                    ['terms' => ['status' => $this->openStatuses()]],
                    ['term' => ['kind' => SearchDocuments::KIND_SUBJECT]],
                ],
            ]];
        }

        return $filters;
    }

    private function openStatuses(): array
    {
        return array_values(array_unique(array_merge(
            SafetyCase::OPEN_STATUSES,
            Investigation::LIVE_STATUSES,
            ['in-force'],
            array_values(array_diff(DataRemoval::STATES, [DataRemoval::STATE_DONE, DataRemoval::STATE_REFUSED])),
        )));
    }

    /**
     * @param  list<string>  $kinds
     * @param  array<string, mixed>  $options
     * @return array{rows: list<array<string, mixed>>, engine: string, total: int, degraded: bool}
     */
    private function throughDatabase(
        string $terms,
        array $kinds,
        int $limit,
        array $options,
        ?User $viewer,
        bool $degraded,
    ): array {
        $titlesOnly = (bool) ($options['titles_only'] ?? false);
        $perKind = (int) max(3, ceil($limit / max(1, count($kinds))) + 2);
        $reference = strtoupper($terms).'%';

        $rows = [];

        foreach ($kinds as $kind) {
            $model = SearchDocuments::MODELS[$kind];
            $query = $model::query()->with($this->eagerLoads($kind));

            $this->matchInDatabase($kind, $query, $terms, $titlesOnly);

            if (($options['mine'] ?? false) && $viewer !== null && $this->hasAssignee($kind)) {
                $query->where($this->assigneeColumn($kind), $viewer->id);
            }

            if ($options['open_only'] ?? false) {
                $this->onlyOpen($kind, $query);
            }

            foreach ($query->orderByDesc('updated_at')->limit($perKind)->get() as $found) {
                $row = $this->row($kind, $found);

                $rows[] = [
                    'rank' => $row['reference'] !== null && str_starts_with((string) $row['reference'], rtrim($reference, '%')) ? 1 : 0,
                    'row' => $row,
                ];
            }
        }

        usort($rows, fn (array $a, array $b) => [$b['rank'], $b['row']['updated'] ?? ''] <=> [$a['rank'], $a['row']['updated'] ?? '']);

        return [
            'rows' => array_map(fn (array $entry) => $entry['row'], array_slice($rows, 0, $limit)),
            'engine' => self::ENGINE_DATABASE,
            'total' => count($rows),
            'degraded' => $degraded,
        ];
    }

    private function matchInDatabase(
        string $kind,
        Builder $query,
        string $terms,
        bool $titlesOnly,
    ): void {
        $like = '%'.$this->escapeLike($terms).'%';
        $reference = strtoupper($this->escapeLike($terms)).'%';
        $accountKey = '%'.$this->escapeLike(Subject::key($terms)).'%';

        $query->where(function (Builder $q) use ($kind, $like, $reference, $accountKey, $titlesOnly) {
            match ($kind) {
                SearchDocuments::KIND_CASE => $q->where('reference', 'like', $reference)
                    ->orWhere('subject_line', 'like', $like)
                    ->orWhere('about', 'like', $like)
                    ->when(! $titlesOnly, fn (Builder $b) => $b
                        ->orWhere('summary', 'like', $like)
                        ->orWhere('answers', 'like', $like)
                        ->orWhere('resolution', 'like', $like)
                        ->orWhereHas('comments', fn (Builder $c) => $c->where('body', 'like', $like))),

                SearchDocuments::KIND_INVESTIGATION => $q->where('reference', 'like', $reference)
                    ->orWhere('title', 'like', $like)
                    ->when(! $titlesOnly, fn (Builder $b) => $b
                        ->orWhere('premise', 'like', $like)
                        ->orWhere('findings', 'like', $like)
                        ->orWhereHas('notes', fn (Builder $n) => $n->where('body', 'like', $like))),

                SearchDocuments::KIND_SUBJECT => $q->where('username_key', 'like', $accountKey)
                    ->orWhere('username', 'like', $like)
                    ->when(! $titlesOnly, fn (Builder $b) => $b->orWhere('notes', 'like', $like)),

                SearchDocuments::KIND_SANCTION => $q->where('reference', 'like', $reference)
                    ->orWhere('label', 'like', $like)
                    ->orWhereHas('subject', fn (Builder $s) => $s->where('username', 'like', $like))
                    ->when(! $titlesOnly, fn (Builder $b) => $b
                        ->orWhere('reason', 'like', $like)
                        ->orWhere('internal_reason', 'like', $like)
                        ->orWhere('scope', 'like', $like)),

                SearchDocuments::KIND_REMOVAL => $q->where('reference', 'like', $reference)
                    ->orWhere('target_username', 'like', $like)
                    ->orWhere('previous_username', 'like', $like)
                    ->when(! $titlesOnly, fn (Builder $b) => $b->orWhere('reason', 'like', $like)),

                default => $q,
            };
        });
    }

    private function onlyOpen(string $kind, Builder $query): void
    {
        match ($kind) {
            SearchDocuments::KIND_CASE => $query->whereIn('status', SafetyCase::OPEN_STATUSES),
            SearchDocuments::KIND_INVESTIGATION => $query->whereIn('status', Investigation::LIVE_STATUSES),
            SearchDocuments::KIND_SANCTION => $query->where('active', true),
            SearchDocuments::KIND_REMOVAL => $query->outstanding(),
            default => null,
        };
    }

    private function hasAssignee(string $kind): bool
    {
        return in_array($kind, [
            SearchDocuments::KIND_CASE,
            SearchDocuments::KIND_INVESTIGATION,
            SearchDocuments::KIND_SANCTION,
            SearchDocuments::KIND_REMOVAL,
        ], true);
    }

    private function assigneeColumn(string $kind): string
    {
        return match ($kind) {
            SearchDocuments::KIND_SANCTION => 'issued_by',
            SearchDocuments::KIND_REMOVAL => 'requested_by',
            default => 'assigned_to',
        };
    }

    private function escapeLike(string $terms): string
    {
        return str_replace(['\\', '%'], ['\\\\', '\\%'], $terms);
    }

    /**
     * @param  list<int>  $ids
     * @return Collection<int, Model>
     */
    private function hydrate(string $kind, array $ids): Collection
    {
        $model = SearchDocuments::MODELS[$kind];

        return $model::query()
            ->with($this->eagerLoads($kind))
            ->whereKey($ids)
            ->get();
    }

    private function eagerLoads(string $kind): array
    {
        return match ($kind) {
            SearchDocuments::KIND_CASE => ['assignee', 'investigation', 'subjects'],
            SearchDocuments::KIND_INVESTIGATION => ['assignee', 'subjects'],
            SearchDocuments::KIND_SANCTION => ['subject', 'issuer'],
            SearchDocuments::KIND_REMOVAL => ['subject', 'requester'],
            default => [],
        };
    }

    /**
     * @param  list<array{text: string, match: bool}>|null  $snippet
     * @return array<string, mixed>
     */
    public function row(string $kind, Model $model, ?array $snippet = null): array
    {
        $shape = match ($kind) {
            SearchDocuments::KIND_CASE => $this->caseRow($model),
            SearchDocuments::KIND_INVESTIGATION => $this->investigationRow($model),
            SearchDocuments::KIND_SUBJECT => $this->subjectRow($model),
            SearchDocuments::KIND_SANCTION => $this->sanctionRow($model),
            SearchDocuments::KIND_REMOVAL => $this->removalRow($model),
            default => [],
        };

        return array_merge([
            'kind' => $kind,
            'kind_label' => SearchDocuments::label($kind),
            'route' => SearchDocuments::route($kind),
            'status_of' => $this->statusVocabulary($kind),
            'id' => $model->getKey(),
            'reference' => null,
            'title' => '',
            'subtitle' => null,
            'status' => null,
            'priority' => null,
            'assignee' => null,
            'accounts' => [],
            'created' => $model->created_at?->toIso8601String(),
            'updated' => $model->updated_at?->toIso8601String(),
            'snippet' => $snippet,
        ], $shape);
    }

    private function statusVocabulary(string $kind): string
    {
        return match ($kind) {
            SearchDocuments::KIND_SUBJECT => 'standing',
            SearchDocuments::KIND_SANCTION => 'force',
            SearchDocuments::KIND_REMOVAL => 'removal',
            SearchDocuments::KIND_INVESTIGATION => 'investigation',
            default => 'case',
        };
    }

    private function caseRow(Model $case): array
    {
        return [
            'reference' => $case->reference,
            'title' => $case->subject_line ?: $case->reference,
            'subtitle' => $case->investigation?->title,
            'status' => $case->status,
            'type' => $case->type,
            'priority' => $case->priority,
            'threat_to_life' => $case->isThreatToLife(),
            'assignee' => $case->assignee?->username,
            'accounts' => $case->relationLoaded('subjects')
                ? $case->subjects->pluck('username')->all()
                : [],
        ];
    }

    private function investigationRow(Model $investigation): array
    {
        return [
            'reference' => $investigation->reference,
            'title' => $investigation->title,
            'status' => $investigation->status,
            'priority' => $investigation->priority,
            'assignee' => $investigation->assignee?->username,
            'accounts' => $investigation->relationLoaded('subjects')
                ? $investigation->subjects->pluck('username')->all()
                : [],
        ];
    }

    private function subjectRow(Model $subject): array
    {
        return [
            'title' => $subject->username,
            'subtitle' => $subject->wiki_username,
            'status' => $subject->standing,
            'accounts' => [$subject->username],
        ];
    }

    private function sanctionRow(Model $sanction): array
    {
        return [
            'reference' => $sanction->reference,
            'title' => $sanction->subject?->username ?: ($sanction->label ?: $sanction->reference),
            'subtitle' => $sanction->label,
            'status' => $sanction->isInForce() ? 'in-force' : 'lifted',
            'type' => $sanction->type,
            'assignee' => $sanction->issuer?->username,
            'accounts' => array_values(array_filter([$sanction->subject?->username])),
        ];
    }

    private function removalRow(Model $removal): array
    {
        return [
            'reference' => $removal->reference,
            'title' => $removal->target_username ?: $removal->reference,
            'subtitle' => $removal->label(),
            'status' => $removal->state,
            'assignee' => $removal->requester?->username,
            'accounts' => array_values(array_filter([
                $removal->target_username,
                $removal->previous_username,
            ])),
        ];
    }

    /**
     * @param  list<string>  $fragments
     * @return list<array{text: string, match: bool}>|null
     */
    private function snippet(array $fragments): ?array
    {
        if ($fragments === []) {
            return null;
        }

        $joined = implode(' … ', $fragments);
        $parts = preg_split(
            '/('.preg_quote(self::HIGHLIGHT_OPEN, '/').'|'.preg_quote(self::HIGHLIGHT_CLOSE, '/').')/u',
            $joined,
            -1,
            PREG_SPLIT_DELIM_CAPTURE,
        ) ?: [];

        $runs = [];
        $matching = false;

        foreach ($parts as $part) {
            if ($part === self::HIGHLIGHT_OPEN) {
                $matching = true;

                continue;
            }

            if ($part === self::HIGHLIGHT_CLOSE) {
                $matching = false;

                continue;
            }

            if ($part !== '') {
                $runs[] = ['text' => $part, 'match' => $matching];
            }
        }

        return $runs === [] ? null : $runs;
    }

    /**
     * @param  list<string>  $asked
     * @return list<string>
     */
    private function kinds(array $asked): array
    {
        $kinds = array_values(array_intersect(SearchDocuments::KINDS, $asked));

        return $kinds === [] ? SearchDocuments::KINDS : $kinds;
    }
}
