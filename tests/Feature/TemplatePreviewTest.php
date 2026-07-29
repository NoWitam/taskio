<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Variables\Models\Constant;
use App\Modules\Workspaces\Models\Workspace;
use Database\Factories\TemplateFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The FAITHFUL, PER-PART template PREVIEW endpoint (POST /generator/preview): it renders a draft recipe
 * through the SHARED resolver against sample slot values + the workspace globals, returning
 * `{data:{parts:{<key>:{rendered}|{plan}}}}`. A text_body/script part renders its markdown body (with
 * `@[ai-text]` INERT-but-LABELED `[AI: …]`); an image_plan part renders a PLAN summary (base + chain,
 * prompts resolved, NO image executed); a shot_list part renders its resolved brief; a storyboard part
 * renders a plan summary (style + filters); a scene_plan part renders a per-scene summary (script /
 * scene_plan are legacy back-compat kinds — see ADR-0035). Every path is fail-soft; an unknown root is
 * not a reference. Draft-friendly — the whole recipe rides the request.
 */
class TemplatePreviewTest extends TestCase
{
    use RefreshDatabase;

    private function workspaceFor(User $user): Workspace
    {
        $workspace = Workspace::factory()->create(['owner_id' => $user->id]);
        $workspace->users()->attach($user->id);

        return $workspace;
    }

    private function directive(string $id, array $pipeline = []): string
    {
        return TemplateFactory::directive($id, $pipeline);
    }

    /** An `@[ai-text]("<payload>")` directive with a plain prompt. */
    private function aiText(string $prompt): string
    {
        $json = json_encode(['v' => 1, 'data' => ['id' => 'ai1', 'personaId' => null, 'prompt' => $prompt, 'labels' => []]]);

        return '@[ai-text]("' . str_replace('"', '\\"', $json) . '")';
    }

    /** Preview a recipe, returning the whole `data` (parts map). */
    private function preview(User $user, string $contentType, array $content, array $slots, array $slotValues, ?Workspace $workspace = null): array
    {
        $request = $this->actingAs($user);

        if ($workspace !== null) {
            $request = $request->withHeader('X-Workspace-Id', $workspace->id);
        }

        return $request->postJson('/api/generator/preview', [
            'content_type' => $contentType,
            'content' => $content,
            'slots' => $slots,
            'slot_values' => $slotValues,
        ])->assertOk()->json('data');
    }

    /** Preview a `post` body markdown, returning the rendered string of its `body` part. */
    private function renderBody(User $user, string $markdown, array $slots, array $slotValues, ?Workspace $workspace = null): string
    {
        $data = $this->preview($user, 'post', ['body' => ['markdown' => $markdown]], $slots, $slotValues, $workspace);

        return $data['parts']['body']['rendered'];
    }

    // ---- text_body part -------------------------------------------------------

    public function test_a_piped_slot_renders_its_transformed_value(): void
    {
        $user = User::factory()->create();

        $rendered = $this->renderBody(
            $user,
            'Topic: ' . $this->directive('slots.topic', [['op' => 'text_uppercase']]) . '!',
            [['name' => 'topic', 'descriptor' => ['base' => 'text']]],
            ['topic' => 'hello'],
        );

        $this->assertSame('Topic: HELLO!', $rendered);
    }

    public function test_an_identity_slot_renders_its_value(): void
    {
        $user = User::factory()->create();

        $rendered = $this->renderBody(
            $user,
            'About ' . $this->directive('slots.topic') . '.',
            [['name' => 'topic', 'descriptor' => ['base' => 'text']]],
            ['topic' => 'Taskio'],
        );

        $this->assertSame('About Taskio.', $rendered);
    }

    public function test_ai_text_renders_an_inert_but_labeled_placeholder(): void
    {
        $user = User::factory()->create();

        // Sub-stage 1 binds a NO-OP AiTextGenerator that emits a LABELED `[AI: <resolved prompt>]`
        // placeholder (not empty) so an author sees where the AI lands — no real AI call runs.
        $rendered = $this->renderBody(
            $user,
            'Intro: ' . $this->aiText('Write a punchy hook') . ' End.',
            [],
            [],
        );

        $this->assertSame('Intro: [AI: Write a punchy hook] End.', $rendered);
        $this->assertStringNotContainsString('@[ai-text]', $rendered);
    }

