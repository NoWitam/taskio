<?php

namespace App\Modules\Knowledge\Models;

use App\Models\AbstractModel;
use App\Modules\Knowledge\Enums\KnowledgeRelationOrigin;
use App\Modules\Knowledge\Enums\KnowledgeRelationState;
use App\Modules\Knowledge\Enums\KnowledgeRelationType;
use App\Traits\HasCreator;
use App\Traits\TenantAware;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A TYPED, APPROVED STATEMENT about two entries: "Anna WORKS ON the refund project".
 *
 * The difference from {@see KnowledgeLink} is not the shape, it is the OWNERSHIP. A link is derived —
 * parsed from prose or measured by a vector — and is deleted and rebuilt on every save; a relation was
 * asserted, reviewed and approved, and nothing automated may remove it. That is why they are separate
 * tables rather than one table with a flag: the link sweep's `delete` cannot name this one.
 *
 * A HasCreator record, unlike a link. An edge the machine derived has no author worth attributing (the
 * author of a wikilink IS the writer of the entry), but somebody stands behind a relation, and "who
 * said this" is the first question asked of a fact that turns out to be wrong.
 *
 * ENDING IS NOT DELETING. `state` + `valid_to` + `superseded_by_id` record that something stopped
 * being true, or was never true, without losing the claim — see {@see KnowledgeRelationState}. Hard
 * deletion exists but is only ever a person's own action; the machine-facing contract has no such
 * verb at all, because a model that can retract statements can quietly empty a base.
 */
class KnowledgeRelation extends AbstractModel
{
    use HasCreator, HasFactory, HasUuids, TenantAware;

    protected $table = 'knowledge_relations';

    protected $fillable = [
        'knowledge_base_id',
        'from_entry_id',
        'to_entry_id',
        'relation_type',
        'description',
        'properties',
        'valid_from',
        'valid_to',
        'state',
        'superseded_by_id',
        'origin',
        'draft_session_id',
        'creator_id',
    ];

    protected $casts = [
        'relation_type' => KnowledgeRelationType::class,
        'state' => KnowledgeRelationState::class,
        'origin' => KnowledgeRelationOrigin::class,
        'properties' => 'array',
        // DATES, not datetimes: the facts recorded here are known to the day at best.
        'valid_from' => 'date',
        'valid_to' => 'date',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function base(): BelongsTo
    {
        return $this->belongsTo(KnowledgeBase::class, 'knowledge_base_id');
    }

    public function fromEntry(): BelongsTo
    {
        return $this->belongsTo(KnowledgeEntry::class, 'from_entry_id');
    }

    public function toEntry(): BelongsTo
    {
        return $this->belongsTo(KnowledgeEntry::class, 'to_entry_id');
    }

    /** The relation that replaced this one, when it was superseded rather than merely ended. */
    public function supersededBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'superseded_by_id');
    }

    /** Still asserted. The default view of a base — see {@see scopeCurrent()}. */
    public function isActive(): bool
    {
        return $this->state === KnowledgeRelationState::ACTIVE;
    }

    /** Symmetric relations are stored once and drawn undirected. */
    public function isSymmetric(): bool
    {
        return $this->relation_type?->isSymmetric() ?? false;
    }

    public function scopeCurrent(Builder $query): void
    {
        $query->where('state', KnowledgeRelationState::ACTIVE->value);
    }

    /** Every relation touching this entry, in either direction — the panel's own read. */
    public function scopeTouching(Builder $query, string $entryId): void
    {
        $query->where(fn (Builder $inner) => $inner
            ->where('from_entry_id', $entryId)
            ->orWhere('to_entry_id', $entryId));
    }

    /**
     * The CANONICAL direction of a symmetric pair: the smaller id first.
     *
     * Storing one row per symmetric claim is what stops the two halves from disagreeing (one ended,
     * one not) with nothing to say which is right — but only if "which half" is decided by the data
     * rather than by whichever end the writer happened to name first. String comparison on the uuid
     * is arbitrary and total, which is all this needs to be.
     *
     * @return array{0: string, 1: string}
     */
    public static function canonicalPair(KnowledgeRelationType $type, string $fromId, string $toId): array
    {
        if (!$type->isSymmetric() || strcmp($fromId, $toId) <= 0) {
            return [$fromId, $toId];
        }

        return [$toId, $fromId];
    }

    /**
     * The snapshot the audit trail stores as `before`/`after`.
     *
     * The relation's own shape, so a trail is readable without joining anything that may since have
     * moved — or been deleted, which is the case the log exists for.
     *
     * @return array<string, mixed>
     */
    public function auditSnapshot(): array
    {
        return [
            'from_entry_id' => $this->from_entry_id,
            'to_entry_id' => $this->to_entry_id,
            'relation_type' => $this->relation_type?->value,
            'description' => $this->description,
            'properties' => $this->properties ?? [],
            'valid_from' => $this->valid_from?->toDateString(),
            'valid_to' => $this->valid_to?->toDateString(),
            'state' => $this->state?->value,
            'superseded_by_id' => $this->superseded_by_id,
            'origin' => $this->origin?->value,
        ];
    }

    protected static function newFactory()
    {
        return \Database\Factories\KnowledgeRelationFactory::new();
    }
}
