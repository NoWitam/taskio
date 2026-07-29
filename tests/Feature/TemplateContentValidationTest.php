<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Factories\TemplateFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The PER-PART content write-validation (TemplateContentValidator + ImagePlanValidator): the DATA-DRIVEN
 * gate that iterates the selected content type's PARTS and checks each BY KIND (D8). Pins that an unknown
 * part key and a missing REQUIRED part are 422s; that an image_plan's base/chain shape, its `from_slot`
 * file-slot rule, its pixel-op params and its ai_edit / ai_generate prompt directives are all validated;
 * and that a video_script's shot_list brief + optional storyboard style/filter chain (Phase B) are validated.
 * Fail-closed at write, so a session can never start from an un-runnable recipe.
 */
class TemplateContentValidationTest extends TestCase
{
    use RefreshDatabase;

    private function directive(string $id, array $pipeline = []): string
    {
        return TemplateFactory::directive($id, $pipeline);
    }

    private function textSlot(string $name = 'topic'): array
    {
        return ['name' => $name, 'descriptor' => ['base' => 'text', 'nullable' => false, 'array' => false]];
    }

    private function fileSlot(string $name = 'photo'): array
    {
        return ['name' => $name, 'description' => 'Hero image', 'descriptor' => TemplateFactory::fileDescriptor()];
    }

    private function postPayload(array $content, array $slots): array
    {
        return ['name' => 'T', 'content_type' => 'post', 'slots' => $slots, 'content' => $content];
    }

    /** A `post_with_image` payload with a text slot + a file slot the image plan can pull from. */
    private function postWithImage(array $imagePlan, array $extraSlots = []): array
    {
        return [
            'name' => 'T',
            'content_type' => 'post_with_image',
            'slots' => array_merge([$this->textSlot(), $this->fileSlot()], $extraSlots),
            'content' => [
                'body' => ['markdown' => 'About ' . $this->directive('slots.topic') . '.'],
                'image' => $imagePlan,
            ],
        ];
    }

    /** A `video_script` payload (Phase B) with an authored shot_list brief + an optional storyboard part. */
    private function videoScript(array $content): array
    {
        return [
            'name' => 'T',
            'content_type' => 'video_script',
            'slots' => [$this->textSlot()],
            'content' => $content,
        ];
    }

    // ---- unknown / missing parts ---------------------------------------------

