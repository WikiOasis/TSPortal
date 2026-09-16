<?php

declare(strict_types=1);

namespace App\Services\Search;

use Closure;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

final class SearchIndexer
{
    public function __construct(
        private readonly OpenSearch $search,
        private readonly SearchDocuments $documents,
    ) {}

    public function kinds(): array
    {
        return SearchDocuments::KINDS;
    }

    /**
     * @param  list<string>  $kinds
     * @return array<string, string> Index name to what happened to it.
     */
    public function createIndexes(array $kinds, bool $fresh = false): array
    {
        $done = [];

        foreach ($kinds as $kind) {
            $index = $this->search->indexName($kind);

            if ($fresh) {
                $this->search->deleteIndex($index);
                $this->search->createIndex($index, $this->documents->settings());
                $done[$index] = 'rebuilt';

                continue;
            }

            if ($this->search->indexExists($index)) {
                $done[$index] = 'already there';

                continue;
            }

            $this->search->createIndex($index, $this->documents->settings());
            $done[$index] = 'created';
        }

        return $done;
    }

    /**
     * @param  list<string>  $kinds
     * @param  Closure(string, int): void|null  $progress
     * @return array<string, int> Kind to documents written.
     */
    public function fill(array $kinds, ?Carbon $since = null, ?Closure $progress = null): array
    {
        $run = Str::uuid()->toString();
        $chunk = max(1, (int) config('opensearch.chunk'));
        $written = [];

        foreach ($kinds as $kind) {
            $index = $this->search->indexName($kind);
            $query = $this->documents->source($kind);

            if ($since !== null) {
                $query = $this->documents->onlyChangedSince($kind, $query, $since->toDateTimeString());
            }

            $count = 0;

            $query->chunkById($chunk, function (Collection $rows) use ($kind, $index, $run, &$count, $progress) {
                $lines = [];

                foreach ($rows as $row) {
                    $lines[] = ['index' => ['_index' => $index, '_id' => (string) $row->getKey()]];
                    $lines[] = $this->document($kind, $row, $run);
                }

                $failures = $this->search->bulk($lines);

                if ($failures !== []) {
                    throw new SearchUnavailable(sprintf(
                        'OpenSearch refused %d %s document(s): %s',
                        count($failures),
                        $kind,
                        implode('; ', array_slice($failures, 0, 3)),
                    ));
                }

                $count += $rows->count();

                if ($progress !== null) {
                    $progress($kind, $rows->count());
                }
            });

            if ($since === null) {
                $this->search->deleteByQuery($index, ['bool' => ['must_not' => [['term' => ['run' => $run]]]]]);
            }

            $written[$kind] = $count;
        }

        return $written;
    }

    private function document(string $kind, Model $row, string $run): array
    {
        return array_merge($this->documents->build($kind, $row), [
            'indexed_at' => now()->toIso8601String(),
            'run' => $run,
        ]);
    }

    /**
     * @param  list<string>  $kinds
     * @return array<string, array{index: string, indexed: int|null, rows: int}>
     */
    public function state(array $kinds): array
    {
        $state = [];

        foreach ($kinds as $kind) {
            $index = $this->search->indexName($kind);
            $exists = $this->search->indexExists($index);

            $state[$kind] = [
                'index' => $index,
                'indexed' => $exists ? $this->search->documentCount($index) : null,
                'rows' => $this->documents->source($kind)->toBase()->count(),
            ];
        }

        return $state;
    }
}
