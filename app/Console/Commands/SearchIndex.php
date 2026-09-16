<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Search\OpenSearch;
use App\Services\Search\SearchDocuments;
use App\Services\Search\SearchIndexer;
use App\Services\Search\SearchUnavailable;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class SearchIndex extends Command
{
    protected $signature = 'tsportal:search-index
        {--fresh : Delete the indexes and build them again from nothing}
        {--only=* : Only these kinds -- case, investigation, subject, sanction, removal}
        {--since= : Only rows written since then, as 15m, 6h, 2d or a date}
        {--check : Say what is in the index and what is in the database, and change nothing}';

    protected $description = 'Create the OpenSearch indexes and fill them from the database';

    public function handle(OpenSearch $search, SearchIndexer $indexer): int
    {
        if (! $search->configured()) {
            $this->components->error(
                'OpenSearch is switched off. Set OPENSEARCH_ENABLED=true and OPENSEARCH_URL in .env.',
            );

            return self::FAILURE;
        }

        if (! $search->reachable()) {
            $this->components->error('Nothing answered at '.$search->url().'.');

            return self::FAILURE;
        }

        $kinds = $this->kinds();

        if ($kinds === []) {
            $this->components->error('No such kind. Pick from: '.implode(', ', SearchDocuments::KINDS).'.');

            return self::FAILURE;
        }

        try {
            return $this->option('check')
                ? $this->report($indexer, $kinds)
                : $this->build($indexer, $kinds);
        } catch (SearchUnavailable $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }
    }

    private function build(SearchIndexer $indexer, array $kinds): int
    {
        $since = $this->since();

        if ($since !== null && $this->option('fresh')) {
            $this->components->error('--since and --fresh ask for opposite things.');

            return self::FAILURE;
        }

        foreach ($indexer->createIndexes($kinds, (bool) $this->option('fresh')) as $index => $what) {
            $this->components->twoColumnDetail($index, $what);
        }

        if ($since !== null) {
            $this->components->info('Only what changed since '.$since->diffForHumans().'.');
        }

        $bar = $this->output->createProgressBar();
        $bar->start();

        $written = $indexer->fill($kinds, $since, function () use ($bar) {
            $bar->advance();
        });

        $bar->finish();
        $this->newLine(2);

        foreach ($written as $kind => $count) {
            $this->components->twoColumnDetail(
                SearchDocuments::label($kind, true),
                $count === 0 ? 'nothing to write' : $count.' indexed',
            );
        }

        $this->newLine();
        $this->components->info('The index is a copy of the database. Run this again whenever you doubt it.');

        return self::SUCCESS;
    }

    private function report(SearchIndexer $indexer, array $kinds): int
    {
        $behind = false;

        foreach ($indexer->state($kinds) as $kind => $state) {
            $indexed = $state['indexed'];

            $this->components->twoColumnDetail(
                SearchDocuments::label($kind, true).' <fg=gray>'.$state['index'].'</>',
                $indexed === null
                    ? '<fg=yellow>no index</> · '.$state['rows'].' in the database'
                    : $indexed.' indexed · '.$state['rows'].' in the database',
            );

            $behind = $behind || $indexed === null || $indexed !== $state['rows'];
        }

        $this->newLine();

        if ($behind) {
            $this->components->warn('The index and the database disagree. `tsportal:search-index --fresh` settles it.');
        } else {
            $this->components->info('The index matches the database.');
        }

        return self::SUCCESS;
    }

    private function kinds(): array
    {
        $only = (array) $this->option('only');

        if ($only === []) {
            return SearchDocuments::KINDS;
        }

        return array_values(array_intersect(
            SearchDocuments::KINDS,
            array_map(fn ($kind) => strtolower(trim((string) $kind)), $only),
        ));
    }

    private function since(): ?Carbon
    {
        $since = (string) ($this->option('since') ?? '');

        if ($since === '') {
            return null;
        }

        if (preg_match('/^(\d+)\s*([mhd])$/i', trim($since), $match) === 1) {
            $amount = (int) $match[1];

            return match (strtolower($match[2])) {
                'm' => now()->subMinutes($amount),
                'h' => now()->subHours($amount),
                default => now()->subDays($amount),
            };
        }

        return Carbon::parse($since);
    }
}
