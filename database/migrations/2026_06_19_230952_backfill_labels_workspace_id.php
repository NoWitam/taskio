<?php

use App\Models\Scopes\WorkspaceScope;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Assign orphaned labels (created before tenant stamping) to the oldest
     * workspace so the tenant-aware WorkspaceScope can resolve them.
     */
    public function up(): void
    {
        $workspaceId = DB::table('workspaces')
            ->whereNull('deleted_at')
            ->orderBy('created_at')
            ->value('id');

        if ($workspaceId === null) {
            return;
        }

        DB::table('labels')
            ->whereNull(WorkspaceScope::COLUMN)
            ->update([WorkspaceScope::COLUMN => $workspaceId]);
    }
};
