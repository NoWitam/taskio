<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Disk\Models\File;
use App\Modules\Disk\Models\Folder;
use App\Modules\Disk\Services\ResourceFolderRegistry;
use App\Modules\Tasks\Models\Task;
use App\Modules\Workspaces\Models\Workspace;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * R1-B3b — the read-only "Zasoby" tree.
 *
 * Files that belong to another resource (task attachments, report outputs) don't live in the
 * user's folder tree, but they still surface on the disk under synthetic folders:
 * Zasoby → <type> → <created_at bucket> → files. This proves the tree only lists types that
 * have files, buckets them correctly (including the month-boundary the reused date scope would
 * have got wrong), and that the synthetic ids can never be used to mutate anything.
 */
class ResourceFoldersTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake();

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

    private function task(): Task
    {
        return Task::factory()->create(['creator_id' => $this->user->id, 'assigned_id' => $this->user->id]);
    }

    private function attachmentAt(string $iso): File
    {
        return File::factory()->attachedTo($this->task())->create([
            'uploader_id' => $this->user->id,
            'created_at' => CarbonImmutable::parse($iso),
        ]);
    }

    // ---- The tree ---------------------------------------------------------------

    public function test_the_tree_lists_only_types_that_have_files(): void
    {
        // A task attachment exists; no report files do.
        $this->attachmentAt('2026-07-10 12:00:00');

        $response = $this->getJson('/api/disk/resources');

        $response->assertOk()->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.type', 'task');
        $response->assertJsonPath('data.0.id', 'sys:res:task');
        $response->assertJsonPath('data.0.label_key', 'disk.resources.task');
        $response->assertJsonPath('data.0.files_count', 1);
    }

    public function test_files_are_bucketed_by_month_newest_first_with_counts(): void
    {
        $this->attachmentAt('2026-07-31 23:30:00'); // late on the last day of July
        $this->attachmentAt('2026-07-01 00:10:00');
        $this->attachmentAt('2026-06-15 09:00:00');

        $buckets = $this->getJson('/api/disk/resources')->json('data.0.buckets');

        $this->assertSame(['2026-07', '2026-06'], array_column($buckets, 'key'), 'Buckets are newest-first.');
        $this->assertSame(2, $buckets[0]['files_count'], 'Both July files land in the July bucket, incl. the 31st.');
        $this->assertSame(1, $buckets[1]['files_count']);
        $this->assertSame('sys:res:task:2026-07', $buckets[0]['id']);
        $this->assertSame('month', $buckets[0]['granularity']);
    }

    public function test_a_detached_attachment_drops_out_of_the_tree(): void
    {
        $file = $this->attachmentAt('2026-07-10 12:00:00');

        $file->delete(); // detach = soft-delete

        $this->getJson('/api/disk/resources')->assertOk()->assertJsonCount(0, 'data');
    }

    // ---- Browsing a bucket ------------------------------------------------------

    public function test_a_bucket_lists_exactly_its_files_including_the_last_day(): void
    {
        $july = $this->attachmentAt('2026-07-31 23:30:00');
        $alsoJuly = $this->attachmentAt('2026-07-02 08:00:00');
        $june = $this->attachmentAt('2026-06-20 08:00:00');

        $ids = collect($this->getJson('/api/disk?source=task&bucket=2026-07')->json('data'))->pluck('id')->all();
        sort($ids);
        $expected = [$july->id, $alsoJuly->id];
        sort($expected);

        // The reused scopeFilterByDate compares date_to against the START of the day, which
        // would have dropped the file created late on July 31 — the precise bucket range must
        // not. And June must not leak in.
        $this->assertSame($expected, $ids);
        $this->assertNotContains($june->id, $ids);
    }

    public function test_files_shown_in_a_bucket_are_read_only(): void
    {
        $this->attachmentAt('2026-07-10 12:00:00');

        $file = collect($this->getJson('/api/disk?source=task&bucket=2026-07')->json('data'))->first();

        // Their lifecycle belongs to the owning module (decision Q1): browse + tag, nothing more.
        $this->assertFalse($file['can_be_moved']);
        $this->assertFalse($file['can_be_deleted']);
        $this->assertTrue($file['can_be_updated'], 'Metadata (tags/description) stays editable.');
    }

    public function test_a_malformed_or_sourceless_bucket_is_ignored_not_errored(): void
    {
        $this->attachmentAt('2026-07-10 12:00:00');

        // A bucket only means something inside a resource type; without a valid source it is
        // ignored rather than 500'ing or silently emptying the list.
        $this->getJson('/api/disk?bucket=2026-07')->assertOk();
        $this->getJson('/api/disk?source=task&bucket=not-a-date')->assertOk()->assertJsonCount(1, 'data');
    }

    // ---- Read-only enforcement (synthetic ids) ----------------------------------

    public function test_a_synthetic_resource_id_can_never_be_used_as_a_folder(): void
    {
        $this->attachmentAt('2026-07-10 12:00:00');

        // sys: ids are not uuids, so the `uuid` rule rejects them everywhere a folder id is
        // accepted — no synthetic node can be created under, moved, renamed or deleted.
        $this->postJson('/api/disk/folders', ['name' => 'x', 'parent_id' => 'sys:res:task'])
            ->assertStatus(422)->assertJsonValidationErrors('parent_id');

        $this->getJson('/api/disk/folders/sys:res:task')->assertNotFound();

        $file = File::factory()->create(['folder_id' => Folder::factory()->create()->id]);
        Storage::put($file->path, 'x');
        $this->patchJson('/api/disk/' . $file->id, ['folder_id' => 'sys:res:task:2026-07'])
            ->assertStatus(422)->assertJsonValidationErrors('folder_id');
    }

    // ---- The bucket math (unit-ish) ---------------------------------------------

    public function test_bucket_ranges_are_half_open_and_reject_the_wrong_granularity(): void
    {
        [$start, $end] = ResourceFolderRegistry::bucketRange('task', '2026-07');
        $this->assertSame('2026-07-01T00:00:00+00:00', $start->toIso8601String());
        $this->assertSame('2026-08-01T00:00:00+00:00', $end->toIso8601String(), 'End is exclusive: the next month start.');

        // A month type must not accept a year or day key.
        $this->assertNull(ResourceFolderRegistry::bucketRange('task', '2026'));
        $this->assertNull(ResourceFolderRegistry::bucketRange('task', '2026-07-10'));
        $this->assertNull(ResourceFolderRegistry::bucketRange('task', 'garbage'));
    }
}
