<?php

namespace App\Modules\Publishing\Contracts;

use App\Modules\Publishing\DTOs\RemoteDraft;
use App\Modules\Publishing\DTOs\RemoteRef;
use App\Modules\Publishing\Enums\PublishingPlatform;
use App\Modules\Publishing\Models\Publication;

/**
 * Everything the Publishing module knows about a destination.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * THE SHAPE IS TWO PHASES, BECAUSE THE PLATFORMS ARE TWO PHASES
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * This is not an abstraction imposed on the platforms; it is the shape both target families already
 * have.
 *
 *   INSTAGRAM   POST /media creates a CONTAINER. POST /media_publish turns that container into a post.
 *   YOUTUBE     a resumable upload session URI is opened, the bytes go up, and a final call commits.
 *
 * A one-call `publish()` would have to hide the gap between them, and the gap is where the only
 * unrecoverable defect in this module lives. So it is on the contract:
 *
 *   {@see createDraft()}   makes the intermediate artifact and returns its handle. The caller persists
 *                          that handle IMMEDIATELY, in its own write, before doing anything else.
 *   {@see publishDraft()}  is given the handle back — from memory on a first run, from the DATABASE on
 *                          a resume — and turns it into a public artifact.
 *
 * An adapter for a platform that genuinely publishes in one call implements `createDraft()` as a
 * no-op returning a synthetic handle. That is the correct degradation: the caller's flow does not
 * branch on how many HTTP calls a platform happens to need.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * findExisting() IS PART OF THE CONTRACT, NOT AN EXTRA
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * It is here from the first day for the same reason the two phases are: a publication that got stuck
 * mid-publish MAY ALREADY BE A POST, and there is exactly one honest way to find out — ask the
 * platform. An adapter that cannot answer this question cannot be retried safely, and a module that
 * treated the answer as optional would be a module that retries on doubt. A doubled post is a public
 * artifact; nothing written here can un-publish it.
 *
 * An adapter whose platform offers no such lookup must NOT return null to mean "probably not". Null
 * means PROVEN ABSENT. If the platform cannot prove it, the adapter throws, the publication stays in
 * `needs_reconcile`, and a person decides — which is the correct outcome, not a gap in the design.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * HOW AN ADAPTER SAYS WHICH KIND OF FAILURE IT HAD
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * Through the exception it throws, and the distinction is the whole safety property:
 *
 *   {@see \App\Modules\Publishing\Exceptions\PlatformRefused}
 *       "The platform said no, and NOTHING WAS CREATED." Only throw this when that second clause is
 *       certain — a validation rejection, a 4xx that the platform documents as pre-creation. It sends
 *       the publication to `failed`, from which a retry is allowed.
 *
 *   ANYTHING ELSE (a timeout, a connection reset, a 5xx, an unexpected shape)
 *       "We do not know." It sends the publication to `needs_reconcile`, which no automatic path
 *       leaves. When in doubt, throw something else — the cost of being wrong that way is a person
 *       looking at a row, and the cost of being wrong the other way is a second post.
 *
 * Adapters must be CHEAP TO CONSTRUCT: they are registered lazily and resolved only when a publication
 * is actually being worked on.
 *
 * There is NO fail-soft around this contract, deliberately unlike the Calendar's source registry. A
 * missing calendar source is a degraded screen; a missing publishing adapter is a publication whose
 * destination the installation does not implement, which is a configuration error and must be loud.
 */
interface PlatformAdapter
{
    /**
     * The destination this adapter serves. Must equal the key it was registered under — the registry
     * refuses the pair when they disagree, because a mismatch produces publications no adapter can
     * ever be found for.
     */
    public function platform(): PublishingPlatform;

    /**
     * PHASE 1 — create the intermediate artifact.
     *
     * The returned handle is persisted by the caller before anything else happens. Implementations
     * must be safe to call only when the publication has no handle yet; the caller checks
     * {@see Publication::hasRemoteDraft()} and skips straight to phase 2 when it does.
     *
     * @throws \App\Modules\Publishing\Exceptions\PlatformRefused when nothing was created and that is certain
     */
    public function createDraft(Publication $publication): RemoteDraft;

    /**
     * PHASE 2 — turn the intermediate artifact into a public one.
     *
     * `$remoteDraftId` is passed explicitly rather than read off the model, so a resume cannot silently
     * publish a handle other than the one that was stored.
     *
     * @throws \App\Modules\Publishing\Exceptions\PlatformRefused when the artifact was NOT created and that is certain
     */
    public function publishDraft(Publication $publication, string $remoteDraftId): RemoteRef;

    /**
     * RECONCILIATION — does the artifact this publication would have produced already exist?
     *
     * @return RemoteRef|null a ref means it EXISTS; null means PROVEN ABSENT. An adapter that cannot
     *                        prove absence throws instead — see the class docblock.
     */
    public function findExisting(Publication $publication): ?RemoteRef;
}
