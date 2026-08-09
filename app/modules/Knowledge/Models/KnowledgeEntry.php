<?php

namespace App\Modules\Knowledge\Models;

use App\Models\AbstractModel;
use App\Modules\Knowledge\Enums\KnowledgeEntryStatus;
use App\Modules\Knowledge\Enums\KnowledgeEntryType;
use App\Modules\Knowledge\Enums\KnowledgeIndexStatus;
use App\Modules\Knowledge\Models\Scopes\WithoutDraftsScope;
use App\Modules\Knowledge\Observers\KnowledgeEntryObserver;
use App\Modules\Knowledge\Support\ChunkVector;
use App\Modules\Knowledge\Support\EntryAliases;
use App\Modules\Knowledge\Support\KnowledgeDigest;
use App\Modules\Knowledge\Support\SectionAppender;
use App\Traits\HasCreator;
use App\Traits\TenantAware;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A KNOWLEDGE ENTRY: one durable topic inside a base.
 *
 * Its `slug` is the STABLE handle other entries point at with `[[wikilinks]]`; it is derived once
 * from the first title and never follows a rename, because a slug that tracked the title would break
 * every inbound link the moment someone fixed a typo in a heading.
 *
 * `content` is plain authored text. It is deliberately NOT template source: the entry is DATA that
 * gets injected into prompts, so template-directive markers are rejected on write (see the store
 * request's fail-closed guard) — otherwise a knowledge entry could smuggle executable directives into
 * every consumer that reads it.
 *
 * The indexing columns are bookkeeping for the embedder (B2a). The authority for "does this need
 * work" is `index_digest != indexed_digest`, never `index_status` alone — see {@see needsIndexing()}.
 */
#[ObservedBy(KnowledgeEntryObserver::class)]
#[ScopedBy(WithoutDraftsScope::class)]
class KnowledgeEntry extends AbstractModel
{
    use HasCreator, HasFactory, HasUuids, SoftDeletes, TenantAware;

    protected $table = 'knowledge_entries';

    protected $fillable = [
        'knowledge_base_id',
        'title',
        'slug',
        'content',
        'aliases',
        'metadata',
        // WHAT KIND of thing this entry is about. Null on every entry written before typed relations
        // existed, and permanently optional — see KnowledgeEntryType for why null is not `other`.
        'entry_type',
        'status',
        'stale_at',
        'position',
        'creator_id',
        // Set only by the AI composer, cleared (atomically) on accept. See WithoutDraftsScope.
        'draft_session_id',
        // SHADOW draft only: the entry this proposal amends, and the revision the composer SAW.
        'targets_entry_id',
        'target_revision_id',
        // HOW it amends: `rewrite` (content IS the new body) or `append` (content is an ADDITION,
        // composed into `amend_section` from the LIVE text at acceptance — see the migration).
        'amend_mode',
        'amend_section',
        // WHICH FACTS of the session's frozen list this entry claims to have absorbed — `["F1","F7"]`
        // and nothing else. The composer's own signal, never a gate; see the migration.
        'covers',
    ];

    protected $casts = [
        'aliases' => 'array',
        'metadata' => 'array',
        'covers' => 'array',
        'entry_type' => KnowledgeEntryType::class,
        'status' => KnowledgeEntryStatus::class,
        'index_status' => KnowledgeIndexStatus::class,
        'index_started_at' => 'datetime',
        'stale_at' => 'datetime',
        'position' => 'integer',
        'chunks_count' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    public function base(): BelongsTo
    {
        return $this->belongsTo(KnowledgeBase::class, 'knowledge_base_id');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(KnowledgeEntryRevision::class, 'knowledge_entry_id');
    }

    public function chunks(): HasMany
    {
        return $this->hasMany(KnowledgeEntryChunk::class, 'knowledge_entry_id');
    }

    /** Edges this entry DRAWS (including ghosts, whose target does not exist yet). */
    public function outgoingLinks(): HasMany
    {
        return $this->hasMany(KnowledgeLink::class, 'from_entry_id');
    }

    /**
     * Edges pointing AT this entry — backlinks. There is no backlink table; a backlink is this same
     * table read in the other direction, which is also why a backlink can never disagree with the
     * link that produced it.
     */
    public function incomingLinks(): HasMany
    {
        return $this->hasMany(KnowledgeLink::class, 'to_entry_id');
    }

    /**
     * TYPED relations this entry asserts. Separate from `outgoingLinks` because they are a different
     * kind of claim entirely: a link is derived and disposable, a relation was approved by a person
     * and nothing automated may remove it.
     */
    public function outgoingRelations(): HasMany
    {
        return $this->hasMany(KnowledgeRelation::class, 'from_entry_id');
    }

    /** Typed relations asserted ABOUT this entry — the other half of the same table. */
    public function incomingRelations(): HasMany
    {
        return $this->hasMany(KnowledgeRelation::class, 'to_entry_id');
    }

    /**
     * The NAMES this entry answers to in prose — its title plus every alias, normalized.
     *
     * The one shape the mention layer consumes: it compiles a pattern per name, so a caller that
     * assembled the list itself would decide, accidentally, whether the title is included and whether
     * a malformed alias becomes a pattern that matches everything.
     *
     * @return array<int, string>
     */
    public function mentionNames(): array
    {
        return array_values(array_unique(array_merge(
            [(string) $this->title],
            EntryAliases::normalize($this->aliases),
        )));
    }

    /** Whether this entry is an unaccepted AI draft (see {@see WithoutDraftsScope}). */
    public function isDraft(): bool
    {
        return $this->draft_session_id !== null;
    }

    /**
     * Whether this draft proposes a CHANGE to an existing entry rather than a new one. Always also a
     * draft — the database enforces that (`knowledge_entries_shadow_is_draft`), because a shadow that
     * escaped the draft scope would surface in the base as an entry with a reserved slug.
     */
    public function isShadow(): bool
    {
        return $this->targets_entry_id !== null;
    }

    /**
     * Whether this shadow ADDS to its target rather than replacing it.
     *
     * An append shadow's `content` is the ADDITION, not a body: it is composed into the live text at
     * acceptance, which is what keeps it commutative with a concurrent human edit. Anything reading a
     * shadow's content for display has to know the difference, or it will show one line where the
     * result is a document.
     */
    public function isAppendShadow(): bool
    {
        return $this->isShadow() && $this->amend_mode === 'append';
    }

    /**
     * What this proposal WOULD make the target say — the reviewer's "after".
     *
     * Composed here rather than stored, for an append, because storing it would freeze the answer
     * against text that can still move. A rewrite's answer is its own content.
     */
    public function amendedBody(): string
    {
        $target = $this->targetsEntry;

        if (!$this->isAppendShadow() || $target === null) {
            return (string) $this->content;
        }

        return SectionAppender::apply((string) $target->content, $this->amend_section, (string) $this->content);
    }

    /** The entry a shadow draft amends. Resolved through the ordinary scope — the target is real. */
    public function targetsEntry(): BelongsTo
    {
        return $this->belongsTo(KnowledgeEntry::class, 'targets_entry_id');
    }

    /**
     * Whether the target has moved on since the composer read it — i.e. accepting this proposal would
     * hit the optimistic lock. Answered before acceptance so the UI can say so rather than surprising
     * the reviewer with a conflict after they click.
     */
    public function targetRevisionIsStale(): bool
    {
        if (!$this->isShadow()) {
            return false;
        }

        $current = $this->targetsEntry?->current_revision_id;

        return $current !== null && $current !== $this->target_revision_id;
    }

    /** Entries whose review date has arrived — the "this fact may have rotted" filter. */
    public function scopeStale(Builder $query): void
    {
        $query->whereNotNull('stale_at')->where('stale_at', '<=', now());
    }

    /**
     * Attach `indexed_chunks_count` — the NUMERATOR that makes `chunks_count` mean something.
     *
     * `partial` is the state this exists for. On its own it says an entry is incompletely indexed but
     * not how incompletely, and "some of this document is searchable" is a very different message from
     * "7 of 8 passages are". One correlated sub-select for a whole page, never a query per row, which
     * is what lets the LIST carry it as well as the detail view.
     *
     * A NO-OP where vectors are unsupported: the attribute is then simply absent and
     * {@see indexedChunksCount()} reports null, which the API renders as "cannot be counted here"
     * rather than as zero. Reporting 0 would be a lie that reads as data loss.
     */
    public function scopeWithIndexedChunks(Builder $query): void
    {
        if (!ChunkVector::supported()) {
            return;
        }

        $query->withCount(['chunks as indexed_chunks_count' => fn ($chunks) => $chunks->indexed()]);
    }

    /** The same count for an entry already in memory (the detail path). Chainable. */
    public function loadIndexedChunks(): static
    {
        if (!ChunkVector::supported()) {
            return $this;
        }

        return $this->loadCount(['chunks as indexed_chunks_count' => fn ($chunks) => $chunks->indexed()]);
    }

    /** How many passages carry a CURRENT vector, or null when this connection cannot say. */
    public function indexedChunksCount(): ?int
    {
        $count = $this->getAttribute('indexed_chunks_count');

        return $count === null ? null : (int) $count;
    }

    public function isStale(): bool
    {
        return $this->stale_at !== null && $this->stale_at->isPast();
    }

    /**
     * Whether the stored chunks/embeddings are out of date with the current text. Derived from the
     * two digests rather than from `index_status`, so a worker that died mid-run (leaving `indexing`
     * behind) can never make a stale entry look current.
     */
    public function needsIndexing(): bool
    {
        // The PIPELINE moved (chunker version, embedding model or width). The stored digests still
        // agree with each other — they were both computed under the old parameters — so comparing
        // them alone would call this entry current when it is anything but. Checked first because it
        // is true of every entry at once.
        if ($this->index_params !== KnowledgeDigest::parameters()) {
            return true;
        }

        return $this->index_digest !== null && $this->index_digest !== $this->indexed_digest;
    }

    /**
     * The QUERY form of {@see needsIndexing()} — the sweep's candidate set. It must express exactly
     * the same predicate, so the two can never disagree about whether an entry is up to date: a
     * mismatch would either leave stale entries unswept forever or make the sweep re-dispatch entries
     * that are already current.
     */
    public function scopeNeedsIndexing(Builder $query): void
    {
        $parameters = KnowledgeDigest::parameters();

        $query->where(fn (Builder $outer) => $outer
            // Indexed under a different pipeline than the one configured now.
            ->whereNull('index_params')
            ->orWhere('index_params', '!=', $parameters)
            // Or the text itself moved since it was last indexed.
            ->orWhere(fn (Builder $text) => $text
                ->whereNotNull('index_digest')
                ->where(fn (Builder $inner) => $inner
                    ->whereNull('indexed_digest')
                    ->orWhereColumn('index_digest', '!=', 'indexed_digest'))));
    }

    protected static function newFactory()
    {
        return \Database\Factories\KnowledgeEntryFactory::new();
    }
}
