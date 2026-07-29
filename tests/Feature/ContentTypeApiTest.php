<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The code-defined CONTENT TYPE catalog endpoint (GET /generator/content-types): the system content types
 * the template editor builds its data-driven sections from. Pins the wire shape
 * `{data:[{id,label,parts:[{key,kind,label,required,config}]}]}`, the THREE v1 system definitions and their
 * exact parts/kinds (post = [text_body]; post_with_image = [text_body, image_plan]; video_script =
 * [shot_list, storyboard] — Phase B rework), and the workspace-member read gate.
 */
class ContentTypeApiTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{label: string, parts: array<int, array<string, mixed>>}> keyed by id */
    private function fetch(User $user): array
    {
        $data = $this->actingAs($user)->getJson('/api/generator/content-types')->assertOk()->json('data');

        $byId = [];
        foreach ($data as $type) {
            $byId[$type['id']] = $type;
        }

        return $byId;
    }

    public function test_it_returns_the_three_system_content_types(): void
    {
        $types = $this->fetch(User::factory()->create());

        $this->assertSame(['post', 'post_with_image', 'video_script'], array_keys($types));
    }

    public function test_post_has_a_single_text_body_part(): void
    {
        $post = $this->fetch(User::factory()->create())['post'];

        $this->assertSame('Post', $post['label']);
        $this->assertCount(1, $post['parts']);
        $this->assertSame('body', $post['parts'][0]['key']);
        $this->assertSame('text_body', $post['parts'][0]['kind']);
        $this->assertTrue($post['parts'][0]['required']);
        $this->assertArrayHasKey('config', $post['parts'][0]);
    }

    public function test_post_with_image_has_a_text_body_and_an_image_plan_part(): void
    {
        $type = $this->fetch(User::factory()->create())['post_with_image'];

        $kinds = array_column($type['parts'], 'kind', 'key');
        $this->assertSame(['body' => 'text_body', 'image' => 'image_plan'], $kinds);

        // The image part is optional (a post_with_image need not carry an image plan).
        $image = collect($type['parts'])->firstWhere('key', 'image');
        $this->assertFalse($image['required']);
    }

    public function test_video_script_composes_a_shot_list_and_a_storyboard_part(): void
    {
        $type = $this->fetch(User::factory()->create())['video_script'];

        // Phase B rework: video_script is a STRUCTURED shot list (required) + a storyboard image fan-out
        // (optional — a shot list alone is a valid recipe).
        $kinds = array_column($type['parts'], 'kind', 'key');
        $this->assertSame(['shot_list' => 'shot_list', 'storyboard' => 'storyboard'], $kinds);

        $shotList = collect($type['parts'])->firstWhere('key', 'shot_list');
        $this->assertTrue($shotList['required']);

        $storyboard = collect($type['parts'])->firstWhere('key', 'storyboard');
        $this->assertFalse($storyboard['required']);
    }

    public function test_it_is_unauthenticated_for_a_guest(): void
    {
        $this->getJson('/api/generator/content-types')->assertUnauthorized();
    }
}
