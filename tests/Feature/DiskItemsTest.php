<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Disk\Models\DiskFileDraft;
use App\Modules\Disk\Models\File;
use App\Modules\Disk\Models\Folder;
use App\Modules\Workspaces\Models\Workspace;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The unified folder browse: `GET /api/disk/items/{folder?}` returns a folder's subfolders AND
 * disk-native files as ONE cursor-paginated list (folders first, then files) via the staged
 * paginator, plus the folder's breadcrumbs — replacing the old separate folders/files calls.
 */
class DiskItemsTest extends TestCase
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

    public function test_it_lists_subfolders_then_files_with_kind_and_breadcrumbs(): void
    {
        $parent = Folder::factory()->create(['name' => 'Parent']);
        $folder = Folder::factory()->childOf($parent)->create(['name' => 'Target']);
        Folder::factory()->childOf($folder)->create(['name' => 'Alpha']);
        Folder::factory()->childOf($folder)->create(['name' => 'Beta']);
        File::factory()->inFolder($folder)->create(['name' => 'one.pdf']);
        File::factory()->inFolder($folder)->create(['name' => 'two.pdf']);

        $response = $this->getJson('/api/disk/items/' . $folder->id);

        $response->assertOk()->assertJsonStructure([
            'data' => [['kind', 'id', 'name']],
            'links',
            'meta' => ['next_cursor'],
            'breadcrumbs',
        ]);

        $kinds = collect($response->json('data'))->pluck('kind')->all();
        // Folders come first, then files — the stage order.
        $this->assertSame(['folder', 'folder', 'file', 'file'], $kinds);
        // Breadcrumbs are the ANCESTORS (root first) — here just the parent.
        $response->assertJsonPath('breadcrumbs.0.id', $parent->id);
    }

    public function test_the_root_lists_placed_files_and_folders_but_not_temps(): void
    {
        Folder::factory()->create(['name' => 'RootFolder']);
        $placed = File::factory()->atRoot()->create(['name' => 'root.png']);
        // A genuine temp (no fileable yet) must NOT surface.
        $temp = File::factory()->create();

        $ids = collect($this->getJson('/api/disk/items')->assertOk()->json('data'))->pluck('id')->all();

        $this->assertContains($placed->id, $ids);
        $this->assertNotContains($temp->id, $ids);
    }

    public function test_items_flag_files_that_have_the_current_users_draft(): void
    {
        // Regression: the grid list 500'd (`File::draft()` undefined) once `has_draft` moved to the
        // per-user `draft` relation — pin that the items endpoint computes it, per user.
        $folder = Folder::factory()->create(['name' => 'Docs']);
        $mine = File::factory()->inFolder($folder)->create(['name' => 'mine.txt']);
        $theirs = File::factory()->inFolder($folder)->create(['name' => 'theirs.txt']);

        $other = User::factory()->create();
        $this->workspace->users()->attach($other->id);
        DiskFileDraft::create(['file_id' => $mine->id, 'user_id' => $this->user->id, 'kind' => 'text']);
        DiskFileDraft::create(['file_id' => $theirs->id, 'user_id' => $other->id, 'kind' => 'text']);

        $rows = collect($this->getJson('/api/disk/items/' . $folder->id)->assertOk()->json('data'))
            ->keyBy('id');

        // My draft flags MY row; another user's draft on a sibling file does NOT flag it for me.
        $this->assertTrue($rows[$mine->id]['has_draft']);
        $this->assertFalse($rows[$theirs->id]['has_draft']);
    }

    public function test_it_paginates_folders_then_files_across_a_page_edge(): void
    {
        $folder = Folder::factory()->create(['name' => 'Big']);
        // 13 subfolders + 13 files = 26 items over a 24/page boundary (page 1 crosses folders→files).
        for ($i = 1; $i <= 13; $i++) {
            Folder::factory()->childOf($folder)->create(['name' => sprintf('sub-%02d', $i)]);
            File::factory()->inFolder($folder)->create(['name' => sprintf('file-%02d.txt', $i)]);
        }

        $first = $this->getJson('/api/disk/items/' . $folder->id)->assertOk();
        $this->assertCount(24, $first->json('data'));
        // Page 1 leads with all 13 folders, then 11 files (folders-first ordering holds across the edge).
        $this->assertSame(array_merge(array_fill(0, 13, 'folder'), array_fill(0, 11, 'file')),
            collect($first->json('data'))->pluck('kind')->all());
        $this->assertNotNull($first->json('meta.next_cursor'));

        $second = $this->getJson('/api/disk/items/' . $folder->id . '?cursor=' . $first->json('meta.next_cursor'))->assertOk();
        $this->assertCount(2, $second->json('data')); // the remaining 2 files
        $this->assertNull($second->json('meta.next_cursor'));

        // No gaps, no duplicates across the two pages.
        $all = array_merge(
            collect($first->json('data'))->pluck('id')->all(),
            collect($second->json('data'))->pluck('id')->all(),
        );
        $this->assertCount(26, array_unique($all));
    }

    public function test_a_foreign_or_trashed_folder_404s(): void
    {
        $otherOwner = User::factory()->create();
        $otherWorkspace = Workspace::factory()->create(['owner_id' => $otherOwner->id]);
        $context = app(TenantContext::class);
        $context->set($otherWorkspace);
        $foreign = Folder::factory()->create();
        $context->set($this->workspace);

        $this->getJson('/api/disk/items/' . $foreign->id)->assertNotFound();

        $trashed = Folder::factory()->create();
        $trashed->delete();
        $this->getJson('/api/disk/items/' . $trashed->id)->assertNotFound();
    }

    // ---- Filters (F1) ---------------------------------------------------------------

    public function test_the_type_filter_narrows_files_and_can_drop_a_whole_kind(): void
    {
        $folder = Folder::factory()->create(['name' => 'Mixed']);
        Folder::factory()->childOf($folder)->create(['name' => 'Sub']);
        File::factory()->image()->inFolder($folder)->create(['name' => 'pic.png']);
        File::factory()->inFolder($folder)->create(['name' => 'doc.pdf']); // FileType::DOCUMENT

        // Only images → no folders stage, no non-image files.
        $imageOnly = $this->getJson('/api/disk/items/' . $folder->id . '?types[]=image')->assertOk();
        $this->assertSame(['file'], collect($imageOnly->json('data'))->pluck('kind')->unique()->values()->all());
        $this->assertSame(['pic.png'], collect($imageOnly->json('data'))->pluck('name')->all());

        // Only folders → no files stage.
        $folderOnly = $this->getJson('/api/disk/items/' . $folder->id . '?types[]=folder')->assertOk();
        $this->assertSame(['folder'], collect($folderOnly->json('data'))->pluck('kind')->unique()->values()->all());
    }

    public function test_search_matches_name_or_optionally_description(): void
    {
        $folder = Folder::factory()->create();
        File::factory()->inFolder($folder)->create(['name' => 'report.pdf', 'description' => 'quarterly numbers']);
        File::factory()->inFolder($folder)->create(['name' => 'notes.txt', 'description' => null]);

        // Default: name only.
        $byName = $this->getJson('/api/disk/items/' . $folder->id . '?q=report')->assertOk();
        $this->assertSame(['report.pdf'], collect($byName->json('data'))->pluck('name')->all());

        // A description term does NOT match under the default (name-only) scope...
        $this->assertCount(0, $this->getJson('/api/disk/items/' . $folder->id . '?q=quarterly')->json('data'));

        // ...but does when search_in=name_description.
        $byDesc = $this->getJson('/api/disk/items/' . $folder->id . '?q=quarterly&search_in=name_description')->assertOk();
        $this->assertSame(['report.pdf'], collect($byDesc->json('data'))->pluck('name')->all());
    }

    public function test_search_where_reaches_the_subtree_and_the_whole_disk(): void
    {
        $a = Folder::factory()->create(['name' => 'A']);
        $b = Folder::factory()->childOf($a)->create(['name' => 'B']);
        File::factory()->inFolder($b)->create(['name' => 'deep.pdf']);

        // 'folder' scope: A's direct contents don't include a file living in the sub-folder B.
        $this->assertCount(0, $this->getJson('/api/disk/items/' . $a->id . '?q=deep')->json('data'));

        // 'subtree' scope: A + descendants — finds the file in B, carrying its folder.
        $subtree = $this->getJson('/api/disk/items/' . $a->id . '?q=deep&search_where=subtree')->assertOk();
        $this->assertSame(['deep.pdf'], collect($subtree->json('data'))->pluck('name')->all());
        $this->assertSame($b->id, $subtree->json('data.0.folder_id'));

        // 'everywhere' scope: from the ROOT, still finds it.
        $everywhere = $this->getJson('/api/disk/items?q=deep&search_where=everywhere')->assertOk();
        $this->assertSame(['deep.pdf'], collect($everywhere->json('data'))->pluck('name')->all());
    }

    public function test_sort_orders_by_name_in_the_requested_direction(): void
    {
        $folder = Folder::factory()->create();
        File::factory()->inFolder($folder)->create(['name' => 'b.pdf']);
        File::factory()->inFolder($folder)->create(['name' => 'a.pdf']);

        $asc = $this->getJson('/api/disk/items/' . $folder->id . '?sort=name&dir=asc')->assertOk();
        $this->assertSame(['a.pdf', 'b.pdf'], collect($asc->json('data'))->pluck('name')->all());

        $desc = $this->getJson('/api/disk/items/' . $folder->id . '?sort=name&dir=desc')->assertOk();
        $this->assertSame(['b.pdf', 'a.pdf'], collect($desc->json('data'))->pluck('name')->all());
    }
}