    public function test_ai_text_placeholder_shows_the_resolved_prompt(): void
    {
        $user = User::factory()->create();

        // The ai-text prompt is resolved FIRST (slot values land in it), so the placeholder is faithful.
        $rendered = $this->renderBody(
            $user,
            $this->aiText('About ' . $this->directive('slots.topic')),
            [['name' => 'topic', 'descriptor' => ['base' => 'text']]],
            ['topic' => 'Taskio'],
        );

        $this->assertSame('[AI: About Taskio]', $rendered);
    }

    public function test_an_unknown_root_is_not_a_reference_and_renders_empty(): void
    {
        $user = User::factory()->create();

        $rendered = $this->renderBody($user, $this->directive('nope.foo'), [], []);

        $this->assertSame('', $rendered);
    }

    public function test_a_workspace_global_renders_in_the_preview(): void
    {
        $user = User::factory()->create();
        $workspace = $this->workspaceFor($user);
        Constant::factory()->text('brand', 'Taskio')->create(['creator_id' => $user->id, 'workspace_id' => $workspace->id]);

        $rendered = $this->renderBody($user, 'By ' . $this->directive('globals.brand') . '.', [], [], $workspace);

        $this->assertSame('By Taskio.', $rendered);
    }

    // ---- subfield references (unchanged engine, now under a part) --------------

    private function objectSlot(string $name = 'product'): array
    {
        $scalar = fn (string $base): array => ['base' => $base, 'nullable' => false, 'array' => false];

        return [
            'name' => $name,
            'descriptor' => [
                'base' => 'object',
                'nullable' => false,
                'array' => false,
                'fields' => [
                    ['key' => 'name', 'label' => 'Name', 'descriptor' => $scalar('text')],
                    ['key' => 'price', 'label' => 'Price', 'descriptor' => $scalar('number')],
                ],
            ],
        ];
    }

    public function test_an_object_slot_subfield_renders_its_sample_value(): void
    {
        $user = User::factory()->create();

        $rendered = $this->renderBody(
            $user,
            'Product: ' . $this->directive('slots.product.name') . '.',
            [$this->objectSlot()],
            ['product' => ['name' => 'Widget', 'price' => 9]],
        );

        $this->assertSame('Product: Widget.', $rendered);
    }

    public function test_a_file_slot_subfield_renders_its_sample_value(): void
    {
        $user = User::factory()->create();

        $rendered = $this->renderBody(
            $user,
            'See ' . $this->directive('slots.image.url') . '.',
            [['name' => 'image', 'descriptor' => TemplateFactory::fileDescriptor()]],
            ['image' => ['id' => 'f1', 'name' => 'photo.png', 'type' => 'image/png', 'size' => 2048, 'url' => 'https://cdn/x.png']],
        );

        $this->assertSame('See https://cdn/x.png.', $rendered);
    }

    public function test_preview_is_fail_soft_for_a_missing_slot_value(): void
    {
        $user = User::factory()->create();

        $rendered = $this->renderBody(
            $user,
            'Topic: ' . $this->directive('slots.topic') . '.',
            [['name' => 'topic', 'descriptor' => ['base' => 'text']]],
            [],
        );

        $this->assertSame('Topic: .', $rendered);
    }

    // ---- image_plan part = a PLAN summary -------------------------------------

    public function test_an_image_plan_part_renders_a_plan_summary_with_resolved_prompts(): void
    {
        $user = User::factory()->create();

        $data = $this->preview(
            $user,
            'post_with_image',
            [
                'body' => ['markdown' => 'Hi'],
                'image' => [
                    'base' => ['kind' => 'from_slot', 'slot' => 'photo'],
                    'filters' => [
                        ['kind' => 'pixel', 'op' => 'grayscale'],
                        ['kind' => 'ai_edit', 'prompt' => 'In the style of ' . $this->directive('slots.topic') . '.'],
                    ],
                ],
            ],
            [
                ['name' => 'topic', 'descriptor' => ['base' => 'text']],
                ['name' => 'photo', 'descriptor' => TemplateFactory::fileDescriptor()],
            ],
            ['topic' => 'noir'],
        );

        $image = $data['parts']['image'];

        // NO `rendered` (an image is a PLAN, not a string) — a `plan` with the base + ordered filters.
        $this->assertArrayNotHasKey('rendered', $image);
        $this->assertSame('from_slot', $image['plan']['base']['kind']);
        $this->assertSame('photo', $image['plan']['base']['slot']);
        $this->assertSame('pixel', $image['plan']['filters'][0]['kind']);
        $this->assertSame('grayscale', $image['plan']['filters'][0]['op']);
        // The ai_edit prompt is directive-resolved against the sample slot value.
        $this->assertSame('ai_edit', $image['plan']['filters'][1]['kind']);
        $this->assertSame('In the style of noir.', $image['plan']['filters'][1]['prompt']);
    }

