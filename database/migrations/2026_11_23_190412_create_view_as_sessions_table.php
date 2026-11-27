<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * View as (Jose, 2026-11-23): a developer Administrator seeing the app as one
 * role at one place, or as one person. One row per start, tied to the sign-in
 * token it was started on, so signing out (the token is deleted) ends it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('view_as_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('actor_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('personal_access_token_id')->constrained('personal_access_tokens')->cascadeOnDelete();
            $table->foreignUuid('target_user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('role_id')->nullable()->constrained('roles')->cascadeOnDelete();
            $table->string('scope_type', 20)->nullable();
            $table->uuid('scope_id')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();
            $table->index(['personal_access_token_id', 'ended_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('view_as_sessions');
    }
};
