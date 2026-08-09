<?php

namespace App\Modules\Knowledge\Models;

use App\Models\AbstractModel;
use App\Traits\HasCreator;
use App\Traits\TenantAware;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An APPEND-ONLY snapshot of a knowledge entry's authored state, written on every mutation including
 * the create.
 *
 * `$timestamps = false` with a manually stamped `created_at` is the model half of "append-only": the
 * table has no updated_at, because a revision that can be modified is not an audit trail. Restoring
 * an old revision therefore appends a NEW one carrying the old text, authored by whoever restored it
 * — history grows, it never rewinds.
 *
 * The actor is the polymorphic HasCreator pair under the domain name author_id/author_type (the same
 * column override the disk file makes for its uploader): on a revision the actor is the AUTHOR of
 * that version. The relation is still `creator()` — that name comes from the shared trait, and
 * renaming it here would fork the trait for cosmetics; the RESOURCE exposes it as `author`.
 */
class KnowledgeEntryRevision extends AbstractModel
{
    use HasCreator, HasFactory, HasUuids, TenantAware;

    /** @see HasCreator — the polymorphic actor pair lives under a domain name here. */
    protected const CREATOR_ID_COLUMN = 'author_id';

    protected const CREATOR_TYPE_COLUMN = 'author_type';

    protected $table = 'knowledge_entry_revisions';

    /** Append-only: created_at is stamped explicitly on insert; there is no updated_at column. */
    public $timestamps = false;

    protected $fillable = [
        'knowledge_entry_id',
        'title',
        'content',
        'metadata',
        'change_note',
        'author_id',
        'created_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'created_at' => 'datetime',
    ];

    public function entry(): BelongsTo
    {
        return $this->belongsTo(KnowledgeEntry::class, 'knowledge_entry_id');
    }

    protected static function newFactory()
    {
        return \Database\Factories\KnowledgeEntryRevisionFactory::new();
    }
}
