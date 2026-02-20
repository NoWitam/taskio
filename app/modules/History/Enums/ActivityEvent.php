<?php

namespace App\Modules\History\Enums;

enum ActivityEvent: string
{
    case CREATED = 'created';
    case UPDATED = 'updated';
    case DELETED = 'deleted';
    case RESTORED = 'restored';
    case ARCHIVED = 'archived';
    case UNARCHIVED = 'unarchived';

    public function getDescription(): string
    {
        return match($this) {
            self::CREATED => 'Utworzył',
            self::UPDATED => 'Edytował',
            self::DELETED => 'Usunął',
            self::RESTORED => 'Przywrócił',
            self::ARCHIVED => 'Zarchiwizował',
            self::UNARCHIVED => 'Od-archiwizował',
        };
    }
}
