<?php

namespace App\Modules\Bot\Tools\Registry;

use App\Modules\Bot\Enums\BotActionType;
use App\Modules\Bot\Tools\BotToolContext;
use App\Modules\Bot\Tools\Support\SearchProvider;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * web_search(query): search the web via the configured provider and return the top
 * results (title, url, snippet). Only exposed when the provider is available (has a
 * key) — see BotToolRegistry availability. Records a `tool_used` action carrying the
 * query only (never the API key).
 */
class WebSearchTool implements Tool
{
    public function __construct(
        private BotToolContext $ctx,
        private SearchProvider $provider,
    ) {}

    public function description(): Stringable|string
    {
        return 'Wyszukuje w internecie i zwraca najlepsze wyniki (tytuł, adres, opis). '
            . 'Używaj do znajdowania źródeł i aktualnych informacji.';
    }

    public function handle(Request $request): Stringable|string
    {
        $query = trim((string) ($request['query'] ?? ''));

        if ($query === '') {
            return 'Zapytanie nie może być puste.';
        }

        $limit = (int) config('ai.search.results', 5);
        $results = $this->provider->search($query, $limit);

        $this->ctx->actions->record($this->ctx->bot, $this->ctx->task, BotActionType::ToolUsed, [
            'tool' => 'web_search',
            'query' => $query,
            'results' => count($results),
        ]);

        if ($results === []) {
            return 'Brak wyników.';
        }

        return collect($results)
            ->map(fn ($r) => "- {$r['title']}\n  {$r['url']}\n  {$r['snippet']}")
            ->implode("\n");
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()
                ->description('Zapytanie wyszukiwania.')
                ->required(),
        ];
    }
}
