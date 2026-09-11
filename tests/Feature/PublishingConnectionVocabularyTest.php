<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Publishing\Enums\PlatformConnectionStatus;
use App\Modules\Publishing\Exceptions\OAuthExchangeFailed;
use App\Modules\Publishing\Exceptions\OAuthStateRejected;
use App\Modules\Publishing\Exceptions\PublicationTransitionRefused;
use App\Modules\Publishing\Managers\PlatformConnectionManager;
use App\Modules\Publishing\Models\PlatformConnection;
use App\Modules\Publishing\Support\OAuthCallbackReason;
use App\Modules\Workspaces\Models\Workspace;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionClass;
use Tests\TestCase;

/**
 * R4 B2/B3 — THE TWO CONNECTION VOCABULARIES, PINNED TO THEIR TRANSLATIONS IN BOTH LANGUAGES.
 *
 * B2 pinned one list — `platform_connections.failure_code`, why an account stopped working. B3 added
 * the second, for the same class of defect arrived at from the other direction: the OAUTH CALLBACK's
 * reason codes, which had never been a list at all.
 *
 * ═════════════════════════════════════════════════════════════════════════════════════════════════
 * WHY THIS NEEDED A TEST OF ITS OWN
 * ═════════════════════════════════════════════════════════════════════════════════════════════════
 * `platform_connections.failure_code` is the one column on that table a person actually reads: it is
 * rendered through `publishing.connection_failures.*` on the screen somebody opens when publishing has
 * stopped. A code with no translation renders as the raw key — `token_refresh_failed` in the middle of a
 * Polish interface — and a translation with no code is a sentence nobody will ever see.
 *
 * Both had already happened when B2 was reviewed, and the interesting part is why nothing was red:
 *
 *   THE REFRESHER wrote `token_refresh_failed`, borrowed from `OAuthExchangeFailed`, while the language
 *   files knew only `refresh_failed`. Every real renewal failure would have shown a raw key.
 *
 *   THE FACTORY defaulted to `refresh_failed` — the translated spelling. So every fixture in the suite
 *   was translatable and the one code the application actually produced was not. The tests were reading
 *   from a different vocabulary than the code, and agreed with the translations by coincidence.
 *
 *   `disconnected_by_user` was translated in both languages and written by NOTHING. Disconnecting an
 *   account left whatever had last broken it on the row, so the screen explained a renewal failure
 *   about an account somebody had deliberately removed.
 *
 * A parity assertion catches all three, and it catches them at the moment somebody adds the fifth code
 * rather than at the moment a user sees it.
 *
 * ═════════════════════════════════════════════════════════════════════════════════════════════════
 * B3: THE SAME ASSERTION, ABOUT THE CALLBACK'S REASONS — WHERE THE DRIFT WAS ALREADY TEN CODES WIDE
 * ═════════════════════════════════════════════════════════════════════════════════════════════════
 * `PlatformOAuthCallbackController` could report fourteen reasons: six from `OAuthStateRejected`, three
 * from `OAuthExchangeFailed`, five bare literals in the controller. The language files knew FOUR. So
 * ten of them would have rendered as their own raw key — `token_response_unusable` in the middle of a
 * Polish sentence — on the screen somebody lands on at the end of a consent flow that failed, which is
 * the worst available moment to show a person a machine identifier.
 *
 * Nothing was red, and could not have been: there was no list to compare a catalog against. Each code
 * was a string at its throw site, and "have we written a sentence for all of them" was not a question
 * the codebase could be asked. {@see OAuthCallbackReason} is that list; the assertion below is the
 * question.
 */
class PublishingConnectionVocabularyTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->workspace = Workspace::factory()->create(['owner_id' => $this->owner->id]);
        $this->workspace->users()->attach($this->owner->id);

        app(TenantContext::class)->set($this->workspace);
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();

        parent::tearDown();
    }

    /**
     * EXACT PARITY, BOTH DIRECTIONS, BOTH LANGUAGES.
     *
     * Both directions matter and they fail differently. A code with no translation is a raw key on a
     * screen; a translation with no code is either a sentence that can never appear (harmless, and
     * misleading to the next reader) or — as with `disconnected_by_user` — the evidence that something
     * which was supposed to write it does not.
     */
    public function test_every_failure_code_is_translated_and_every_translation_is_a_failure_code(): void
    {
        $codes = PlatformConnectionManager::FAILURE_CODES;

        $this->assertNotEmpty($codes);

        foreach (['en', 'pl'] as $locale) {
            $translations = array_keys((array) __('publishing.connection_failures', [], $locale));

            sort($codes);
            sort($translations);

            $this->assertSame(
                $codes,
                $translations,
                "The [{$locale}] connection_failures translations and PlatformConnectionManager::FAILURE_CODES "
                . 'have drifted apart. A code with no sentence renders as a raw key on the screen '
                . 'somebody opens when publishing has stopped; a sentence with no code is one nobody '
                . 'can reach.',
            );
        }
    }

    /** And each one really resolves to prose, rather than echoing its own key back. */
    public function test_each_failure_code_resolves_to_a_sentence_in_both_languages(): void
    {
        foreach (PlatformConnectionManager::FAILURE_CODES as $code) {
            foreach (['en', 'pl'] as $locale) {
                $key = 'publishing.connection_failures.' . $code;
                $sentence = (string) __($key, [], $locale);

                $this->assertNotSame($key, $sentence, "[{$code}] has no [{$locale}] translation");
                $this->assertNotSame('', trim($sentence));
            }
        }
    }

    /**
     * B3 — THE TRANSITION REFUSALS SPEAK BOTH LANGUAGES TOO, and this pin closed an asymmetry: the
     * two maps above were dictionary-tested from the day they existed, while `transitions` — whose
     * `reconcile_before_retry` sentence the module itself calls the most important one it renders —
     * had nothing. A deleted `lost_race` key left the whole suite green (verified by running that
     * mutation) — and NOT as a raw key on a screen, which is the second lesson this test carries:
     *
     * IT DELIBERATELY NEVER ASKS THE TRANSLATOR. A first version constructed the exceptions and
     * asserted their messages were prose — and stayed green under the deletion mutation, because a
     * missing key falls through to `app.fallback_locale`, which this installation's `.env` sets to
     * `pl`. Every hole in the English catalog renders as a grammatical POLISH sentence to an English
     * reader, invisible to any assertion built on `__()`. So the test compares the two files' KEY
     * SETS directly against the sentences the exception can emit; the count guard against the class
     * constants is what drags this list — and the catalogs — along when a sixth reason is added.
     */
    public function test_every_transition_refusal_reason_has_a_sentence_in_both_catalogs(): void
    {
        // The exception's messageKey() vocabulary. Private, so restated here — the assertCount ties
        // this list to the constants, and the set comparison ties the catalogs to this list.
        $expected = ['blocked_holds', 'lost_race', 'not_allowed', 'reconcile_before_retry', 'terminal'];

        $this->assertCount(
            count((new ReflectionClass(PublicationTransitionRefused::class))->getConstants()),
            $expected,
            'PublicationTransitionRefused gained or lost a reason; update $expected and BOTH catalogs',
        );

        foreach (['en', 'pl'] as $locale) {
            $catalog = (array) __('publishing.transitions', [], $locale);

            $keys = array_keys($catalog);
            sort($keys);

            $this->assertSame(
                $expected,
                $keys,
                "The [{$locale}] publishing.transitions catalog and the refusal vocabulary have "
                . 'drifted apart. A missing key does NOT render as a raw string here — it falls back '
                . "to the other language's sentence, which no reader will report as broken.",
            );

            foreach ($catalog as $key => $sentence) {
                $this->assertNotSame('', trim((string) $sentence), "[{$key}] is empty in [{$locale}]");
            }
        }
    }

    /**
     * B3 — EXACT PARITY FOR THE CALLBACK'S REASONS, both directions, both languages.
     *
     * The direction that was actually broken is "a code with no sentence": ten of the fourteen. The
     * other direction is asserted for the same reason it is on the connection codes — a sentence with no
     * code is either unreachable prose or the evidence that something which was supposed to report it
     * does not.
     */
    public function test_every_oauth_callback_reason_is_translated_and_every_translation_is_a_reason(): void
    {
        $reasons = OAuthCallbackReason::ALL;

        $this->assertNotEmpty($reasons);

        foreach (['en', 'pl'] as $locale) {
            $translations = array_keys((array) __('publishing.oauth_failures', [], $locale));

            sort($reasons);
            sort($translations);

            $this->assertSame(
                $reasons,
                $translations,
                "The [{$locale}] oauth_failures translations and OAuthCallbackReason::ALL have drifted "
                . 'apart. A reason with no sentence renders as a raw key on the screen somebody lands on '
                . 'when connecting an account has just failed; a sentence with no reason is one nobody '
                . 'can reach.',
            );
        }
    }

    /** And each one really resolves to prose, rather than echoing its own key back. */
    public function test_each_oauth_callback_reason_resolves_to_a_sentence_in_both_languages(): void
    {
        foreach (OAuthCallbackReason::ALL as $reason) {
            foreach (['en', 'pl'] as $locale) {
                $key = 'publishing.oauth_failures.' . $reason;
                $sentence = (string) __($key, [], $locale);

                $this->assertNotSame($key, $sentence, "[{$reason}] has no [{$locale}] translation");
                $this->assertNotSame('', trim($sentence));
            }
        }
    }

    /**
     * THE LIST IS THE EXCEPTIONS' OWN LITERALS, not a second spelling of them.
     *
     * This is the assertion that would have caught B2's `token_refresh_failed` / `refresh_failed` split
     * had it existed then, and it is cheap insurance against the same mistake being made here: the
     * moment somebody types `'oauth_state_expired'` into `OAuthCallbackReason` instead of referring to
     * `OAuthStateRejected::EXPIRED`, the two are free to drift and only one of them is what the
     * controller actually redirects with.
     */
    public function test_the_callback_reasons_reuse_the_exception_constants_rather_than_re_spelling_them(): void
    {
        $this->assertContains(OAuthStateRejected::MALFORMED, OAuthCallbackReason::ALL);
        $this->assertContains(OAuthStateRejected::BAD_SIGNATURE, OAuthCallbackReason::ALL);
        $this->assertContains(OAuthStateRejected::EXPIRED, OAuthCallbackReason::ALL);
        $this->assertContains(OAuthStateRejected::ALREADY_USED, OAuthCallbackReason::ALL);
        $this->assertContains(OAuthStateRejected::PLATFORM_MISMATCH, OAuthCallbackReason::ALL);
        $this->assertContains(OAuthStateRejected::BROWSER_MISMATCH, OAuthCallbackReason::ALL);

        $this->assertContains(OAuthExchangeFailed::EXCHANGE, OAuthCallbackReason::ALL);
        $this->assertContains(OAuthExchangeFailed::UNUSABLE_RESPONSE, OAuthCallbackReason::ALL);
        $this->assertContains(OAuthExchangeFailed::ACCOUNT_LOOKUP, OAuthCallbackReason::ALL);

        // The RENEWAL codes are deliberately absent: they describe a stored account rather than a
        // handshake and already have sentences under `connection_failures`. A code with two sentences in
        // two catalogs is a code whose two sentences drift.
        $this->assertNotContains(OAuthExchangeFailed::REFRESH, OAuthCallbackReason::ALL);
        $this->assertNotContains(OAuthExchangeFailed::REFRESH_UNSUPPORTED, OAuthCallbackReason::ALL);

        // And an exchange failure outside the list is mapped to the catch-all rather than travelling to
        // a browser with no sentence behind it.
        $this->assertSame(
            OAuthCallbackReason::CONNECTION_FAILED,
            OAuthCallbackReason::forExchange(OAuthExchangeFailed::REFRESH),
        );
        $this->assertSame(
            OAuthExchangeFailed::EXCHANGE,
            OAuthCallbackReason::forExchange(OAuthExchangeFailed::EXCHANGE),
            'a reason the catalog DOES answer for must pass through unchanged',
        );
    }

    /**
     * DISCONNECTING WRITES ITS OWN CODE, over whatever broke the connection before.
     *
     * The half of the drift that was not a spelling problem: the sentence existed, in two languages,
     * and nothing ever produced the code that would show it. A connection parked by a failed renewal and
     * then disconnected kept saying the platform had refused to renew it — about an account the user had
     * just removed on purpose.
     */
    public function test_disconnecting_stamps_its_own_reason_over_the_previous_failure(): void
    {
        $connection = PlatformConnection::factory()->needsReauth()->create([
            'creator_id' => $this->owner->id,
        ]);

        $this->assertSame(PlatformConnectionManager::FAILURE_REFRESH_FAILED, $connection->failure_code);

        app(PlatformConnectionManager::class)->revoke($connection);

        $trashed = PlatformConnection::withTrashed()->findOrFail($connection->id);

        $this->assertSame(PlatformConnectionStatus::REVOKED, $trashed->status);
        $this->assertSame(
            PlatformConnectionManager::FAILURE_DISCONNECTED,
            $trashed->failure_code,
            'a disconnected account must say it was disconnected, not repeat what last broke it',
        );
    }

    /** Reconnecting clears it again, so the code always describes the CURRENT state of the account. */
    public function test_reconnecting_clears_the_disconnection_reason(): void
    {
        $connection = PlatformConnection::factory()->create(['creator_id' => $this->owner->id]);

        $manager = app(PlatformConnectionManager::class);

        $manager->revoke($connection);

        $manager->connect(
            platform: $connection->platform,
            account: \App\Modules\Publishing\DTOs\RemoteAccount::make($connection->external_account_id, 'Reconnected'),
            tokens: \App\Modules\Publishing\DTOs\OAuthTokens::make('fake-access-reconnected', 'fake-refresh-reconnected', 3600),
            creatorId: $this->owner->id,
        );

        $restored = PlatformConnection::query()->sole();

        $this->assertSame(PlatformConnectionStatus::ACTIVE, $restored->status);
        $this->assertNull($restored->failure_code);
    }
}
