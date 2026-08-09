<?php

namespace App\Modules\Knowledge\Models;

use App\Models\AbstractModel;
use App\Modules\Knowledge\Enums\KnowledgeBindingMode;
use App\Traits\TenantAware;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * "This consumer reads that base, in this mode."
 *
 * `bindable_type` is a MORPH ALIAS (`'bot'`) and there is deliberately NO `morphTo()` relation: resolving
 * the alias back to a class would make this module depend on every module that binds to it, which is the
 * one thing the Knowledge boundary forbids. The consumer is addressed, never loaded — a binding answers
 * "what does THIS id read", and the id's owner is the caller's business.
 *
 * Not soft-deleted and not a {@see \App\Traits\HasCreator} record: a binding is a POINTER, not a document.
 * Un-binding is meant to be complete (nothing to restore, nothing to leave in a trash), and "who wired
 * this up" is answered by the consumer's own audit trail — the bot action log records every read.
 */
class KnowledgeBinding extends AbstractModel
{
    use HasFactory, HasUuids, TenantAware;

    protected $table = 'knowledge_bindings';

    protected $fillable = [
        'bindable_type',
        'bindable_id',
        'knowledge_base_id',
        'mode',
    ];

    protected $casts = [
        'mode' => KnowledgeBindingMode::class,
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function base(): BelongsTo
    {
        return $this->belongsTo(KnowledgeBase::class, 'knowledge_base_id');
    }

    /** The binding of ONE consumer, addressed by primitives — the module's only lookup shape. */
    public function scopeForBindable(Builder $query, string $type, string $id): void
    {
        $query->where('bindable_type', $type)->where('bindable_id', $id);
    }

    protected static function newFactory()
    {
        return \Database\Factories\KnowledgeBindingFactory::new();
    }
}
