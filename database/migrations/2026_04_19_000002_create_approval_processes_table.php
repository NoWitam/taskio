<?php

use App\Models\User;
use App\Modules\Approvals\Enums\ApprovalProcessStatus;
use App\Modules\Approvals\Models\ApprovalPipeline;
use App\Modules\Approvals\Models\ApprovalStage;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_processes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('run_id')->index();
            $table->foreignIdFor(ApprovalPipeline::class, 'approval_pipeline_id')->constrained();
            $table->foreignIdFor(ApprovalStage::class, 'approval_stage_id')->constrained();
            $table->string('approvable_type');
            $table->uuid('approvable_id');
            $table->string('approver_type');
            $table->foreignIdFor(User::class, 'approver_id')->nullable();
            $table->string('status')->default(ApprovalProcessStatus::Pending->value)->index();
            $table->text('note')->nullable();
            $table->json('context')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->foreignIdFor(User::class, 'creator_id');
            $table->timestamps();

            $table->index(['approvable_type', 'approvable_id', 'status']);
            $table->index(['approver_id', 'status']);
        });
    }
};
