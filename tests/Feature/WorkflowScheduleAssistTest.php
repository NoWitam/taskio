<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Workflows\Agents\ScheduleAssistAgent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The AI SCHEDULE ASSIST endpoint (POST /api/workflows/schedule-assist). The model's JSON is
 * faked (the agent is tool-less, so Agent::fake with a text response is enough); the point of
 * these tests is the BACKEND contract around it: the model is NEVER trusted — every config it
 * returns is re-validated against the same rules the write path uses (and compiled), a
 * hallucinated/out-of-bounds config is downgraded, an invalid alternative is dropped, malformed
 * output degrades gracefully (no 500), the per-user rate limit yields a 429, and auth is enforced.
 *
 * The model now speaks the v2 compositional descriptor { time, day?, month?, exclusions?, tz? }
 * natively; a legacy { family, params } proposal is still accepted (the read-shim upgrades it
 * before the gate) — one backward-compat case pins that.
 */
class WorkflowScheduleAssistTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/api/workflows/schedule-assist';

    /** Fake the schedule-assist agent to return the given JSON string (or array of them, in order). */
    private function fakeAssist(string|array $json): void
    {
        ScheduleAssistAgent::fake(is_array($json) ? $json : [$json]);
    }

    public function test_guest_is_unauthenticated(): void
    {
        $this->postJson(self::ENDPOINT, ['prompt' => 'codziennie o 9'])->assertUnauthorized();
    }

    public function test_prompt_is_required(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson(self::ENDPOINT, ['prompt' => ''])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['prompt']);
    }

    public function test_feasible_happy_path_passes_a_validated_config(): void
    {
        $user = User::factory()->create();

        $this->fakeAssist(json_encode([
            'feasible' => true,
            'config' => ['time' => ['mode' => 'at', 'at' => ['09:00']]],
            'unsupported' => [],
            'alternative' => null,
            'explanation' => 'Codziennie o 9:00.',
        ]));

        $this->actingAs($user)
            ->postJson(self::ENDPOINT, ['prompt' => 'codziennie o 9'])
            ->assertOk()
            ->assertJsonPath('data.feasible', true)
            ->assertJsonPath('data.config.time.mode', 'at')
            ->assertJsonPath('data.config.time.at', ['09:00'])
            ->assertJsonPath('data.alternative', null);
    }

    public function test_tz_hint_is_merged_into_a_config_without_one(): void
    {
        $user = User::factory()->create();

        $this->fakeAssist(json_encode([
            'feasible' => true,
            'config' => ['time' => ['mode' => 'at', 'at' => ['09:00']]],
            'unsupported' => [],
            'alternative' => null,
            'explanation' => 'ok',
        ]));

        $this->actingAs($user)
            ->postJson(self::ENDPOINT, ['prompt' => 'codziennie o 9', 'tz' => 'Europe/Warsaw'])
            ->assertOk()
            ->assertJsonPath('data.config.tz', 'Europe/Warsaw');
    }

    /**
     * The agent must be ANCHORED on today's date in the caller's zone. Without it, a request like
     * "codziennie z wyjątkiem dni wolnych od pracy" — which is answered with concrete
     * `exclusions.dates` — would have its holidays enumerated for whatever year the model's training
     * suggests: a config that validates and compiles cleanly while silently excluding the wrong days.
     * The zone rides along so the model can tell WHICH country's holidays are meant.
     */
    public function test_the_agent_is_anchored_on_todays_date_and_the_callers_zone(): void
    {
        $user = User::factory()->create();

        $this->fakeAssist(json_encode([
            'feasible' => true,
            'config' => ['time' => ['mode' => 'at', 'at' => ['09:00']]],
            'unsupported' => [],
            'alternative' => null,
            'explanation' => 'ok',
        ]));

        $this->actingAs($user)
            ->postJson(self::ENDPOINT, [
                'prompt' => 'codziennie z wyjątkiem dni wolnych od pracy',
                'tz' => 'Europe/Warsaw',
            ])
            ->assertOk();

        $today = now('Europe/Warsaw')->toDateString();

        ScheduleAssistAgent::assertPrompted(function ($prompt) use ($today): bool {
            $instructions = (string) $prompt->agent->instructions();

            return str_contains($instructions, 'Today is ' . $today)
                && str_contains($instructions, 'The caller\'s timezone is "Europe/Warsaw"');
        });
    }

    public function test_hallucinated_mode_is_downgraded(): void
    {
        $user = User::factory()->create();

        // The model claims feasible with a time.mode that does not exist. Re-validation must refuse
        // to trust it: feasible->false, config->null, a note appended to unsupported.
        $this->fakeAssist(json_encode([
            'feasible' => true,
            'config' => ['time' => ['mode' => 'every_full_moon']],
            'unsupported' => [],
            'alternative' => null,
            'explanation' => 'Every full moon.',
        ]));

        $response = $this->actingAs($user)
            ->postJson(self::ENDPOINT, ['prompt' => 'przy każdej pełni księżyca'])
            ->assertOk()
            ->assertJsonPath('data.feasible', false)
            ->assertJsonPath('data.config', null);

        $this->assertNotEmpty($response->json('data.unsupported'));
    }

    public function test_out_of_bounds_params_are_downgraded(): void
    {
        $user = User::factory()->create();

        // time.every_hours hours is 1..23; hours=40 is out of range. A real mode but an invalid
        // config — must be downgraded exactly like a hallucinated mode.
        $this->fakeAssist(json_encode([
            'feasible' => true,
            'config' => ['time' => ['mode' => 'every_hours', 'hours' => 40]],
            'unsupported' => [],
            'alternative' => null,
            'explanation' => 'Co 40 godzin.',
        ]));

        $response = $this->actingAs($user)
            ->postJson(self::ENDPOINT, ['prompt' => 'co 40 godzin'])
            ->assertOk()
            ->assertJsonPath('data.feasible', false)
            ->assertJsonPath('data.config', null);

        $this->assertNotEmpty($response->json('data.unsupported'));
    }

    public function test_infeasible_with_valid_alternative_is_passed_through(): void
    {
        $user = User::factory()->create();

        // "co 2 tygodnie" (every other week) is not expressible — there is no every-N-weeks axis.
        // The model is honest: feasible false, unsupported populated, and a VALID weekly-on-Monday
        // alternative — which must survive re-validation and be returned.
        $this->fakeAssist(json_encode([
            'feasible' => false,
            'config' => null,
            'unsupported' => ['co 2 tygodnie — brak osi „co N tygodni”'],
            'alternative' => [
                'config' => [
                    'time' => ['mode' => 'at', 'at' => ['08:00']],
                    'day' => ['mode' => 'weekdays', 'weekdays' => [1]],
                ],
                'note' => 'Zaproponowano co tydzień w poniedziałek zamiast co 2 tygodnie.',
            ],
            'explanation' => 'Nie można ustawić co 2 tygodnie; proponuję co tydzień w poniedziałek.',
        ]));

        $this->actingAs($user)
            ->postJson(self::ENDPOINT, ['prompt' => 'co 2 tygodnie w poniedziałek o 8'])
            ->assertOk()
            ->assertJsonPath('data.feasible', false)
            ->assertJsonPath('data.config', null)
            ->assertJsonPath('data.alternative.config.time.at', ['08:00'])
            ->assertJsonPath('data.alternative.config.day.weekdays', [1])
            ->assertJsonPath('data.unsupported.0', 'co 2 tygodnie — brak osi „co N tygodni”');
    }

    public function test_invalid_alternative_is_dropped_and_unsupported_preserved(): void
    {
        $user = User::factory()->create();

        // Infeasible main, and an alternative whose config is itself invalid (an out-of-bounds
        // every_hours the re-validation rejects). The alternative must be dropped; unsupported
        // preserved (the alternative's failure is NOT surfaced — it is simply removed).
        $this->fakeAssist(json_encode([
            'feasible' => false,
            'config' => null,
            'unsupported' => [' dokładnie co 90 minut — brak interwału kroczącego'],
            'alternative' => [
                'config' => ['time' => ['mode' => 'every_hours', 'hours' => 40]],
                'note' => 'nonsens',
            ],
            'explanation' => 'Nie można.',
        ]));

        $this->actingAs($user)
            ->postJson(self::ENDPOINT, ['prompt' => 'dokładnie co 90 minut'])
            ->assertOk()
            ->assertJsonPath('data.feasible', false)
            ->assertJsonPath('data.config', null)
            ->assertJsonPath('data.alternative', null)
            ->assertJsonPath('data.unsupported.0', ' dokładnie co 90 minut — brak interwału kroczącego');
    }

    public function test_dropped_alternative_does_not_leak_validator_jargon(): void
    {
        $user = User::factory()->create();

        // Convention pin: a DROPPED alternative's validation failure never leaks into the envelope.
        // Only a MAIN config downgrade appends validator messages to `unsupported`; an invalid
        // alternative is removed silently, so `unsupported` stays EXACTLY the model's plain-language
        // reasons and no raw validator jargon (e.g. "must not be greater than") appears anywhere.
        $this->fakeAssist(json_encode([
            'feasible' => false,
            'config' => null,
            'unsupported' => ['co 2,5 godziny — brak niecałkowitego kroku godzinowego'],
            'alternative' => [
                'config' => ['time' => ['mode' => 'every_hours', 'hours' => 40]],
                'note' => 'co 40 godzin jako namiastka',
            ],
            'explanation' => 'Nie można ustawić co 2,5 godziny.',
        ]));

        $response = $this->actingAs($user)
            ->postJson(self::ENDPOINT, ['prompt' => 'co 2,5 godziny'])
            ->assertOk()
            ->assertJsonPath('data.alternative', null)
            ->assertJsonPath('data.unsupported', ['co 2,5 godziny — brak niecałkowitego kroku godzinowego'])
            ->assertJsonPath('data.explanation', 'Nie można ustawić co 2,5 godziny.');

        // The alternative's raw validator error ("...hours field must not be greater than 23.")
        // must not have leaked into any envelope field.
        $response->assertDontSee('must not be greater than', false);
    }

    public function test_legacy_family_config_from_model_is_normalized_to_v2(): void
    {
        $user = User::factory()->create();

        // Backward compatibility: a model that still answers in the pre-v2 { family, params } shape
        // is NOT rejected — the whitelist bridges those keys and LegacyScheduleUpgrader upgrades the
        // block to v2 inside the gate, so it validates and compiles and is returned as proposed. It
        // is ALSO normalized on the way out: the envelope must carry the v2 descriptor (time.at), not
        // the legacy shape, so the FE always seeds its editor from ONE shape.
        $this->fakeAssist(json_encode([
            'feasible' => true,
            'config' => ['family' => 'daily', 'params' => ['time' => '09:00']],
            'unsupported' => [],
            'alternative' => null,
            'explanation' => 'Codziennie o 9:00 (stary format).',
        ]));

        $config = $this->actingAs($user)
            ->postJson(self::ENDPOINT, ['prompt' => 'codziennie o 9'])
            ->assertOk()
            ->assertJsonPath('data.feasible', true)
            ->assertJsonPath('data.config.time.mode', 'at')
            ->assertJsonPath('data.config.time.at', ['09:00'])
            ->json('data.config');

        // The legacy vocabulary must NOT leak back — the client receives a pure v2 descriptor.
        $this->assertArrayNotHasKey('family', $config);
        $this->assertArrayNotHasKey('params', $config);
    }

    public function test_legacy_alternative_config_from_model_is_normalized_to_v2(): void
    {
        $user = User::factory()->create();

        // Same normalization must apply to a legacy-shaped alternative: an infeasible main with a
        // pre-v2 { family, params } alternative survives the gate (upgraded inside it) AND is handed
        // back as v2, never as { family, params }.
        $this->fakeAssist(json_encode([
            'feasible' => false,
            'config' => null,
            'unsupported' => ['co 2 tygodnie — brak osi „co N tygodni”'],
            'alternative' => [
                'config' => ['family' => 'weekly', 'params' => ['weekdays' => [1], 'time' => '08:00']],
                'note' => 'Zaproponowano co tydzień w poniedziałek zamiast co 2 tygodnie.',
            ],
            'explanation' => 'Nie można ustawić co 2 tygodnie; proponuję co tydzień w poniedziałek.',
        ]));

        $altConfig = $this->actingAs($user)
            ->postJson(self::ENDPOINT, ['prompt' => 'co 2 tygodnie w poniedziałek o 8'])
            ->assertOk()
            ->assertJsonPath('data.feasible', false)
            ->assertJsonPath('data.config', null)
            ->assertJsonPath('data.alternative.config.time.at', ['08:00'])
            ->assertJsonPath('data.alternative.config.day.mode', 'weekdays')
            ->assertJsonPath('data.alternative.config.day.weekdays', [1])
            ->json('data.alternative.config');

        $this->assertArrayNotHasKey('family', $altConfig);
        $this->assertArrayNotHasKey('params', $altConfig);
    }

    public function test_malformed_model_output_degrades_to_generic_feasible_false(): void
    {
        $user = User::factory()->create();

        // Not JSON at all — must not 500; a safe generic feasible:false with an explanation.
        $this->fakeAssist('I am not JSON, I am a friendly assistant!');

        $this->actingAs($user)
            ->postJson(self::ENDPOINT, ['prompt' => 'codziennie o 9'])
            ->assertOk()
            ->assertJsonPath('data.feasible', false)
            ->assertJsonPath('data.config', null)
            ->assertJsonPath('data.alternative', null)
            ->assertJsonPath('data.unsupported', [])
            ->assertJsonStructure(['data' => ['feasible', 'config', 'unsupported', 'alternative', 'explanation']]);
    }

    public function test_generic_fallback_explanation_is_polish_for_pl_locale(): void
    {
        $user = User::factory()->create();

        // The controller derives the assist language from the app locale. On a broken model output the
        // generic fallback explanation must honor that language — Polish for the pl locale.
        $this->app->setLocale('pl');
        $this->fakeAssist('I am not JSON, I am a friendly assistant!');

        $this->actingAs($user)
            ->postJson(self::ENDPOINT, ['prompt' => 'codziennie o 9'])
            ->assertOk()
            ->assertJsonPath('data.feasible', false)
            ->assertJsonPath('data.explanation', 'Nie udało się przetworzyć opisu harmonogramu. Spróbuj opisać go inaczej lub ustaw harmonogram ręcznie.');
    }

    public function test_generic_fallback_explanation_is_english_for_en_locale(): void
    {
        $user = User::factory()->create();

        // Same broken-output path, but under the en locale the fallback explanation must be English —
        // the previously hardcoded Polish string must no longer leak to an English-speaking user.
        $this->app->setLocale('en');
        $this->fakeAssist('I am not JSON, I am a friendly assistant!');

        $this->actingAs($user)
            ->postJson(self::ENDPOINT, ['prompt' => 'every day at 9'])
            ->assertOk()
            ->assertJsonPath('data.feasible', false)
            ->assertJsonPath('data.explanation', 'Could not process the schedule description. Try describing it differently or set the schedule manually.');
    }

    public function test_unknown_model_keys_are_never_merged_into_the_response(): void
    {
        $user = User::factory()->create();

        // The model smuggles extra keys (top-level and inside config). None may reach the payload.
        $this->fakeAssist(json_encode([
            'feasible' => true,
            'config' => ['time' => ['mode' => 'at', 'at' => ['09:00']], 'evil' => 'x'],
            'unsupported' => [],
            'alternative' => null,
            'explanation' => 'ok',
            'injected' => 'should not appear',
        ]));

        $data = $this->actingAs($user)
            ->postJson(self::ENDPOINT, ['prompt' => 'codziennie o 9'])
            ->assertOk()
            ->json('data');

        $this->assertSame(['feasible', 'config', 'unsupported', 'alternative', 'explanation'], array_keys($data));
        $this->assertArrayNotHasKey('injected', $data);
        $this->assertArrayNotHasKey('evil', $data['config']);
    }

    public function test_rate_limit_returns_429_after_the_configured_number_of_calls(): void
    {
        config()->set('workflows.assist_rate_per_minute', 2);

        $user = User::factory()->create();

        $this->fakeAssist([
            $this->validDailyJson(),
            $this->validDailyJson(),
            $this->validDailyJson(),
        ]);

        // First two succeed…
        $this->actingAs($user)->postJson(self::ENDPOINT, ['prompt' => 'codziennie o 9'])->assertOk();
        $this->actingAs($user)->postJson(self::ENDPOINT, ['prompt' => 'codziennie o 9'])->assertOk();

        // …the third is throttled.
        $this->actingAs($user)
            ->postJson(self::ENDPOINT, ['prompt' => 'codziennie o 9'])
            ->assertStatus(429);
    }

    public function test_feasible_multi_time_config_with_exclusions_passes_the_gate(): void
    {
        $user = User::factory()->create();

        // "codziennie o 8 i 17 oprócz weekendów" -> time.at with two fire times and a weekday
        // exclusion. Both must survive the whitelist AND the re-validation gate.
        $this->fakeAssist(json_encode([
            'feasible' => true,
            'config' => [
                'time' => ['mode' => 'at', 'at' => ['08:00', '17:00']],
                'exclusions' => ['weekdays' => [0, 6]],
            ],
            'unsupported' => [],
            'alternative' => null,
            'explanation' => 'Codziennie o 8:00 i 17:00, z pominięciem weekendów.',
        ]));

        $this->actingAs($user)
            ->postJson(self::ENDPOINT, ['prompt' => 'codziennie o 8 i 17 oprócz weekendów'])
            ->assertOk()
            ->assertJsonPath('data.feasible', true)
            ->assertJsonPath('data.config.time.at', ['08:00', '17:00'])
            ->assertJsonPath('data.config.exclusions.weekdays', [0, 6]);
    }

    public function test_member_gets_a_result(): void
    {
        $user = User::factory()->create();

        $this->fakeAssist($this->validDailyJson());

        $this->actingAs($user)
            ->postJson(self::ENDPOINT, ['prompt' => 'codziennie o 9'])
            ->assertOk()
            ->assertJsonPath('data.feasible', true);
    }

    private function validDailyJson(): string
    {
        return json_encode([
            'feasible' => true,
            'config' => ['time' => ['mode' => 'at', 'at' => ['09:00']]],
            'unsupported' => [],
            'alternative' => null,
            'explanation' => 'ok',
        ]);
    }
}
