<?php

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
        Schema::table('tasks', function (Blueprint $table) {
            $table->foreignIdFor(Form::class, 'form_id')->nullable()->after('assigned_id')->constrained()->nullOnDelete();
            $table->string('form_mode')->nullable()->after('form_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropForeign(['form_id']);
            $table->dropColumn(['form_id', 'form_mode']);
        });
    }
};
