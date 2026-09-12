<?php

namespace App\Modules\Publishing\Listeners;

use App\Http\Middleware\SetUserLocale;
use App\Models\User;
use App\Modules\Publishing\Enums\PublicationStatus;
use App\Modules\Publishing\Events\PublicationConcluded;
use App\Modules\Publishing\Mail\PublicationFailedMail;
use App\Modules\Publishing\Models\Publication;
use App\Modules\Workspaces\Models\Workspace;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * SOMEBODY IS TOLD WHEN A PUBLICATION DID NOT GO OUT (D4).
 *
 * A scheduled post that failed is the one outcome in this module that nobody is looking at when it
 * happens: it is concluded by a sweep's worker at 09:00 on a Sunday, and until now the only way to learn
 * of it was to open the publications screen and notice a badge. So the module sends a letter — the third
 * operational mail of the product, after the workspace invitation and the password reset.
 *
 * ═════════════════════════════════════════════════════════════════════════════════════════════════
 * `failed` ONLY, AND THE OTHER TWO ABSENCES ARE DECISIONS
 * ═════════════════════════════════════════════════════════════════════════════════════════════════
 * {@see PublicationConcluded} announces four outcomes. This listener answers exactly one of them:
 *
 *   failed            → A LETTER. The platform was asked and proved nothing was created, so there is a
 *                       true sentence to write ("nothing was sent") and an action worth taking.
 *   published         → nothing. Success is what was asked for.
 *   blocked           → NOTHING, DELIBERATELY. A hold is not an event about one publication: one broken
 *                       connection blocks everything queued behind it, so a mail per row would be a mail
 *                       storm about a single fact. It already carries the `needs_attention` badge and the
 *                       connection banner, which name the remedy where the remedy is.
 *   review rejected   → nothing. A person decided, in the application, and the author sees it there.
 *
 * `needs_reconcile` is not announced at all (see the event), and would be the WRONG thing to mail even if
 * it were: the letter would have to say "we do not know", which is a notification that cannot be acted on
 * and would arrive far more often than a failure.
 *
 * THESE ARE THE OWNER'S OPTIONS FOR LATER, NAMED so the next person does not have to re-derive them: a
 * hold mail would want to be PER CONNECTION and sent by the connection manager, not per publication; a
 * `needs_reconcile` mail would want a delay, so the automatic probe has had its chance to answer first.
 * Neither is D4.
 *
 * ═════════════════════════════════════════════════════════════════════════════════════════════════
 * IT NEVER THROWS, AND IT NEVER WAITS FOR AN SMTP SERVER
 * ═════════════════════════════════════════════════════════════════════════════════════════════════
 * This runs INSIDE whatever concluded the publication — the publish worker, a sweep pass, a person's
 * reconcile request — exactly like the Workflows listener on the same event. Two consequences, both
 * load-bearing:
 *
 *   NOTHING ESCAPES. Everything is wrapped and merely reported. A failure here would fail the thing that
 *   has just talked to a platform, and the publication's own record of what happened is worth more than
 *   any notification about it.
 *
 *   THE MAIL IS QUEUED, never sent inline. `Mail::to()->queue()` writes a job and returns; an SMTP dialogue
 *   in the publish worker would hold a claimed publication open for the length of somebody else's network
 *   problem, and the job's timeout is deliberately ordered against the queue's redelivery window
 *   ({@see \App\Modules\Publishing\Jobs\PublishPublicationJob}). A mail that cannot be delivered retries on
 *   the queue, where a retry is free — unlike a publish.
 *
 *   CONSEQUENTLY IT NEEDS A LIVE `php artisan queue:work`. Not a new requirement: the module's only
 *   publishing path is already a queued job, so an installation with no worker was never publishing
 *   anything. It is named here because the failure mode is quiet — the letter sits in `jobs` and the
 *   publication looks correctly failed from every screen.
 *
 * ═════════════════════════════════════════════════════════════════════════════════════════════════
 * WHY IT LIVES IN PUBLISHING AND IS REGISTERED FROM PUBLISHING'S OWN PROVIDER
 * ═════════════════════════════════════════════════════════════════════════════════════════════════
 * The event and the listener are in the SAME module, so this adds no module edge at all — nothing like
 * the Workflows listener, which exists precisely because Publishing may never name Workflows
 * (`WorkflowsPublishingBoundaryTest`). Telling somebody that their own publication failed is Publishing's
 * own business, and the only names it needs outside the module are shared infrastructure: the tenancy
 * context, the `User`/`Workspace` pair that says who is answerable, and the app-level locale rule.
 *
 * ═════════════════════════════════════════════════════════════════════════════════════════════════
 * ONE CONCLUSION, ONE LETTER
 * ═════════════════════════════════════════════════════════════════════════════════════════════════
 * The event is raised AFTER the conditional update wins (`PublicationManager::transition()`), so of two
 * processes concluding one publication exactly one announces and exactly one letter is queued. A
 * deliberate retry that fails again is a NEW fact and a new letter, which is correct: somebody chose to
 * try, and the outcome of their choice is worth telling them.
 *
 * THE ROW IS RE-READ ON THE AMBIENT CONNECTION, and no tenant is activated here. Whatever concluded this
 * publication has the right connection configured by construction — it just wrote to the row — while
 * `TenantContext::set()` from inside a sweep's per-workspace loop would rewrite the caller's own context
 * mid-pass. The announced workspace id is still checked against the row ({@see belongsToAnnouncedWorkspace})
 * so a future caller that delivers this event under a foreign context declines to write a letter instead
 * of writing one about the wrong workspace's publication.
 */
