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
    /**
     * @param  string[]  $allowedTables  Table/view names the agent is allowed to query
     * @param  string|null  $formId  When querying form_submissions directly, enforce WHERE form_id = $formId
     */
    public function __construct(
        private FormReport $report,
        private array $allowedTables = [],
        private ?string $formId = null,
    ) {}

    /**
     * Get the description of the tool's purpose.
     */
    public function description(): Stringable|string
    {
        $tables = implode(', ', array_map(fn ($t) => "'{$t}'", $this->allowedTables));

        $desc = 'Wykonuje zapytanie SQL (PostgreSQL 16) na tabelach/widokach z wypełnieniami formularza. '
            . 'Zwraca wyniki jako JSON (max 50 rekordów). Możesz używać tego narzędzia wielokrotnie do eksploracji danych. '
            . "DOSTĘPNE TABELE/WIDOKI: {$tables}. ";

        if ($this->formId) {
            $desc .= "WYMAGANE: WHERE form_id = '{$this->formId}' w każdym zapytaniu. "
                . "Dane wypełnień w kolumnie 'data' (JSONB) — używaj operatorów ->, ->>. "
                . 'Do paginacji użyj LIMIT 50 OFFSET N.';
        } else {
            $desc .= 'Wszystkie pola formularza są osobnymi kolumnami. UŻYWAJ STANDARDOWEGO SQL.';
        }

        return $desc;
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): Stringable|string
    {
        try {
            $query = $request['query'];

            if (!preg_match('/^\s*SELECT/i', $query)) {
                return json_encode([
                    'error' => 'Only SELECT queries are allowed',
                    'query' => $query,
                ]);
            }

            // Validate that query uses one of the allowed tables/views
            $usesAllowedTable = false;
            foreach ($this->allowedTables as $table) {
                if (str_contains($query, $table)) {
                    $usesAllowedTable = true;
                    break;
                }
            }

            if (!$usesAllowedTable) {
                $tables = implode(', ', $this->allowedTables);

                return json_encode([
                    'error' => "Query must use one of the allowed tables: {$tables}",
                    'query' => $query,
                ]);
            }

            // When querying form_submissions directly, enforce form_id filter
            if ($this->formId && str_contains($query, 'form_submissions')) {
                if (!str_contains($query, $this->formId)) {
                    return json_encode([
                        'error' => "Query on form_submissions must include WHERE form_id = '{$this->formId}'",
                        'query' => $query,
                    ]);
                }
            }

            // Enforce LIMIT to prevent excessive data retrieval
            if (!preg_match('/\bLIMIT\b/i', $query)) {
                $query = rtrim(rtrim($query), ';') . ' LIMIT 50';
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
        $primaryTable = $this->allowedTables[0] ?? 'submissions';
        $tablesList = implode(', ', $this->allowedTables);

        if ($this->formId) {
            $description = 'Zapytanie SQL (PostgreSQL 16) do wykonania. '
                . "TABELA: {$tablesList}. "
                . "WYMAGANE: WHERE form_id = '{$this->formId}' AND approved_at IS NOT NULL AND deleted_at IS NULL. "
                . "Dane w kolumnie 'data' (JSONB) — użyj ->, ->> do ekstrakcji pól. "
                . 'Max 50 rekordów (LIMIT 50). Paginacja: LIMIT 50 OFFSET N.';
        } else {
            $fieldColumns = $this->formatAvailableColumns();
            $description = 'Zapytanie SQL (PostgreSQL 16) do wykonania. '
                . "DOSTĘPNE TABELE/WIDOKI: {$tablesList}. "
                . "KOLUMNY ({$primaryTable}): submission_id, source, creator_id, creator_name, created_at + {$fieldColumns}. "
                . 'UWAGA: creator_name to tylko twórca-CZŁOWIEK; jest NULL dla wypełnień utworzonych przez automatyzację lub bota. '
                . 'UŻYWAJ STANDARDOWEGO SQL. Max 50 rekordów (LIMIT 50).';
        }

        return [
            'query' => $schema->string()
                ->description($description)
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
