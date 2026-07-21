<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Disk\DTOs\UpdateFolderDTO;
use App\Modules\Disk\Models\File;
use App\Modules\Disk\Models\Folder;
use App\Modules\Disk\Services\FileService;
use App\Modules\Disk\Services\FolderLabelEnforcer;
use App\Modules\Disk\Services\FolderService;
use App\Modules\Labels\Models\Label;
use App\Modules\Workspaces\Models\Workspace;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * F3 — the materialized enforced-label engine.
 *
 * A folder can ENFORCE labels down its subtree; the engine keeps those as real `enforced`
 * labelables rows on each file (so the filter/resource/changelog keep working). The invariants
 * worth pinning: enforcing cascades to the whole subtree, a full recompute resolves "un-enforce
 * here while a parent still enforces", a move re-parents the enforced set, and manual labels are
 * never touched.
 */
class FolderLabelEnforcerTest extends TestCase
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

    private function enforcer(): FolderLabelEnforcer
    {
        return app(FolderLabelEnforcer::class);
    }

    /** @return array<int, string> the file's enforced (locked) label ids, sorted */
    private function enforcedIds(File $file): array
    {
        return $file->labels()->wherePivot('enforced', true)->pluck('labels.id')->sort()->values()->all();
    }

    /** @return array<int, string> the file's manual label ids, sorted */
    private function manualIds(File $file): array
    {
        return $file->labels()->wherePivot('enforced', false)->pluck('labels.id')->sort()->values()->all();
    }

    /** Enforce a label on a folder's governance pivot directly (setup helper). */
    private function enforce(Folder $folder, Label $label): void
    {
        $folder->labels()->syncWithoutDetaching([$label->id => ['mode' => Folder::LABEL_MODE_ENFORCED]]);
    }

    public function test_enforcing_a_label_cascades_to_every_file_in_the_subtree(): void
    {
        $root = Folder::factory()->create(['name' => 'A']);
        $child = Folder::factory()->childOf($root)->create(['name' => 'B']);
        $label = Label::create(['name' => 'Marka']);

        $atRoot = File::factory()->inFolder($root)->create();
        $deep = File::factory()->inFolder($child)->create();

        $this->enforce($root, $label);
        $this->enforcer()->syncSubtree($root);

        // Both the folder's own file and a file two levels down inherit the enforced label.
        $this->assertSame([$label->id], $this->enforcedIds($atRoot));
        $this->assertSame([$label->id], $this->enforcedIds($deep));
    }

    public function test_a_file_inherits_the_union_of_all_ancestor_enforced_labels(): void
    {
        $root = Folder::factory()->create();
        $child = Folder::factory()->childOf($root)->create();
        $rootLabel = Label::create(['name' => 'Root']);
        $childLabel = Label::create(['name' => 'Child']);

        $file = File::factory()->inFolder($child)->create();

        $this->enforce($root, $rootLabel);
        $this->enforce($child, $childLabel);
        $this->enforcer()->syncFile($file);

        $this->assertEqualsCanonicalizing([$rootLabel->id, $childLabel->id], $this->enforcedIds($file));
    }

    public function test_un_enforcing_at_a_child_keeps_a_label_a_parent_still_enforces(): void
    {
        $root = Folder::factory()->create();
        $child = Folder::factory()->childOf($root)->create();
        $label = Label::create(['name' => 'Shared']);

        $file = File::factory()->inFolder($child)->create();

        // Both levels enforce it; then the child stops — the parent still does, so it must remain.
        $this->enforce($root, $label);
        $this->enforce($child, $label);
        $this->enforcer()->syncFile($file);
        $this->assertSame([$label->id], $this->enforcedIds($file));

        $child->labels()->detach($label->id); // child no longer enforces
        $this->enforcer()->syncSubtree($root);

        $this->assertSame([$label->id], $this->enforcedIds($file), 'A still-enforcing ancestor keeps the label.');
    }

    public function test_removing_the_only_enforcing_folder_detaches_the_label(): void
    {
        $folder = Folder::factory()->create();
        $label = Label::create(['name' => 'Gone']);
        $file = File::factory()->inFolder($folder)->create();

        $this->enforce($folder, $label);
        $this->enforcer()->syncFile($file);
        $this->assertSame([$label->id], $this->enforcedIds($file));

        $folder->labels()->detach($label->id);
        $this->enforcer()->syncFile($file);

        $this->assertSame([], $this->enforcedIds($file));
    }

    public function test_manual_labels_are_preserved_across_a_recompute(): void
    {
        $folder = Folder::factory()->create();
        $manual = Label::create(['name' => 'Manual']);
        $enforced = Label::create(['name' => 'Enforced']);
        $file = File::factory()->inFolder($folder)->create();
        $file->labels()->attach($manual->id, ['enforced' => false]);

        $this->enforce($folder, $enforced);
        $this->enforcer()->syncFile($file);

        $this->assertSame([$enforced->id], $this->enforcedIds($file));
        $this->assertSame([$manual->id], $this->manualIds($file), 'The manual label survives the recompute.');
    }

    public function test_a_manual_label_that_becomes_enforced_is_upgraded(): void
    {
        $folder = Folder::factory()->create();
        $label = Label::create(['name' => 'Both']);
        $file = File::factory()->inFolder($folder)->create();
        $file->labels()->attach($label->id, ['enforced' => false]); // manual first

        $this->enforce($folder, $label);
        $this->enforcer()->syncFile($file);

        // The colliding manual row is upgraded, not duplicated.
        $this->assertSame([$label->id], $this->enforcedIds($file));
        $this->assertSame([], $this->manualIds($file));
        $this->assertSame(1, $file->labels()->count());
    }

    public function test_moving_a_folder_recomputes_its_files_enforced_labels(): void
    {
        $from = Folder::factory()->create(['name' => 'From']);
        $to = Folder::factory()->create(['name' => 'To']);
        $moving = Folder::factory()->childOf($from)->create(['name' => 'Moving']);
        $fromLabel = Label::create(['name' => 'FromLabel']);
        $toLabel = Label::create(['name' => 'ToLabel']);

        $file = File::factory()->inFolder($moving)->create();

        $this->enforce($from, $fromLabel);
        $this->enforce($to, $toLabel);
        $this->enforcer()->syncSubtree($from);
        $this->assertSame([$fromLabel->id], $this->enforcedIds($file));

        // Move exercises FolderService::move → syncSubtree: the file loses From's label, gains To's.
        app(FolderService::class)->move($moving, $to);

        $this->assertSame([$toLabel->id], $this->enforcedIds($file->fresh()));
    }

    public function test_a_new_upload_is_seeded_with_only_the_immediate_folders_recommended_labels(): void
    {
        Storage::fake();

        $parent = Folder::factory()->create();
        $child = Folder::factory()->childOf($parent)->create();
        $parentRec = Label::create(['name' => 'ParentRec']);
        $childRec = Label::create(['name' => 'ChildRec']);
        $parent->labels()->syncWithoutDetaching([$parentRec->id => ['mode' => Folder::LABEL_MODE_RECOMMENDED]]);
        $child->labels()->syncWithoutDetaching([$childRec->id => ['mode' => Folder::LABEL_MODE_RECOMMENDED]]);

        $file = app(FileService::class)->store(UploadedFile::fake()->create('doc.pdf', 10), $child);

        // Seeded as a MANUAL (removable) row from the IMMEDIATE folder only — recommendations do
        // not cascade the way enforced labels do.
        $this->assertSame([$childRec->id], $this->manualIds($file));
        $this->assertSame([], $this->enforcedIds($file));
    }

    public function test_folder_service_update_triggers_the_cascade(): void
    {
        $folder = Folder::factory()->create();
        $label = Label::create(['name' => 'Governed']);
        $file = File::factory()->inFolder($folder)->create();

        // Drive it through the real service path (as the controller does) to prove the wiring.
        app(FolderService::class)->update($folder, new UpdateFolderDTO(
            name: null, hasName: false,
            description: null, hasDescription: false,
            icon: null, hasIcon: false,
            labels: [['id' => $label->id, 'mode' => 'enforced']], hasLabels: true,
        ));

        $this->assertSame([$label->id], $this->enforcedIds($file->fresh()));
    }
}
