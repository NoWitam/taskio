<?php

namespace App\Modules\Publishing\Adapters;

use App\Modules\Publishing\Contracts\PlatformAdapter;
use App\Modules\Publishing\DTOs\RemoteDraft;
use App\Modules\Publishing\DTOs\RemoteRef;
use App\Modules\Publishing\Enums\PublicationAttemptPhase;
use App\Modules\Publishing\Enums\PublishingPlatform;
use App\Modules\Publishing\Exceptions\PlatformRefused;
use App\Modules\Publishing\Models\Publication;
use App\Modules\Publishing\Models\PublicationAttempt;
use Illuminate\Support\Str;

/**
 * A COMPLETE ADAPTER FOR A DESTINATION THAT IS THIS DATABASE.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * IT IS NOT A TEST DOUBLE, AND THE DIFFERENCE IS NOT PEDANTRY
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * A double is a thing tests substitute for the real path. This IS a real path: it is registered in the
 * container like any other adapter, selectable by a user, it goes through both phases, it writes a
 * durable record of every call, and its `findExisting()` genuinely answers from evidence rather than
 * from a canned value. Two things depend on that:
 *
 *   THE REVIEW. Meta and Google both want to see a working product before granting the permissions that
 *   let it publish. The demo runs on this adapter, end to end, with nothing leaving the building.
 *
 *   THE SUITE. Every B1–B3 test drives this. A double registered only under test conditions would
 *   exercise machinery that does not ship; this one is the shipped machinery, so a defect in the
 *   publisher, the manager or the calendar source fails here rather than on the first real token.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * WHAT IT WRITES
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * One `publication_attempts` row per call — the same table a real adapter will write, with the same
 * `request` payload discipline: what would have gone out (destination, caption, media ids, options),
 * and nothing that could authenticate it. There are no credentials in B1, which is exactly why the
 * habit is set now: B2's adapters inherit a shape rather than argue with one.
 *
 * The ids it mints are prefixed and random — `dryrun_draft_…`, `dryrun_…` — so a row that somehow
 * reached a real platform's code path would be obviously wrong instead of plausibly wrong.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * findExisting() READS ITS OWN LOG, WHICH IS THE HONEST SIMULATION
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * The question a real reconciliation asks is "does an artifact exist on the platform for this
 * publication?", and the only honest way to answer it is to consult the system of record for that
 * platform. For this adapter, that system of record is `publication_attempts`: a succeeded `publish`
 * row IS the artifact's existence.
 *
 * It deliberately does NOT read `publications.remote_id`. That column is what the reconciliation is
 * trying to establish; answering from it would make the enquiry agree with whatever we already believed
 * and never contradict us — which is the one thing a reconciliation exists to be able to do. A worker
 * killed after the platform call and before the status write leaves an attempt row and no `remote_id`,
 * and that is precisely the case this must get right.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * IT STILL REFUSES THINGS
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * A publication with no title has nothing to send. Refusing it exercises the whole
 * `PlatformRefused → failed → retry` path rather than leaving that branch untested until a real
 * platform first says no. It is thrown BEFORE any handle is minted, so the contract's "nothing was
 * created" clause is literally true.
 */
class DryRunPlatformAdapter implements PlatformAdapter
{
    /** So a stray id is obviously synthetic wherever it surfaces. */
    private const DRAFT_PREFIX = 'dryrun_draft_';

    private const PUBLISHED_PREFIX = 'dryrun_';

    public function platform(): PublishingPlatform
    {
        return PublishingPlatform::DRY_RUN;
    }

    public function createDraft(Publication $publication): RemoteDraft
    {
        $this->guardPublishable($publication, PublicationAttemptPhase::DRAFT);

        $draftId = self::DRAFT_PREFIX . Str::lower(Str::random(24));

        $this->record($publication, PublicationAttemptPhase::DRAFT, true, [
            'remote_draft_id' => $draftId,
        ]);

        return RemoteDraft::make($draftId);
    }

