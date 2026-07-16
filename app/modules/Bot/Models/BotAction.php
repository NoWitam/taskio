<?php

namespace App\Modules\Bot\Models;

use App\Models\AbstractModel;
use App\Modules\Bot\Enums\BotActionType;
use App\Modules\Tasks\Models\Task;
use App\Traits\HasCreator;
use App\Traits\TenantAware;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Audit-log row for a single step a bot performed while executing a task
 * (task_started, form_filled, commented, submitted_to_test, execution_failed, …).
 * Bot-initiated rows may have a null creator_id (no originating user).
 */
class BotAction extends AbstractModel
{
    use HasCreator, HasFactory, HasUuids, SoftDeletes, TenantAware;

    protected $table = 'bot_actions';

    protected $fillable = [
        'bot_id',
        'task_id',
        'type',
        'payload',
        'status',
        'error',
        'creator_id',
    ];

    protected $casts = [
        'type' => BotActionType::class,
        'payload' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    public function bot(): BelongsTo
    {
        return $this->belongsTo(Bot::class, 'bot_id');
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'task_id');
    }

    protected static function newFactory()
    {
        return \Database\Factories\BotActionFactory::new();
    }
}
