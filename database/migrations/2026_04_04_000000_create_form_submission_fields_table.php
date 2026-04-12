<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('form_submission_fields', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('form_submission_id');
            $table->string('field_path'); // e.g., 'email', 'address.city', 'items[0].name'
            $table->string('field_key'); // e.g., 'email', 'city', 'name'
            $table->text('field_value')->nullable(); // TEXT value
            $table->decimal('field_value_numeric', 20, 6)->nullable(); // DECIMAL for numbers
            $table->boolean('field_value_boolean')->nullable(); // BOOLEAN value
            $table->timestamp('field_value_date')->nullable(); // TIMESTAMP for dates
            $table->string('field_type', 20); // 'string', 'number', 'boolean', 'date'
            $table->timestamps();

            // Foreign key with CASCADE delete
            $table->foreign('form_submission_id')
                ->references('id')
                ->on('form_submissions')
                ->onDelete('cascade');

            // Performance indexes
            $table->index('form_submission_id'); // Basic lookup
            $table->index('field_path'); // Filtering by field
            $table->index(['form_submission_id', 'field_path']); // Composite for fast specific field lookup
            $table->index(['field_path', 'field_value']); // Composite for filtering WHERE field='value'
            $table->index('field_value_numeric'); // Numeric queries (AVG, SUM, MIN, MAX)
            $table->index('field_value_date'); // Date queries (DATE_TRUNC, BETWEEN)
        });
        
        // Partial index for non-null values (frequent filtering)
        // Must be created after the table exists
        DB::statement('CREATE INDEX form_submission_fields_field_value_not_null_idx ON form_submission_fields (field_value) WHERE field_value IS NOT NULL');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('form_submission_fields');
    }
};
