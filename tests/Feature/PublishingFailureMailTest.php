<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Publishing\DTOs\RemoteRef;
use App\Modules\Publishing\Enums\PublicationStatus;
use App\Modules\Publishing\Events\PublicationConcluded;
use App\Modules\Publishing\Exceptions\PublicationTransitionRefused;
use App\Modules\Publishing\Mail\PublicationFailedMail;
use App\Modules\Publishing\Managers\PublicationManager;
use App\Modules\Publishing\Models\Publication;
use App\Modules\Workflows\Models\WorkflowRun;
use App\Modules\Workspaces\Models\Workspace;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * R4 D4 — SOMEBODY IS TOLD WHEN A PUBLICATION DID NOT GO OUT.
 *
 * ═════════════════════════════════════════════════════════════════════════════════════════════════
 * THE SENTENCE EVERY TEST HERE IS A CONSEQUENCE OF
 * ═════════════════════════════════════════════════════════════════════════════════════════════════
 * A SCHEDULED POST THAT FAILED IS THE ONE OUTCOME NOBODY IS LOOKING AT WHEN IT HAPPENS. It is concluded
 * by a sweep's worker while everybody is somewhere else, so the module writes a letter — and the rules
 * below all follow from what that letter is allowed to claim and whom it may reach:
 *
 *   - `failed` ONLY. It is the status whose contract is "the platform was asked and PROVED nothing was
 *     created", which is the only state in which "nothing was sent" is a true sentence to send somebody.
 *   - one conclusion, ONE letter — the announcement is raised after the conditional update wins, so the
 *     loser of a race writes nothing.
 *   - the human creator, else the WORKSPACE OWNER, because a publication made by a workflow run is a
 *     system record nobody owns (ADR-0015) and the owner is who may retry it.
 *   - the recipient's OWN language, because a letter is read in an inbox and not on the screen that
 *     happened to cause it.
 *   - the failure sentence comes from the EXISTING `publishing.failures.*` catalog, and an unrecognised
 *     code names itself rather than putting a raw translation key in somebody's mailbox.
 *   - nothing from `failure_context`, no exception class, nothing that came near a credential.
 *
 * EVERY PUBLICATION HERE IS ON `dry_run`, AND THE LETTERS ARE STILL SENT. That is the decision, not an
 * artefact of the fixtures: `dry_run` is a complete adapter rather than a stub, so the owner's own
 * environment test produces a real failure and a real letter about it — which is how the mail gets
 * exercised before a real platform is ever connected.
 *
 * ═════════════════════════════════════════════════════════════════════════════════════════════════
 * EVERY PROSE ASSERTION NAMES ITS LANGUAGE. THIS IS NOT STYLE.
 * ═════════════════════════════════════════════════════════════════════════════════════════════════
 * There is no `.env.testing`, so the suite inherits the developer's `APP_LOCALE` — which on this machine
 * is `pl` and in CI is `en`. A test asserting an English sentence while relying on the installation
 * default would pass here and fail there (or the reverse), for a reason nothing in the diff would name.
 * So each recipient's `users.locale` is SET, and each expectation is read from the catalog with that same
 * locale passed explicitly.
 */
class PublishingFailureMailTest extends TestCase
{
    use RefreshDatabase;

    /** Not the application default, so a timezone fallback can never look like a success. */
    private const WORKSPACE_TIMEZONE = 'Europe/Warsaw';

    private User $owner;

    private User $creator;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.timezone' => 'UTC']);
        $this->assertNotSame(self::WORKSPACE_TIMEZONE, config('app.timezone'));

        // Both accounts state a language, so every sentence below is asserted against a chosen locale
        // rather than against whatever `.env` happens to say (see the class docblock).
        $this->owner = User::factory()->create(['locale' => 'en']);
        $this->creator = User::factory()->create(['locale' => 'en']);

