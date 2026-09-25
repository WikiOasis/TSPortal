<?php

declare(strict_types=1);

namespace App\Services\AutoReview;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

final class OpenRouterClient
{
    public function configured(): bool
    {
        return (string) config('autoreview.openrouter.key', '') !== '';
    }

    public function model(): string
    {
        return (string) config('autoreview.openrouter.model', 'z-ai/glm-5.3-flash');
    }

    /**
     * @param  list<array{role: string, content: string}>  $messages
     * @param  array<string, mixed>|null  $schema
     * @return array{content: string, model: string, tokens: ?int, cost: ?float}
     */
    public function chat(array $messages, ?array $schema = null): array
    {
        if (! $this->configured()) {
            throw new ClassificationFailed('No OpenRouter API key is set.', retry: false);
        }

        $body = [
            'model' => $this->model(),
            'messages' => $messages,
            'temperature' => 0,
            'max_tokens' => (int) config('autoreview.openrouter.max_tokens', 2000),
            'usage' => ['include' => true],
        ];

        if ($schema !== null && (bool) config('autoreview.openrouter.structured', true)) {
            $body['response_format'] = [
                'type' => 'json_schema',
                'json_schema' => ['name' => 'triage', 'strict' => true, 'schema' => $schema],
            ];
        } else {
            $body['response_format'] = ['type' => 'json_object'];
        }

        try {
            $response = Http::withToken((string) config('autoreview.openrouter.key'))
                ->withHeaders([
                    'HTTP-Referer' => (string) config('autoreview.openrouter.referer'),
                    'X-Title' => (string) config('app.name', 'TSPortal'),
                ])
                ->acceptJson()
                ->timeout(max(5, (int) config('autoreview.openrouter.timeout', 90)))
                ->post(config('autoreview.openrouter.url').'/chat/completions', $body);
        } catch (ConnectionException $e) {
            throw new ClassificationFailed('OpenRouter did not answer: '.$e->getMessage());
        }

        $data = $response->json();

        if (! $response->successful() || ! is_array($data)) {
            $message = is_array($data) ? (string) ($data['error']['message'] ?? '') : '';

            throw new ClassificationFailed(
                sprintf('OpenRouter returned HTTP %d%s', $response->status(), $message !== '' ? ': '.$message : ''),
                retry: $response->status() === 429 || $response->status() >= 500 || $response->status() === 408,
            );
        }

        if (isset($data['error'])) {
            throw new ClassificationFailed('OpenRouter: '.(string) ($data['error']['message'] ?? 'unknown error'));
        }

        $content = $data['choices'][0]['message']['content'] ?? null;

        if (is_array($content)) {
            $content = implode('', array_map(fn ($part) => is_array($part) ? (string) ($part['text'] ?? '') : (string) $part, $content));
        }

        if (! is_string($content) || trim($content) === '') {
            throw new ClassificationFailed('The model returned an empty answer.');
        }

        return [
            'content' => $content,
            'model' => (string) ($data['model'] ?? $this->model()),
            'tokens' => isset($data['usage']['total_tokens']) ? (int) $data['usage']['total_tokens'] : null,
            'cost' => isset($data['usage']['cost']) && is_numeric($data['usage']['cost']) ? (float) $data['usage']['cost'] : null,
        ];
    }
}
