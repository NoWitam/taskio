<?php

use App\Models\User;
use App\Modules\Approvals\Models\ApprovalPipeline;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_stages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignIdFor(ApprovalPipeline::class, 'approval_pipeline_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('icon')->nullable();
            $table->text('description')->nullable();
            $table->string('approver_type')->index();
            $table->foreignIdFor(User::class, 'approver_id')->nullable();
            $table->unsignedInteger('order');
            $table->timestamps();

            $table->unique(['approval_pipeline_id', 'order']);
        });
    }
};
