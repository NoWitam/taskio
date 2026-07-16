<?php

use App\Modules\Approvals\Enums\ApprovalProcessStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-database schema (db_mode = own). Mirrors central `approval_processes`
 * (create_approval_processes_table + make_approval_stage_id_nullable) MINUS workspace_id.
 * approval_pipeline_id / approval_stage_id are intra-tenant FKs and are KEPT (stage_id
 * nullable). approver_id / creator_id and the approvable morph point at central/runtime
 * data and carry no FK.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_processes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('run_id')->index();
            $table->foreignUuid('approval_pipeline_id')->constrained('approval_pipelines');
            $table->foreignUuid('approval_stage_id')->nullable()->constrained('approval_stages');
            $table->string('approvable_type');
            $table->uuid('approvable_id');
            $table->string('approver_type');
            $table->uuid('approver_id')->nullable();
            $table->string('status')->default(ApprovalProcessStatus::Pending->value)->index();
            $table->text('note')->nullable();
            $table->json('context')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->uuid('creator_id');
            $table->timestamps();

            $table->index(['approvable_type', 'approvable_id', 'status']);
            $table->index(['approver_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_processes');
    }
};
