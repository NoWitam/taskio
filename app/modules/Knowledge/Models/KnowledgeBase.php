<?php

namespace App\Modules\Knowledge\Models;

use App\Models\AbstractModel;
use App\Traits\HasCreator;
use App\Traits\TenantAware;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A KNOWLEDGE BASE: the workspace-scoped container for durable facts, plus the two things that make
 * a pile of notes into a base — the CHARTER (prose stating what belongs in it) and the METADATA
 * SCHEMA (the typed fields every entry is validated against).
 *
 * The schema is a list of `{key, label, descriptor}` where `descriptor` is a descriptor from the
 * shared type system, so an entry's metadata is validated by exactly the authority that validates a
 * constant's value. That reuse is the point: a knowledge field can never describe a shape the rest of
 * the product cannot read back.
 *
 * Soft-deleted. Deleting a base cascades to its entries THROUGH THE SERVICE (not the database), so
 * the cascade is reversible: restoring the base restores exactly the entries that fell with it.
 */
class KnowledgeBase extends AbstractModel
{
    use HasCreator, HasFactory, HasUuids, SoftDeletes, TenantAware;

    protected $table = 'knowledge_bases';

    protected $fillable = [
        'name',
        'description',
        'charter',
        'metadata_schema',
        // The relation verbs this base allows — a subset of the global vocabulary. NULL means all of
        // them, which is deliberately NOT the same as `[]` (none). See RelationVocabulary.
        'relation_types',
        'language',
        'creator_id',
    ];

    protected $casts = [
        // Always a list of {key, label, descriptor} objects.
        'metadata_schema' => 'array',
        'relation_types' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    public function entries(): HasMany
    {
        return $this->hasMany(KnowledgeEntry::class, 'knowledge_base_id');
    }

    public function links(): HasMany
    {
        return $this->hasMany(KnowledgeLink::class, 'knowledge_base_id');
    }

    /** The base's TYPED relations — approved statements, not the derived link cache. */
    public function relations(): HasMany
    {
        return $this->hasMany(KnowledgeRelation::class, 'knowledge_base_id');
    }

    /**
     * The declared metadata fields, normalized to `{key: descriptor}` — the shape both the write
     * validator and (later) the retrieval filters read. Malformed rows are skipped rather than
     * throwing: the schema is validated on write, so a bad row here is corrupt data, and a base that
     * cannot be opened at all is a worse failure than one field quietly not being enforced.
     *
     * @return array<string, array<string, mixed>>
     */
    public function metadataDescriptors(): array
    {
        $descriptors = [];

        foreach (is_array($this->metadata_schema) ? $this->metadata_schema : [] as $field) {
            if (!is_array($field) || !is_string($field['key'] ?? null) || !is_array($field['descriptor'] ?? null)) {
                continue;
            }

            $descriptors[$field['key']] = $field['descriptor'];
        }

        return $descriptors;
    }

    protected static function newFactory()
    {
        return \Database\Factories\KnowledgeBaseFactory::new();
    }
}