        $this->workspace = Workspace::factory()->create([
            'owner_id' => $this->owner->id,
            'timezone' => self::WORKSPACE_TIMEZONE,
        ]);
        $this->workspace->users()->attach([$this->owner->id, $this->creator->id]);

        app(TenantContext::class)->set($this->workspace);
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();

        parent::tearDown();
    }

    // ---------------------------------------------------------------- helpers

    /** A publication in flight, i.e. one the machine can conclude as `failed`. */
    private function inFlight(array $attributes = []): Publication
    {
        return Publication::factory()->publishing()->create($attributes + [
            'title' => 'The Monday teaser',
            'creator_id' => $this->creator->id,
        ]);
    }

    /** The one letter that was queued, as the worker would render it. */
    private function theLetter(): PublicationFailedMail
    {
        Mail::assertQueued(PublicationFailedMail::class, 1);

        /** @var PublicationFailedMail $letter */
        $letter = Mail::queued(PublicationFailedMail::class)->first();

        return $letter;
    }

    /**
     * The rendered body, in the language the letter was addressed in.
     *
     * `Mailable::render()` wraps itself in `withLocale($this->locale)`, which is the property
     * `Mail::to()->locale()` sets — so this asserts the same bytes the recipient would receive.
     */
    private function bodyOf(Mailable $letter): string
    {
        return $letter->render();
    }

    /**
     * The subject line as DELIVERY would resolve it.
     *
     * `envelope()` is NOT self-localizing, and this is the one asymmetry in the mailable contract worth
     * a helper: `Mailable::send()` wraps the whole preparation — envelope included — in
     * `withLocale($this->locale)`, so the recipient really does get their own language in the subject.
     * Calling `envelope()` bare from a test resolves in whatever the SUITE is speaking, which is the
     * developer's `APP_LOCALE`. Asserting on that is precisely the blind i18n assertion this file's
     * docblock warns about: it would pass on a Polish installation and fail in CI, for a reason nothing
     * in the diff names.
     */
    private function subjectOf(Mailable $letter): string
    {
        $original = app()->getLocale();
        app()->setLocale((string) $letter->locale);

        try {
            return (string) $letter->envelope()->subject;
        } finally {
            app()->setLocale($original);
        }
    }

    // ---------------------------------------------------------------- the letter

    /**
     * THE WHOLE OF IT, ONCE: a failure reaches the human who made the publication, and the letter says
     * what failed, where it was going, why, when it was meant to go out, and where to look.
     *
     * The link is asserted as a PATH rather than an absolute URL: `config('app.url')` is environment, the
     * route is a product decision (`next.publishing.publication`, whose bare record redirects to its
     * default section — so the letter survives a section being renamed).
     */
    public function test_a_failed_publication_is_mailed_to_the_human_who_made_it(): void
    {
        $publication = $this->inFlight();

        Mail::fake();

        app(PublicationManager::class)->markFailed($publication, 'title_missing');

        Mail::assertQueued(
            PublicationFailedMail::class,
            fn (PublicationFailedMail $mail) => $mail->hasTo($this->creator->email),
        );

        $letter = $this->theLetter();
        $body = $this->bodyOf($letter);

        $this->assertSame(
            __('publishing.mail.failed.subject', ['title' => 'The Monday teaser'], 'en'),
            $this->subjectOf($letter),
        );

        $this->assertStringContainsString('The Monday teaser', $body);
        $this->assertStringContainsString(__('publishing.platforms.dry_run', [], 'en'), $body);
        $this->assertStringContainsString(__('publishing.failures.title_missing', [], 'en'), $body);
        $this->assertStringContainsString('/next/publishing/publications/' . $publication->id, $body);

        // NOT A SENT MAIL. An SMTP dialogue inside the thing that has just concluded a publication would
        // hold a claimed row open for the length of somebody else's network problem.
        Mail::assertNothingSent();
    }

    /**
     * A PUBLICATION NOBODY OWNS IS THE WORKSPACE OWNER'S TO HEAR ABOUT.
     *
     * The `HasCreator` doctrine applied to a letter: a `workflow_run` creator is a system record with no
     * human behind it, and the escalation is the same widening `PublicationPolicy` makes when it decides
     * who may retry the publication. Whoever may act on it is whoever is told.
     */
    public function test_a_publication_made_by_a_workflow_run_is_mailed_to_the_workspace_owner(): void
    {
        $run = WorkflowRun::factory()->create();

        $publication = $this->inFlight();
        $publication->forceFill([
            'creator_id' => $run->id,
            'creator_type' => $run->getMorphClass(),
        ])->save();

        Mail::fake();

        app(PublicationManager::class)->markFailed($publication->fresh(), 'title_missing');

        Mail::assertQueued(
            PublicationFailedMail::class,
            fn (PublicationFailedMail $mail) => $mail->hasTo($this->owner->email)
                && !$mail->hasTo($this->creator->email),
        );

        $this->assertStringContainsString($this->owner->name, $this->bodyOf($this->theLetter()));
    }

    /**
     * A CREATOR WHO HAS LEFT THE WORKSPACE IS NOT WRITTEN TO — the owner is.
     *
     * `HasCreator::creator()` deliberately bypasses `WorkspaceMemberScope` so a departed author still
     * resolves on screen. That is right for rendering authorship and wrong for posting somebody the title
     * of a post they can no longer open, so the listener re-checks membership.
     *
     * ─────────────────────────────────────────────────────────────────────────────────────────────
     * IT IS CONCLUDED WITH NO WORKSPACE ACTIVE, AND THAT IS THE ENTIRE POINT OF THE FIXTURE
     * ─────────────────────────────────────────────────────────────────────────────────────────────
     * `WorkspaceMemberScope` is inert when no workspace is active — which is exactly the posture
     * `SweepsEveryWorkspace` runs its SHARED pass in, deliberately, so one query covers every shared
     * workspace at once. That is also where most of these letters come from: a post that failed at 09:00
     * on a Sunday was concluded by the due sweep, not by anybody's request.
     *
     * So in the only path that matters here the scope hides nobody, the departed creator resolves in
     * full, and THE MEMBERSHIP CHECK IS THE ONLY THING standing between a workspace's content and an
     * ex-member's inbox. Concluded under an active workspace the same fixture passes either way (the
     * scope resolves the creator to null and the letter escalates for the wrong reason), which is how
     * this guard nearly shipped untested.
     */
    public function test_a_creator_who_has_left_the_workspace_is_not_written_to(): void
    {
        $departed = User::factory()->create(['locale' => 'en']);

        $publication = $this->inFlight(['creator_id' => $departed->id]);

        // The due sweep's shared pass: no workspace active, the row's own column says which one it is.
        app(TenantContext::class)->clear();

        Mail::fake();

        app(PublicationManager::class)->markFailed($publication, 'title_missing');

        Mail::assertQueued(
            PublicationFailedMail::class,
            fn (PublicationFailedMail $mail) => $mail->hasTo($this->owner->email)
                && !$mail->hasTo($departed->email),
        );
    }

    /**
     * NO RECIPIENT AT ALL IS A NO-OP, NOT AN EXCEPTION — and not a reported one either.
     *
     * "Nobody can be told" is a state this product can genuinely reach — a workflow run made the
     * publication and the owner's account is gone — and the publication's own `failed` record must not
     * depend on there being a reader. The conclusion still stands; only the letter is missing.
     *
     * `Exceptions::fake()` is what gives this test teeth. The listener catches everything and reports it,
     * so WITHOUT the recipient guard the run would still end with a failed row and an empty mailbox —
     * this test would pass while a `TypeError` was being reported on every unattributable failure, once
     * per conclusion, in a log somebody pays to store. Asserting that nothing was reported is the
     * difference between "it did not crash" and "it decided".
     */
    public function test_a_failure_nobody_can_be_told_about_still_concludes(): void
    {
        Exceptions::fake();

        $publication = $this->inFlight();
        $publication->forceFill([
            'creator_id' => (string) Str::uuid7(),
            'creator_type' => 'workflow_run',
        ])->save();

        // The owner's row removed from under the workspace, leaving `owner_id` pointing at nothing.
        User::query()->withoutWorkspaceMemberScope()->whereKey($this->owner->id)->delete();

        Mail::fake();

        $concluded = app(PublicationManager::class)->markFailed($publication->fresh(), 'title_missing');

        $this->assertSame(PublicationStatus::FAILED, $concluded->status);
        Mail::assertNothingQueued();
        Mail::assertNothingSent();
        Exceptions::assertNothingReported();
    }

    // ---------------------------------------------------------------- what is NOT mailed

    /**
     * ═════════════════════════════════════════════════════════════════════════════════════════════
     * `failed` ONLY. THE OTHER OUTCOMES ARE SILENT, AND EACH ABSENCE IS A DECISION.
     * ═════════════════════════════════════════════════════════════════════════════════════════════
     *
     *   published        success is what was asked for.
     *   blocked          one broken connection holds EVERYTHING queued behind it, so a letter per row is
     *                    a storm about a single fact. It has the `needs_attention` badge and the
     *                    connection banner, which name the remedy where the remedy is.
     *   needs_reconcile  not announced at all, and it would be the wrong thing to say: the letter would
     *                    have to read "we do not know".
     *   review rejected  a person decided, in the application, where the author is already looking.
     *
     * MUTATION-CHECKED: adding `PublicationStatus::BLOCKED` to the listener's one condition turns this
     * red on the blocked leg. Without the leg, the whole D4 scope could widen unnoticed.
     */
    public function test_no_other_conclusion_writes_a_letter(): void
    {
        $manager = app(PublicationManager::class);

        Mail::fake();

        $manager->markPublished($this->inFlight(), RemoteRef::make('dryrun_out_in_the_world'));
        $manager->markNeedsReconcile($this->inFlight(), 'publish_outcome_unknown');
        $manager->block(
            Publication::factory()->scheduled()->create(['creator_id' => $this->creator->id]),
            'connection_needs_reauth',
        );

        // The fourth conclusion is not a transition at all — a review said no and the row stayed a draft
        // — so it is raised where the model raises it, by the only other caller of the event.
        PublicationConcluded::fromRejectedReview(
            Publication::factory()->create(['creator_id' => $this->creator->id]),
        );

        Mail::assertNothingQueued();
        Mail::assertNothingSent();
    }

    /**
     * THE LOSER OF A CONCLUDING RACE WRITES NO SECOND LETTER.
     *
     * The event is raised AFTER the conditional update inside `PublicationManager::transition()`, so a
     * caller holding a stale copy throws `lostRace` before it can announce anything. This is the property
     * that makes "one conclusion, one letter" true without the listener having to deduplicate — the same
     * interleave `PublishingQueueTest::test_a_stale_copy_cannot_write_over_a_publication_that_was_already_published`
     * scripts for the row itself, asserted here for the mailbox.
     *
     * A DELIBERATE RETRY THAT FAILS AGAIN IS A NEW FACT and does get a new letter. That is not a flood:
     * somebody chose to try, and the outcome of their choice is the thing worth telling them.
     */
    public function test_the_loser_of_a_concluding_race_writes_no_second_letter(): void
    {
        $publication = $this->inFlight();

        // A SECOND in-memory copy of the same row, taken while it is still in flight — what a redelivered
        // job or a racing sweep pass holds.
        $stale = Publication::query()->findOrFail($publication->id);

        Mail::fake();

        app(PublicationManager::class)->markFailed($publication, 'title_missing');

        try {
            app(PublicationManager::class)->markFailed($stale, 'title_missing');
            $this->fail('the stale copy was allowed to conclude a publication that was already concluded');
        } catch (PublicationTransitionRefused) {
            // Expected: the conditional update matched no row.
        }

        Mail::assertQueued(PublicationFailedMail::class, 1);
    }

    /**
     * A PUBLICATION TRASHED BETWEEN FAILING AND THE LETTER IS A SILENT NO-OP.
     *
     * The listener runs inside whatever concluded the publication, so a throw here would fail the thing
     * that has just talked to a platform. The row can legitimately be gone by the time it looks: somebody
     * deleted it, or a purge ran. Nothing is sent, nothing is raised, and the log carries the reason.
     *
     * The event is dispatched by hand because that is the only way to reach this window: the Manager
     * cannot conclude a row that is already trashed.
     */
    public function test_a_publication_trashed_before_the_letter_is_written_is_a_silent_no_op(): void
    {
        $publication = Publication::factory()->failed()->create(['creator_id' => $this->creator->id]);
        $publication->delete();

        Mail::fake();

        PublicationConcluded::dispatch(
            $this->workspace->id,
            $publication->id,
            PublicationStatus::FAILED->value,
        );

        Mail::assertNothingQueued();
        Mail::assertNothingSent();
    }

    /**
     * A CONCLUSION ANNOUNCED FOR A FOREIGN WORKSPACE WRITES NOTHING.
     *
     * Nothing produces this today — every raiser reads the workspace the same two ways the listener does
     * — which is exactly why it is pinned. A future caller that delivers this event under a context it
     * did not come from must decline to write a letter rather than write one about another workspace's
     * publication, naming its title to somebody who has no business reading it.
     */
    public function test_a_conclusion_announced_for_another_workspace_writes_nothing(): void
    {
        $publication = Publication::factory()->failed()->create(['creator_id' => $this->creator->id]);

        $other = Workspace::factory()->create(['owner_id' => $this->owner->id]);

        Mail::fake();

        PublicationConcluded::dispatch($other->id, $publication->id, PublicationStatus::FAILED->value);

        Mail::assertNothingQueued();
        Mail::assertNothingSent();
    }

    // ---------------------------------------------------------------- what the letter contains

    /**
     * THE LETTER IS WRITTEN IN THE RECIPIENT'S LANGUAGE — their stored choice, never the request's.
     *
     * A Polish account gets Polish prose out of a conclusion reached by a worker that has no request and
     * no screen behind it. The locale rides on the mailable (`Mail::to()->locale()`), which is what makes
     * the subject line follow it too, and `render()` re-applies it exactly as delivery does.
     */
    public function test_the_letter_is_written_in_the_recipients_own_language(): void
    {
        $this->creator->forceFill(['locale' => 'pl'])->save();

        $publication = $this->inFlight();

        Mail::fake();

        app(PublicationManager::class)->markFailed($publication, 'title_missing');

        $letter = $this->theLetter();

        $this->assertSame('pl', $letter->locale);
        $this->assertSame(
            __('publishing.mail.failed.subject', ['title' => 'The Monday teaser'], 'pl'),
            $this->subjectOf($letter),
        );

        $body = $this->bodyOf($letter);

        $this->assertStringContainsString(__('publishing.failures.title_missing', [], 'pl'), $body);
        $this->assertStringContainsString(__('publishing.mail.failed.action', [], 'pl'), $body);

        // And NOT the other language, which is the half that catches a letter rendered in whatever the
        // concluding process happened to be speaking.
        $this->assertStringNotContainsString(__('publishing.failures.title_missing', [], 'en'), $body);
    }

    /**
     * THE MOMENT IS STATED ON THE WORKSPACE'S CLOCK, and the zone is named.
     *
     * Every publishing screen renders an instant against the workspace timezone (`publishingTime.ts`
     * formats against `store.timezone`), so a letter in UTC would describe a 09:00 post as 07:00 and be
     * read as a different plan. The workspace here is deliberately not on the application timezone.
     */
    public function test_the_moment_is_stated_on_the_workspace_clock(): void
    {
        $publication = $this->inFlight();

        Mail::fake();

        app(PublicationManager::class)->markFailed($publication, 'title_missing');

        $body = $this->bodyOf($this->theLetter());

        // 09:00 UTC on 10 September is 11:00 in Warsaw.
        $this->assertStringContainsString('2026-09-10 11:00', $body);
        $this->assertStringContainsString(self::WORKSPACE_TIMEZONE, $body);
        $this->assertStringNotContainsString('2026-09-10 09:00', $body);
    }

    /**
     * AN UNRECOGNISED CODE NAMES ITSELF. It never puts a raw translation key in somebody's mailbox.
     *
     * B4 adds adapter codes this build has never heard of, and the catalog is the list of codes the
     * server can explain — so the lookup asks the catalog rather than carrying a second copy of the list.
     * The floor sentence quotes the code, which is what makes a support conversation possible.
     */
    public function test_an_unrecognised_failure_code_names_itself(): void
    {
        $publication = $this->inFlight();

        Mail::fake();

        app(PublicationManager::class)->markFailed($publication, 'quota_exhausted_by_a_future_adapter');

        $body = $this->bodyOf($this->theLetter());

        $this->assertStringContainsString(
            __('publishing.failures.unknown', ['code' => 'quota_exhausted_by_a_future_adapter'], 'en'),
            $body,
        );
        $this->assertStringContainsString('quota_exhausted_by_a_future_adapter', $body);
        $this->assertStringNotContainsString('publishing.failures', $body);
    }

    /**
     * ═════════════════════════════════════════════════════════════════════════════════════════════
     * THE LETTER CARRIES NO `failure_context`, NO EXCEPTION, AND NOTHING THAT CAME NEAR A CREDENTIAL.
     * ═════════════════════════════════════════════════════════════════════════════════════════════
     *
     * The column is schema-less and today holds `{exception: 'Illuminate\…'}` alongside operational
     * numbers. An exception class name is not a message for a person; it is an implementation detail
     * being forwarded, unredactably, into an inbox and through whatever relays it. The UI applies the
     * same whitelist rule for the same reason. This scans the bytes rather than trusting the template,
     * because the failure mode is somebody adding one helpful line to a Blade file.
     */
    public function test_the_letter_never_carries_the_failure_context_or_an_exception(): void
    {
        $publication = $this->inFlight();

        Mail::fake();

        app(PublicationManager::class)->markFailed($publication, 'title_missing', [
            'exception' => 'Illuminate\\Database\\QueryException',
            'stale_after_seconds' => 900,
            'account_hint' => 'ya29.a0AfB_secret_looking_string',
        ]);

        $body = $this->bodyOf($this->theLetter());

        foreach ([
            'QueryException',
            'Illuminate',
            'stale_after_seconds',
            'account_hint',
            'ya29.a0AfB_secret_looking_string',
            'failure_context',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $body, "the letter leaked [{$forbidden}]");
        }

        // NOT VACUOUS: the same render does contain the things it is supposed to.
        $this->assertStringContainsString('The Monday teaser', $body);
    }

    // ---------------------------------------------------------------- the D4 review's follow-ups

    /**
     * A RECIPIENT WHO NEVER TOUCHED THE LANGUAGE SWITCH gets the INSTALLATION's language — and the two
     * configs are set DIVERGENT here on purpose. `App::setLocale()` writes `config('app.locale')`
     * (ADR-0053 addendum), so a fallback read from there would be the language of whoever happened to
     * make the last request; on a machine where both configs agree the wrong read is invisible, which
     * is exactly how the review's locale mutation stayed green against this whole file.
     */
    public function test_a_recipient_with_no_chosen_language_gets_the_installations(): void
    {
        config(['app.locale' => 'en', 'app.default_locale' => 'pl']);

        $this->creator->forceFill(['locale' => null])->save();

        $publication = $this->inFlight();

        Mail::fake();

        app(PublicationManager::class)->markFailed($publication, 'title_missing');

        $letter = $this->theLetter();

        $this->assertSame('pl', $letter->locale, 'null locale must fall back to the INSTALLATION default, never to app.locale');
    }

    /**
     * THE MAILABLE CARRIES ONLY SCALARS — pinned by reflection, because the whole tenancy argument for
     * queueing it rests on this. A model added to the constructor tomorrow would ride through
     * `SerializesModels`, re-query in a worker whose dispatch context is deliberately EMPTY for the
     * shared pass, and for an own-database workspace read the CENTRAL tables. Every test here would
     * stay green; the only thing that would catch it is the tenant twin, which never runs.
     */
    public function test_the_mailable_constructor_accepts_only_scalars(): void
    {
        $constructor = new \ReflectionMethod(PublicationFailedMail::class, '__construct');

        foreach ($constructor->getParameters() as $parameter) {
            $type = $parameter->getType();

            $this->assertInstanceOf(\ReflectionNamedType::class, $type, $parameter->getName());
            $this->assertTrue(
                $type->isBuiltin(),
                'PublicationFailedMail::$' . $parameter->getName() . ' must stay a builtin scalar — '
                . 'a model here re-queries in a worker with no workspace active (see the class docblock).',
            );
        }
    }

    /**
     * A BROKEN MAILER NEVER BREAKS THE CONCLUSION. The listener runs inside whatever concluded the
     * publication — a queued publish worker, a sweep pass, a person's reconcile click — and a mail
     * transport blowing up must cost a letter, not the conclusion. The review proved this with a probe
     * (PROBE 6); this is the probe as a pinned test.
     */
    public function test_a_broken_mailer_costs_the_letter_and_never_the_conclusion(): void
    {
        $publication = $this->inFlight();

        Exceptions::fake();

        Mail::shouldReceive('to')->andThrow(new \RuntimeException('smtp is down'));

        $concluded = app(PublicationManager::class)->markFailed($publication, 'title_missing');

        $this->assertSame(PublicationStatus::FAILED, $concluded->status, 'the conclusion must survive the mailer');

        Exceptions::assertReported(\RuntimeException::class);
    }

    /**
     * A BLANK TITLE READS AS A NAMED STAND-IN, not as a typographically broken subject. The workflow
     * path deliberately lets a titleless publication through to `title_missing` ("a blank one is a run
     * that pays") — its letter goes to the owner, and without the stand-in the subject ends in ": "
     * and the intro quotes an empty string, in the one medium nobody can re-render later.
     */
    public function test_a_blank_title_reads_as_a_named_stand_in(): void
    {
        $publication = $this->inFlight(['title' => '   ']);

        Mail::fake();

        app(PublicationManager::class)->markFailed($publication, 'title_missing');

        $letter = $this->theLetter();

        $this->assertSame(
            __('publishing.mail.failed.subject', ['title' => __('publishing.mail.failed.untitled', [], 'en')], 'en'),
            $this->subjectOf($letter),
        );
        $this->assertStringContainsString(__('publishing.mail.failed.untitled', [], 'en'), $this->bodyOf($letter));
    }

    /**
     * A CODE LITERALLY NAMED `unknown` still substitutes — the direct catalog branch used to resolve
     * `failures.unknown` WITHOUT the substitution array, and the recipient read a literal ":code" in
     * their inbox (review PROBE 4, measured). No adapter emits `unknown` today; B4 is the batch that
     * adds adapter codes, and this is the exact word one would reach for.
     */
    public function test_a_code_literally_named_unknown_still_substitutes(): void
    {
        $publication = $this->inFlight();

        Mail::fake();

        app(PublicationManager::class)->markFailed($publication, 'unknown');

        $body = $this->bodyOf($this->theLetter());

        $this->assertStringNotContainsString(':code', $body, 'the placeholder must be substituted on BOTH catalog branches');
        $this->assertStringContainsString('unknown', $body, 'and the code itself is what names the failure');
    }
}
