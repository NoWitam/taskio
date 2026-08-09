<?php

namespace App\Modules\Knowledge\Models;

use App\Models\AbstractModel;
use App\Modules\Knowledge\Enums\KnowledgeLinkSource;
use App\Traits\TenantAware;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One directed edge between entries of the same base.
 *
 * A link ALWAYS knows its `target_slug` and MAY not know its `to_entry_id`: an unresolved edge is a
 * GHOST, which is how a base surfaces the entry someone meant to write. Ghosts attach automatically
 * the moment an entry with that slug appears, which is only possible because resolution keys on the
 * slug and the slug never follows a rename.
 *
 * NOT a HasCreator record: an edge has no author worth attributing separately from the entry that
 * draws it (a wikilink's author IS the entry's writer, a similarity edge has no human author at all),
 * and stamping one would invite ownership checks on a derived row.
 */
class KnowledgeLink extends AbstractModel
{
    use HasFactory, HasUuids, TenantAware;

    protected $table = 'knowledge_links';

    protected $fillable = [
        'knowledge_base_id',
        'from_entry_id',
        'to_entry_id',
        'target_slug',
        'source',
        'score',
        'evidence',
        'dismissed_at',
    ];

    protected $casts = [
        'source' => KnowledgeLinkSource::class,
        'evidence' => 'array',
        'score' => 'float',
        'dismissed_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function fromEntry(): BelongsTo
    {
        return $this->belongsTo(KnowledgeEntry::class, 'from_entry_id');
    }

    public function toEntry(): BelongsTo
    {
        return $this->belongsTo(KnowledgeEntry::class, 'to_entry_id');
    }

    /** An edge whose target does not exist (yet) — the base telling you what is missing. */
    public function isGhost(): bool
    {
        return $this->to_entry_id === null;
    }

    public function scopeGhost(Builder $query): void
    {
        $query->whereNull('to_entry_id');
    }

    public function scopeOfSource(Builder $query, KnowledgeLinkSource $source): void
    {
        $query->where('source', $source->value);
    }

    protected static function newFactory()
    {
        return \Database\Factories\KnowledgeLinkFactory::new();
    }
}
