<?php

namespace App\Modules\Forms\Enums;

enum FormElementType: string
{
    // Layout elements
    case SECTION = 'section';
    case GRID = 'grid';
    case REPEATER = 'repeater';

    // Content elements
    case HEADING = 'heading';
    case TEXT_BLOCK = 'text_block';
    case DIVIDER = 'divider';

    // Input fields
    case SHORT_TEXT = 'short_text';
    case LONG_TEXT = 'long_text';
    case SELECT = 'select';
    // A single-file upload. The wire value stays 'image' for backward compatibility with
    // existing form content and the builder palette — historically this was an AI-image
    // placeholder, now it is a real file input (P6). Its JSON schema carries format:'file',
    // which is what the workflow variable catalog keys on to expose it as a FILE variable.
    case IMAGE = 'image';
    case CHECKBOX = 'checkbox';
    case NUMBER = 'number';
    case DATE = 'date';
    case TIME = 'time';
    case URL = 'url';
    case CHECKLIST = 'checklist';

    public function category(): FormElementCategory
    {
        return match ($this) {
            self::SECTION, self::GRID, self::REPEATER => FormElementCategory::LAYOUT,
            self::HEADING, self::TEXT_BLOCK, self::DIVIDER => FormElementCategory::CONTENT,
            default => FormElementCategory::INPUT,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::SECTION => 'Sekcja',
            self::GRID => 'Siatka',
            self::REPEATER => 'Powtórzenia',
            self::HEADING => 'Nagłówek',
            self::TEXT_BLOCK => 'Blok tekstowy',
            self::DIVIDER => 'Separator',
            self::SHORT_TEXT => 'Krótki tekst',
            self::LONG_TEXT => 'Długi tekst',
            self::SELECT => 'Lista wyboru',
            self::IMAGE => 'Plik',
            self::CHECKBOX => 'Checkbox',
            self::NUMBER => 'Liczba',
            self::DATE => 'Data',
            self::TIME => 'Czas',
            self::URL => 'URL',
            self::CHECKLIST => 'Checklista',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::SECTION => 'layout-grid',
            self::GRID => 'columns',
            self::REPEATER => 'repeat',
            self::HEADING => 'heading',
            self::TEXT_BLOCK => 'text',
            self::DIVIDER => 'minus',
            self::SHORT_TEXT => 'type',
            self::LONG_TEXT => 'file-text',
            self::SELECT => 'list',
            self::IMAGE => 'upload',
            self::CHECKBOX => 'check-square',
            self::NUMBER => 'hash',
            self::DATE => 'calendar',
            self::TIME => 'clock',
            self::URL => 'link',
            self::CHECKLIST => 'list-check',
        };
    }

    public function isLayoutElement(): bool
    {
        return $this->category() === FormElementCategory::LAYOUT;
    }

    public function isContentElement(): bool
    {
        return $this->category() === FormElementCategory::CONTENT;
    }

    public function isInputElement(): bool
    {
        return $this->category() === FormElementCategory::INPUT;
    }

    public function canHaveChildren(): bool
    {
        return in_array($this, [self::SECTION, self::REPEATER]);
    }

    /**
     * Check if this element type should be normalized (used as JSON key)
     */
    public function shouldBeNormalized(): bool
    {
        return $this->isInputElement()
            || $this === self::SECTION
            || $this === self::REPEATER;
    }

    /**
     * Get the config key for normalization
     */
    public function getNormalizationKey(array $config): string
    {
        return match ($this) {
            self::SECTION, self::REPEATER => $config['name'] ?? 'field',
            default => $config['label'] ?? $config['name'] ?? 'field'
        };
    }

