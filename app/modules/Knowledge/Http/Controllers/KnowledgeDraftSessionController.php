<?php

namespace App\Modules\Knowledge\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Knowledge\Http\Requests\AcceptKnowledgeDraftsRequest;
use App\Modules\Knowledge\Http\Requests\ExpandKnowledgeDraftContextRequest;
use App\Modules\Knowledge\Http\Requests\RebaseKnowledgeDraftRequest;
use App\Modules\Knowledge\Http\Requests\RefineKnowledgeDraftSessionRequest;
use App\Modules\Knowledge\Http\Requests\StoreKnowledgeDraftSessionRequest;
use App\Modules\Knowledge\Http\Resources\KnowledgeDraftSessionResource;
use App\Modules\Knowledge\Http\Resources\KnowledgeEntryResource;
use App\Modules\Knowledge\Http\Resources\KnowledgeRelationResource;
use App\Modules\Knowledge\Models\KnowledgeBase;
use App\Modules\Knowledge\Models\KnowledgeDraftSession;
use App\Modules\Knowledge\Models\KnowledgeEntry;
use App\Modules\Knowledge\Models\KnowledgeRelation;
use App\Modules\Knowledge\Services\KnowledgeDraftRelationService;
use App\Modules\Knowledge\Services\KnowledgeDraftSessionService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * The AI COMPOSER: open a session on raw material, watch it, refine it, accept what is good.
 *
 * Every response that carries the session carries its DRAFTS, loaded through the session's own relation
 * — which is the only place in the product that opts out of the draft-invisibility scope. That
 * concentration is deliberate: `withDrafts()` reads as an exception at every call site, so a reviewer
 * seeing it anywhere else knows immediately that something is wrong.
 *
 * The session and its drafts are workspace-scoped bindings, so a foreign id 404s at bind, before any
 * policy runs.
 */
class KnowledgeDraftSessionController extends Controller
{
    public function __construct(
        private KnowledgeDraftSessionService $service,
    ) {}

    /**
     * Whether the composer can run at all — asked BEFORE the form is rendered, so an over-cap or
     * switched-off workspace sees an explanation instead of a button that 429s.
     */
    public function availability(KnowledgeBase $base): JsonResponse
    {
        $this->authorize('view', $base);
        $this->authorize('compose', KnowledgeEntry::class);

        return response()->json($this->service->availability());
    }

