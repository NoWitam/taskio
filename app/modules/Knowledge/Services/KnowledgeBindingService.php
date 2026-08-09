<?php

namespace App\Modules\Knowledge\Services;

use App\Modules\Knowledge\Enums\KnowledgeBindingMode;
use App\Modules\Knowledge\Models\KnowledgeBase;
use App\Modules\Knowledge\Models\KnowledgeBinding;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * The ONE authority for "which base does this consumer read".
 *
 * ------------------------------------------------------------------------------------------------
 * THE SEAM IS INVERTED, AND THAT IS THE WHOLE DESIGN
 *
 * Every method here takes PRIMITIVES — a morph alias and a uuid — never a consumer model. Knowledge is a
 * lower shared layer and may not name the modules that read it (pinned literally by
 * KnowledgeModuleBoundaryTest), so a signature like `attach(Bot $bot, ...)` is not merely bad taste, it is
 * unrepresentable: the type would have to be imported, and the scan would fail the build.
 *
 * The inversion buys something real. A consumer wires itself up by handing over two strings, so binding a
 * generation session, a workflow step or anything after them needs NO change here — while the alternative
 * (a `knowledge_base_id` column per consumer) would need a migration, a validator and a mode vocabulary
 * per consumer, each free to drift.
 *
 * ------------------------------------------------------------------------------------------------
 * WHAT IS AND IS NOT CHECKED HERE
 *
 * {@see attach()} proves the BASE belongs to the active workspace, because that is a fact only this module
 * can establish and the caller cannot fake: a foreign base id is refused as not-found, so a binding can
 * never point across a tenancy boundary.
 *
 * It does NOT check whether the caller may edit the CONSUMER. That is the consumer's own policy (a bot's
 * `update` ability, enforced in the Bot module's FormRequest) and this module has no way to evaluate it —
 * it does not know what a bot is. Hiding an authorization decision in a service that cannot name the
 * subject of the decision is exactly the failure the "no hidden authorization" rule exists for.
 */
class KnowledgeBindingService
{
    /** The binding of one consumer, or null when it reads no base. */
    public function get(string $bindableType, string $bindableId): ?KnowledgeBinding
    {
        return KnowledgeBinding::query()
            ->forBindable($bindableType, $bindableId)
            ->first();
    }

    /**
     * Point a consumer at a base — an UPSERT, because a consumer reads exactly one base and "switch it to
     * that one" is the same user intent as "start reading that one". Re-binding the same pair only ever
     * changes the mode.
     *
     * @throws ModelNotFoundException when the base is not a live base of the active workspace
     */
    public function attach(
        string $bindableType,
        string $bindableId,
        string $knowledgeBaseId,
        KnowledgeBindingMode $mode = KnowledgeBindingMode::AUTO,
    ): KnowledgeBinding {
        // Scoped by WorkspaceScope + the soft-delete scope: a base in another workspace, or one sitting in
        // the trash, is simply not found. Binding to a trashed base would produce a consumer that reads
        // nothing and cannot be told why.
        $base = KnowledgeBase::query()->whereKey($knowledgeBaseId)->first();

        if ($base === null) {
            throw (new ModelNotFoundException)->setModel(KnowledgeBase::class, [$knowledgeBaseId]);
        }

        $binding = $this->get($bindableType, $bindableId) ?? new KnowledgeBinding([
            'bindable_type' => $bindableType,
            'bindable_id' => $bindableId,
        ]);

        $binding->fill([
            'knowledge_base_id' => $base->getKey(),
            'mode' => $mode,
        ]);

        $binding->save();

        return $binding;
    }

    /** Stop a consumer reading anything. Idempotent: unbinding what was never bound is not an error. */
    public function detach(string $bindableType, string $bindableId): bool
    {
        $binding = $this->get($bindableType, $bindableId);

        if ($binding === null) {
            return false;
        }

        $binding->delete();

        return true;
    }
}
