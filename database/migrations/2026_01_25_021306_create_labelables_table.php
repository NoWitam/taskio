<?php

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
        Schema::create('labelables', function (Blueprint $table) {
            $table->uuid('label_id');
            $table->uuidMorphs('labelable');
            $table->timestamps();

            $table->unique(['label_id', 'labelable_id', 'labelable_type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('labelables');
    }
};
