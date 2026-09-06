<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Publishing\Enums\PlatformConnectionStatus;
use App\Modules\Publishing\Managers\PlatformConnectionManager;
use App\Modules\Publishing\Models\PlatformConnection;
use App\Modules\Workspaces\Models\Workspace;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * R4 B2 — THE FAILURE-CODE VOCABULARY, PINNED TO ITS TRANSLATIONS IN BOTH LANGUAGES.
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
