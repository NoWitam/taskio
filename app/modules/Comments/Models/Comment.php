<?php

namespace App\Modules\Comments\Models;

use App\Casts\MarkdownTreeCast;
use App\Models\AbstractModel;
use App\Models\User;
use App\Traits\TenantAware;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Comment extends AbstractModel
{
    use HasUuids, SoftDeletes, TenantAware;

    protected $table = 'comments';

    protected $fillable = [
        'content',
        'commentable_type',
        'commentable_id',
        'author_type',
        'author_id',
    ];

    protected $casts = [
        'content' => MarkdownTreeCast::class,
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    public function commentable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Polymorphic author (User|Bot). A comment is normally authored by the current
     * user; the bot task-execution flow authors comments as a Bot. The User branch
     * bypasses WorkspaceMemberScope on load so a former-member author still renders
     * (the comment author is part of a comment's permanent record).
     */
    public function author(): MorphTo
    {
        return $this->morphTo('author')->constrain([
            User::class => fn ($query) => $query->withoutWorkspaceMemberScope(),
        ]);
    }
}