    public function test_an_unknown_content_part_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/generator/templates', $this->postPayload([
                'body' => ['markdown' => 'Hi'],
                'headline' => ['markdown' => 'Nope'],
            ], [$this->textSlot()]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['content.headline']);
    }

    public function test_a_missing_required_part_is_rejected(): void
    {
        $user = User::factory()->create();

        // `post` requires its `body` part.
        $this->actingAs($user)
            ->postJson('/api/generator/templates', $this->postPayload([], [$this->textSlot()]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['content.body']);
    }

    public function test_an_optional_part_may_be_omitted(): void
    {
        $user = User::factory()->create();

        // `post_with_image`'s image part is optional — body alone is a valid recipe.
        $this->actingAs($user)
            ->postJson('/api/generator/templates', [
                'name' => 'T',
                'content_type' => 'post_with_image',
                'slots' => [$this->textSlot()],
                'content' => ['body' => ['markdown' => 'Just text']],
            ])
            ->assertCreated();
    }

    // ---- image plan: base -----------------------------------------------------

    public function test_image_plan_from_slot_must_name_a_declared_file_slot(): void
    {
        $user = User::factory()->create();

        // `topic` is a TEXT slot, not a file slot — a from_slot base may not name it.
        $this->actingAs($user)
            ->postJson('/api/generator/templates', $this->postWithImage([
                'base' => ['kind' => 'from_slot', 'slot' => 'topic'],
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['content.image.base.slot']);
    }

    public function test_image_plan_from_slot_naming_a_file_slot_is_accepted(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/generator/templates', $this->postWithImage([
                'base' => ['kind' => 'from_slot', 'slot' => 'photo'],
            ]))
            ->assertCreated();
    }

    public function test_image_plan_disk_file_base_is_accepted_decoupled(): void
    {
        $user = User::factory()->create();

        // BOUNDARY: the disk file id is an OPAQUE string here — its existence/ownership is deferred to
        // execution (sub-stage 2), so a well-formed id is accepted without a Disk lookup.
        $this->actingAs($user)
            ->postJson('/api/generator/templates', $this->postWithImage([
                'base' => ['kind' => 'disk_file', 'file' => 'some-disk-file-uuid'],
            ]))
            ->assertCreated();
    }

    public function test_image_plan_disk_file_base_requires_a_file_id(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/generator/templates', $this->postWithImage([
                'base' => ['kind' => 'disk_file', 'file' => ''],
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['content.image.base.file']);
    }

    public function test_image_plan_unknown_base_kind_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/generator/templates', $this->postWithImage([
                'base' => ['kind' => 'magic'],
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['content.image.base.kind']);
    }

    public function test_image_plan_ai_generate_base_prompt_directive_is_validated(): void
    {
        $user = User::factory()->create();

        // The ai_generate prompt references an unknown slot → rejected (D6: modeled + directive-validated).
        $this->actingAs($user)
            ->postJson('/api/generator/templates', $this->postWithImage([
                'base' => ['kind' => 'ai_generate', 'prompt' => 'Draw ' . $this->directive('slots.missing') . '.'],
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['content.image.base.prompt']);
    }

    // ---- image plan: filter chain --------------------------------------------

    public function test_image_plan_pixel_op_must_be_known(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/generator/templates', $this->postWithImage([
                'base' => ['kind' => 'from_slot', 'slot' => 'photo'],
                'filters' => [['kind' => 'pixel', 'op' => 'blur']],
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['content.image.filters.0.op']);
    }

    public function test_image_plan_pixel_op_params_are_validated(): void
    {
        $user = User::factory()->create();

        // brightness takes an amount in [-100, 100]; 999 is out of range.
        $this->actingAs($user)
            ->postJson('/api/generator/templates', $this->postWithImage([
                'base' => ['kind' => 'from_slot', 'slot' => 'photo'],
                'filters' => [['kind' => 'pixel', 'op' => 'brightness', 'params' => ['amount' => 999]]],
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['content.image.filters.0.params.amount']);
    }

    public function test_image_plan_ai_edit_prompt_slot_ref_is_validated(): void
    {
        $user = User::factory()->create();

        // An ai_edit prompt carries the SAME `@[variable]` directives — an unknown slot ref is a 422.
        $this->actingAs($user)
            ->postJson('/api/generator/templates', $this->postWithImage([
                'base' => ['kind' => 'from_slot', 'slot' => 'photo'],
                'filters' => [['kind' => 'ai_edit', 'prompt' => 'In the style of ' . $this->directive('slots.nope') . '.']],
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['content.image.filters.0.prompt']);
    }

    public function test_image_plan_full_chain_with_a_valid_slot_ref_is_accepted(): void
    {
        $user = User::factory()->create();

        // A from_slot base + a pixel op + an ai_edit prompt referencing a DECLARED slot type-flows.
        $this->actingAs($user)
            ->postJson('/api/generator/templates', $this->postWithImage([
                'base' => ['kind' => 'from_slot', 'slot' => 'photo'],
                'filters' => [
                    ['kind' => 'pixel', 'op' => 'grayscale'],
                    ['kind' => 'pixel', 'op' => 'brightness', 'params' => ['amount' => 20]],
                    ['kind' => 'ai_edit', 'prompt' => 'In the style of ' . $this->directive('slots.topic') . '.'],
                ],
            ]))
            ->assertCreated();
    }

    public function test_image_plan_filters_must_be_a_list(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/generator/templates', $this->postWithImage([
                'base' => ['kind' => 'from_slot', 'slot' => 'photo'],
                'filters' => ['not' => 'a list'],
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['content.image.filters']);
    }

    // ---- shot_list + storyboard (Phase B) -------------------------------------

    public function test_a_shot_list_brief_directive_is_validated(): void
    {
        $user = User::factory()->create();

        // The brief is a body — an unknown slot reference in it is a 422 (relocated under brief.markdown).
        $this->actingAs($user)
            ->postJson('/api/generator/templates', $this->videoScript([
                'shot_list' => ['brief' => ['markdown' => 'A video about ' . $this->directive('slots.ghost') . '.']],
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['content.shot_list.brief.markdown']);
    }

    public function test_a_missing_required_shot_list_is_rejected(): void
    {
        $user = User::factory()->create();

        // shot_list is the REQUIRED part of video_script; a storyboard alone is not a valid recipe.
        $this->actingAs($user)
            ->postJson('/api/generator/templates', $this->videoScript([
                'storyboard' => ['style' => ['markdown' => 'Flat vector']],
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['content.shot_list']);
    }

    public function test_a_shot_list_alone_is_accepted_storyboard_optional(): void
    {
        $user = User::factory()->create();

        // The storyboard is OPTIONAL — a shot list on its own is a valid recipe.
        $this->actingAs($user)
            ->postJson('/api/generator/templates', $this->videoScript([
                'shot_list' => ['brief' => ['markdown' => 'A short video about ' . $this->directive('slots.topic') . '.']],
            ]))
            ->assertCreated();
    }

    public function test_a_storyboard_style_directive_is_validated(): void
    {
        $user = User::factory()->create();

        // The storyboard's optional style prompt is a body — an unknown slot ref is a 422.
        $this->actingAs($user)
            ->postJson('/api/generator/templates', $this->videoScript([
                'shot_list' => ['brief' => ['markdown' => 'About ' . $this->directive('slots.topic') . '.']],
                'storyboard' => ['style' => ['markdown' => 'In the palette of ' . $this->directive('slots.ghost') . '.']],
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['content.storyboard.style.markdown']);
    }

    public function test_a_storyboard_filter_chain_is_validated(): void
    {
        $user = User::factory()->create();

        // The storyboard's authored per-shot filter chain reuses the image filter authority — an unknown pixel
        // op is a 422 under content.storyboard.filters.*.
        $this->actingAs($user)
            ->postJson('/api/generator/templates', $this->videoScript([
                'shot_list' => ['brief' => ['markdown' => 'About ' . $this->directive('slots.topic') . '.']],
                'storyboard' => ['filters' => [['kind' => 'pixel', 'op' => 'blur']]],
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['content.storyboard.filters.0.op']);
    }

    public function test_a_well_formed_shot_list_and_storyboard_is_accepted(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/generator/templates', $this->videoScript([
                'shot_list' => ['brief' => ['markdown' => 'A punchy video about ' . $this->directive('slots.topic') . '.']],
                'storyboard' => [
                    'style' => ['markdown' => 'Flat vector illustration of ' . $this->directive('slots.topic') . '.'],
                    'filters' => [['kind' => 'pixel', 'op' => 'sepia']],
                ],
            ]))
            ->assertCreated();
    }

    // ---- storyboard.max_shots (the per-template shot cap) ----------------------

    /** POST a video_script whose storyboard carries $maxShots, returning the response. */
    private function postMaxShots(mixed $maxShots): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs(User::factory()->create())
            ->postJson('/api/generator/templates', $this->videoScript([
                'shot_list' => ['brief' => ['markdown' => 'About ' . $this->directive('slots.topic') . '.']],
                'storyboard' => ['max_shots' => $maxShots],
            ]));
    }

    public function test_an_authored_max_shots_inside_the_platform_ceiling_is_accepted(): void
    {
        // The AUTHOR may TIGHTEN the platform ceiling per recipe; the run's effective cap is min(authored,
        // ceiling) and drives the shot-list instruction, the parse clamp AND the storyboard fan-out alike.
        config()->set('generator.storyboard_max_shots', 8);

        $this->postMaxShots(3)->assertCreated();
        $this->postMaxShots(1)->assertCreated();
        $this->postMaxShots(8)->assertCreated();
    }

    public function test_an_absent_or_null_max_shots_is_fine(): void
    {
        $this->actingAs(User::factory()->create())
            ->postJson('/api/generator/templates', $this->videoScript([
                'shot_list' => ['brief' => ['markdown' => 'About ' . $this->directive('slots.topic') . '.']],
                'storyboard' => ['style' => ['markdown' => 'Flat vector']],
            ]))
            ->assertCreated();

        $this->postMaxShots(null)->assertCreated();
    }

    public function test_an_out_of_range_or_non_integer_max_shots_is_422(): void
    {
        config()->set('generator.storyboard_max_shots', 8);

        // Above the platform ceiling — refused at WRITE rather than silently truncated at run time.
        $this->postMaxShots(9)->assertUnprocessable()->assertJsonValidationErrors(['content.storyboard.max_shots']);
        $this->postMaxShots(0)->assertUnprocessable()->assertJsonValidationErrors(['content.storyboard.max_shots']);
        $this->postMaxShots(-2)->assertUnprocessable()->assertJsonValidationErrors(['content.storyboard.max_shots']);
        $this->postMaxShots('3')->assertUnprocessable()->assertJsonValidationErrors(['content.storyboard.max_shots']);
        $this->postMaxShots(2.5)->assertUnprocessable()->assertJsonValidationErrors(['content.storyboard.max_shots']);
        $this->postMaxShots(['3'])->assertUnprocessable()->assertJsonValidationErrors(['content.storyboard.max_shots']);
    }
}
