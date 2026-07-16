<?php

namespace App\Modules\Bot\Tools\Support;

/**
 * Web-search provider abstraction. Kept tiny so tests fake the concrete implementation
 * (Http::fake) or bind a stub. A provider is AVAILABLE only when configured (e.g. an
 * API key is present) — the registry hides web_search entirely otherwise.
 */
interface SearchProvider
{
    /** Whether the provider is configured and usable (e.g. has an API key). */
    public function isAvailable(): bool;

    /**
     * @return array<int, array{title: string, url: string, snippet: string}>
     */
    public function search(string $query, int $limit): array;
}
