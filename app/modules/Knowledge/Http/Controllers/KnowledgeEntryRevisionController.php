<?php

namespace App\Modules\Knowledge\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Knowledge\Http\Resources\KnowledgeEntryRevisionResource;
use App\Modules\Knowledge\Models\KnowledgeEntry;
use App\Modules\Knowledge\Services\KnowledgeEntryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * An entry's version HISTORY — readable, and read-only.
 *
 * The history is what the composer wrote and a person approved, version by version, and reading it is
 * how anybody audits that. RESTORING a revision is gone: it republishes an old body as the entry's
 * current text, which is authoring the entry by another name.
 *
 * `draftDiff` stays and is not an exception to that: it returns the two TEXTS a reviewer compares
 * before deciding, and writes nothing.
 */
class KnowledgeEntryRevisionController extends Controller
{
    public function __construct(
        private KnowledgeEntryService $service,
    ) {}

    public function index(KnowledgeEntry $entry): AnonymousResourceCollection
    {
        $this->authorize('view', $entry);

        return KnowledgeEntryRevisionResource::collection($this->service->revisions($entry));
    }

    /**
     * The TWO texts a draft diff compares — the server returns versions, the client renders the diff.
     *
     * `baseline=original` is the entry as first generated (its OLDEST revision); `previous` is the
     * state before the last refinement. Two baselines because a reviewer asks two different questions:
     * "what has the composer done to this since it started" and "what did my last instruction change".
     *
     * Deliberately NOT a computed diff. A character-level diff is a rendering decision — inline vs
     * side-by-side, word vs line granularity — and computing it here would freeze one choice into the
     * API and ship a diff library on the server to do work the browser already does well.
     *
     * Bound with withDrafts(): the whole point is inspecting an entry the ordinary binding cannot see.
     */
    public function draftDiff(Request $request, string $entry): JsonResponse
    {
        $target = KnowledgeEntry::query()->withDrafts()->findOrFail($entry);

        $this->authorize('view', $target);

        $requested = $request->string('baseline')->value();
        $baseline = match (true) {
            // A SHADOW compares against the ENTRY IT AMENDS, at the revision the composer read — which
            // is the only comparison a reviewer of an amendment actually wants. Falls back to the
            // target's current revision when the frozen one is gone (a purged history), so the panel
            // still has something honest to show.
            $requested === 'target' && $target->isShadow() => $target->targetsEntry
                ?->revisions()
                ->where('id', $target->target_revision_id)
                ->first()
                ?? $target->targetsEntry?->revisions()->orderByDesc('created_at')->first(),

            // The state BEFORE the newest revision; on a first generation there is nothing before it.
            $requested === 'previous' => $target->revisions()->orderBy('created_at')->orderBy('id')->get()->slice(-2, 1)->first(),

            default => $target->revisions()->orderBy('created_at')->orderBy('id')->first(),
        };

        return response()->json([
            'baseline' => in_array($requested, ['previous', 'target'], true) ? $requested : 'original',
            // Only meaningful for `target`: the entry has moved on since the composer read it, so
            // accepting would hit the optimistic lock.
            'target_revision_stale' => $target->targetRevisionIsStale(),
            'has_baseline' => $baseline !== null && (string) $baseline->getKey() !== (string) $target->current_revision_id,
            'from' => $baseline === null ? null : [
                'revision_id' => $baseline->getKey(),
                'title' => $baseline->title,
                'content' => $baseline->content,
                'created_at' => $baseline->created_at?->toISOString(),
            ],
            'to' => [
                'revision_id' => $target->current_revision_id,
                'title' => $target->title,
                // WHAT THE ENTRY WOULD SAY, not what the row happens to store.
                //
                // An APPEND shadow stores its addition alone — the property that keeps it commutative
                // with a concurrent edit — so returning the raw column made this diff read as "the
                // whole document is replaced by that one sentence". A diff that misstates the effect of
                // accepting is worse than no diff at all: the reviewer is not merely uninformed, they
                // are confidently wrong, on the one surface the whole review gate exists to serve.
                //
                // `amendedBody()` composes from the target's LIVE text, matching what publication does,
                // so the picture cannot go stale against somebody else's edit either. For a rewrite and
                // for a plain draft it returns the content unchanged — byte for byte as before.
                'content' => $target->amendedBody(),
                'created_at' => $target->updated_at?->toISOString(),
            ],
        ]);
    }

    // THERE IS NO `restore`. Republishing an old revision as the entry's current body is authoring its
    // text: the words happen to have been written before, which changes nothing about who is choosing
    // them now. The history stays READABLE — it is the record of what the AI wrote and a person
    // approved, and reading it is how anybody audits that.
}
