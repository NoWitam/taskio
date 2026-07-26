<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Disk\Models\File;
use App\Modules\Disk\Models\Folder;
use App\Modules\Workspaces\Models\Workspace;
use App\Support\Pagination\StagedCursorPaginator;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\Cursor;
use Tests\TestCase;

/**
 * The reusable cross-table cursor paginator. Two stages (folders then files) are paginated as ONE
 * sequential list; the tricky bits are that the page never gaps or duplicates across the stage
 * boundary and that a tampered cursor degrades to the first page rather than throwing.
 *
 * Uses the disk models purely as two convenient ordered tables — nothing here is disk-specific.
 */
class StagedCursorPaginatorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_id' => $user->id]);
        $workspace->users()->attach($user->id);
        $this->actingAs($user);
        app(TenantContext::class)->set($workspace);
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();
        parent::tearDown();
    }

    /** Paginate folders (by name) then files (by name), $perPage per page. */
    private function paginate(int $perPage, ?string $cursor): \Illuminate\Pagination\CursorPaginator
    {
        return StagedCursorPaginator::make($perPage)
            ->stage('folders', fn () => Folder::query()->orderBy('name')->orderBy('id'))
            ->stage('files', fn () => File::query()->orderBy('name')->orderBy('id'))
            ->paginate($cursor);
    }

    /** Walk every page and return the flat list of item names, in order. */
    private function walk(int $perPage): array
    {
        $names = [];
        $cursor = null;
        $guard = 0;
        do {
            $page = $this->paginate($perPage, $cursor);
            $this->assertLessThanOrEqual($perPage, $page->count(), 'a page never exceeds perPage');
            $names = array_merge($names, $page->getCollection()->pluck('name')->all());
            $cursor = $page->nextCursor()?->encode();
        } while ($cursor !== null && ++$guard < 50);

        return $names;
    }

    public function test_it_concatenates_stages_with_no_gaps_or_duplicates_across_the_boundary(): void
    {
        foreach (['A', 'B', 'C', 'D'] as $n) {
            Folder::factory()->create(['name' => $n]);
        }
        foreach (['E', 'F', 'G', 'H'] as $n) {
            File::factory()->create(['name' => $n]);
        }

        // perPage 3 makes the folder→file boundary fall MID-PAGE (D | E F), the interesting case.
        $this->assertSame(['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H'], $this->walk(3));
    }

    public function test_a_boundary_exactly_on_a_page_edge_does_not_duplicate(): void
    {
        foreach (['A', 'B', 'C', 'D'] as $n) {
            Folder::factory()->create(['name' => $n]);
        }
        foreach (['E', 'F', 'G', 'H'] as $n) {
            File::factory()->create(['name' => $n]);
        }

        // perPage 4 = exactly the folder count: page 1 is all folders, page 2 all files. The probe
        // row (first file) is fetched on page 1 to detect "more", then correctly re-served on page 2.
        $first = $this->paginate(4, null);
        $this->assertSame(['A', 'B', 'C', 'D'], $first->getCollection()->pluck('name')->all());
        $this->assertNotNull($first->nextCursor());

        $second = $this->paginate(4, $first->nextCursor()->encode());
        $this->assertSame(['E', 'F', 'G', 'H'], $second->getCollection()->pluck('name')->all());
        $this->assertNull($second->nextCursor(), 'the last page has no next cursor');

        // Whole walk is still gap/dupe-free.
        $this->assertSame(['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H'], $this->walk(4));
    }

    public function test_the_next_cursor_records_the_stage_it_resumes_in(): void
    {
        foreach (['A', 'B', 'C'] as $n) {
            Folder::factory()->create(['name' => $n]);
        }
        File::factory()->create(['name' => 'Z']);

        // Page 1 (perPage 2) ends inside the FOLDERS stage → the cursor resumes there.
        $cursor = Cursor::fromEncoded($this->paginate(2, null)->nextCursor()->encode());
        $this->assertSame('folders', $cursor->toArray()['_stage']);
    }

    public function test_a_tampered_or_unknown_stage_cursor_falls_back_to_the_first_page(): void
    {
        foreach (['A', 'B', 'C'] as $n) {
            Folder::factory()->create(['name' => $n]);
        }

        // An unknown _stage must not throw — it restarts from the top.
        $forged = (new Cursor(['_stage' => 'nope', 'name' => 'Z', 'id' => 'x'], true))->encode();
        $page = $this->paginate(2, $forged);
        $this->assertSame(['A', 'B'], $page->getCollection()->pluck('name')->all());
    }

    public function test_load_missing_eager_loads_across_a_mixed_page_and_skips_inapplicable_relations(): void
    {
        Folder::factory()->create(['name' => 'A']);
        File::factory()->create(['name' => 'B']);

        $page = $this->paginate(10, null);
        // 'creator' exists on BOTH models (HasCreator); 'folder' only on File (its containing
        // folder) — a Folder must not be asked for it (Eloquent's own loadMissing would blow up on
        // the mixed collection). NB both models now carry `labels`, so it can't demonstrate the skip.
        $page->loadMissing(['creator', 'folder']);

        $folder = $page->getCollection()->first(fn ($m) => $m instanceof Folder);
        $file = $page->getCollection()->first(fn ($m) => $m instanceof File);

        $this->assertTrue($folder->relationLoaded('creator'));
        $this->assertTrue($file->relationLoaded('creator'));
        $this->assertTrue($file->relationLoaded('folder'));
        $this->assertFalse($folder->relationLoaded('folder')); // skipped, not an error
    }
}
