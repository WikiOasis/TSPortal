<?php

declare(strict_types=1);

namespace App\Services\AutoReview;

use App\Models\AutomatedReview;
use App\Models\SafetyCase;
use App\Models\Wiki;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Throwable;

final class WikiContent
{
    public function __construct(private readonly LineDiff $diff = new LineDiff) {}

    /**
     * @return array<string, mixed>
     */
    public function gather(AutomatedReview $review, SafetyCase $case): array
    {
        $answers = (array) ($case->answers ?? []);
        $revisionUrl = is_string($answers['revision_url'] ?? null) ? $answers['revision_url'] : null;

        $evidence = [
            'source' => 'metadata',
            'mode' => $review->scan_mode ?? 'edit',
            'revision_url' => $revisionUrl,
            'page_url' => null,
            'contributions_url' => null,
            'diff' => null,
            'content' => null,
            'truncated' => false,
            'reverted' => null,
            'current' => null,
            'page_missing' => false,
            'revision_missing' => false,
            'content_hidden' => false,
            'revision' => null,
            'latest_revision_id' => null,
            'author' => null,
            'problem' => null,
        ];

        if (! (bool) config('autoreview.fetch.enabled', true)) {
            $evidence['problem'] = 'Fetching wiki content is turned off.';

            return $evidence;
        }

        $wiki = $review->wiki !== null ? Wiki::find($review->wiki) : null;

        if ($wiki?->private) {
            $evidence['problem'] = 'The wiki is private, so its content was not fetched.';

            return $evidence;
        }

        $api = $this->apiUrl($revisionUrl, $wiki);

        if ($api === null) {
            $evidence['problem'] = 'No address is known for this wiki.';

            return $evidence;
        }

        $evidence['page_url'] = $this->articleUrl($api, $review->page_title);
        $evidence['contributions_url'] = $review->author !== null
            ? $this->articleUrl($api, 'Special:Contributions/'.$review->author)
            : null;

        if ($review->revision_id === null) {
            $evidence['problem'] = 'The report did not say which revision was flagged.';

            return $evidence;
        }

        try {
            return $this->fetch($api, $review, $evidence, $revisionUrl);
        } catch (Throwable $e) {
            $evidence['problem'] = 'The wiki could not be read: '.mb_substr($e->getMessage(), 0, 200);

            return $evidence;
        }
    }

    /**
     * @param  array<string, mixed>  $evidence
     * @return array<string, mixed>
     */
    private function fetch(string $api, AutomatedReview $review, array $evidence, ?string $revisionUrl): array
    {
        $params = [
            'action' => 'query',
            'format' => 'json',
            'formatversion' => '2',
            'prop' => 'revisions|info',
            'revids' => (string) $review->revision_id,
            'rvprop' => 'ids|timestamp|user|comment|size|tags|content|flags',
            'rvslots' => 'main',
        ];

        if ($review->author !== null) {
            $params['list'] = 'users';
            $params['ususers'] = $review->author;
            $params['usprop'] = 'editcount|registration|groups|blockinfo';
        }

        $data = $this->get($api, $params);

        $user = Arr::first((array) Arr::get($data, 'query.users', []));
        if (is_array($user) && ! isset($user['missing']) && ! isset($user['invalid'])) {
            $evidence['author'] = [
                'name' => $user['name'] ?? $review->author,
                'edits' => isset($user['editcount']) ? (int) $user['editcount'] : null,
                'registered' => $user['registration'] ?? null,
                'groups' => array_values(array_diff((array) ($user['groups'] ?? []), ['*', 'user', 'autoconfirmed'])),
                'blocked' => isset($user['blockid']),
                'block_reason' => isset($user['blockid']) ? mb_substr((string) ($user['blockreason'] ?? ''), 0, 300) : null,
            ];
        } elseif ($review->author !== null && filter_var($review->author, FILTER_VALIDATE_IP) !== false) {
            $evidence['author'] = ['name' => $review->author, 'ip' => true];
        }

        if (Arr::get($data, 'query.badrevids') !== null) {
            $evidence['revision_missing'] = true;
            $evidence['problem'] = 'The revision is gone: the page was probably deleted.';

            return $evidence;
        }

        $page = Arr::first((array) Arr::get($data, 'query.pages', []));
        if (! is_array($page) || isset($page['missing'])) {
            $evidence['page_missing'] = true;
            $evidence['problem'] = 'The page no longer exists.';

            return $evidence;
        }

        $revision = Arr::first((array) ($page['revisions'] ?? []));
        if (! is_array($revision)) {
            $evidence['revision_missing'] = true;

            return $evidence;
        }

        $tags = array_values((array) ($revision['tags'] ?? []));
        $slot = (array) Arr::get($revision, 'slots.main', []);
        $hidden = isset($revision['texthidden']) || isset($slot['texthidden']);
        $text = is_string($slot['content'] ?? null) ? $slot['content'] : null;

        $evidence['revision'] = [
            'id' => (int) ($revision['revid'] ?? $review->revision_id),
            'parent_id' => isset($revision['parentid']) ? (int) $revision['parentid'] : null,
            'user' => $revision['user'] ?? null,
            'timestamp' => $revision['timestamp'] ?? null,
            'comment' => isset($revision['comment']) ? mb_substr((string) $revision['comment'], 0, 500) : null,
            'size' => isset($revision['size']) ? (int) $revision['size'] : null,
            'tags' => $tags,
            'minor' => (bool) ($revision['minor'] ?? false),
        ];
        $evidence['latest_revision_id'] = isset($page['lastrevid']) ? (int) $page['lastrevid'] : null;
        $evidence['current'] = $evidence['latest_revision_id'] === $evidence['revision']['id'];
        $evidence['reverted'] = in_array('mw-reverted', $tags, true);
        $evidence['content_hidden'] = $hidden;

        if ($hidden || $text === null) {
            $evidence['problem'] = $hidden
                ? 'The revision text has been hidden on the wiki.'
                : 'The wiki did not return the revision text.';

            return $evidence;
        }

        $max = max(1000, (int) config('autoreview.fetch.max_chars', 12000));
        $parentId = $evidence['revision']['parent_id'] ?? $this->oldIdFrom($revisionUrl);

        if (($review->scan_mode ?? 'edit') !== 'page' && $parentId !== null && $parentId > 0) {
            $before = $this->revisionText($api, $parentId);

            if ($before !== null) {
                [$diff, $truncated] = $this->diff->render($before, $text, $max);

                $evidence['source'] = 'diff';
                $evidence['diff'] = $diff;
                $evidence['truncated'] = $truncated;
                $evidence['page_size'] = mb_strlen($text);

                if ($evidence['revision']['size'] !== null && $evidence['revision']['size'] < 20000) {
                    $evidence['content'] = mb_substr($text, 0, (int) min(4000, $max / 3));
                }

                return $evidence;
            }
        }

        $evidence['source'] = 'page';
        $evidence['content'] = mb_substr($text, 0, $max);
        $evidence['truncated'] = mb_strlen($text) > $max;
        $evidence['page_created'] = $parentId === null || $parentId === 0;

        return $evidence;
    }