    /**
     * Convert element configuration to JsonSchema type
     */
    public function toJsonSchema(array $config): ?\Illuminate\JsonSchema\Types\Type
    {
        if (!$this->isInputElement()) {
            return null;
        }

        $schema = match ($this) {
            self::SHORT_TEXT, self::LONG_TEXT, self::URL => self::createStringSchema($config),
            self::NUMBER => self::createNumberSchema($config),
            self::CHECKBOX => self::createBooleanSchema($config),
            self::SELECT => self::createSelectSchema($config),
            self::CHECKLIST => self::createChecklistSchema($config),
            self::DATE => self::createDateSchema($config),
            self::TIME => self::createTimeSchema($config),
            self::IMAGE => self::createFileSchema($config),
            default => \Illuminate\JsonSchema\JsonSchema::string()
        };

        // Apply required constraint
        if ($schema && ($config['required'] ?? false)) {
            $schema->required();
        }

        return $schema;
    }

    /**
     * Normalize elements array
     */
    public static function normalizeElements(array $elements): array
    {
        $usedKeys = [];

        return self::normalizeElementsRecursive($elements, $usedKeys);
    }

    /**
     * Build JsonSchema properties from elements
     */
    public static function buildJsonSchema(array $elements): array
    {
        $properties = [];

        foreach ($elements as $element) {
            $type = self::tryFrom($element['type'] ?? '');

            if (!$type) {
                continue;
            }

            // Handle INPUT elements
            if ($type->isInputElement()) {
                $fieldId = $element['id'] ?? null;
                if ($fieldId) {
                    $schema = $type->toJsonSchema($element['config'] ?? []);
                    if ($schema) {
                        $properties[$fieldId] = $schema;
                    }
                }
            }

            // Handle SECTION - nested object
            if ($type === self::SECTION && isset($element['config']['children'])) {
                $sectionId = $element['id'] ?? null;
                if ($sectionId) {
                    $childProperties = self::buildJsonSchema($element['config']['children']);
                    if (!empty($childProperties)) {
                        $properties[$sectionId] = \Illuminate\JsonSchema\JsonSchema::object($childProperties);
                    }
                }
            }

            // Handle REPEATER - array of objects
            if ($type === self::REPEATER && isset($element['config']['children'])) {
                $repeaterId = $element['id'] ?? null;
                if ($repeaterId) {
                    $childProperties = self::buildJsonSchema($element['config']['children']);
                    if (!empty($childProperties)) {
                        $itemSchema = \Illuminate\JsonSchema\JsonSchema::object($childProperties);
                        $arraySchema = \Illuminate\JsonSchema\JsonSchema::array()->items($itemSchema);

                        // Add min/max from config
                        $config = $element['config'];
                        if (isset($config['min'])) {
                            $arraySchema->min($config['min']);
                        }
                        if (isset($config['max'])) {
                            $arraySchema->max($config['max']);
                        }

                        $properties[$repeaterId] = $arraySchema;
                    }
                }
            }

            // Handle GRID - flatten all columns to same level
            if ($type === self::GRID && isset($element['config']['columns'])) {
                foreach ($element['config']['columns'] as $column) {
                    if (isset($column['element']) && is_array($column['element'])) {
                        $columnProps = self::buildJsonSchema([$column['element']]);
                        $properties = array_merge($properties, $columnProps);
                    }
                }
            }
        }

        return $properties;
    }

