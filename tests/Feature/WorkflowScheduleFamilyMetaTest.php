<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Workflows\Enums\WorkflowScheduleFamily;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The schedule-family discovery endpoint (GET /api/workflows/meta/schedule-families). It is the
 * CONTRACT the FE schedule builder and the future AI-assist batch consume: the full family list
 * with per-param descriptors, derived from the one backend source of truth
 * (WorkflowScheduleFamily::paramDescriptors). Guests are refused; any authenticated member reads it.
 */
class WorkflowScheduleFamilyMetaTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/api/workflows/meta/schedule-families';

    public function test_guest_is_unauthenticated(): void
    {
        $this->getJson(self::ENDPOINT)->assertUnauthorized();
    }

    public function test_member_gets_the_full_family_list_with_descriptors(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson(self::ENDPOINT)->assertOk();

        // Every family the enum defines is present, in enum order.
        $response->assertJsonCount(count(WorkflowScheduleFamily::cases()), 'data');
        $this->assertSame(
            WorkflowScheduleFamily::ids(),
            array_column($response->json('data'), 'family'),
        );

        // Each entry carries a params descriptor list of {name, type, required} (+ optional bounds).
        $response->assertJsonStructure([
            'data' => [
                '*' => [
                    'family',
                    'params' => [
                        '*' => ['name', 'type', 'required'],
                    ],
                ],
            ],
        ]);
    }

    public function test_descriptors_match_the_enum_source_of_truth(): void
    {
        $user = User::factory()->create();

        $data = $this->actingAs($user)->getJson(self::ENDPOINT)->assertOk()->json('data');
        $byFamily = collect($data)->keyBy('family');

        foreach (WorkflowScheduleFamily::cases() as $family) {
            $this->assertSame(
                $family->paramDescriptors(),
                $byFamily[$family->value]['params'],
                "descriptors for {$family->value} must mirror the enum",
            );
        }

        // Spot-check a representative family's contract for the consumers.
        $everyNHours = $byFamily['every_n_hours']['params'];
        $this->assertSame('n', $everyNHours[0]['name']);
        $this->assertSame('int', $everyNHours[0]['type']);
        $this->assertTrue($everyNHours[0]['required']);
        $this->assertSame(2, $everyNHours[0]['min']);
        $this->assertSame(12, $everyNHours[0]['max']);
        $this->assertFalse($everyNHours[1]['required']); // optional minute

        // Spot-check the weekday_list type on weekly: the descriptor surfaces the ELEMENT bounds
        // (0..6) so the FE knows which array member values are legal.
        $weekly = $byFamily['weekly']['params'];
        $this->assertSame('weekdays', $weekly[0]['name']);
        $this->assertSame('weekday_list', $weekly[0]['type']);
        $this->assertTrue($weekly[0]['required']);
        $this->assertSame(0, $weekly[0]['min']);
        $this->assertSame(6, $weekly[0]['max']);

        // Spot-check the weekday-of-month families are now part of the vocabulary.
        $this->assertSame('ordinal', $byFamily['nth_weekday_of_month']['params'][0]['name']);
        $this->assertSame('last_working_day_of_month', $byFamily['last_working_day_of_month']['family']);
    }
}
