<?php

namespace App\Modules\Approvals\Enums;

enum ApprovalProcessStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Oczekuje',
            self::Approved => 'Zatwierdzono',
            self::Rejected => 'Odrzucono',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Approved => 'success',
            self::Rejected => 'danger',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Pending => 'clock',
            self::Approved => 'check-circle',
            self::Rejected => 'x-circle',
        };
    }
}
