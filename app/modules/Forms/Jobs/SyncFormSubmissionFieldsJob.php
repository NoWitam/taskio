<?php

namespace App\Modules\Forms\Jobs;

use App\Modules\Forms\Models\FormSubmission;
use App\Modules\Forms\Models\FormSubmissionField;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;

class SyncFormSubmissionFieldsJob implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(
        private FormSubmission $submission
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        // Delete old fields
        $this->submission->fields()->delete();

        // Flatten the data and prepare records
        $records = $this->flattenData($this->submission->data);

        // Batch insert all records
        if (!empty($records)) {
            FormSubmissionField::insert($records);
        }
    }

    /**
     * Recursively flatten nested data structure.
     * 
     * @param array $data The data to flatten
     * @param string $prefix The current path prefix
     * @return array Array of records ready for insertion
     */
    private function flattenData(array $data, string $prefix = ''): array
    {
        $records = [];

        foreach ($data as $key => $value) {
            // Build the path for this field
            $path = $prefix ? "{$prefix}.{$key}" : $key;

            // If value is an array and not empty, recursively flatten
            if (is_array($value) && !empty($value)) {
                // Check if it's a numeric array (indexed array)
                if (array_keys($value) === range(0, count($value) - 1)) {
                    // It's an indexed array (e.g., repeater)
                    foreach ($value as $index => $item) {
                        if (is_array($item)) {
                            // Nested object in array
                            $records = array_merge(
                                $records,
                                $this->flattenData($item, "{$path}[{$index}]")
                            );
                        } else {
                            // Scalar value in array
                            $records[] = $this->createRecord($this->submission->id, "{$path}[{$index}]", (string)$index, $item);
                        }
                    }
                } else {
                    // It's an associative array (object), recurse
                    $records = array_merge(
                        $records,
                        $this->flattenData($value, $path)
                    );
                }
            } else {
                // Scalar value or empty array - create a record
                $records[] = $this->createRecord($this->submission->id, $path, $this->extractKey($key), $value);
            }
        }

        return $records;
    }

    /**
     * Create a single field record.
     */
    private function createRecord(string $submissionId, string $path, string $key, mixed $value): array
    {
        $type = $this->detectType($value);
        
        return [
            'id' => Str::uuid()->toString(),
            'form_submission_id' => $submissionId,
            'field_path' => $path,
            'field_key' => $key,
            'field_value' => $this->toString($value),
            'field_value_numeric' => $type === 'number' ? $this->toNumeric($value) : null,
            'field_value_boolean' => $type === 'boolean' ? $this->toBoolean($value) : null,
            'field_value_date' => $type === 'date' ? $this->toDate($value) : null,
            'field_type' => $type,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    /**
     * Extract the key name from a path segment.
     */
    private function extractKey(string $segment): string
    {
        // Remove array indices: 'items[0]' -> 'items'
        return preg_replace('/\[\d+\]$/', '', $segment);
    }

    /**
     * Detect the type of a value.
     */
    private function detectType(mixed $value): string
    {
        if (is_null($value)) {
            return 'string';
        }

        if (is_bool($value)) {
            return 'boolean';
        }

        if (is_numeric($value)) {
            return 'number';
        }

        // Try to detect date strings
        if (is_string($value) && $this->isDateString($value)) {
            return 'date';
        }

        return 'string';
    }

    /**
     * Check if a string looks like a date.
     */
    private function isDateString(string $value): bool
    {
        // Common date patterns
        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $value)) {
            return true;
        }

        // Try to parse as date
        try {
            $date = new \DateTime($value);
            // Check if it's a valid date and not just a number
            return $date && strlen($value) >= 8;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Convert value to string.
     */
    private function toString(mixed $value): ?string
    {
        if (is_null($value)) {
            return null;
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_array($value)) {
            return json_encode($value);
        }

        return (string)$value;
    }

    /**
     * Convert value to numeric.
     */
    private function toNumeric(mixed $value): ?float
    {
        if (is_null($value)) {
            return null;
        }

        if (is_numeric($value)) {
            return (float)$value;
        }

        return null;
    }

    /**
     * Convert value to boolean.
     */
    private function toBoolean(mixed $value): ?bool
    {
        if (is_null($value)) {
            return null;
        }

        if (is_bool($value)) {
            return $value;
        }

        // Handle string boolean values
        if (is_string($value)) {
            $lower = strtolower($value);
            if (in_array($lower, ['true', '1', 'yes', 'on'])) {
                return true;
            }
            if (in_array($lower, ['false', '0', 'no', 'off'])) {
                return false;
            }
        }

        return null;
    }

    /**
     * Convert value to date.
     */
    private function toDate(mixed $value): ?string
    {
        if (is_null($value) || !is_string($value)) {
            return null;
        }

        try {
            $date = new \DateTime($value);
            return $date->format('Y-m-d H:i:s');
        } catch (\Exception $e) {
            return null;
        }
    }
}
