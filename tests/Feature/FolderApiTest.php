<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Disk\Models\File;
use App\Modules\Disk\Models\Folder;
use App\Modules\Workspaces\Models\Workspace;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * R1-B3 — the folder tree API.
 *
 * The interesting half is the MOVE: because every folder stores its ancestors as a
 * materialized path, re-parenting a folder must re-anchor its whole subtree in a single
 * statement, and must refuse the two moves that would corrupt the tree (into itself, into its
 * own descendant) or exceed the depth cap.
 */
class FolderApiTest extends TestCase
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

    /** Build models as another workspace's tenant (mirrors CrossWorkspaceBindingTest). */
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

    // ---- Reading the tree -------------------------------------------------------

    public function test_the_index_lists_roots_and_then_one_level_at_a_time(): void
    {
        $root = Folder::factory()->create(['name' => 'Kampanie']);
        $child = Folder::factory()->childOf($root)->create(['name' => 'Wrzesień']);

        $roots = $this->getJson('/api/disk/folders');
        $roots->assertOk()->assertJsonCount(1, 'data');
        $roots->assertJsonPath('data.0.id', $root->id);
        // The tree needs to know whether a node opens before it loads that level.
        $roots->assertJsonPath('data.0.has_children', true);

        $level = $this->getJson('/api/disk/folders?parent_id=' . $root->id);
        $level->assertOk()->assertJsonCount(1, 'data');
        $level->assertJsonPath('data.0.id', $child->id);
        $level->assertJsonPath('data.0.has_children', false);
    }

    public function test_show_returns_breadcrumbs_root_first(): void
    {
        $root = Folder::factory()->create(['name' => 'A']);
        $child = Folder::factory()->childOf($root)->create(['name' => 'B']);
        $grandchild = Folder::factory()->childOf($child)->create(['name' => 'C']);

        $response = $this->getJson('/api/disk/folders/' . $grandchild->id);

        $response->assertOk();
        $response->assertJsonPath('data.name', 'C');
        $response->assertJsonPath('data.depth', 3);
        $response->assertJsonPath('breadcrumbs.0.name', 'A');
        $response->assertJsonPath('breadcrumbs.1.name', 'B');
        $response->assertJsonCount(2, 'breadcrumbs');
    }

    public function test_the_raw_path_encoding_is_not_exposed(): void
    {
        $folder = Folder::factory()->create();

        $response = $this->getJson('/api/disk/folders/' . $folder->id);

        // Clients get ancestor_ids; `path` is an internal encoding they must not depend on.
        $response->assertJsonMissingPath('data.path');
        $response->assertJsonPath('data.ancestor_ids', []);
    }

    // ---- Creating / renaming ----------------------------------------------------

    public function test_a_folder_can_be_created_at_the_root_and_inside_a_parent(): void
    {
        $root = $this->postJson('/api/disk/folders', ['name' => 'Zasoby']);
        $root->assertCreated()->assertJsonPath('data.depth', 1);

        $child = $this->postJson('/api/disk/folders', [
            'name' => 'Grafiki',
            'parent_id' => $root->json('data.id'),
        ]);

        $child->assertCreated();
        $child->assertJsonPath('data.parent_id', $root->json('data.id'));
        $child->assertJsonPath('data.depth', 2);
        $child->assertJsonPath('data.ancestor_ids', [$root->json('data.id')]);
    }

    public function test_sibling_names_must_be_unique_including_at_the_root(): void
    {
        Folder::factory()->create(['name' => 'Duplikat']);

        // Postgres treats NULLs as distinct, so the DB unique index does NOT cover root-level
        // siblings — the service guard has to.
        $this->postJson('/api/disk/folders', ['name' => 'Duplikat'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');
    }

    public function test_a_folder_from_another_workspace_cannot_be_used_as_a_parent(): void
    {
        $otherOwner = User::factory()->create();
        $otherWorkspace = Workspace::factory()->create(['owner_id' => $otherOwner->id]);
        $foreign = $this->within($otherWorkspace, fn () => Folder::factory()->create());

        $this->postJson('/api/disk/folders', ['name' => 'X', 'parent_id' => $foreign->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('parent_id');
    }

    public function test_a_folder_can_be_renamed(): void
    {
        $folder = Folder::factory()->create(['name' => 'Stara']);

        $this->patchJson('/api/disk/folders/' . $folder->id, ['name' => 'Nowa'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Nowa');
    }

    public function test_creating_deeper_than_the_cap_is_rejected(): void
    {
        $parent = Folder::factory()->create();
        for ($depth = 2; $depth < Folder::MAX_DEPTH; $depth++) {
            $parent = Folder::factory()->childOf($parent)->create();
        }
        $this->assertSame(Folder::MAX_DEPTH - 1, $parent->depth());

        // The last legal level.
        $deepest = $this->postJson('/api/disk/folders', ['name' => 'ostatni', 'parent_id' => $parent->id]);
        $deepest->assertCreated()->assertJsonPath('data.depth', Folder::MAX_DEPTH);

        $this->postJson('/api/disk/folders', ['name' => 'za-gleboko', 'parent_id' => $deepest->json('data.id')])
            ->assertStatus(422)
            ->assertJsonValidationErrors('parent_id');
    }

    // ---- Moving -----------------------------------------------------------------

    public function test_moving_a_folder_re_anchors_its_whole_subtree_in_one_statement(): void
    {
        $source = Folder::factory()->create(['name' => 'source']);
        $moved = Folder::factory()->childOf($source)->create(['name' => 'moved']);
        $child = Folder::factory()->childOf($moved)->create(['name' => 'child']);
        $grandchild = Folder::factory()->childOf($child)->create(['name' => 'grandchild']);
        $target = Folder::factory()->create(['name' => 'target']);

        DB::enableQueryLog();
        $response = $this->postJson('/api/disk/folders/' . $moved->id . '/move', [
            'target_folder_id' => $target->id,
        ]);
        $updates = collect(DB::getQueryLog())
            ->filter(fn (array $entry) => str_starts_with(strtolower($entry['query']), 'update "folders"'))
            ->count();
        DB::disableQueryLog();

        $response->assertOk()->assertJsonPath('data.parent_id', $target->id);

        // The moved row itself + ONE re-anchor covering every descendant, no matter how many.
        $this->assertSame(2, $updates, 'A subtree move must not walk the tree row by row.');

        $this->assertSame("/{$target->id}/", $moved->refresh()->path);
        $this->assertSame("/{$target->id}/{$moved->id}/", $child->refresh()->path);
        $this->assertSame("/{$target->id}/{$moved->id}/{$child->id}/", $grandchild->refresh()->path);
        $this->assertSame(4, $grandchild->depth());
    }

    public function test_a_folder_can_be_moved_to_the_root(): void
    {
        $parent = Folder::factory()->create();
        $moved = Folder::factory()->childOf($parent)->create();
        $child = Folder::factory()->childOf($moved)->create();

        $this->postJson('/api/disk/folders/' . $moved->id . '/move', ['target_folder_id' => null])
            ->assertOk()
            ->assertJsonPath('data.parent_id', null)
            ->assertJsonPath('data.depth', 1);

        $this->assertSame("/{$moved->id}/", $child->refresh()->path);
    }

    public function test_a_folder_cannot_be_moved_into_itself(): void
    {
        $folder = Folder::factory()->create();

        $this->postJson('/api/disk/folders/' . $folder->id . '/move', ['target_folder_id' => $folder->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('target_folder_id');
    }

    public function test_a_folder_cannot_be_moved_into_its_own_descendant(): void
    {
        $folder = Folder::factory()->create();
        $child = Folder::factory()->childOf($folder)->create();
        $grandchild = Folder::factory()->childOf($child)->create();

        // This would cut the subtree loose from the tree entirely.
        $this->postJson('/api/disk/folders/' . $folder->id . '/move', ['target_folder_id' => $grandchild->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('target_folder_id');

        $this->assertSame('/', $folder->refresh()->path, 'A refused move must change nothing.');
    }

    public function test_a_move_that_would_push_a_descendant_past_the_cap_is_rejected(): void
    {
        // A two-level subtree...
        $moved = Folder::factory()->create();
        $child = Folder::factory()->childOf($moved)->create();

        // ...cannot be moved under a folder that is already at the cap minus one: the child
        // would land one level too deep. The guard must consider the SUBTREE, not the folder.
        $target = Folder::factory()->create();
        for ($depth = 2; $depth < Folder::MAX_DEPTH; $depth++) {
            $target = Folder::factory()->childOf($target)->create();
        }

        $this->postJson('/api/disk/folders/' . $moved->id . '/move', ['target_folder_id' => $target->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('parent_id');

        $this->assertSame('/', $moved->refresh()->path);
        $this->assertSame("/{$moved->id}/", $child->refresh()->path);
    }

    public function test_moving_into_a_folder_that_already_has_that_name_is_rejected(): void
    {
        $moved = Folder::factory()->create(['name' => 'Grafiki']);
        $target = Folder::factory()->create(['name' => 'target']);
        Folder::factory()->childOf($target)->create(['name' => 'Grafiki']);

        $this->postJson('/api/disk/folders/' . $moved->id . '/move', ['target_folder_id' => $target->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');
    }

    // ---- Trash / restore ---------------------------------------------------------

    public function test_an_empty_folder_can_be_trashed_and_restored(): void
    {
        $folder = Folder::factory()->create();

        $this->deleteJson('/api/disk/folders/' . $folder->id)->assertNoContent();
        $this->assertSoftDeleted('folders', ['id' => $folder->id]);

        $this->postJson('/api/disk/folders/' . $folder->id . '/restore')->assertOk();
        $this->assertNotSoftDeleted('folders', ['id' => $folder->id]);
    }

    public function test_the_index_lists_trashed_folders_flat_and_only_there(): void
    {
        $alive = Folder::factory()->create(['name' => 'Alive']);
        $trashed = Folder::factory()->create(['name' => 'Binned']);
        $this->deleteJson('/api/disk/folders/' . $trashed->id)->assertNoContent();

        // The trash listing shows ONLY trashed folders; the live index shows only live ones.
        $trashedIds = collect($this->getJson('/api/disk/folders?trashed=1')->json('data'))->pluck('id')->all();
        $this->assertSame([$trashed->id], $trashedIds);

        $liveIds = collect($this->getJson('/api/disk/folders')->json('data'))->pluck('id')->all();
        $this->assertSame([$alive->id], $liveIds);
    }

    public function test_a_folder_holding_subfolders_or_files_refuses_to_be_trashed(): void
    {
        $withChild = Folder::factory()->create();
        Folder::factory()->childOf($withChild)->create();

        $withFile = Folder::factory()->create();
        File::factory()->create(['folder_id' => $withFile->id]);

        // No surprise mass-deletes: the user empties it first (cascade is a later decision).
        $this->deleteJson('/api/disk/folders/' . $withChild->id)->assertStatus(422);
        $this->deleteJson('/api/disk/folders/' . $withFile->id)->assertStatus(422);

        $this->assertNotSoftDeleted('folders', ['id' => $withChild->id]);
        $this->assertNotSoftDeleted('folders', ['id' => $withFile->id]);
    }

    public function test_restoring_into_a_trashed_parent_falls_back_to_the_root(): void
    {
        $parent = Folder::factory()->create();
        $child = Folder::factory()->childOf($parent)->create();

        $child->delete();
        $parent->delete();

        // Otherwise the folder would come back invisible, buried under a deleted parent.
        $this->postJson('/api/disk/folders/' . $child->id . '/restore')
            ->assertOk()
            ->assertJsonPath('data.parent_id', null);

        $this->assertSame('/', $child->refresh()->path);
    }

    // ---- Isolation ----------------------------------------------------------------

    public function test_a_foreign_folder_404s_at_binding(): void
    {
        $otherOwner = User::factory()->create();
        $otherWorkspace = Workspace::factory()->create(['owner_id' => $otherOwner->id]);
        $foreign = $this->within($otherWorkspace, fn () => Folder::factory()->create());

        $this->getJson('/api/disk/folders/' . $foreign->id)->assertNotFound();
        $this->patchJson('/api/disk/folders/' . $foreign->id, ['name' => 'x'])->assertNotFound();
        $this->deleteJson('/api/disk/folders/' . $foreign->id)->assertNotFound();
        $this->postJson('/api/disk/folders/' . $foreign->id . '/move', [])->assertNotFound();
        $this->postJson('/api/disk/folders/' . $foreign->id . '/restore')->assertNotFound();
    }

    public function test_only_the_creator_may_mutate_a_folder(): void
    {
        $folder = Folder::factory()->create();

        $member = User::factory()->create();
        $this->workspace->users()->attach($member->id);

        // Reads stay open to the workspace; mutations are the creator's (ADR-0015 matrix).
        $this->actingAs($member)->withHeader('X-Workspace-Id', $this->workspace->id);

        $this->getJson('/api/disk/folders/' . $folder->id)->assertOk();
        $this->patchJson('/api/disk/folders/' . $folder->id, ['name' => 'x'])->assertForbidden();
        $this->deleteJson('/api/disk/folders/' . $folder->id)->assertForbidden();
    }
}
