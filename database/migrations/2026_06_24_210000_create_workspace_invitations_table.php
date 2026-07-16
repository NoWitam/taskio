<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Invitations are CENTRAL data (default connection) and work identically for
     * shared- and own-database workspaces: members/invitations never live in a
     * tenant database, only domain data does.
     */
    public function up(): void
    {
        Schema::create('workspace_invitations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')
                ->constrained('workspaces')
                ->cascadeOnDelete();
            $table->string('email');
            // Only the SHA-256 hash of the token is stored; the plaintext is
            // emailed once and never persisted. Looked up by hash on accept.
            $table->string('token_hash')->unique();
            $table->foreignIdFor(User::class, 'invited_by');
            $table->string('status')->default('pending');
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_invitations');
    }
};
