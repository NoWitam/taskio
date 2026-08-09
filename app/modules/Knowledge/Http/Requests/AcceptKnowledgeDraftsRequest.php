<?php

namespace App\Modules\Knowledge\Http\Requests;

use App\Modules\Knowledge\Enums\KnowledgeEntryStatus;
use App\Modules\Knowledge\Models\KnowledgeDraftSession;
use App\Modules\Knowledge\Models\KnowledgeEntry;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PUBLISH the chosen drafts.
 *
 * `status` is explicit and narrowed to `approved | draft`, because those are the two things a reviewer
 * can honestly mean: "this is right, the workspace stands behind it" or "this is a useful start, I will
 * finish it myself". `proposed` is excluded because that is what the drafts already ARE, and `archived`
 * because publishing something directly into the retired state is not an action anybody wants.
 *
 * The ids are validated as uuids only; WHICH drafts they may name is decided by the service, which
 * resolves them through the session's own relation — so an id from another session (or a real entry's
 * id) simply is not found rather than being authorized against.
 */
class AcceptKnowledgeDraftsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->route('session') instanceof KnowledgeDraftSession
            && ($this->user()?->can('compose', KnowledgeEntry::class) ?? false);
    }

    public function rules(): array
    {
        return [
            // PRESENT but possibly EMPTY. A graph-only proposal has no drafts to publish, and demanding
            // at least one id made accepting one impossible to express — the caller had to invent an id
            // to satisfy a rule about a list it had nothing to put in.
            'entry_ids' => ['present', 'array'],
            'entry_ids.*' => ['required', 'uuid', 'distinct'],
            // WHICH relation operations to apply. ABSENT means all of them — the compatible reading,
            // and what a client that has not learned about selection keeps getting. PRESENT means
            // exactly these, and everything else comes back in `skipped` as `not_selected`, so a
            // reviewer sees that their refusal took effect rather than that something quietly vanished.
            'graph_op_keys' => ['sometimes', 'array'],
            // NO `distinct` HERE. That rule reports against the ITEM (`graph_op_keys.0`), while every
            // consumer of this endpoint tells a selection refusal from a draft problem by looking for
            // the exact key `graph_op_keys` — so a duplicate would surface on the draft cards instead
            // of the operations panel. Duplicates are checked in withValidator() and reported on the
            // array itself, next to the other two selection rules.
            'graph_op_keys.*' => ['string', 'max:32'],
            'status' => ['required', Rule::in([
                KnowledgeEntryStatus::APPROVED->value,
                KnowledgeEntryStatus::DRAFT->value,
            ])],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $session = $this->route('session');

            if (!$session instanceof KnowledgeDraftSession || !$this->has('graph_op_keys')) {
                return;
            }

            $raw = array_filter($this->array('graph_op_keys'), 'is_string');

            if (count($raw) !== count(array_unique($raw))) {
                // Reported on the ARRAY, not the item — see the rules() note.
                $validator->errors()->add('graph_op_keys', __('knowledge.relations.duplicate_op_key'));

                return;
            }

            $known = array_keys($session->graphOps()['graph_updates']);
            $unknown = array_values(array_diff(
                $this->graphOpKeys() ?? [],
                array_map(static fn (int $index): string => 'graph:' . $index, $known),
            ));

            if ($unknown !== []) {
                // 422 rather than a silent skip, because an unknown key means the CLIENT IS LOOKING AT
                // A STALE PREVIEW — the run was refined and the operations renumbered under it. Quietly
                // ignoring it would apply some of what the reviewer approved and drop the rest without
                // saying so, which is the exact failure selection exists to prevent.
                $validator->errors()->add('graph_op_keys', __('knowledge.relations.unknown_op_key', [
                    'keys' => implode(', ', $unknown),
                ]));

                return; // one clear reason beats two, and the pair check would be noise on stale keys
            }

            // A BOUND PAIR IS INSEPARABLE, and this REFUSES rather than auto-completing the selection.
            //
            // Auto-including the missing half would write something the reviewer never ticked — the same
            // class of defect as a checkbox that does not do what it says, and worse here, because the
            // thing quietly added is a fact about a person. Refusing is not a dead end: every
            // combination stays reachable by adding the partner, and refusing BOTH is always allowed.
            // The only blocked state is the half-selection, which is blocked on purpose — an `end`
            // without its replacement says Anna works nowhere, and a replacement without the ending says
            // she works in two places at once.
            //
            // EXEMPT when the partner has ALREADY been applied: a second accept must not demand that a
            // reviewer re-tick an operation that already happened and would only be reported as a skip.
            $selected = $this->graphOpKeys() ?? [];
            $applied = is_array($session->applied_ops) ? $session->applied_ops : [];
            $split = [];

            foreach ($session->graphOpPairs() as $key => $partner) {
                if (in_array($key, $selected, true)
                    && !in_array($partner, $selected, true)
                    && !in_array($partner, $applied, true)) {
                    $split[] = $key . ' + ' . $partner;
                }
            }

            if ($split !== []) {
                $validator->errors()->add('graph_op_keys', __('knowledge.relations.inseparable_ops', [
                    'pairs' => implode(', ', array_unique($split)),
                ]));
            }
        });
    }

    public function resolvedStatus(): KnowledgeEntryStatus
    {
        return KnowledgeEntryStatus::from($this->string('status')->value());
    }

    /** @return array<int, string> */
    public function entryIds(): array
    {
        return array_values(array_unique($this->array('entry_ids')));
    }

    /**
     * The relation operations to apply, or NULL for "all of them".
     *
     * Null and `[]` are deliberately different: an absent key is a client that does not select, an
     * empty array is a reviewer who refused every relation operation while accepting some drafts.
     *
     * @return array<int, string>|null
     */
    public function graphOpKeys(): ?array
    {
        if (!$this->has('graph_op_keys')) {
            return null;
        }

        return array_values(array_unique(array_map(
            static fn ($key): string => is_string($key) ? $key : '',
            $this->array('graph_op_keys'),
        )));
    }
}