    /**
     * Collect every FILE-field answer out of a submission's data, walking the content tree the
     * same way {@see buildJsonSchema} shapes the payload: top-level fields sit at the root,
     * sections nest under their id, grids flatten to the parent level, and repeaters repeat per
     * item. Returns a flat list of {field, value} so a validator or the submission service can
     * act on each file answer without re-deriving the structure.
     *
     * @param  array<int, array<string, mixed>>  $elements
     * @param  array<string, mixed>  $data
     * @return array<int, array{field: string, value: mixed}>
     */
    public static function collectFileAnswers(array $elements, array $data): array
    {
        $answers = [];

        foreach ($elements as $element) {
            $type = self::tryFrom($element['type'] ?? '');
            $id = $element['id'] ?? null;

            if (!$type) {
                continue;
            }

            if ($type === self::IMAGE && $id !== null && array_key_exists($id, $data)) {
                $answers[] = ['field' => $id, 'value' => $data[$id]];

                continue;
            }

            // Section: data nests under the section id.
            if ($type === self::SECTION && isset($element['config']['children']) && is_array($element['config']['children'])) {
                $nested = ($id !== null && is_array($data[$id] ?? null)) ? $data[$id] : [];
                $answers = array_merge($answers, self::collectFileAnswers($element['config']['children'], $nested));
            }

            // Repeater: data is an array of item objects.
            if ($type === self::REPEATER && isset($element['config']['children']) && is_array($element['config']['children'])) {
                $items = ($id !== null && is_array($data[$id] ?? null)) ? $data[$id] : [];
                foreach ($items as $item) {
                    $answers = array_merge(
                        $answers,
                        self::collectFileAnswers($element['config']['children'], is_array($item) ? $item : [])
                    );
                }
            }

            // Grid: columns flatten to the SAME data level as the grid itself.
            if ($type === self::GRID && isset($element['config']['columns']) && is_array($element['config']['columns'])) {
                foreach ($element['config']['columns'] as $column) {
                    if (isset($column['element']) && is_array($column['element'])) {
                        $answers = array_merge($answers, self::collectFileAnswers([$column['element']], $data));
                    }
                }
            }
        }

        return $answers;
    }

    /**
     * Recursively normalize elements array (private helper)
     */
    private static function normalizeElementsRecursive(array $elements, array &$usedKeys): array
    {
        $normalized = [];

        foreach ($elements as $element) {
            $normalized[] = self::normalizeElement($element, $usedKeys);
        }

        return $normalized;
    }

    /**
     * Normalize a single element
     */
    private static function normalizeElement(array $element, array &$usedKeys): array
    {
        $type = self::tryFrom($element['type'] ?? '');

        // Normalize elements used as JSON keys
        if ($type && $type->shouldBeNormalized()) {
            $label = $type->getNormalizationKey($element['config'] ?? []);
            $normalizedId = self::generateFieldKey($label, $usedKeys);
            $element['id'] = $normalizedId;
        }

        // Recursively normalize children in sections and repeaters
        if (isset($element['config']['children']) && is_array($element['config']['children'])) {
            $element['config']['children'] = self::normalizeElementsRecursive(
                $element['config']['children'],
                $usedKeys
            );
        }

        // Recursively normalize grid columns
        if (isset($element['config']['columns']) && is_array($element['config']['columns'])) {
            foreach ($element['config']['columns'] as $colIndex => $column) {
                if (isset($column['element']) && is_array($column['element'])) {
                    $element['config']['columns'][$colIndex]['element'] = self::normalizeElement(
                        $column['element'],
                        $usedKeys
                    );
                }
            }
        }

        return $element;
    }

    /**
     * Generate a snake_case field key from label
     */
    private static function generateFieldKey(string $label, array &$usedKeys): string
    {
        // Convert Polish characters to ASCII
        $transliteration = [
            'ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n',
            'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z',
            'Ą' => 'A', 'Ć' => 'C', 'Ę' => 'E', 'Ł' => 'L', 'Ń' => 'N',
            'Ó' => 'O', 'Ś' => 'S', 'Ź' => 'Z', 'Ż' => 'Z',
        ];

        $label = str_replace(array_keys($transliteration), array_values($transliteration), $label);

        // Convert to snake_case
        $key = \Illuminate\Support\Str::snake($label);

        // Remove any non-alphanumeric characters except underscores
        $key = preg_replace('/[^a-z0-9_]/', '', $key);

        // Ensure it doesn't start with a number
        if (preg_match('/^[0-9]/', $key)) {
            $key = 'field_' . $key;
        }

        // Handle empty key
        if (empty($key)) {
            $key = 'field';
        }

        // Handle duplicates
        $originalKey = $key;
        $counter = 2;

        while (in_array($key, $usedKeys)) {
            $key = $originalKey . '_' . $counter;
            $counter++;
        }

        $usedKeys[] = $key;

        return $key;
    }