    private function revisionText(string $api, int $revisionId): ?string
    {
        $data = $this->get($api, [
            'action' => 'query',
            'format' => 'json',
            'formatversion' => '2',
            'prop' => 'revisions',
            'revids' => (string) $revisionId,
            'rvprop' => 'content',
            'rvslots' => 'main',
        ]);

        $content = Arr::get($data, 'query.pages.0.revisions.0.slots.main.content');

        return is_string($content) ? $content : null;
    }

    /**
     * @param  array<string, string>  $params
     * @return array<string, mixed>
     */
    private function get(string $api, array $params): array
    {
        try {
            $response = Http::withUserAgent((string) config('mediawiki.user_agent'))
                ->acceptJson()
                ->timeout(max(1, (int) config('autoreview.fetch.timeout', 15)))
                ->get($api, $params);
        } catch (ConnectionException $e) {
            throw new \RuntimeException('the wiki did not answer');
        }

        if (! $response->successful()) {
            throw new \RuntimeException('HTTP '.$response->status());
        }

        $data = $response->json();

        if (! is_array($data)) {
            throw new \RuntimeException('the answer was not JSON');
        }

        if (isset($data['error']['info'])) {
            throw new \RuntimeException((string) $data['error']['info']);
        }

        return $data;
    }

    public function apiUrl(?string $revisionUrl, ?Wiki $wiki): ?string
    {
        if ($revisionUrl !== null) {
            $parts = parse_url($revisionUrl);

            if (is_array($parts) && isset($parts['host']) && in_array($parts['scheme'] ?? 'https', ['http', 'https'], true)) {
                $path = (string) ($parts['path'] ?? '');
                $base = str_contains($path, '/index.php')
                    ? substr($path, 0, (int) strpos($path, '/index.php'))
                    : '/w';

                return sprintf(
                    '%s://%s%s%s/api.php',
                    $parts['scheme'] ?? 'https',
                    $parts['host'],
                    isset($parts['port']) ? ':'.$parts['port'] : '',
                    $base,
                );
            }
        }

        if ($wiki !== null && is_string($wiki->url) && $wiki->url !== '') {
            $url = rtrim($wiki->url, '/');

            return str_ends_with($url, '/api.php') ? $url : $url.'/w/api.php';
        }

        return null;
    }

    private function articleUrl(string $api, ?string $title): ?string
    {
        if ($title === null || $title === '') {
            return null;
        }

        return substr($api, 0, -strlen('api.php')).'index.php?title='.rawurlencode(str_replace(' ', '_', $title));
    }

    private function oldIdFrom(?string $url): ?int
    {
        if ($url === null) {
            return null;
        }

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return isset($query['oldid']) && is_numeric($query['oldid']) ? (int) $query['oldid'] : null;
    }
}
