<?php

namespace App\Modules\Changelog\Enums;

enum ChangelogEvent: string
{
    case CREATED = 'created';
    case UPDATED = 'updated';
    case DELETED = 'deleted';
    case RESTORED = 'restored';
    case ARCHIVED = 'archived';
    case UNARCHIVED = 'unarchived';
    case STATUS_CHANGED = 'status_changed';

    public function getDescription(): string
    {
        return match($this) {
            self::CREATED => 'Utworzył',
            self::UPDATED => 'Edytował',
            self::DELETED => 'Usunął',
            self::RESTORED => 'Przywrócił',
            self::ARCHIVED => 'Zarchiwizował',
            self::UNARCHIVED => 'Od-archiwizował',
            self::STATUS_CHANGED => 'Zmienił status',
        };
    }
}
