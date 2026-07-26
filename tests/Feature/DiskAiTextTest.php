<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Disk\Agents\DiskTextAiAgent;
use App\Modules\Workspaces\Models\Workspace;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * FEATURE B — the SYNC AI text-edit flow: POST /disk/ai/text applies an instruction to text content
 * and returns the edited text inline (no queue — a gpt-4o text edit is fast, unlike the image edit).
 * The agent is faked via the built-in Laravel AI seam (DiskTextAiAgent::fake, exactly as the workflow
 * and schedule agents are faked), so no provider is ever called. The tests pin the contract: the
 * response shape, validation, the localized 502 on a provider failure, and workspace gating.
 */
class DiskAiTextTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/api/disk/ai/text';

    private User $user;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->workspace = Workspace::factory()->create(['owner_id' => $this->user->id]);
        $this->workspace->users()->attach($this->user->id);

        $this->actingAs($this->user)->withHeader('X-Workspace-Id', $this->workspace->id);
        app(TenantContext::class)->set($this->workspace);
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();

        parent::tearDown();
    }

    public function test_it_returns_the_edited_text(): void
    {
        $seen = null;
        DiskTextAiAgent::fake(function (string $prompt) use (&$seen) {
            $seen = $prompt;

            return 'Edited output.';
        });

        $this->postJson(self::ENDPOINT, [
            'content' => 'the original text',
            'prompt' => 'Fix the grammar',
        ])
            ->assertOk()
            ->assertExactJson(['data' => ['text' => 'Edited output.']]);

        // The user prompt carries BOTH the instruction and the content to transform.
        $this->assertStringContainsString('Fix the grammar', (string) $seen);
        $this->assertStringContainsString('the original text', (string) $seen);
    }

    public function test_it_validates_the_content_and_prompt(): void
    {
        DiskTextAiAgent::fake(fn () => 'SHOULD NOT BE CALLED');

        $this->postJson(self::ENDPOINT, ['prompt' => 'x'])
            ->assertStatus(422)->assertJsonValidationErrors('content');

        $this->postJson(self::ENDPOINT, ['content' => 'x'])
            ->assertStatus(422)->assertJsonValidationErrors('prompt');

        $this->postJson(self::ENDPOINT, ['content' => '', 'prompt' => 'x'])
            ->assertStatus(422)->assertJsonValidationErrors('content');

        $this->postJson(self::ENDPOINT, ['content' => str_repeat('a', 100_001), 'prompt' => 'x'])
            ->assertStatus(422)->assertJsonValidationErrors('content');

        $this->postJson(self::ENDPOINT, ['content' => 'x', 'prompt' => str_repeat('a', 2_001)])
            ->assertStatus(422)->assertJsonValidationErrors('prompt');
    }

    public function test_a_provider_failure_collapses_to_a_localized_502(): void
    {
        DiskTextAiAgent::fake(fn () => throw new RuntimeException('raw provider body - upstream 500'));

        $response = $this->postJson(self::ENDPOINT, [
            'content' => 'the original text',
            'prompt' => 'Rewrite it',
        ]);

        $response->assertStatus(502);
        // A localized, non-secret message — never the raw provider body.
        $response->assertJsonPath('message', __('disk.ai.failed'));
        $this->assertStringNotContainsString('raw provider body', $response->getContent());
    }

    public function test_it_requires_an_active_workspace(): void
    {
        DiskTextAiAgent::fake(fn () => 'unused');

        $this->flushHeaders();

        $this->actingAs($this->user)
            ->postJson(self::ENDPOINT, ['content' => 'x', 'prompt' => 'y'])
            ->assertStatus(400); // RequireWorkspace refuses before validation or the agent runs
    }

    public function test_a_non_member_is_forbidden(): void
    {
        DiskTextAiAgent::fake(fn () => 'unused');

        $outsider = User::factory()->create(); // not attached to the workspace

        $this->actingAs($outsider)
            ->withHeader('X-Workspace-Id', $this->workspace->id)
            ->postJson(self::ENDPOINT, ['content' => 'x', 'prompt' => 'y'])
            ->assertStatus(403); // ResolveWorkspace refuses a non-member
    }
}
