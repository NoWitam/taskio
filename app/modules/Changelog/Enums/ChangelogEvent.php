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
    case CHANGE_STATUS = 'change_status';
    case ENABLED = 'enabled';
    case FORM_FILLED = 'form_filled';

    public function getDescription(): string
    {
        return match($this) {
            self::CREATED => 'changelog.created',
            self::UPDATED => 'changelog.updated',
            self::DELETED => 'changelog.deleted',
            self::RESTORED => 'changelog.restored',
            self::ARCHIVED => 'changelog.archived',
            self::UNARCHIVED => 'changelog.unarchived',
            self::CHANGE_STATUS => 'changelog.changedStatus',
            self::ENABLED => 'changelog.enabled',
            self::FORM_FILLED => 'changelog.formFilled',
        };
    }
}
