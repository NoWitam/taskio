<?php

namespace App\Modules\Bot\Tools\Support;

use Illuminate\Support\Facades\Http;

/**
 * Brave Search API implementation. Available only when config('ai.search.api_key') is
 * set. The key is sent via the X-Subscription-Token header and is NEVER logged, echoed,
 * or persisted (the tool records only the query, not the key).
 */
class BraveSearchProvider implements SearchProvider
{
    private const ENDPOINT = 'https://api.search.brave.com/res/v1/web/search';

    public function isAvailable(): bool
    {
        return filled(config('ai.search.api_key'));
    }

    public function search(string $query, int $limit): array
    {
        $response = Http::withHeaders([
            'Accept' => 'application/json',
            'X-Subscription-Token' => (string) config('ai.search.api_key'),
        ])->timeout(10)->get(self::ENDPOINT, [
            'q' => $query,
            'count' => $limit,
        ]);

        $results = $response->json('web.results') ?? [];

        return collect($results)
            ->take($limit)
            ->map(fn ($result) => [
                'title' => (string) ($result['title'] ?? ''),
                'url' => (string) ($result['url'] ?? ''),
                'snippet' => (string) ($result['description'] ?? ''),
            ])
            ->values()
            ->all();
    }
}
