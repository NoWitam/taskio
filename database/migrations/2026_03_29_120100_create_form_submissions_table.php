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
        Schema::create('form_submissions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignIdFor(Form::class, 'form_id')->constrained()->cascadeOnDelete();
            $table->uuidMorphs('submittable');
            $table->json('data');
            $table->foreignIdFor(User::class, 'creator_id');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('form_submissions');
    }
};