    /**
     * The handle arrives as an ARGUMENT and is recorded as given, never re-read from the model. On a
     * resume that argument came out of the database, and logging what was actually used is the only way
     * the trail can later show that a resume published the container it was supposed to.
     */
    public function publishDraft(Publication $publication, string $remoteDraftId): RemoteRef
    {
        $this->guardPublishable($publication, PublicationAttemptPhase::PUBLISH, $remoteDraftId);

        $remoteId = self::PUBLISHED_PREFIX . Str::lower(Str::random(20));

        $this->record($publication, PublicationAttemptPhase::PUBLISH, true, [
            'remote_draft_id' => $remoteDraftId,
            'remote_id' => $remoteId,
        ]);

        return RemoteRef::make(
            id: $remoteId,
            url: 'https://dry-run.taskio.local/p/' . $remoteId,
            publishedAt: now(),
        );
    }

    /**
     * Does an artifact already exist for this publication?
     *
     * Answered from the attempt log — see the class docblock for why not from `publications.remote_id`.
     * A null return here means PROVEN ABSENT, which this adapter can honestly say because it owns the
     * whole record of what it ever did. A real adapter whose platform offers no such lookup must throw
     * instead, leaving the row in `needs_reconcile` for a person.
     *
     * The enquiry is itself logged, because evidence about an irreversible act belongs in the same
     * trail as the act.
     */
    public function findExisting(Publication $publication): ?RemoteRef
    {
        $published = PublicationAttempt::query()
            ->where('publication_id', $publication->id)
            ->where('phase', PublicationAttemptPhase::PUBLISH)
            ->where('succeeded', true)
            ->whereNotNull('remote_id')
            ->orderByDesc('created_at')
            ->first();

        $this->record($publication, PublicationAttemptPhase::RECONCILE, true, [
            'remote_id' => $published?->remote_id,
            'remote_draft_id' => $publication->remote_draft_id,
        ]);

        if ($published === null) {
            return null;
        }

        return RemoteRef::make(
            id: $published->remote_id,
            url: 'https://dry-run.taskio.local/p/' . $published->remote_id,
            publishedAt: $published->created_at,
        );
    }

    /**
     * The one refusal this adapter makes, thrown BEFORE anything is minted so the "nothing was created"
     * half of {@see PlatformRefused}'s contract is literally true.
     *
     * The failed attempt is recorded first: an attempt that was made and refused is still something that
     * happened, and the trail is the thing that has to be able to say so.
     */
    private function guardPublishable(Publication $publication, PublicationAttemptPhase $phase, ?string $draftId = null): void
    {
        if (trim((string) $publication->title) !== '') {
            return;
        }

        $this->record($publication, $phase, false, [
            'remote_draft_id' => $draftId,
            'failure_code' => 'title_missing',
        ]);

        throw new PlatformRefused('title_missing');
    }

    /**
     * One row of the trail.
     *
     * `request` is WHAT WOULD HAVE BEEN SENT, assembled here rather than handed in, so every phase
     * records the same shape and nobody has to remember to include the media on one of them. Media are
     * the Disk IDS ONLY — this module never dereferences them, and a payload carrying resolved paths or
     * signed URLs would be both a Disk dependency and, later, a credential in a log.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function record(Publication $publication, PublicationAttemptPhase $phase, bool $succeeded, array $overrides = []): void
    {
        PublicationAttempt::create($overrides + [
            'publication_id' => $publication->id,
            'platform' => $publication->platform,
            'phase' => $phase,
            'succeeded' => $succeeded,
            'attempt' => max(1, $publication->attempts),
            'request' => [
                'platform' => $publication->platform->value,
                'title' => $publication->title,
                'body' => $publication->body,
                // IDS ONLY. See the method docblock.
                'media' => $publication->media,
                'options' => $publication->options,
                'scheduled_at' => $publication->scheduled_at?->toISOString(),
            ],
        ]);
    }
}