    // ---- video_script (Phase B) = a shot_list brief + a storyboard summary ------

    public function test_a_shot_list_part_renders_its_resolved_brief_and_the_storyboard_summary(): void
    {
        $user = User::factory()->create();

        $data = $this->preview(
            $user,
            'video_script',
            [
                // The shot_list brief is a body — its slot directive resolves against the sample value.
                'shot_list' => ['brief' => ['markdown' => 'A short video about ' . $this->directive('slots.topic') . '.']],
                // The storyboard's style prompt resolves too; its authored filter chain is summarized (no image
                // is executed in a preview — the per-shot images are produced only in a real run).
                'storyboard' => [
                    'style' => ['markdown' => 'Flat vector, ' . $this->directive('slots.topic') . ' palette'],
                    'filters' => [['kind' => 'pixel', 'op' => 'sepia']],
                ],
            ],
            [['name' => 'topic', 'descriptor' => ['base' => 'text']]],
            ['topic' => 'the sea'],
        );

        $this->assertSame('A short video about the sea.', $data['parts']['shot_list']['brief']);

        $storyboard = $data['parts']['storyboard']['plan'];
        $this->assertSame('Flat vector, the sea palette', $storyboard['style']);
        $this->assertSame('sepia', $storyboard['filters'][0]['op']);
        // No authored cap → null (the run then uses the platform ceiling).
        $this->assertNull($storyboard['max_shots']);
    }

    public function test_the_storyboard_summary_surfaces_an_authored_max_shots(): void
    {
        // The preview shows HOW MANY frames the recipe will produce, so an author sees the cost/shape of the
        // run before they run it.
        $data = $this->preview(
            User::factory()->create(),
            'video_script',
            [
                'shot_list' => ['brief' => ['markdown' => 'A short video']],
                'storyboard' => ['style' => ['markdown' => 'Flat vector'], 'max_shots' => 3],
            ],
            [],
            [],
        );

        $this->assertSame(3, $data['parts']['storyboard']['plan']['max_shots']);
    }

    public function test_preview_of_an_unknown_content_type_returns_no_parts(): void
    {
        $user = User::factory()->create();

        $data = $this->preview($user, 'nonsense', ['body' => ['markdown' => 'Hi']], [], []);

        $this->assertSame([], $data['parts']);
    }

    public function test_preview_is_unauthenticated_for_a_guest(): void
    {
        $this->postJson('/api/generator/preview', ['content_type' => 'post', 'content' => [], 'slots' => [], 'slot_values' => []])
            ->assertUnauthorized();
    }

    public function test_a_slot_value_containing_reference_bytes_renders_verbatim_no_second_order_injection(): void
    {
        // HARDENING PIN (adversarial review): `slot_values` are USER-CONTROLLED — the preview's injection
        // surface. A resolved SLOT VALUE that literally contains reference/directive bytes must be inserted
        // VERBATIM, never re-scanned as a SECOND-ORDER reference (the shared resolver's NUL-mask, inherited
        // by the per-part render). This pins it so it can't regress into an injection/exfiltration.
        $user = User::factory()->create();
        $workspace = $this->workspaceFor($user);

        $secret = 'S3CR3T-must-never-leak';
        Constant::factory()->text('secret', $secret)->create(['creator_id' => $user->id, 'workspace_id' => $workspace->id]);

        $rendered = $this->renderBody(
            $user,
            'Slot: ' . $this->directive('slots.note') . ' | Trigger: ' . $this->directive('slots.trig'),
            [
                ['name' => 'note', 'descriptor' => ['base' => 'text']],
                ['name' => 'trig', 'descriptor' => ['base' => 'text']],
            ],
            [
                'note' => '{{globals.secret}} and @[variable]("x")',
                'trig' => '{{trigger.x}}',
            ],
            $workspace,
        );

        $this->assertSame('Slot: {{globals.secret}} and @[variable]("x") | Trigger: {{trigger.x}}', $rendered);
        $this->assertStringNotContainsString($secret, $rendered);
    }
}
