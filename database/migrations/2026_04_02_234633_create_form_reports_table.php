<?php

use App\Models\User;
use App\Modules\Forms\Models\Form;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('form_reports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignIdFor(Form::class);
            $table->string('name');
            $table->text('guidelines')->nullable();
            $table->json('sources');
            $table->date('submissions_from');
            $table->date('submissions_to');
            $table->timestamp('completed_at')->nullable();
            $table->foreignIdFor(User::class, 'creator_id');
            $table->timestamps();
            $table->softDeletes();

            $table->index('form_id');
            $table->index(['form_id', 'completed_at']);
            $table->index(['form_id', 'creator_id', 'completed_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('form_reports');
    }
};
