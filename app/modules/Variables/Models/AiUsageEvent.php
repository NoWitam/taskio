<?php

namespace App\Modules\Variables\Models;

use App\Models\AbstractModel;
use App\Models\User;
use App\Traits\TenantAware;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One recorded AI SPEND — the row the cost meter ({@see \App\Modules\Variables\Support\LedgerMeteredAiCall})
 * writes after every metered provider call, across every spender (`ai_text` now, `ai_image_edit` now,
 * generation sessions later). It is the ledger the calendar-month budget gate reads BEFORE the next spend.
 *
 * Workspace-scoped and TenantAware exactly like {@see Constant}: in shared-database mode WorkspaceScope
 * isolates + stamps `workspace_id`; the own-database mirror omits that column (one tenant DB = one
 * workspace). `total_tokens` is the summed spend the gate compares to the cap; `estimated_cost` is an
 * DOLLAR figure the R2 sub-stage 4 $-gate sums (an ESTIMATE from the per-channel price map; never billed
 * on). `session_id` is a generation session (null for workflow/disk spend; NO FK — a session may be
 * trashed/purged while its ledger stays). `actor_type`/`actor_id` are the POLYMORPHIC actor the spend
 * attributes to (user|workflow_run|bot), tagged ambiently at record time; NO FK, so the attribution
 * outlives a purged session / a departed member, and the display name is resolved at READ time.
 */
class AiUsageEvent extends AbstractModel
{
    use HasUuids, TenantAware;

    protected $fillable = [
        'channel',
        'prompt_tokens',
        'completion_tokens',
        'total_tokens',
        'estimated_cost',
        'session_id',
        'actor_type',
        'actor_id',
        'meta',
    ];

    protected $casts = [
        'prompt_tokens' => 'integer',
        'completion_tokens' => 'integer',
        'total_tokens' => 'integer',
        'estimated_cost' => 'decimal:4',
        'meta' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * The polymorphic actor (User | WorkflowRun | Bot | null) that drove this spend. The User branch
     * bypasses the WorkspaceMemberScope on load — exactly as {@see \App\Traits\HasCreator::creator} does
     * — so a spender who has since left the workspace still resolves for the per-actor summary. The
     * per-actor summary in {@see \App\Modules\Variables\Services\AiUsageService} resolves names GENERICALLY
     * through the morph map (never storing a name), so this relation is the convenience accessor, not the
     * summary's hot path.
     */
    public function actor(): MorphTo
    {
        return $this->morphTo('actor', 'actor_type', 'actor_id')
            ->constrain([
                User::class => fn ($query) => $query->withoutWorkspaceMemberScope(),
            ]);
    }
}
