<?php

namespace App\Modules\Forms\Enums;

enum FormElementCategory: string
{
    case LAYOUT = 'layout';
    case CONTENT = 'content';
    case INPUT = 'input';

    public function label(): string
    {
        return match($this) {
            self::LAYOUT => 'Layout',
            self::CONTENT => 'Zawartość',
            self::INPUT => 'Pola formularza',
        };
    }
}
