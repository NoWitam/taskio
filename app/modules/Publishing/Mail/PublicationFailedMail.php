<?php

namespace App\Modules\Publishing\Mail;

use App\Modules\Publishing\Enums\PublishingPlatform;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Lang;

/**
 * "A PUBLICATION DID NOT GO OUT" — the third operational mail of this product (D4), after the workspace
 * invitation and the password reset, and built on the same shape: one `Mailable`, one inline-styled Blade
 * in `resources/views/emails/`, copy translated in `lang/{en,pl}/publishing.php`.
 *
 * ═════════════════════════════════════════════════════════════════════════════════════════════════
 * IT CARRIES PRIMITIVES, AND THAT IS NOT A STYLE PREFERENCE — IT IS A TENANCY REQUIREMENT
 * ═════════════════════════════════════════════════════════════════════════════════════════════════
 * This mail is QUEUED, so everything below is rendered in a worker rather than in whatever concluded the
 * publication. A `Publication` on the constructor would arrive there through `SerializesModels`, which
 * re-queries the row — and `App\Tenancy\QueueTenancy` stamps jobs with the DISPATCHING workspace, which
 * for the due sweep's shared pass is deliberately EMPTY. The re-query would then run with no workspace
 * active: harmless for a shared-database row, and for an own-database workspace a read of the CENTRAL
 * tables, i.e. a mail about the wrong row or no mail at all. The same reasoning
 * {@see \App\Modules\Publishing\Jobs\PublishPublicationJob} states for its own payload, with a milder
 * consequence — here it is a letter, not a post.
 *
 * So the listener resolves everything while the right connection is still active, and this class holds
 * strings. It touches no database at all.
 *
 * ═════════════════════════════════════════════════════════════════════════════════════════════════
 * WHAT IT MAY SAY, AND THE THREE THINGS IT MAY NEVER
 * ═════════════════════════════════════════════════════════════════════════════════════════════════
 * The title, the destination, the translated sentence for the failure CODE, the moment it was meant to go
 * out, and a link to the publication. That is the whole of it.
 *
 * NOT `failure_context`. The column is schema-less and today holds `{exception: 'Illuminate\\…'}` and
 * `{stale_after_seconds: 900}`; an exception class name is not a message for a person, and the UI applies
 * the same whitelist rule for the same reason (see `publishingMeta.ts`). NOT the platform's own prose,
 * which is never stored. AND NOTHING DERIVED FROM A CREDENTIAL — the module keeps tokens out of logs by
 * construction, and an inbox is a log that forwards itself. `PublishingFailureMailTest` scans the rendered
 * body for all of it.
 *
 * THE FAILURE SENTENCE COMES FROM THE EXISTING CATALOG, never from here. `publishing.failures.*` already
 * holds one sentence per code, written for the screen a person opens after a failure; a mail that wrote
 * its own would be a second, drifting description of the same event. An unrecognised code (B4 adapters
 * this build has never heard of) falls back to `publishing.failures.unknown`, which NAMES the code —
 * exactly the fallback `failureSentenceKey()` applies in the UI.
 *
 * EVERY SENTENCE IS RESOLVED AT SEND TIME, inside `Mailable::withLocale()`, which is what makes the
 * recipient's language reach the subject line as well as the body. Nothing is pre-rendered by the caller
 * except the one thing `__()` cannot do: the instant, which is formatted against the WORKSPACE's
 * timezone rather than the reader's, because that is the clock every publishing screen already draws.
 */
class PublicationFailedMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  string  $recipientName  the human this letter is addressed to
     * @param  string  $publicationId  the only id in the payload; it becomes a link, never a query
     * @param  string  $platform  a `PublishingPlatform` value, labelled in the recipient's language
     * @param  string|null  $failureCode  a `publications.failure_code`, translated through the catalog
     * @param  string|null  $plannedForIso  the intended moment as an ISO instant, or null if it had none
     * @param  string  $timezone  the WORKSPACE's IANA zone; validated by the caller, never guessed
     */
    public function __construct(
        public string $recipientName,
        public string $publicationId,
        public string $title,
        public string $platform,
        public ?string $failureCode,
        public ?string $plannedForIso,
        public string $timezone,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('publishing.mail.failed.subject', ['title' => $this->displayedTitle()]),
        );
    }

    /**
     * The title as the subject and body render it — with a translated stand-in for a BLANK one.
     *
     * A blank title is reachable on purpose: the workflow path deliberately lets a titleless
     * publication through to `title_missing` ("a blank one is a run that pays"), and its letter goes to
     * the workspace owner. Without this, that letter's subject reads `…: ` and its first sentence
     * quotes an empty string — typographically broken in the one medium nobody can re-render later.
     */
    private function displayedTitle(): string
    {
        return trim($this->title) === '' ? __('publishing.mail.failed.untitled') : $this->title;
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.publication-failed',
            with: [
                'recipientName' => $this->recipientName,
                'displayTitle' => $this->displayedTitle(),
                'platformLabel' => $this->platformLabel(),
                'failureSentence' => $this->failureSentence(),
                'plannedFor' => $this->plannedFor(),
                'timezone' => $this->timezone,
                'publicationUrl' => $this->publicationUrl(),
            ],
        );
    }

    /**
     * The destination's name in the reader's language.
     *
     * `tryFrom` rather than `from`: the value comes off an enum-cast column so it cannot realistically be
     * unknown, but this runs in a worker, and a throw here would fail a mail job over a label. A raw value
     * is a poor name and still tells the reader where the post was going.
     */
    private function platformLabel(): string
    {
        return PublishingPlatform::tryFrom($this->platform)?->label() ?? $this->platform;
    }

    /**
     * WHY it did not go out, in the reader's language — or null when the row carries no code at all.
     *
     * `Lang::has()` rather than a second copy of the code list. The catalog IS the list of codes this
     * build can explain, and a hardcoded array here would be a list free to fall behind it — which is the
     * drift `PublishingConnectionVocabularyTest` was written after the module had already suffered once.
     *
     * A `blocked` publication's hold codes (`connection_needs_reauth`, `connection_disconnected`) live
     * under `publishing.holds`, not here, and this mail is only ever sent for `failed` — so they would
     * take the unknown fallback if they ever arrived, naming the code rather than inventing a sentence.
     */
    private function failureSentence(): ?string
    {
        if ($this->failureCode === null || $this->failureCode === '') {
            return null;
        }

        $key = 'publishing.failures.' . $this->failureCode;

        // The substitutions ride on BOTH branches, deliberately. A code named literally `unknown`
        // resolves the direct branch (`failures.unknown` exists), and that sentence carries `:code` —
        // without the array the recipient reads a literal ":code" in their inbox. Harmless for keys
        // with no placeholder; measured as a live defect in the D4 review (PROBE 4) before this line.
        return Lang::has($key)
            ? __($key, ['code' => $this->failureCode])
            : __('publishing.failures.unknown', ['code' => $this->failureCode]);
    }

    /**
     * The intended moment on the WORKSPACE's clock, 'Y-m-d H:i', or null when there was none.
     *
     * The zone is the workspace's because that is the zone every publishing screen renders an instant in
     * (`publishingTime.ts` formats against `store.timezone`), and a mail that said 07:00 about a post the
     * detail page calls 09:00 would be read as two different plans. A numeric format on purpose: month
     * names would depend on Carbon's locale being wired to the translator's, which is a second mechanism
     * to be wrong about for no gain in a one-line fact.
     */
    private function plannedFor(): ?string
    {
        if ($this->plannedForIso === null) {
            return null;
        }

        return CarbonImmutable::parse($this->plannedForIso)
            ->setTimezone($this->timezone)
            ->format('Y-m-d H:i');
    }

    /**
     * The publication's own screen. Same shape as the other two mails' links: an absolute URL into the
     * SPA, whose router owns everything under /next.
     *
     * The bare record path redirects to its default section, so this link survives a section being
     * renamed — see the route table's `next.publishing.publication`.
     */
    private function publicationUrl(): string
    {
        return config('app.url') . '/next/publishing/publications/' . $this->publicationId;
    }
}
