<?php

namespace App\Modules\Publishing\Models;

use App\Models\AbstractModel;
use App\Modules\Publishing\Enums\PublicationAttemptPhase;
use App\Modules\Publishing\Enums\PublishingPlatform;
use App\Traits\HasCreator;
use App\Traits\TenantAware;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ONE CALL TO A PLATFORM, recorded — what we would have sent, and what came back.
 *
 * APPEND-ONLY: `$timestamps = false` with a manually stamped `created_at` is the model half of that,
 * mirroring `KnowledgeRelationEvent`. A row here says "this happened"; nothing amends it, nothing soft-
 * deletes it, and — the part that matters — nothing rolls it back with the status change that followed
 * it. The evidence has to survive the failure it is evidence of.
 *
 * The trail OUTLIVES its publication (no foreign key), because a purged row's attempts are what remain
 * to explain a post still sitting on somebody's timeline.
 *
 * `request` holds what would have been sent: caption, media ids, options, destination. It holds NOTHING
 * derived from a credential — no token, no Authorization header, no signed URL carrying one. B1 has no
 * tokens to leak, which is precisely why the rule is set now, while nothing tempts anybody to break it.
 *
 * @property PublicationAttemptPhase $phase
 * @property PublishingPlatform $platform
 */
class PublicationAttempt extends AbstractModel
{
    use HasCreator, HasUuids, TenantAware;

    protected $table = 'publication_attempts';

    /** Append-only. `created_at` is stamped by {@see booted()}; there is no `updated_at` column. */
    public $timestamps = false;

    protected $fillable = [
        'publication_id',
        'platform',
        'phase',
        'succeeded',
        'attempt',
        'remote_draft_id',
        'remote_id',
        'request',
        'failure_code',
        'failure_context',
        'creator_id',
    ];

    protected $casts = [
        'platform' => PublishingPlatform::class,
        'phase' => PublicationAttemptPhase::class,
        'succeeded' => 'boolean',
        'attempt' => 'integer',
        'request' => 'array',
        'failure_context' => 'array',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        // Stamped here rather than by Eloquent, because `$timestamps = false` turns the automatic pair
        // off wholesale and this table wants exactly one half of it.
        static::creating(function (self $attempt): void {
            $attempt->created_at ??= now();
        });
    }

    public function publication(): BelongsTo
    {
        return $this->belongsTo(Publication::class);
    }
}
