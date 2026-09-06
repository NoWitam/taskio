<?php

namespace Database\Factories;

use App\Modules\Publishing\Enums\PlatformConnectionStatus;
use App\Modules\Publishing\Enums\PublishingPlatform;
use App\Modules\Publishing\Managers\PlatformConnectionManager;
use App\Modules\Publishing\Models\PlatformConnection;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PlatformConnection>
 *
 * The DEFAULT is a HEALTHY YouTube connection with a token that expires in sixty days — the state a
 * connection spends almost all of its life in, so a test that does not say otherwise gets the ordinary
 * case rather than an edge one.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * THE TOKENS ARE OBVIOUSLY FAKE, AND THAT IS LOAD-BEARING
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * Every generated token starts with `fake-access-` or `fake-refresh-` and carries random entropy. Two
 * things depend on that prefix rather than merely benefiting from it:
 *
 *   THE LEAK TESTS. `PublishingConnectionSecrecyTest` scans API responses and the application log for
 *   the exact token strings a scenario used. That only works if a token is a distinctive literal —
 *   `Str::random(40)` alone would produce something that could plausibly collide with a uuid fragment
 *   and would make a failure hard to read.
 *
 *   AN ACCIDENT AT A REAL PLATFORM. If a fixture ever escaped into a code path that talks to Google,
 *   the request fails with an obviously synthetic credential in the rejection rather than with something
 *   that looks like it might have been real.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * THE STATES SET `status` DIRECTLY, WHICH NOTHING IN THE MODULE MAY DO
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * The same carve-out `PublicationFactory` documents, and it applies for the same reason: a fixture
 * legitimately needs to START somewhere rather than walk there. A test about a held queue should not
 * have to fail a real token refresh to obtain a `needs_reauth` row.
 *
 * The cost is the same too, and the states below pay it: each sets the COMPANION columns the Manager
 * would have set, so a fixture is a plausible row rather than a status string with healthy metadata
 * behind it.
 *
 * AND IT TAKES A FORCE-FILL TO DO IT, which is the part that must not be quietly removed. `status` is not
 * in `PlatformConnection::$fillable` — see that model's docblock — so an ordinary factory would have the
 * attribute SILENTLY DISCARDED by `fill()`, and every `needsReauth()` fixture would come out `active` on
 * the column's database default with a failure code beside it. That is the worst available outcome: a
 * test about a held queue that builds a healthy connection and asserts against it. So {@see newModel()}
 * force-fills, and the carve-out is structural instead of depending on a fillable list a security change
 * is entitled to shorten.
 */
class PlatformConnectionFactory extends Factory
{
    protected $model = PlatformConnection::class;

    /**
     * Built by FORCE-FILL rather than mass assignment. See the class docblock.
     *
     * The scope is exactly this factory: fixtures are trusted input by definition, while the model's
     * `$fillable` goes on governing everything reachable from a request.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function newModel(array $attributes = []): PlatformConnection
    {
        return (new PlatformConnection)->forceFill($attributes);
    }

    public function definition(): array
    {
        return [
            'platform' => PublishingPlatform::YOUTUBE,
            'external_account_id' => 'UC' . Str::lower(Str::random(22)),
            'account_name' => $this->faker->company(),
            // See the class docblock: the prefix is what the leak-scanning tests key on.
            'access_token' => 'fake-access-' . Str::random(40),
            'refresh_token' => 'fake-refresh-' . Str::random(40),
            'scopes' => ['https://www.googleapis.com/auth/youtube.upload'],
            'expires_at' => now()->addDays(60),
            'status' => PlatformConnectionStatus::ACTIVE,
            'last_refreshed_at' => now(),
            'failure_code' => null,
        ];
    }

    /** A connection on a named destination. */
    public function on(PublishingPlatform $platform): static
    {
        return $this->state(fn (): array => ['platform' => $platform]);
    }

    /**
     * A META connection: long-lived access token, NO refresh token.
     *
     * A separate state rather than a flag, because "refresh_token is null" is not a variation on a
     * Google connection — it is what every Meta connection looks like, and a test that renews one must
     * be exercising the access-token path.
     */
    public function meta(PublishingPlatform $platform = PublishingPlatform::FACEBOOK): static
    {
        return $this->state(fn (): array => [
            'platform' => $platform,
            'external_account_id' => (string) $this->faker->numberBetween(100000000000000, 999999999999999),
            'access_token' => 'fake-access-' . Str::random(40),
            'refresh_token' => null,
            'scopes' => ['pages_manage_posts', 'pages_show_list'],
        ]);
    }

    /**
     * A token inside the refresh lead — what the sweep selects.
     *
     * Expressed in HOURS rather than as an absolute instant so it stays inside the lead whatever the
     * configured lead is, and so a test does not have to know the configuration to write a fixture the
     * sweep will find.
     */
    public function expiringIn(int $hours = 2): static
    {
        return $this->state(fn (): array => ['expires_at' => now()->addHours($hours)]);
    }

    /** A connection with no stated expiry — the sweep must LEAVE THIS ALONE, never treat it as overdue. */
    public function withoutExpiry(): static
    {
        return $this->state(fn (): array => ['expires_at' => null]);
    }

    /**
     * BROKEN. Its scheduled publications belong on hold; a fixture in this state usually says so too.
     *
     * The default is the MANAGER'S constant rather than a literal. It was a literal, and it happened to
     * spell the code the translations use while the refresher stamped a different one — so every fixture
     * looked right, every translation resolved, and the drift was invisible from both ends.
     */
    public function needsReauth(string $failureCode = PlatformConnectionManager::FAILURE_REFRESH_FAILED): static
    {
        return $this->state(fn (): array => [
            'status' => PlatformConnectionStatus::NEEDS_REAUTH,
            'failure_code' => $failureCode,
        ]);
    }
}
