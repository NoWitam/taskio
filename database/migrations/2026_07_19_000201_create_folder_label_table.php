<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A folder's label GOVERNANCE — deliberately its OWN pivot, not the shared `labelables`.
 *
 * A folder does not "have" labels the way a file does; it DECLARES labels for the items it
 * contains, each with a `mode`:
 *   - enforced    — materialized onto every file in the subtree (a real, locked labelables row),
 *   - recommended — pre-selected (removable) when a new file/folder is created here.
 *
 * Keeping this apart from `labelables` means the shared file/task pivot never grows a
 * folder-only `mode` column, and the file label filter keeps matching real rows only.
 * Isolated through `folder_id` (always a tenant-scoped folder), so no workspace_id — exactly as
 * `labelables` is isolated through its labels parent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('folder_label', function (Blueprint $table) {
            $table->uuid('folder_id');
            $table->uuid('label_id');
            // 'enforced' | 'recommended' — kept a plain string (mirrors how labelables carries no
            // enum column); the app layer is authoritative on the value set.
            $table->string('mode');
            $table->timestamps();

            $table->unique(['folder_id', 'label_id']);
            $table->index('label_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('folder_label');
    }
};
