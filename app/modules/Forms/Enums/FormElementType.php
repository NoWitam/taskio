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
    case IMAGE = 'image';
    case CHECKBOX = 'checkbox';
    case NUMBER = 'number';
    case DATE = 'date';
    case TIME = 'time';
    case URL = 'url';
    case CHECKLIST = 'checklist';

    public function category(): FormElementCategory
    {
        return match($this) {
            self::SECTION, self::GRID, self::REPEATER => FormElementCategory::LAYOUT,
            self::HEADING, self::TEXT_BLOCK, self::DIVIDER => FormElementCategory::CONTENT,
            default => FormElementCategory::INPUT,
        };
    }

    public function label(): string
    {
        return match($this) {
            self::SECTION => 'Sekcja',
            self::GRID => 'Siatka',
            self::REPEATER => 'Powtórzenia',
            self::HEADING => 'Nagłówek',
            self::TEXT_BLOCK => 'Blok tekstowy',
            self::DIVIDER => 'Separator',
            self::SHORT_TEXT => 'Krótki tekst',
            self::LONG_TEXT => 'Długi tekst',
            self::SELECT => 'Lista wyboru',
            self::IMAGE => 'Obraz',
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
        return match($this) {
            self::SECTION => 'layout-grid',
            self::GRID => 'columns',
            self::REPEATER => 'repeat',
            self::HEADING => 'heading',
            self::TEXT_BLOCK => 'text',
            self::DIVIDER => 'minus',
            self::SHORT_TEXT => 'type',
            self::LONG_TEXT => 'file-text',
            self::SELECT => 'list',
            self::IMAGE => 'image',
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
}