    /** Open a session and queue the first composition. 201 + a session already in `generating`. */
    public function store(StoreKnowledgeDraftSessionRequest $request, KnowledgeBase $base): JsonResponse
    {
        $session = $this->service->start(
            $base,
            $request->string('source_text')->value(),
            $request->seedSlug(),
            $request->seedTitle(),
        );

        return KnowledgeDraftSessionResource::make($this->loaded($session))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /** The poll. Same shape throughout the session's life; `status` is what changes. */
    public function show(KnowledgeDraftSession $session): KnowledgeDraftSessionResource
    {
        $this->authorize('view', $session->base);

        return KnowledgeDraftSessionResource::make($this->loaded($session));
    }

    /**
     * Ask for a revision of the whole set. Answers 200 with the session; when a run is already in
     * flight the request is a no-op and the returned status says so, rather than a 409 the client
     * would have to translate back into "keep polling".
     */
    public function refine(RefineKnowledgeDraftSessionRequest $request, KnowledgeDraftSession $session): KnowledgeDraftSessionResource
    {
        return KnowledgeDraftSessionResource::make(
            $this->loaded($this->service->refine($session, $request->instruction()))
        );
    }

    /**
     * Publish the chosen drafts — PARTIALLY, on purpose.
     *
     * A shadow whose target was edited after the composer read it cannot be applied (its
     * optimistic-lock token is stale). That is reported as a CONFLICT alongside everything that did go
     * through, rather than failing the whole request: a reviewer who accepted five proposals should not
     * be told none of them happened because one target moved. 200 either way; the client shows the
     * conflicts and offers a rebase.
     */
    public function accept(AcceptKnowledgeDraftsRequest $request, KnowledgeDraftSession $session): JsonResponse
    {
        $result = $this->service->accept(
            $session,
            $request->entryIds(),
            $request->resolvedStatus(),
            $request->graphOpKeys(),
        );

        return response()->json([
            // THE ENTRIES THAT NOW HOLD WHAT THE REVIEWER APPROVED — including, for an amendment, the
            // LIVE TARGET rather than the shadow that proposed it (the shadow is purged on publication).
            //
            // There is no companion `updated` list. There was one, and it was always empty: it was
            // meant for entries the GRAPH half changed, and the graph half stopped touching entry text
            // when `wiki_updates` was routed through shadow drafts. Filling it with the targets of
            // accepted amendments would have restated this array item for item, so it was removed
            // rather than given something to say.
            'accepted' => KnowledgeEntryResource::collection(
                collect($result['accepted'])->each(fn (KnowledgeEntry $entry) => $entry->loadMissing('creator'))
            )->resolve(),
            'relations' => KnowledgeRelationResource::collection(
                collect($result['relations'])->each(fn (KnowledgeRelation $relation) => $relation->loadMissing(['fromEntry', 'toEntry', 'creator']))
            )->resolve(),
            'conflicts' => $result['conflicts'],
            // OPERATIONS THAT DID NOT HAPPEN, each with a reason. Never silent: a reviewer who approved
            // six things and got four has to be told which two and why — the target was trashed, the
            // relation had already been ended by somebody else, the entity's own draft was not in this
            // batch. Every one of those is news about the world rather than an error, which is exactly
            // why they are a list in a 200 and not an exception.
            'skipped' => $result['skipped'],
        ]);
    }

    /**
     * Point a shadow draft at the target's CURRENT revision, without asking the model anything.
     *
     * The proposal's text is untouched — this only says "compare against what is there now", which is
     * the honest division of labour: the machine cannot know whether the human's edit and the proposal
     * conflict in meaning, and the reviewer now has a diff against the real thing.
     */
    public function rebase(RebaseKnowledgeDraftRequest $request, KnowledgeDraftSession $session): KnowledgeEntryResource
    {
        $shadow = $session->drafts()
            ->whereNotNull('targets_entry_id')
            ->findOrFail($request->string('entry_id')->value());

        return KnowledgeEntryResource::make(
            $this->service->rebase($shadow)->load('targetsEntry')
        );
    }

    /**
     * Retrieve context again — one more metered embedding, which is why it is an explicit action rather
     * than something a refinement does silently. Widens the evidence for the NEXT run.
     */
    public function expandContext(ExpandKnowledgeDraftContextRequest $request, KnowledgeDraftSession $session): KnowledgeDraftSessionResource
    {
        return KnowledgeDraftSessionResource::make(
            $this->loaded($this->service->expandContext($session))
        );
    }

    /**
     * WHAT THE PROPOSAL WOULD DO TO THE BASE: the drafts, the entries they touch, and the edges
     * between them — in the SAME shape the base's own graph endpoint returns, so the client reuses one
     * canvas rather than growing a second.
     *
     * Nothing here is written to `knowledge_links`: these are previews of relations that do not exist
     * until the drafts are accepted. Computed on demand and cached on the session by a digest of the
     * drafts' text, so re-opening the panel unchanged costs no AI at all.
     */
    public function relations(KnowledgeDraftSession $session, KnowledgeDraftRelationService $relations): JsonResponse
    {
        $this->authorize('view', $session->base);
        $this->authorize('compose', KnowledgeEntry::class);

        return response()->json(['data' => $relations->relations($session)]);
    }

    /** Abandon: every draft destroyed, the session with them. */
    public function destroy(KnowledgeDraftSession $session): JsonResponse
    {
        $this->authorize('compose', KnowledgeEntry::class);

        $this->service->abandon($session);

        return response()->json(status: Response::HTTP_NO_CONTENT);
    }

    /**
     * Throw ONE draft away. Bound with `withDrafts()` explicitly — the ordinary `{entry}` binding
     * cannot see a draft at all, which is the invisibility working as intended.
     */
    public function rejectDraft(string $entry): JsonResponse
    {
        $draft = KnowledgeEntry::query()->withDrafts()->whereNotNull('draft_session_id')->findOrFail($entry);

        $this->authorize('rejectDraft', $draft);

        $this->service->reject($draft);

        return response()->json(status: Response::HTTP_NO_CONTENT);
    }

    private function loaded(KnowledgeDraftSession $session): KnowledgeDraftSession
    {
        // `drafts.targetsEntry` is eager-loaded because the resource now composes an append shadow's
        // RESULT from its target's live text — without it every shadow on the card list would fetch
        // its own target, one query per row.
        //
        // `drafts.outgoingLinks` / `.incomingLinks` are DELIBERATELY NOT loaded: a draft draws no edges
        // until it is accepted, so the query would be guaranteed empty and the `[]` it produced would
        // tell the reviewer "this proposal connects to nothing". See KnowledgeEntryResource's docblock.
        //
        // Nor is `withCount('drafts')`: `drafts` itself is on every session payload, so a count beside
        // the array it counts is a second source for the same number.
        return $session->load(['drafts.creator', 'drafts.targetsEntry', 'creator']);
    }
}
