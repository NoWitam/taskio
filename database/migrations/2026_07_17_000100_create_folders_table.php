<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Disk folders: a per-workspace tree, PURELY LOGICAL — blobs are never moved or renamed on
 * the filesystem (File.path stays a flat uuid), so a folder move is a database operation only.
 *
 * `path` is a MATERIALIZED PATH of ANCESTOR ids, slash-delimited and slash-terminated:
 * '/{root}/{child}/' (a root folder's path is just '/'). It buys three things in one column:
 *   - breadcrumbs in ONE query (the path IS the ancestor list, one whereIn),
 *   - a whole subtree in one predicate (path LIKE '/{ancestors}/{self}/%'),
 *   - a subtree MOVE as a single UPDATE (re-anchor that prefix).
 * The folder's own id is deliberately absent — it is known from the row, and keeping the path
 * free of self-references means it depends only on the parent. Depth = separator count,
 * capped at Folder::MAX_DEPTH.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('folders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id')->nullable()->index();

            $table->string('name');
            $table->uuid('parent_id')->nullable()->index();

            // Long enough for the deepest legal chain: at MAX_DEPTH=10 a folder has 9
            // ancestors → 9 * (36 uuid chars + '/') + the leading '/'. 400 leaves headroom.
            $table->string('path', 400);

            $table->uuid('creator_id')->nullable();
            $table->string('creator_type')->nullable();

            $table->softDeletes();
            $table->timestamps();

            // Sibling names stay unique per workspace. NOTE: Postgres treats NULLs as
            // distinct, so this does NOT cover root-level folders (parent_id NULL) — those
            // are guarded in the service/request layer instead.
            $table->unique(['workspace_id', 'parent_id', 'name']);
        });

        // Prefix matching (path LIKE '/a/b/%') only uses an index under text_pattern_ops on
        // Postgres; the default collation-aware btree is useless for LIKE.
        DB::statement('CREATE INDEX folders_path_prefix_idx ON folders (path text_pattern_ops)');
    }

    public function down(): void
    {
        Schema::dropIfExists('folders');
    }
};
