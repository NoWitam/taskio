<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Comments\Models\Comment;
use App\Modules\Disk\Models\File;
use App\Modules\Disk\Models\Folder;
use App\Modules\Workspaces\Models\Workspace;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Preview P2 — comments on disk files and folders through the GENERIC comments module
 * (/{module}/{id}/comments, module = the singular morph alias, like the changelog endpoint).
 * Pins: both morphs resolve, the author + workspace are stamped, a foreign id 404s at resolve,
 * and delete rights = comment author OR the item's human owner (CommentPolicy via isOwnedBy).
 */
class DiskCommentsTest extends TestCase
{
    use RefreshDatabase;

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

    /** Build models as another workspace's tenant (mirrors FolderApiTest). */
    private function within(Workspace $workspace, Closure $build)
    {
        $context = app(TenantContext::class);
        $context->set($workspace);

        try {
            return $build();
        } finally {
            $context->set($this->workspace);
        }
    }

    public function test_a_file_and_a_folder_can_be_commented_and_listed(): void
    {
        $file = File::factory()->atRoot()->create();
        $folder = Folder::factory()->create();

        $this->postJson('/api/file/' . $file->id . '/comments', ['content' => 'Świetny plik'])
            ->assertSuccessful()
            ->assertJsonPath('data.author.id', $this->user->id);

        $this->postJson('/api/folder/' . $folder->id . '/comments', ['content' => 'Uwaga do folderu'])
            ->assertSuccessful();

        $this->assertCount(1, $this->getJson('/api/file/' . $file->id . '/comments')->assertOk()->json('data'));
        $this->assertCount(1, $this->getJson('/api/folder/' . $folder->id . '/comments')->assertOk()->json('data'));

        // The pivot rows carry the workspace + the polymorphic subject.
        $comment = Comment::query()->where('commentable_id', $file->id)->first();
        $this->assertSame('file', $comment->commentable_type);
        $this->assertSame($this->workspace->id, $comment->workspace_id);
    }

    public function test_a_foreign_workspace_item_cannot_be_commented(): void
    {
        $otherOwner = User::factory()->create();
        $otherWorkspace = Workspace::factory()->create(['owner_id' => $otherOwner->id]);
        $foreign = $this->within($otherWorkspace, fn () => File::factory()->atRoot()->create());

        $this->postJson('/api/file/' . $foreign->id . '/comments', ['content' => 'x'])->assertNotFound();
        $this->getJson('/api/file/' . $foreign->id . '/comments')->assertNotFound();
    }

    public function test_delete_rights_follow_the_author_or_the_items_owner(): void
    {
        // Explicit owner: the factory default stamps a RANDOM uploader (HasCreator keeps an
        // explicit id), and CommentPolicy::delete checks pure isOwnedBy — no workspace-owner
        // fallback — so the file must belong to $this->user for the owner branch to fire.
        $file = File::factory()->atRoot()->create(['uploader_id' => $this->user->id]);

        $author = User::factory()->create();
        $this->workspace->users()->attach($author->id);
        $third = User::factory()->create();
        $this->workspace->users()->attach($third->id);

        // A member comments the owner's file…
        $commentId = $this->actingAs($author)->withHeader('X-Workspace-Id', $this->workspace->id)
            ->postJson('/api/file/' . $file->id . '/comments', ['content' => 'komentarz'])
            ->assertSuccessful()
            ->json('data.id');

        // …a THIRD member may not delete it…
        $this->actingAs($third)->withHeader('X-Workspace-Id', $this->workspace->id)
            ->deleteJson('/api/comments/' . $commentId)
            ->assertForbidden();

        // …but the FILE'S OWNER may (CommentPolicy::delete via isOwnedBy).
        $this->actingAs($this->user)->withHeader('X-Workspace-Id', $this->workspace->id)
            ->deleteJson('/api/comments/' . $commentId)
            ->assertSuccessful();
    }
}
