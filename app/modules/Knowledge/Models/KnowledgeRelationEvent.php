<?php

namespace App\Modules\Knowledge\Models;

use App\Models\AbstractModel;
use App\Traits\HasCreator;
use App\Traits\TenantAware;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One APPEND-ONLY line of a relation's history: what happened, what it looked like before, and what it
 * looks like now.
 *
 * `$timestamps = false` with a manually stamped `created_at` is the model half of "append-only" — the
 * same posture {@see KnowledgeEntryRevision} takes, and for the same reason: a record that can be
 * modified is not evidence.
 *
 * The relation is a plain `belongsTo` with NO foreign key behind it, deliberately. The log outlives its
 * subject: a cascade would delete a relation's history along with the relation, erasing precisely the
 * `delete` event that explains where it went. `relation()` therefore resolves to null for a deleted
 * relation, which is the correct answer rather than an error.
 *
 * See the migration for why this is not the shared `changelogs` table (four reasons, the first being
 * that `changelogs` has no tenant mirror at all).
 */
class KnowledgeRelationEvent extends AbstractModel
{
    use HasCreator, HasUuids, TenantAware;

    public const OP_CREATE = 'create';

    public const OP_UPDATE = 'update';

    public const OP_END = 'end';

    public const OP_SUPERSEDE = 'supersede';

    public const OP_RETRACT = 'retract';

    public const OP_DELETE = 'delete';

    // THERE IS NO `OP_PROMOTE`. It was removed rather than kept "for historical rows", because the rows
    // were checked and there are none: promotion was reachable only through `POST /relations` with
    // `promote_link_id` (withdrawn with hand-authorship, ADR-0049 D2), the module has never shipped, and
    // the only base that exists holds `create` events exclusively. A named constant nothing emits is
    // worse than its absence — the next reader assumes something writes it and goes looking for what.
    // `op` is a plain string column, so were such a row ever to arrive it would read back fine.

    protected $table = 'knowledge_relation_events';

    /** Append-only: created_at is stamped explicitly on insert; there is no updated_at column. */
    public $timestamps = false;

    protected $fillable = [
        'relation_id',
        'knowledge_base_id',
        'op',
        'before',
        'after',
        'draft_session_id',
        'creator_id',
        'created_at',
    ];

    protected $casts = [
        'before' => 'array',
        'after' => 'array',
        'created_at' => 'datetime',
    ];

    /** Null once the relation is gone — which is the state this log exists to survive. */
    public function relation(): BelongsTo
    {
        return $this->belongsTo(KnowledgeRelation::class, 'relation_id');
    }
}
