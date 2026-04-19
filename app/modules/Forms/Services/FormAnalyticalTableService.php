<?php

namespace App\Modules\Forms\Services;

use App\Modules\Forms\Models\Form;
use App\Modules\Forms\Models\FormContentVersion;
use App\Modules\Forms\Models\FormSubmission;
use App\Modules\Forms\Traits\InteractsWithFormSchema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class FormAnalyticalTableService
{
    use InteractsWithFormSchema;

    /**
     * Get the deterministic analytical table name for a form.
     * Uses the form UUID with hyphens replaced by underscores.
     */
    public function getTableName(Form $form): string
    {
        return 'form_analytical_' . str_replace('-', '_', $form->id);
    }

    /**
     * Check if the analytical table exists for a form.
     */
    public function tableExists(Form $form): bool
    {
        return Schema::hasTable($this->getTableName($form));
    }

    /**
     * Create the dedicated analytical table for an indexed form.
     * The table has system columns plus one column per form field,
     * derived from the form's JSON Schema.
     * Repeater fields get JSONB columns.
     */
    public function createTable(Form $form): void
    {
        $tableName = $this->getTableName($form);

        // Safety: drop if already exists (e.g. from interrupted previous indexing)
        if (Schema::hasTable($tableName)) {
            Schema::drop($tableName);
        }

        $fieldPaths = $this->extractFieldPaths($form->getJsonSchema());
        $fieldColumnsSql = $this->buildTableColumns($fieldPaths);

        $sql = "
            CREATE TABLE {$tableName} (
                submission_id UUID NOT NULL,
                source TEXT,
                creator_id UUID,
                creator_name TEXT,
                created_at TIMESTAMP,
                form_content_version_id UUID
                {$fieldColumnsSql},
                PRIMARY KEY (submission_id)
            )
        ";

        DB::statement($sql);
    }

    /**
     * Drop the analytical table for a form.
     */
    public function dropTable(Form $form): void
    {
        $tableName = $this->getTableName($form);

        if (Schema::hasTable($tableName)) {
            Schema::drop($tableName);
        }
    }

    /**
    /**
     * Index a single submission into the analytical table.
     * Returns true if the submission was indexed, false otherwise.
     */
    public function indexSubmission(Form $form, FormSubmission $submission): bool
    {
        if (!$this->tableExists($form)) {
            return false;
        }

        if (!$form->isSubmissionCompatible($submission)) {
            return false;
        }

        $tableName = $this->getTableName($form);
        $fieldPaths = $this->extractFieldPaths($form->getJsonSchema());
        $jsonColumns = $this->buildJsonSelectColumns($fieldPaths);

        $selectColumns = "
            fs.id as submission_id,
            fs.submittable_type as source,
            fs.creator_id,
            u.name as creator_name,
            fs.approved_at as created_at,
            fs.form_content_version_id
        ";

        $insertColumns = "submission_id, source, creator_id, creator_name, created_at, form_content_version_id";

        if (!empty($jsonColumns['select'])) {
            $selectColumns .= ",\n    " . implode(",\n    ", $jsonColumns['select']);
            $insertColumns .= ', ' . implode(', ', $jsonColumns['names']);
        }

        $sql = "
            INSERT INTO {$tableName} ({$insertColumns})
            SELECT 
                {$selectColumns}
            FROM 
                form_submissions fs
            LEFT JOIN users u ON u.id = fs.creator_id
            WHERE 
                fs.id = ?
            ON CONFLICT (submission_id) DO NOTHING
        ";

        DB::statement($sql, [$submission->id]);

        $submission->update(['indexed_at' => now()]);

        return true;
    }

    /**
     * Get a snapshot of the analytical table schema for backup purposes.
     * Returns the field paths and content version info used for the table.
     */
    public function getTableSchema(Form $form): array
    {
        $fieldPaths = $this->extractFieldPaths($form->getJsonSchema());
        $latestVersion = $form->latestContentVersion();

        return [
            'content_version' => $form->content_version,
            'form_content_version_id' => $latestVersion?->id,
            'field_paths' => collect($fieldPaths)->map(fn($p) => [
                'path' => $p['path'],
                'type' => $p['type'],
            ])->all(),
        ];
    }

    /**
     * Get structured incompatibility information for a form's submissions.
     * Groups incompatible submissions by content version and time period,
     * so the user can understand which submissions will NOT be indexed.
     *
     * Returns an array with:
     * - total_submissions: total approved submissions count
     * - compatible_count: submissions compatible with current version
     * - incompatible_count: submissions incompatible with current version
     * - incompatible_periods: array of periods with incompatible submissions
     *   Each period has: version, submissions_count, period_from, period_to
     */
    public function getCompatibilityInfo(Form $form): array
    {
        $latestVersion = $form->latestContentVersion();

        $totalApproved = FormSubmission::where('form_id', $form->id)
            ->whereNotNull('approved_at')
            ->whereNull('deleted_at')
            ->count();

        if (!$latestVersion) {
            return [
                'total_submissions' => $totalApproved,
                'compatible_count' => 0,
                'incompatible_count' => $totalApproved,
                'incompatible_periods' => [],
            ];
        }

        $compatibleCount = FormSubmission::where('form_id', $form->id)
            ->whereNotNull('approved_at')
            ->whereNull('deleted_at')
            ->where('form_content_version_id', $latestVersion->id)
            ->count();

        $incompatibleCount = $totalApproved - $compatibleCount;

        // Group incompatible submissions by content version with time ranges
        $incompatiblePeriods = [];
        if ($incompatibleCount > 0) {
            $periods = DB::table('form_submissions as fs')
                ->leftJoin('form_content_versions as fcv', 'fs.form_content_version_id', '=', 'fcv.id')
                ->where('fs.form_id', $form->id)
                ->whereNotNull('fs.approved_at')
                ->whereNull('fs.deleted_at')
                ->where(function ($query) use ($latestVersion) {
                    $query->where('fs.form_content_version_id', '!=', $latestVersion->id)
                        ->orWhereNull('fs.form_content_version_id');
                })
                ->selectRaw("
                    COALESCE(fcv.version, 0) as version,
                    COUNT(*) as submissions_count,
                    MIN(fs.approved_at) as period_from,
                    MAX(fs.approved_at) as period_to
                ")
                ->groupByRaw('COALESCE(fcv.version, 0)')
                ->orderBy('period_from')
                ->get();

            foreach ($periods as $period) {
                $incompatiblePeriods[] = [
                    'version' => (int) $period->version,
                    'submissions_count' => (int) $period->submissions_count,
                    'period_from' => $period->period_from,
                    'period_to' => $period->period_to,
                ];
            }
        }

        return [
            'total_submissions' => $totalApproved,
            'compatible_count' => $compatibleCount,
            'incompatible_count' => $incompatibleCount,
            'incompatible_periods' => $incompatiblePeriods,
        ];
    }

    /**
     * Build CREATE TABLE column definitions from field paths.
     * Returns a SQL fragment for field columns (with leading comma if non-empty).
     * Repeater fields become JSONB columns.
     */
    private function buildTableColumns(array $fieldPaths): string
    {
        $columns = [];

        foreach ($fieldPaths as $pathInfo) {
            $columnName = $this->sanitizeColumnName($pathInfo['path']);

            if ($pathInfo['type'] === 'repeater') {
                $columns[] = "{$columnName} JSONB";
                continue;
            }

            $type = $this->detectFieldType($pathInfo['type']);

            $sqlType = match($type) {
                'number' => 'DECIMAL(20,6)',
                'boolean' => 'BOOLEAN',
                'date' => 'TIMESTAMP',
                default => 'TEXT'
            };

            $columns[] = "{$columnName} {$sqlType}";
        }

        if (empty($columns)) {
            return '';
        }

        return ",\n                " . implode(",\n                ", $columns);
    }

    /**
     * Build JSONB extraction SELECT expressions for each field path.
     * Uses PostgreSQL JSONB operators to extract data from form_submissions.data.
     *
     * Returns ['select' => [...sql expressions...], 'names' => [...column names...]]
     */
    private function buildJsonSelectColumns(array $fieldPaths): array
    {
        $select = [];
        $names = [];

        foreach ($fieldPaths as $pathInfo) {
            $path = $pathInfo['path'];
            $columnName = $this->sanitizeColumnName($path);
            $names[] = $columnName;

            if ($pathInfo['type'] === 'repeater') {
                $jsonPath = $this->buildJsonbExpression($path, asText: false);
                $select[] = "{$jsonPath} as {$columnName}";
                continue;
            }

            $type = $this->detectFieldType($pathInfo['type']);
            $jsonPath = $this->buildJsonbExpression($path, asText: true);

            $castExpr = match($type) {
                'number' => "NULLIF({$jsonPath}, '')::DECIMAL(20,6)",
                'boolean' => "NULLIF({$jsonPath}, '')::BOOLEAN",
                'date' => "NULLIF({$jsonPath}, '')::TIMESTAMP",
                default => $jsonPath
            };

            $select[] = "{$castExpr} as {$columnName}";
        }

        return ['select' => $select, 'names' => $names];
    }

    /**
     * Build a PostgreSQL JSONB extraction expression for a dotted field path.
     * 
     * Examples:
     *   "name" → fs.data->>'name' (text) or fs.data->'name' (jsonb)
     *   "section.field" → fs.data->'section'->>'field' (text) or fs.data->'section'->'field' (jsonb)
     *
     * @param string $path Dotted field path
     * @param bool $asText Whether to extract as text (->>') or JSONB (->)
     */
    private function buildJsonbExpression(string $path, bool $asText = true): string
    {
        $parts = explode('.', $path);
        $expr = 'fs.data';

        // Validate each part against SQL injection
        foreach ($parts as $part) {
            $this->assertSafeIdentifier($part);
        }

        // Navigate through all parts except the last with -> operator
        for ($i = 0; $i < count($parts) - 1; $i++) {
            $expr .= "->'" . $parts[$i] . "'";
        }

        // Last part uses ->> for text or -> for jsonb
        $lastPart = $parts[count($parts) - 1];
        $operator = $asText ? "->>'" : "->'";
        $expr .= $operator . $lastPart . "'";

        return $expr;
    }
}
