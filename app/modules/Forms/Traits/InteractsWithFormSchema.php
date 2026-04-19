<?php

namespace App\Modules\Forms\Traits;

/**
 * Shared logic for extracting field paths and building SQL columns
 * from a form's JSON Schema.
 * 
 * Used by CreateFormReport (AI prompt building) and 
 * FormAnalyticalTableService (dedicated per-form tables).
 */
trait InteractsWithFormSchema
{
    /**
     * Extract all field paths from form schema recursively.
     * Supports JSON Schema format (properties/type structure).
     * Distinguishes repeaters (array of objects → JSONB) from simple arrays (multi-select → TEXT).
     */
    protected function extractFieldPaths(array $schema, string $prefix = ''): array
    {
        $paths = [];

        // JSON Schema format: check for 'properties' key
        if (isset($schema['properties'])) {
            foreach ($schema['properties'] as $fieldName => $fieldSchema) {
                $path = $prefix ? "{$prefix}.{$fieldName}" : $fieldName;
                
                // Get type from JSON Schema
                $type = $fieldSchema['type'] ?? 'string';
                
                // If it's an object (section), recurse into its properties
                if ($type === 'object' && isset($fieldSchema['properties'])) {
                    $paths = array_merge(
                        $paths,
                        $this->extractFieldPaths($fieldSchema, $path)
                    );
                } elseif ($type === 'array') {
                    // Distinguish repeaters (array of objects) from simple arrays (multi-select/checklist)
                    $itemsType = $fieldSchema['items']['type'] ?? 'string';

                    if ($itemsType === 'object') {
                        // Repeater: array of objects → JSONB column
                        $paths[] = [
                            'path' => $path,
                            'type' => 'repeater',
                            'field' => array_merge(['id' => $fieldName, 'label' => $fieldName], $fieldSchema)
                        ];
                    } else {
                        // Simple array (multi-select, checklist) → TEXT column
                        $paths[] = [
                            'path' => $path,
                            'type' => 'string',
                            'field' => array_merge(['id' => $fieldName, 'label' => $fieldName], $fieldSchema)
                        ];
                    }
                } else {
                    // Regular field - map JSON Schema type to our internal type
                    $internalType = $this->mapJsonSchemaType($type);
                    
                    $paths[] = [
                        'path' => $path,
                        'type' => $internalType,
                        'field' => array_merge(['id' => $fieldName, 'label' => $fieldName], $fieldSchema)
                    ];
                }
            }
        }

        return $paths;
    }

    /**
     * Map JSON Schema type to internal type.
     */
    protected function mapJsonSchemaType(string $jsonSchemaType): string
    {
        return match($jsonSchemaType) {
            'integer', 'number' => 'number',
            'boolean' => 'boolean',
            'string' => 'string',
            default => 'string'
        };
    }

    /**
     * Detect field type from schema.
     */
    protected function detectFieldType(string $schemaType): string
    {
        return match($schemaType) {
            'number', 'rating', 'integer' => 'number',
            'checkbox', 'toggle', 'boolean' => 'boolean',
            'date', 'datetime' => 'date',
            default => 'string'
        };
    }

    /**
     * Sanitize a field path into a safe SQL column name.
     * Replaces dots and brackets with underscores, then validates against whitelist.
     */
    protected function sanitizeColumnName(string $path): string
    {
        $columnName = str_replace(['.', '[', ']'], '_', $path);
        $columnName = preg_replace('/_+/', '_', $columnName); // Remove duplicate underscores
        $columnName = trim($columnName, '_');

        // PostgreSQL identifier limit is 63 bytes — truncate if needed
        if (strlen($columnName) > 63) {
            $columnName = substr($columnName, 0, 63);
            $columnName = rtrim($columnName, '_');
        }

        $this->assertSafeIdentifier($columnName);

        return $columnName;
    }

    /**
     * Assert that a string is a safe SQL identifier (column name, table part, JSON key).
     * Only allows alphanumeric characters and underscores to prevent SQL injection
     * in raw SQL expressions built from user-defined field names.
     *
     * @throws \InvalidArgumentException if the identifier contains unsafe characters
     */
    protected function assertSafeIdentifier(string $identifier): void
    {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]{0,62}$/', $identifier)) {
            throw new \InvalidArgumentException(
                "Unsafe SQL identifier detected: '{$identifier}'. Only alphanumeric characters and underscores are allowed."
            );
        }
    }

    /**
     * Format field columns for AI prompt documentation.
     */
    protected function formatFieldColumns(array $fieldPaths): string
    {
        $lines = [];

        foreach ($fieldPaths as $pathInfo) {
            $path = $pathInfo['path'];
            $columnName = $this->sanitizeColumnName($path);

            if ($pathInfo['type'] === 'repeater') {
                $label = $pathInfo['field']['label'] ?? $path;
                $lines[] = "- {$columnName} (JSONB) - {$label} (array of objects)";
                continue;
            }

            $type = $this->detectFieldType($pathInfo['type']);

            $sqlType = match($type) {
                'number' => 'DECIMAL',
                'boolean' => 'BOOLEAN',
                'date' => 'TIMESTAMP',
                default => 'TEXT'
            };

            $label = $pathInfo['field']['label'] ?? $path;
            $lines[] = "- {$columnName} ({$sqlType}) - {$label}";
        }

        return implode("\n", $lines);
    }
}
