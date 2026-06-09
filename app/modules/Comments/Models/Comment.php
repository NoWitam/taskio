<?php

namespace App\Modules\Comments\Models;

use App\Casts\MarkdownTreeCast;
use App\Models\AbstractModel;
use App\Models\User;
use App\Traits\TenantAware;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}
