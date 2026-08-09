<?php

namespace App\Modules\Knowledge\Http\Resources;

use App\Http\Resources\CreatorResource;
use App\Modules\Knowledge\Services\KnowledgeDraftRelationService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One drafting session and, when loaded, the drafts on the table — the POLL payload.
 *
 * `status` is the whole state machine the client renders from, and `failure_reason` is a stable CODE
 * rather than prose: the client owns the wording (it has the room and the locale), and a message
 * composed server-side would be untranslatable copy baked into an API. The codes are the small set
 * {@see \App\Modules\Knowledge\Services\KnowledgeDraftService} can produce.
 *
 * `source_text` is echoed back because the composer's own form shows it (the user may want to edit and
 * re-run), and `prompt_history` because the panel lists what has been asked so far.
 *
 * The drafts are serialized by the ORDINARY entry resource. They are ordinary entries — that is the
 * point of the design — so the reviewer's card gets `title`, `content`, `metadata`, `links`, and the
 * revision pointer the diff view needs, with no second shape to keep in step.
 */
class KnowledgeDraftSessionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'knowledge_base_id' => $this->knowledge_base_id,

            'status' => $this->status?->value,
            'failure_reason' => $this->failure_reason,

            // What the SERVER did to the model's answer on the last run — `{code, ...context}` objects,
            // codes not prose, rewritten wholesale per run. A run can SUCCEED and still have been
            // altered (an amendment constrained to an append because its target was too long to show
            // the composer in full; a proposal dropped because applying it would burst the entry cap),
            // and a reviewer looking at a diff that does not match what was asked for has no other way
            // to find out why.
            'notes' => $this->runNotes(),

            // WHO AND WHAT the material is about, matched against entries that already exist — frozen at
            // session start and stable for its whole life.
            //
            // `ambiguous[]` is the section a UI must not skip. Each item is a name the base matched to
            // SEVERAL entries, with the candidates attached, and it is a QUESTION rather than a result:
            // the composer may answer it from context, and whatever it does not answer is a one-click
            // decision for the reviewer. Rendered as an error, or not rendered at all, the one case this
            // layer deliberately refuses to guess at becomes the one case nobody ever resolves.
            //
            // `degraded[]` non-empty means the pass ran short (budget, no vector support, a base past
            // the scan limit), so an `unresolved` name there means "not looked for properly" rather
            // than "not in this base" — a distinction the reviewer has to be able to see.
            'resolution' => $this->resolutionSet(),

            // THE REVIEWER'S CHECKLIST: what the material says happened, beside what was proposed.
            //
            // A READING AID, never a verdict — and the server no longer marks any row on it. The run
            // used to name the facts nobody claimed (`facts_not_covered`); that was withdrawn when a
            // measured run showed the claim channel empty and the panel accusing the writer of omitting
            // nine facts out of nine (ADR-0050 D1). What the notes still carry is the list's own
            // housekeeping — `facts_truncated`, `facts_unavailable`. The comparison is the reader's.
            //
            // Per-fact `covered`/`covered_by` are NOT here yet: that needs the draft to persist its
            // claim, which needs a column and therefore the owner's approval. See factChecklist().
            'facts' => $this->factChecklist(),

            // WHAT THE RUN PROPOSED TO DO TO THE GRAPH, laundered — `entities` (things to create),
            // `wiki_updates` (content), `graph_updates` (typed relations), plus the two report lists.
            //
            // `rejected[]` is not an error log, it is a SECTION OF THE REVIEW: each item names an
            // operation the server refused and why, so a gap in the vocabulary or a run of invented
            // handles becomes visible evidence instead of a silent shortfall nobody can account for.
            // `warnings[]` is the advisory half, and it is about the GRAPH ONLY — a type pair nothing
            // could check, an ambiguity nobody settled, a content change moved to review, a `replaces`
            // whose other half did not survive. What the server did to a proposal's TEXT is NOT here:
            // an append forced in place of a rewrite, or a rewrite that drops wikilinks, are RUN NOTES
            // (`notes` above). One fact, one channel — a client reading both for the same thing would
            // find it in one of them and conclude the other was broken.
            //
            // NOTHING HERE HAS BEEN APPLIED. It is a proposal; a human accepts it, and the write path
            // re-checks every rule from scratch because the base can move while it sits on screen.
            'graph_ops' => $this->graphOps(),

            // WHICH GRAPH OPERATIONS HAVE ALREADY BEEN APPLIED — the operation keys, and nothing else.
            //
            // Acceptance is deliberately staged ("publish the pages now, I will look at the graph in a
            // minute"), so a session can be half-applied. Without this a client re-opening one cannot
            // tell an applied proposal from a waiting one, and its only safe move is to offer the whole
            // batch again and lean on the applier answering `already_applied`. Safe, and a workaround:
            // the count it shows is wrong.
            //
            // A LIST, not a boolean. A bool would lie in both directions on exactly the case that
            // motivated it — "some of it is done" is neither true nor false.
            //
            // Only `graph:<n>` keys, because those are the only ones a client addresses. The ledger
            // also carries `entity:<n>=<uuid>` rows, which are internal bookkeeping (they record WHICH
            // entry a handle became) and name nothing the client can select.
            'applied_graph_op_keys' => $this->appliedGraphOpKeys(),

            'source_text' => $this->source_text,
            'prompt_history' => $this->instructions(),

            'seed_slug' => $this->seed_slug,
            'seed_title' => $this->seed_title,

            // When the context was last WIDENED, or null. Non-null means `expand-context` will refuse
            // (422 `knowledge_context_already_expanded`) until a refinement consumes it — so the client
            // can disable the paid action for everyone looking at this session, not just for the tab
            // that pressed it.
            'context_expanded_at' => $this->context_expanded_at?->toISOString(),

            // The duplicate warnings, keyed by draft id, read from the relation cache — never computed
            // here (an embedding batch inside a serializer would be a spend nobody asked for). Empty
            // until the relations endpoint has run once. Carried on the SESSION rather than on each
            // draft so one cache read serves the whole list instead of an N+1 of them.
            'duplicates' => KnowledgeDraftRelationService::cachedDuplicates($this->resource),

            // No `drafts_count` beside it. Every endpoint that serves a session serves its drafts too
            // — there is no list view of sessions — so the count was `whenCounted` on a relation nobody
            // counts, absent from every response, and would have been a second source for `length` if
            // anyone had wired it up.
            'drafts' => KnowledgeEntryResource::collection($this->whenLoaded('drafts')),

            'creator' => CreatorResource::make($this->whenLoaded('creator')),

            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
