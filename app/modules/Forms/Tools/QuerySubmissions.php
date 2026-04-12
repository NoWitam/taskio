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
        $viewName = $this->report->getViewName();
        $eavViewName = $viewName . '_eav';
        
        return "Wykonuje zapytanie SQL (PostgreSQL 16) na widokach z wypełnieniami formularza. "
            . "Zwraca wyniki jako JSON. Możesz używać tego narzędzia wielokrotnie do eksploracji danych. "
            . "MASZ DOSTĘP DO DWÓCH WIDOKÓW: "
            . "1) WIDOK GŁÓWNY (PIVOT): '{$viewName}' - UŻYJ TEGO - kolumny dla każdego pola formularza, jeden wiersz = jedno wypełnienie. "
            . "2) WIDOK EAV: '{$eavViewName}' - opcjonalny - surowe dane pól, jeden wiersz = jedno pole. "
            . "UŻYWAJ STANDARDOWEGO SQL - BRAK operatorów JSON (->, ->>, @>, ?). "
            . "KOLUMNY WIDOKU GŁÓWNEGO: submission_id, source, creator_id, creator_name, created_at + kolumny pól formularza.";
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): Stringable|string
    {
        try {
            $query = $request['query'];
            $viewName = $this->report->getViewName();
            $eavViewName = $viewName . '_eav';

            // Security: ensure query only reads from the view
            // Basic SQL injection protection - only allow SELECT
            if (!preg_match('/^\s*SELECT/i', $query)) {
                return json_encode([
                    'error' => 'Only SELECT queries are allowed',
                    'query' => $query,
                ]);
            }
            
            // Validate that query uses allowed view names
            if (!str_contains($query, $viewName) && !str_contains($query, $eavViewName)) {
                return json_encode([
                    'error' => "Query must use one of the allowed views: {$viewName} or {$eavViewName}",
                    'query' => $query,
                ]);
            }
            
            // Block JSON operators - these don't exist in the new structure
            if (preg_match('/(->|@>|\?&|\?\||#>|#>>)/', $query)) {
                return json_encode([
                    'error' => 'JSON operators (->, ->>, @>, ?, ?&, ?|, #>, #>>) are not allowed. Use column names directly (e.g., SELECT email instead of SELECT data->>\"email\")',
                    'query' => $query,
                ]);
            }
            
            dump($query);
            $results = DB::select($query);

            return json_encode([
                'success' => true,
                'row_count' => count($results),
                'data' => $results,
            ]);
        } catch (\Exception $e) {
            dump($e->getMessage());
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
        $viewName = $this->report->getViewName();
        $eavViewName = $viewName . '_eav';
        $fieldColumns = $this->formatAvailableColumns();
        
        return [
            'query' => $schema->string()
                ->description(
                    "Zapytanie SQL (PostgreSQL 16) do wykonania. "
                    . "DOSTĘPNE WIDOKI: "
                    . "1) WIDOK GŁÓWNY (UŻYJ TEGO): {$viewName} - kolumny: submission_id, source, creator_id, creator_name, created_at + {$fieldColumns}. "
                    . "2) WIDOK EAV (opcjonalny): {$eavViewName} - kolumny: submission_id, source, creator_id, creator_name, created_at, field_path, field_key, field_value, field_value_numeric, field_value_boolean, field_value_date, field_type. "
                    . "UŻYWAJ STANDARDOWEGO SQL - BEZ operatorów JSON! "
                    . "PRZYKŁADY: SELECT COUNT(*) FROM {$viewName}; SELECT email, COUNT(*) FROM {$viewName} GROUP BY email; SELECT AVG(age) FROM {$viewName}. "
                    . "NIE UŻYWAJ: data->>'pole', data @> '...', data ? 'klucz' (te składnie nie działają). "
                    . "LIMIT: Maksymalnie 50 rekordów (LIMIT 50)."
                )
                ->required(),
        ];
    }
    
    /**
     * Format available columns for tool description.
     */
    private function formatAvailableColumns(): string
    {
        $form = $this->report->form;
        $schema = $form->getJsonSchema();
        $fieldPaths = $this->extractFieldPaths($schema);
        
        $columns = [];
        foreach ($fieldPaths as $pathInfo) {
            if ($pathInfo['type'] === 'repeater') {
                continue;
            }
            
            $columnName = str_replace(['.', '[', ']'], '_', $pathInfo['path']);
            $columnName = preg_replace('/_+/', '_', $columnName);
            $columnName = trim($columnName, '_');
            $columns[] = $columnName;
        }
        
        return implode(', ', array_slice($columns, 0, 10)) . (count($columns) > 10 ? '...' : '');
    }
    
    /**
     * Extract field paths from schema (same logic as in CreateFormReport).
     */
    private function extractFieldPaths(array $schema, string $prefix = ''): array
    {
        $paths = [];

        foreach ($schema as $field) {
            if (!isset($field['id'])) {
                continue;
            }

            $fieldId = $field['id'];
            $path = $prefix ? "{$prefix}.{$fieldId}" : $fieldId;
            $type = $field['type'] ?? 'short_text';

            if ($type === 'section' && isset($field['config']['children'])) {
                $paths = array_merge(
                    $paths,
                    $this->extractFieldPaths($field['config']['children'], $path)
                );
            } elseif ($type === 'repeater' && isset($field['config']['children'])) {
                $paths[] = ['path' => $path, 'type' => $type, 'field' => $field];
                
                foreach ($field['config']['children'] as $childField) {
                    if (isset($childField['id'])) {
                        $childPath = "{$path}[n].{$childField['id']}";
                        $paths[] = ['path' => $childPath, 'type' => $childField['type'] ?? 'short_text', 'field' => $childField];
                    }
                }
            } else {
                $paths[] = ['path' => $path, 'type' => $type, 'field' => $field];
            }
        }

        return $paths;
    }
}
