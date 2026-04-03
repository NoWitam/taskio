<?php

namespace App\Modules\Forms\Tools;

use App\Modules\Forms\Models\FormReport;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class QuerySubmissions implements Tool
{
    public function __construct(
        private FormReport $report
    ) {}

    /**
     * Get the description of the tool's purpose.
     */
    public function description(): Stringable|string
    {
        return "Wykonuje zapytanie SQL na widoku z wypełnieniami formularza '{$this->report->getViewName()}'. "
            . "Zwraca wyniki jako JSON. Możesz używać tego narzędzia wielokrotnie do eksploracji danych. "
            . "Widok zawiera kolumny: data (JSON), source (TEXT), creator_id (UUID), creator_name (TEXT), created_at (TIMESTAMP).";
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): Stringable|string
    {
        try {
            $query = $request['query'];

            // Security: ensure query only reads from the view
            // Basic SQL injection protection - only allow SELECT
            if (!preg_match('/^\s*SELECT/i', $query)) {
                return json_encode([
                    'error' => 'Only SELECT queries are allowed',
                    'query' => $query,
                ]);
            }

            $results = DB::select($query);

            return json_encode([
                'success' => true,
                'row_count' => count($results),
                'data' => $results,
            ]);
        } catch (\Exception $e) {
            return json_encode([
                'error' => $e->getMessage(),
                'query' => $request['query'] ?? null,
            ]);
        }
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()
                ->description(
                    "Zapytanie SQL do wykonania. Używaj nazwy widoku: {$this->report->getViewName()}. "
                    . "Dostępne kolumny: data, source, creator_id, creator_name, created_at. "
                    . "Możesz używać funkcji PostgreSQL do analizy JSON (np. data::json->'pole')."
                    . "Pobieraj na raz maksymalnie 50 rekordów aby nie przekroczyć limitu tekstu jaki na raz mogę ci przekazać"
                )
                ->required(),
        ];
    }
}
