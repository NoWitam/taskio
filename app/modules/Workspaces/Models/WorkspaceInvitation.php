<?php

namespace App\Modules\Workspaces\Models;

use App\Models\AbstractModel;
use App\Models\User;
use App\Modules\Workspaces\Enums\WorkspaceInvitationStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkspaceInvitation extends AbstractModel
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'workspace_id',
        'email',
        'token_hash',
        'invited_by',
        'status',
        'expires_at',
        'accepted_at',
    ];

    /**
     * Never serialize the token hash; the plaintext is only ever in the email.
     *
     * @var list<string>
     */
    protected $hidden = [
        'token_hash',
    ];

    protected function casts(): array
    {
        return [
            'status' => WorkspaceInvitationStatus::class,
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    public function scopePending(Builder $query): void
    {
        $query->where('status', WorkspaceInvitationStatus::Pending);
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    protected static function newFactory()
    {
        return \Database\Factories\WorkspaceInvitationFactory::new();
    }
}
