<?php

use App\Modules\Approvals\Models\ApprovalPipeline;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->foreignIdFor(ApprovalPipeline::class, 'approval_pipeline_id')
                ->nullable()
                ->after('form_id')
                ->constrained()
                ->nullOnDelete();
        });
    }
};
