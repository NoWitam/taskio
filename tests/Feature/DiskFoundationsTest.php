<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Disk\Models\File;
use App\Modules\Disk\Models\Folder;
use App\Modules\Disk\Policies\FilePolicy;
use App\Modules\Disk\Policies\FolderPolicy;
use App\Modules\Labels\Models\Label;
use App\Modules\Tasks\Models\Task;
use App\Modules\Workspaces\Models\Workspace;
use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * R1-B1 — the Disk domain foundations: morph aliases, the Folder tree with its materialized
 * path, file metadata/tags/history wiring, and the policy matrix.
 *
 * The path column is the load-bearing piece: breadcrumbs, subtree reads and subtree moves all
 * become single queries because of it, and a malformed path silently corrupts every one of
 * them — so its invariants are pinned here rather than left to the API batches.
 */
class DiskFoundationsTest extends TestCase
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

        $this->actingAs($this->user);
        app(TenantContext::class)->set($this->workspace);
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();

        parent::tearDown();
    }

    // ---- Morph aliases (the precondition for labels + changelog on files) ------

    public function test_files_and_folders_have_enforced_morph_aliases(): void
    {
        // The morph map is enforced app-wide, so an unmapped model throws on getMorphClass()
        // — which is exactly why File could not carry labels or a changelog before B1.
        $this->assertTrue(Relation::requiresMorphMap());
        $this->assertSame('file', (new File)->getMorphClass());
        $this->assertSame('folder', (new Folder)->getMorphClass());
    }

    // ---- Materialized path invariants -----------------------------------------

    public function test_a_root_folder_has_no_ancestors(): void
    {
        $folder = Folder::factory()->create(['name' => 'Kampanie']);

        // The path holds ANCESTORS only — a root folder has none, so it is just the separator.
        // Its own id is never stored there: the row (and the URL) already carry it.
        $this->assertSame('/', $folder->path);
        $this->assertSame(1, $folder->depth());
        $this->assertSame([], $folder->ancestorIds());
    }

    public function test_a_child_path_is_its_parents_ancestors_plus_the_parent(): void
    {
        $root = Folder::factory()->create();
        $child = Folder::factory()->childOf($root)->create();
        $grandchild = Folder::factory()->childOf($child)->create();

        $this->assertSame("/{$root->id}/", $child->path);
        $this->assertSame("/{$root->id}/{$child->id}/", $grandchild->path);
        $this->assertSame(3, $grandchild->depth());
        // Breadcrumbs, root first — the stored path IS the answer, nothing to trim.
        $this->assertSame([$root->id, $child->id], $grandchild->ancestorIds());
    }

    public function test_every_descendant_is_one_prefix_query(): void
    {
        $root = Folder::factory()->create();
        $child = Folder::factory()->childOf($root)->create();
        $grandchild = Folder::factory()->childOf($child)->create();
        $unrelated = Folder::factory()->create();

        $descendants = Folder::query()
            ->where('path', 'like', $root->descendantPrefix() . '%')
            ->pluck('id')
            ->all();

        // The whole subtree beneath the folder — what a cascade check or a subtree move needs
        // (the folder's own row is handled alongside its parent_id change).
        sort($descendants);
        $expected = [$child->id, $grandchild->id];
        sort($expected);
        $this->assertSame($expected, $descendants);
        $this->assertNotContains($unrelated->id, $descendants);
    }

    public function test_the_trailing_separator_stops_a_sibling_prefix_from_matching(): void
    {
        // Without the trailing '/', the prefix '/{a}' would also match '/{a}X/...'. Uuids make
        // that collision improbable, not impossible — so build it explicitly.
        $a = Folder::factory()->create();
        $child = Folder::factory()->childOf($a)->create();
        $impostor = Folder::factory()->create();
        Folder::withoutGlobalScopes()->whereKey($impostor->id)
            ->update(['path' => rtrim($a->descendantPrefix(), '/') . 'X/']);

        $descendants = Folder::query()->where('path', 'like', $a->descendantPrefix() . '%')->pluck('id')->all();

        $this->assertSame([$child->id], $descendants);
    }

    public function test_ancestry_is_directional_and_not_reflexive(): void
    {
        $root = Folder::factory()->create();
        $child = Folder::factory()->childOf($root)->create();

        $this->assertTrue($root->isAncestorOf($child));
        $this->assertFalse($child->isAncestorOf($root));
        // Not reflexive on purpose — a move guard rejects the self-target separately.
        $this->assertFalse($root->isAncestorOf($root));
    }

    public function test_a_folder_is_stamped_with_the_active_workspace(): void
    {
        $folder = Folder::factory()->create();

        $this->assertSame($this->workspace->id, $folder->workspace_id);
    }

    public function test_a_parent_from_another_workspace_cannot_be_used(): void
    {
        $otherOwner = User::factory()->create();
        $otherWorkspace = Workspace::factory()->create(['owner_id' => $otherOwner->id]);

        $context = app(TenantContext::class);
        $context->set($otherWorkspace);
        $foreignParent = Folder::factory()->create();
        $context->set($this->workspace);

        // Silently rooting the folder would be worse than failing: the tree would lie.
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

        Folder::factory()->create(['parent_id' => $foreignParent->id]);
    }

    // ---- File metadata, tags, history ------------------------------------------

    public function test_a_file_carries_disk_metadata(): void
    {
        $folder = Folder::factory()->create();
        $file = File::factory()->create([
            'folder_id' => $folder->id,
            'description' => 'Materiał na wrzesień',
        ]);

        $this->assertSame($folder->id, $file->folder->id);
        $this->assertSame('Materiał na wrzesień', $file->description);
        $this->assertNull($file->disk_trashed_at);
        $this->assertFalse($file->isOwnedByResource(), 'A disk file has no owning resource.');
    }

    public function test_a_file_can_be_tagged_with_the_workspace_labels(): void
    {
        $file = File::factory()->create();
        // No LabelFactory exists in this codebase; TenantAware stamps the workspace on create.
        $label = Label::create(['name' => 'Wrzesień']);

        $file->labels()->attach($label->id);

        // Files share the workspace-wide label vocabulary with tasks — no separate tag entity.
        $this->assertSame([$label->id], $file->labels()->pluck('labels.id')->all());
        $this->assertSame('file', $file->labels()->first()->pivot->labelable_type);
    }

    public function test_renaming_a_file_is_recorded_in_its_history(): void
    {
        $file = File::factory()->create(['name' => 'stary.pdf']);

        $file->update(['name' => 'nowy.pdf']);
        app(\App\Modules\Changelog\Managers\ChangelogManager::class)->flush();

        $entry = \App\Modules\Changelog\Models\Changelog::query()
            ->where('subject_type', 'file')
            ->where('subject_id', $file->id)
            ->first();

        $this->assertNotNull($entry, 'A file rename must produce a changelog entry keyed by the "file" morph alias.');
    }

    public function test_the_disk_trash_marker_is_independent_of_soft_deletes(): void
    {
        $detached = File::factory()->create();
        $detached->delete(); // what FileService::detach does to a removed attachment

        $trashed = File::factory()->create(['disk_trashed_at' => now()]);

        // Detaching an attachment must NOT surface it in the disk's trash.
        $inDiskTrash = File::query()->withTrashed()->diskTrashed()->pluck('id')->all();

        $this->assertSame([$trashed->id], $inDiskTrash);
        $this->assertNotContains($detached->id, $inDiskTrash);
    }

    // ---- Policies ---------------------------------------------------------------

    public function test_any_member_may_read_but_only_the_owner_may_mutate(): void
    {
        $policy = new FilePolicy;
        $owner = $this->user;
        $otherMember = User::factory()->create();
        $this->workspace->users()->attach($otherMember->id);

        $file = File::factory()->create(['uploader_id' => $owner->id]);

        // Creator never gates reads (a teammate must see the file), always gates mutations.
        $this->assertTrue($policy->view($otherMember, $file));
        $this->assertTrue($policy->update($owner, $file));
        $this->assertFalse($policy->update($otherMember, $file));
        $this->assertFalse($policy->delete($otherMember, $file));
    }

    public function test_a_resource_owned_file_may_be_described_but_never_trashed_or_moved_from_the_disk(): void
    {
        $policy = new FilePolicy;
        $task = Task::factory()->create(['creator_id' => $this->user->id, 'assigned_id' => $this->user->id]);
        $attachment = File::factory()->attachedTo($task)->create(['uploader_id' => $this->user->id]);

        // Its lifecycle belongs to the owning module: detach it from the task instead.
        $this->assertTrue($policy->view($this->user, $attachment));
        $this->assertTrue($policy->update($this->user, $attachment), 'Tags/description stay editable from the disk.');
        $this->assertFalse($policy->delete($this->user, $attachment));
        $this->assertFalse($policy->move($this->user, $attachment));
    }

    public function test_folder_mutations_are_owner_gated(): void
    {
        $policy = new FolderPolicy;
        $stranger = User::factory()->create();
        $folder = Folder::factory()->create();

        $this->assertTrue($policy->view($stranger, $folder), 'Reads are membership-gated upstream, not creator-gated.');
        $this->assertTrue($policy->update($this->user, $folder));
        $this->assertFalse($policy->update($stranger, $folder));
        $this->assertFalse($policy->move($stranger, $folder));
    }
}