    private static function createStringSchema(array $config): \Illuminate\JsonSchema\Types\StringType
    {
        $schema = \Illuminate\JsonSchema\JsonSchema::string();

        if (isset($config['minLength'])) {
            $schema->min($config['minLength']);
        }

        if (isset($config['maxLength'])) {
            $schema->max($config['maxLength']);
        }

        if (isset($config['hint']) && !empty($config['hint'])) {
            $schema->description($config['hint']);
        }

        return $schema;
    }

    private static function createNumberSchema(array $config): \Illuminate\JsonSchema\Types\Type
    {
        $step = $config['step'] ?? 1;
        $schema = ($step == 1) ? \Illuminate\JsonSchema\JsonSchema::integer() : \Illuminate\JsonSchema\JsonSchema::number();

        if (isset($config['min'])) {
            $schema->min($config['min']);
        }

        if (isset($config['max'])) {
            $schema->max($config['max']);
        }

        if (isset($config['hint']) && !empty($config['hint'])) {
            $schema->description($config['hint']);
        }

        return $schema;
    }

    private static function createSelectSchema(array $config): \Illuminate\JsonSchema\Types\Type
    {
        $isMultiple = $config['multiple'] ?? false;
        $options = $config['options'] ?? [];

        if ($isMultiple) {
            $itemSchema = \Illuminate\JsonSchema\JsonSchema::string();

            if (!empty($options)) {
                $values = array_map(fn ($opt) => $opt['value'] ?? $opt, $options);
                $itemSchema->enum($values);
            }

            $schema = \Illuminate\JsonSchema\JsonSchema::array()->items($itemSchema);

            if (isset($config['hint']) && !empty($config['hint'])) {
                $schema->description($config['hint']);
            }

            return $schema;
        } else {
            $schema = \Illuminate\JsonSchema\JsonSchema::string();

            if (!empty($options)) {
                $values = array_map(fn ($opt) => $opt['value'] ?? $opt, $options);
                $schema->enum($values);
            }

            if (isset($config['hint']) && !empty($config['hint'])) {
                $schema->description($config['hint']);
            }

            return $schema;
        }
    }

    private static function createChecklistSchema(array $config): \Illuminate\JsonSchema\Types\ArrayType
    {
        $options = $config['options'] ?? [];
        $itemSchema = \Illuminate\JsonSchema\JsonSchema::string();

        if (!empty($options)) {
            $values = array_map(fn ($opt) => $opt['value'] ?? $opt, $options);
            $itemSchema->enum($values);
        }

        $schema = \Illuminate\JsonSchema\JsonSchema::array()->items($itemSchema);

        if (isset($config['hint']) && !empty($config['hint'])) {
            $schema->description($config['hint']);
        }

        return $schema;
    }

    private static function createBooleanSchema(array $config): \Illuminate\JsonSchema\Types\BooleanType
    {
        $schema = \Illuminate\JsonSchema\JsonSchema::boolean();

        if (isset($config['hint']) && !empty($config['hint'])) {
            $schema->description($config['hint']);
        }

        return $schema;
    }

    private static function createDateSchema(array $config): \Illuminate\JsonSchema\Types\StringType
    {
        $schema = \Illuminate\JsonSchema\JsonSchema::string()->format('date');

        if (isset($config['hint']) && !empty($config['hint'])) {
            $schema->description($config['hint']);
        }

        return $schema;
    }

    private static function createTimeSchema(array $config): \Illuminate\JsonSchema\Types\StringType
    {
        $schema = \Illuminate\JsonSchema\JsonSchema::string()->format('time');

        if (isset($config['hint']) && !empty($config['hint'])) {
            $schema->description($config['hint']);
        }

        return $schema;
    }

    /**
     * A single uploaded file. The answer is a Disk File uuid; format:'file' is the wire
     * signal the workflow variable catalog maps to a FILE variable (and the FE renders as an
     * upload widget). Kept a string schema so an unanswered field is simply absent.
     */
    private static function createFileSchema(array $config): \Illuminate\JsonSchema\Types\StringType
    {
        $schema = \Illuminate\JsonSchema\JsonSchema::string()->format('file');

        if (isset($config['hint']) && !empty($config['hint'])) {
            $schema->description($config['hint']);
        }

        return $schema;
    }
}