class MailFailureOnPublicationConcluded
{
    public function handle(PublicationConcluded $event): void
    {
        try {
            if ($event->status !== PublicationStatus::FAILED->value) {
                return;
            }

            $publication = Publication::query()->find($event->publicationId);

            if ($publication === null) {
                // Trashed or purged between the conclusion and this line — OR hidden by the workspace
                // scope, because an ACTIVE workspace other than the announced one makes `find()` return
                // null before the ownership guard below ever runs. The message names both readings so a
                // production line does not send a reader chasing a deletion that never happened; the
                // announced id is what lets them tell the two apart against the active context.
                Log::warning('A failed publication could not be read back (deleted, or outside the active workspace\'s scope); no mail was sent.', [
                    'publication' => $event->publicationId,
                    'announced_workspace_id' => $event->workspaceId,
                ]);

                return;
            }

            if (!$this->belongsToAnnouncedWorkspace($publication, $event->workspaceId)) {
                Log::warning('A publication conclusion named a workspace the row does not belong to; no mail was sent.', [
                    'publication' => $event->publicationId,
                    'announced_workspace_id' => $event->workspaceId,
                ]);

                return;
            }

            $workspace = Workspace::find($event->workspaceId);

            if ($workspace === null) {
                Log::warning('A publication failed in a workspace that no longer exists; no mail was sent.', [
                    'publication' => $event->publicationId,
                    'workspace_id' => $event->workspaceId,
                ]);

                return;
            }

            $recipient = $this->recipient($publication, $workspace);

            if ($recipient === null) {
                // NOT AN EXCEPTION. "Nobody can be told" is a state this product can genuinely be in — a
                // workflow run created the publication and the workspace owner's account is gone — and the
                // publication's own `failed` record is unaffected by the absence of a reader.
                Log::warning('A publication failed and no recipient could be established; no mail was sent.', [
                    'publication' => $publication->id,
                    'workspace_id' => $workspace->id,
                ]);

                return;
            }

            Mail::to($recipient->email)
                ->locale(SetUserLocale::accountLocale($recipient))
                ->queue(new PublicationFailedMail(
                    recipientName: $recipient->name,
                    publicationId: (string) $publication->getKey(),
                    title: (string) $publication->title,
                    platform: $publication->platform->value,
                    failureCode: $publication->failure_code,
                    plannedForIso: $publication->intendedPublishAt()?->toISOString(),
                    timezone: $this->timezoneOf($workspace),
                ));
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * WHO IS ANSWERABLE FOR THIS PUBLICATION — the human creator, else the workspace owner.
     *
     * The `HasCreator` doctrine (ADR-0015), applied to a letter. A publication made by a workflow run or a
     * bot is a SYSTEM RECORD that nobody owns: `creatorUser()` answers null for it by design, and the
     * escalation is the workspace owner — the same widening `PublicationPolicy` already makes when it
     * decides who may retry a publication. Whoever may act on it is whoever is told about it.
     *
     * MEMBERSHIP IS RE-CHECKED, and that is not defensive noise. `HasCreator::creator()` deliberately
     * bypasses `WorkspaceMemberScope` so a creator who has LEFT still resolves on screen — right for
     * rendering a record's authorship, wrong for posting them a letter containing the title of a post
     * they can no longer open. An ex-member's failure notice goes to the owner instead.
     */
    private function recipient(Publication $publication, Workspace $workspace): ?User
    {
        $creator = $publication->creatorUser();

        if ($creator !== null && $workspace->hasMember($creator)) {
            return $creator;
        }

        // Through the member scope rather than the `owner` relation: with a workspace active the relation
        // would be filtered by membership, and the owner is not guaranteed a pivot row.
        return User::query()->withoutWorkspaceMemberScope()->find($workspace->owner_id);
    }

    /**
     * ONE workspace id, read the same two ways {@see PublicationConcluded} reads it.
     *
     * During an own-database pass a workspace is ACTIVE and the row has no `workspace_id` column at all;
     * during the shared pass no workspace is active on purpose and the answer is on the row. Both agree
     * with the announcement today — this is the pin that says so, and it fails CLOSED: an unattributable
     * row sends no letter rather than a letter about somebody else's publication.
     */
    private function belongsToAnnouncedWorkspace(Publication $publication, string $workspaceId): bool
    {
        $actual = app(TenantContext::class)->id()
            ?? $publication->getAttribute('workspace_id');

        return is_string($actual) && $actual === $workspaceId;
    }

    /**
     * The clock the letter states its moment on: the WORKSPACE's, else the application's.
     *
     * The same rule (and the same fallback for an unusable stored value) as
     * {@see \App\Modules\Calendar\Services\CalendarTimezoneResolver}, which cannot be called here because
     * it reads the AMBIENT workspace through the tenancy context — and this listener can run in the due
     * sweep's shared pass, where NO workspace is active and the one that owns the publication is a
     * constructor argument away. Falling back to `app.timezone` there would stamp a Warsaw team's letter
     * with UTC hours while every screen shows 09:00.
     */
    private function timezoneOf(Workspace $workspace): string
    {
        $fallback = (string) config('app.timezone', 'UTC');
        $timezone = $workspace->timezone;

        if (!is_string($timezone) || $timezone === '') {
            return $fallback;
        }

        if (!in_array($timezone, timezone_identifiers_list(), true)) {
            Log::warning('Workspace has an unusable timezone; a failure mail falls back to the application timezone.', [
                'workspace_id' => $workspace->id,
                'timezone' => $timezone,
            ]);

            return $fallback;
        }

        return $timezone;
    }
}
