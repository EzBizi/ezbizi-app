<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Laravel's stub, modified for EzBizi: users are UUID-keyed, not BIGINT.
 *
 * Why (plan §5): sequential integer IDs leak business volume through the public
 * API — a client seeing /users/1041 learns roughly how many users exist across the
 * whole platform. Every tenant-owned table uses UUIDv7, and users are no exception.
 * Leaving them on BIGINT also makes every foreign key pointing at users the wrong
 * type, which is exactly how this was caught: assignments.user_id (uuid) could not
 * reference users.id (bigint) — MySQL error 3780.
 *
 * Editing a framework stub is acceptable *here* because the project is greenfield
 * with zero data. It stops being acceptable the moment real rows exist; after that,
 * changes belong in new migrations.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            // This global unique index is REPLACED by a per-organization one in
            // 2026_08_05_000001_create_foundation_tables — one person may hold
            // separate accounts in two organizations (plan §4).
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignUuid('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('sessions');
    }
};
