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
            'config' => ['family' => 'daily', 'params' => ['time' => '09:00']],
            'unsupported' => [],
            'alternative' => null,
            'explanation' => 'Codziennie o 9:00.',
        ]));

        $this->actingAs($user)
            ->postJson(self::ENDPOINT, ['prompt' => 'codziennie o 9'])
            ->assertOk()
            ->assertJsonPath('data.feasible', true)
            ->assertJsonPath('data.config.family', 'daily')
            ->assertJsonPath('data.config.params.time', '09:00')
            ->assertJsonPath('data.alternative', null);
    }

    public function test_tz_hint_is_merged_into_a_config_without_one(): void
    {
        $user = User::factory()->create();

        $this->fakeAssist(json_encode([
            'feasible' => true,
            'config' => ['family' => 'daily', 'params' => ['time' => '09:00']],
            'unsupported' => [],
            'alternative' => null,
            'explanation' => 'ok',
        ]));

        $this->actingAs($user)
            ->postJson(self::ENDPOINT, ['prompt' => 'codziennie o 9', 'tz' => 'Europe/Warsaw'])
            ->assertOk()
            ->assertJsonPath('data.config.tz', 'Europe/Warsaw');
    }

    public function test_hallucinated_family_is_downgraded(): void
    {
        $user = User::factory()->create();

        // The model claims feasible with a family that does not exist. The re-validation must
        // refuse to trust it: feasible->false, config->null, a note appended to unsupported.
        $this->fakeAssist(json_encode([
            'feasible' => true,
            'config' => ['family' => 'every_full_moon', 'params' => []],
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

        // every_n_hours n is 2..12; n=40 is out of range. A real family but an invalid config —
        // must be downgraded exactly like a hallucinated family.
        $this->fakeAssist(json_encode([
            'feasible' => true,
            'config' => ['family' => 'every_n_hours', 'params' => ['n' => 40]],
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

        // "co drugi dzień" (every other day) is not expressible. The model is honest: feasible
        // false, unsupported populated, and a VALID daily alternative — which must survive
        // re-validation and be returned.
        $this->fakeAssist(json_encode([
            'feasible' => false,
            'config' => null,
            'unsupported' => ['co drugi dzień — brak rodziny „co N dni”'],
            'alternative' => [
                'config' => ['family' => 'daily', 'params' => ['time' => '08:00']],
                'note' => 'Zaproponowano codziennie zamiast co drugi dzień.',
            ],
            'explanation' => 'Nie można ustawić co drugi dzień; proponuję codziennie.',
        ]));

        $this->actingAs($user)
            ->postJson(self::ENDPOINT, ['prompt' => 'co drugi dzień o 8'])
            ->assertOk()
            ->assertJsonPath('data.feasible', false)
            ->assertJsonPath('data.config', null)
            ->assertJsonPath('data.alternative.config.family', 'daily')
            ->assertJsonPath('data.alternative.config.params.time', '08:00')
            ->assertJsonPath('data.unsupported.0', 'co drugi dzień — brak rodziny „co N dni”');
    }

    public function test_invalid_alternative_is_dropped_and_unsupported_preserved(): void
    {
        $user = User::factory()->create();

        // Infeasible main, and an alternative whose config is itself invalid (twice_daily with
        // first_hour >= second_hour). The alternative must be dropped; unsupported preserved.
        $this->fakeAssist(json_encode([
            'feasible' => false,
            'config' => null,
            'unsupported' => ['ostatni piątek miesiąca — brak rodziny dzień-tygodnia-miesiąca'],
            'alternative' => [
                'config' => ['family' => 'twice_daily', 'params' => ['first_hour' => 18, 'second_hour' => 9]],
                'note' => 'nonsens',
            ],
            'explanation' => 'Nie można.',
        ]));

        $this->actingAs($user)
            ->postJson(self::ENDPOINT, ['prompt' => 'ostatni piątek miesiąca'])
            ->assertOk()
            ->assertJsonPath('data.feasible', false)
            ->assertJsonPath('data.config', null)
            ->assertJsonPath('data.alternative', null)
            ->assertJsonPath('data.unsupported.0', 'ostatni piątek miesiąca — brak rodziny dzień-tygodnia-miesiąca');
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

    public function test_unknown_model_keys_are_never_merged_into_the_response(): void
    {
        $user = User::factory()->create();

        // The model smuggles extra keys (top-level and inside config). None may reach the payload.
        $this->fakeAssist(json_encode([
            'feasible' => true,
            'config' => ['family' => 'daily', 'params' => ['time' => '09:00'], 'evil' => 'x'],
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

    public function test_feasible_config_with_times_and_exclusions_passes_the_gate(): void
    {
        $user = User::factory()->create();

        // "codziennie o 8 i 17 oprócz weekendów" -> daily with a times[] list and a weekday
        // exclusion. The optional keys must survive the whitelist AND the re-validation gate.
        $this->fakeAssist(json_encode([
            'feasible' => true,
            'config' => [
                'family' => 'daily',
                'params' => [],
                'times' => ['08:00', '17:00'],
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
            ->assertJsonPath('data.config.family', 'daily')
            ->assertJsonPath('data.config.times', ['08:00', '17:00'])
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
            'config' => ['family' => 'daily', 'params' => ['time' => '09:00']],
            'unsupported' => [],
            'alternative' => null,
            'explanation' => 'ok',
        ]);
    }
}
