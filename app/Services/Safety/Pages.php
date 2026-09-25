<?php

declare(strict_types=1);

namespace App\Services\Safety;

use App\Models\Wiki;
use InvalidArgumentException;

final class Pages
{
    public const MAX_PER_ACTION = 500;

    /**
     * @param  array<string, mixed>  $roles
     * @param  array<string, mixed>  $answers
     * @return list<array{wiki: string, title: string}>
     */
    public static function fromSubmission(array $roles, array $answers, ?string $filedOn): array
    {
        $field = $roles['pages'] ?? null;
        if (! is_string($field)) {
            return [];
        }

        $wiki = self::wikiFrom($roles, $answers) ?? $filedOn;

        $pages = [];
        foreach ((array) ($answers[$field] ?? []) as $value) {
            if (! is_scalar($value)) {
                continue;
            }

            $page = self::parse((string) $value, $wiki);
            if ($page !== null) {
                $pages[] = $page;
            }
        }

        return self::unique($pages);
    }

    /**
     * @param  array<string, mixed>  $roles
     * @param  array<string, mixed>  $answers
     */
    private static function wikiFrom(array $roles, array $answers): ?string
    {
        $field = $roles['wikis'] ?? null;
        if (! is_string($field)) {
            return null;
        }

        foreach ((array) ($answers[$field] ?? []) as $value) {
            if (! is_scalar($value)) {
                continue;
            }

            $value = strtolower(trim((string) $value));
            if (self::isDbName($value)) {
                return $value;
            }

            $host = parse_url(str_contains($value, '://') ? $value : 'https://'.$value, PHP_URL_HOST);
            if (is_string($host) && ($found = self::wikiForHost($host)) !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * @return array{wiki: string, title: string}|null
     */
    public static function parse(string $value, ?string $wiki): ?array
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        if (preg_match('#^https?://#i', $value) === 1) {
            $parts = parse_url($value);
            $host = $parts['host'] ?? null;
            $title = null;

            if (isset($parts['query'])) {
                parse_str($parts['query'], $query);
                if (isset($query['title']) && is_string($query['title'])) {
                    $title = $query['title'];
                }
            }

            if ($title === null && isset($parts['path']) && preg_match('#/wiki/(.+)$#', $parts['path'], $m) === 1) {
                $title = rawurldecode($m[1]);
            }

            $wiki = (is_string($host) ? self::wikiForHost($host) : null) ?? $wiki;

            if ($title === null) {
                return null;
            }

            $value = $title;
        }

        return self::make($wiki, $value);
    }

    /**
     * @return array{wiki: string, title: string}|null
     */
    public static function make(?string $wiki, ?string $title): ?array
    {
        $wiki = strtolower(trim((string) $wiki));
        $title = trim(preg_replace('/\s+/u', ' ', str_replace('_', ' ', (string) $title)) ?? '');

        if (! self::isDbName($wiki) || $title === '' || mb_strlen($title) > 255) {
            return null;
        }

        return ['wiki' => $wiki, 'title' => $title];
    }

    /**
     * @return array{pages: list<array{wiki: string, title: string}>, skipped: list<array{page: string, why: string}>}
     */
    public static function inText(string $text, ?string $wiki): array
    {
        $known = null;
        $pages = [];
        $skipped = [];

        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            $line = trim((string) preg_replace('/^[\s*#:\-•]+/u', '', $line));

            if (preg_match('/^\[\[([^\]|]+)(?:\|[^\]]*)?\]\]$/u', $line, $m) === 1) {
                $line = trim($m[1]);
            }

            if ($line === '') {
                continue;
            }

            $page = null;

            if (preg_match('#^https?://#i', $line) === 1) {
                $page = self::parse($line, $wiki);
            } elseif (preg_match('/^([a-z0-9_]{1,64})\s*:\s*(.+)$/', $line, $m) === 1) {
                $known ??= array_flip(Wiki::query()->pluck('dbname')->all());

                $page = isset($known[$m[1]]) || str_ends_with($m[1], 'wiki')
                    ? self::make($m[1], $m[2])
                    : self::make($wiki, $line);
            } else {
                $page = self::make($wiki, $line);
            }

            if ($page === null) {
                $skipped[] = [
                    'page' => $line,
                    'why' => $wiki === null || $wiki === ''
                        ? 'Say which wiki it is on, as examplewiki:Title, or pick a wiki for the list.'
                        : 'Not a page title that could be read.',
                ];

                continue;
            }

            $pages[] = $page;
        }

        return ['pages' => self::unique($pages), 'skipped' => $skipped];
    }

    /**
     * @param  iterable<mixed>  $given
     * @return list<array{wiki: string, title: string}>
     *
     * @throws InvalidArgumentException
     */
    public static function normalise(iterable $given): array
    {
        $pages = [];

        foreach ($given as $entry) {
            $page = is_array($entry)
                ? self::make(
                    is_scalar($entry['wiki'] ?? null) ? (string) $entry['wiki'] : null,
                    is_scalar($entry['title'] ?? null) ? (string) $entry['title'] : null,
                )
                : null;

            if ($page === null) {
                throw new InvalidArgumentException(
                    'Every page needs the wiki it is on, as a database name like examplewiki, and its title.'
                );
            }

            $pages[] = $page;
        }

        return self::unique($pages);
    }

    /**
     * @param  list<array{wiki: string, title: string}>  $pages
     * @return list<array{wiki: string, title: string}>
     */
    public static function unique(array $pages): array
    {
        $seen = [];

        foreach ($pages as $page) {
            $seen[self::key($page)] ??= $page;
        }

        return array_values($seen);
    }

    /**
     * @param  array{wiki: string, title: string}  $page
     */
    public static function key(array $page): string
    {
        return $page['wiki'].'|'.$page['title'];
    }

    /**
     * @param  list<array{wiki: string, title: string}>  $pages
     * @return array<string, list<string>>
     */
    public static function byWiki(array $pages): array
    {
        $grouped = [];

        foreach ($pages as $page) {
            $grouped[$page['wiki']][] = $page['title'];
        }

        return $grouped;
    }

    /**
     * @param  list<array{wiki: string, title: string}>  $pages
     */
    public static function describe(array $pages, int $limit = 255): string
    {
        $parts = [];
        foreach (self::byWiki($pages) as $wiki => $titles) {
            $parts[] = $wiki.': '.implode(', ', $titles);
        }

        $text = implode('; ', $parts);

        if (mb_strlen($text) <= $limit) {
            return $text;
        }

        $count = count($pages);
        $tail = sprintf(' … (%d pages)', $count);

        return rtrim(mb_substr($text, 0, $limit - mb_strlen($tail))).$tail;
    }

    private static function isDbName(string $value): bool
    {
        return preg_match('/^[a-z0-9_]{1,64}$/', $value) === 1;
    }

    private static function wikiForHost(string $host): ?string
    {
        $host = strtolower($host);

        $wiki = Wiki::query()
            ->where('url', 'like', '%://'.$host)
            ->orWhere('url', 'like', '%://'.$host.'/%')
            ->value('dbname');

        return is_string($wiki) ? $wiki : null;
    }
}
