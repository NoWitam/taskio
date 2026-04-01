<?php

namespace App\Modules\Forms\Rules;

use App\Modules\Forms\Enums\FormElementType;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class ValidFormContent implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (!is_array($value)) {
            $fail('The :attribute must be an array.');
            return;
        }

        $errors = $this->validateElements($value, false);
        
        foreach ($errors as $error) {
            $fail($error);
        }
    }

    /**
     * Validate elements array recursively
     */
    private function validateElements(array $elements, bool $isNested): array
    {
        $errors = [];

        foreach ($elements as $index => $element) {
            // Check basic structure
            if (!isset($element['id']) || !is_string($element['id'])) {
                $errors[] = "Element at index {$index} must have a valid 'id' string.";
                continue;
            }

            if (!isset($element['type']) || !is_string($element['type'])) {
                $errors[] = "Element {$element['id']} must have a valid 'type' string.";
                continue;
            }

            // Validate type
            $type = FormElementType::tryFrom($element['type']);
            if (!$type) {
                $errors[] = "Element {$element['id']} has invalid type '{$element['type']}'.";
                continue;
            }

            if (!isset($element['config']) || !is_array($element['config'])) {
                $errors[] = "Element {$element['id']} must have a 'config' object.";
                continue;
            }

            // Validate based on type
            $elementErrors = match($type) {
                FormElementType::SECTION => $this->validateSection($element, $isNested),
                FormElementType::GRID => $this->validateGrid($element),
                FormElementType::REPEATER => $this->validateRepeater($element),
                FormElementType::HEADING => $this->validateHeading($element),
                FormElementType::TEXT_BLOCK => $this->validateTextBlock($element),
                FormElementType::SHORT_TEXT, 
                FormElementType::LONG_TEXT,
                FormElementType::SELECT,
                FormElementType::CHECKLIST,
                FormElementType::NUMBER,
                FormElementType::DATE,
                FormElementType::TIME,
                FormElementType::URL,
                FormElementType::IMAGE,
                FormElementType::CHECKBOX => $this->validateInputField($element, $type),
                default => [],
            };

            $errors = array_merge($errors, $elementErrors);
        }

        return $errors;
    }

    private function validateSection(array $element, bool $isNested): array
    {
        $errors = [];
        $config = $element['config'];

        // Sections cannot be nested
        if ($isNested) {
            $errors[] = "Element {$element['id']}: Sections cannot be nested inside other sections.";
        }

        // Name is required
        if (!isset($config['name']) || !is_string($config['name']) || trim($config['name']) === '') {
            $errors[] = "Element {$element['id']}: Section must have a 'name'.";
        }

        // Children must be an array
        if (!isset($config['children']) || !is_array($config['children'])) {
            $errors[] = "Element {$element['id']}: Section must have 'children' array.";
        } else {
            // Validate children recursively (isNested = true to prevent nested sections)
            $childErrors = $this->validateElements($config['children'], true);
            $errors = array_merge($errors, $childErrors);
        }

        return $errors;
    }

    private function validateGrid(array $element): array
    {
        $errors = [];
        $config = $element['config'];

        // Columns must be an array
        if (!isset($config['columns']) || !is_array($config['columns'])) {
            $errors[] = "Element {$element['id']}: Grid must have 'columns' array.";
            return $errors;
        }

        $columns = $config['columns'];

        // Max 4 columns
        if (count($columns) > 4) {
            $errors[] = "Element {$element['id']}: Grid can have maximum 4 columns.";
        }

        // Validate each column
        $totalWidth = 0;
        foreach ($columns as $colIndex => $column) {
            if (!isset($column['width']) || !is_numeric($column['width'])) {
                $errors[] = "Element {$element['id']}: Grid column {$colIndex} must have a 'width' number.";
            } else {
                $width = (int) $column['width'];
                if (!in_array($width, [25, 50, 75, 100])) {
                    $errors[] = "Element {$element['id']}: Grid column width must be 25, 50, 75, or 100.";
                }
                $totalWidth += $width;
            }

            // Element is optional (can be null)
            if (isset($column['element']) && $column['element'] !== null) {
                if (!is_array($column['element'])) {
                    $errors[] = "Element {$element['id']}: Grid column {$colIndex} 'element' must be an object or null.";
                    continue;
                }

                // Validate element (must be input type)
                $colElement = $column['element'];
                if (isset($colElement['type'])) {
                    $type = FormElementType::tryFrom($colElement['type']);
                    if ($type && !$type->isInputElement()) {
                        $errors[] = "Element {$element['id']}: Grid columns can only contain input elements.";
                    }
                }

                // Validate the element structure
                $elementErrors = $this->validateElements([$colElement], false);
                $errors = array_merge($errors, $elementErrors);
            }
        }

        // Total width should not exceed 100%
        if ($totalWidth > 100) {
            $errors[] = "Element {$element['id']}: Grid columns total width exceeds 100%.";
        }

        return $errors;
    }

    private function validateRepeater(array $element): array
    {
        $errors = [];
        $config = $element['config'];

        // Name is required
        if (!isset($config['name']) || !is_string($config['name']) || trim($config['name']) === '') {
            $errors[] = "Element {$element['id']}: Repeater must have a 'name'.";
        }

        // Min and max
        if (!isset($config['min']) || !is_numeric($config['min'])) {
            $errors[] = "Element {$element['id']}: Repeater must have a 'min' number.";
        }

        if (!isset($config['max']) || !is_numeric($config['max'])) {
            $errors[] = "Element {$element['id']}: Repeater must have a 'max' number.";
        }

        if (isset($config['min']) && isset($config['max'])) {
            $min = (int) $config['min'];
            $max = (int) $config['max'];
            
            if ($min < 0) {
                $errors[] = "Element {$element['id']}: Repeater 'min' must be at least 0.";
            }

            if ($max < 1) {
                $errors[] = "Element {$element['id']}: Repeater 'max' must be at least 1.";
            }

            if ($min > $max) {
                $errors[] = "Element {$element['id']}: Repeater 'min' cannot exceed 'max'.";
            }
        }

        // Children must be an array
        if (!isset($config['children']) || !is_array($config['children'])) {
            $errors[] = "Element {$element['id']}: Repeater must have 'children' array.";
        } else {
            // Validate children recursively
            $childErrors = $this->validateElements($config['children'], false);
            $errors = array_merge($errors, $childErrors);
        }

        return $errors;
    }

    private function validateHeading(array $element): array
    {
        $errors = [];
        $config = $element['config'];

        if (!isset($config['level']) || !in_array($config['level'], [1, 2, 3])) {
            $errors[] = "Element {$element['id']}: Heading must have 'level' of 1, 2, or 3.";
        }

        if (!isset($config['text']) || !is_string($config['text'])) {
            $errors[] = "Element {$element['id']}: Heading must have 'text' string.";
        }

        return $errors;
    }

    private function validateTextBlock(array $element): array
    {
        $errors = [];
        $config = $element['config'];

        if (!isset($config['content']) || !is_string($config['content'])) {
            $errors[] = "Element {$element['id']}: Text block must have 'content' string.";
        }

        return $errors;
    }

    private function validateInputField(array $element, FormElementType $type): array
    {
        $errors = [];
        $config = $element['config'];

        // All input fields need a label (except checkbox which can have it in different place)
        if ($type !== FormElementType::CHECKBOX) {
            if (!isset($config['label']) || !is_string($config['label']) || trim($config['label']) === '') {
                $errors[] = "Element {$element['id']}: Input field must have a 'label'.";
            }
        }

        // Type-specific validation
        if ($type === FormElementType::SELECT || $type === FormElementType::CHECKLIST) {
            if (!isset($config['options']) || !is_array($config['options'])) {
                $errors[] = "Element {$element['id']}: Select/Checklist must have 'options' array.";
            } else {
                foreach ($config['options'] as $optIndex => $option) {
                    if (!is_array($option) || !isset($option['value']) || !isset($option['label'])) {
                        $errors[] = "Element {$element['id']}: Option at index {$optIndex} must have 'value' and 'label'.";
                    }
                }
            }
        }

        if ($type === FormElementType::NUMBER) {
            if (isset($config['min']) && !is_numeric($config['min'])) {
                $errors[] = "Element {$element['id']}: Number 'min' must be numeric.";
            }
            if (isset($config['max']) && !is_numeric($config['max'])) {
                $errors[] = "Element {$element['id']}: Number 'max' must be numeric.";
            }
        }

        return $errors;
    }
}
