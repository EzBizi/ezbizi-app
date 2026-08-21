<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Connected social pages, and the short-lived Pinterest sign-in that authenticates
 * the public OAuth callback.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_connections', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');

            $table->enum('scope_type', ['organization', 'company', 'franchise']);
            $table->uuid('scope_id');

            $table->string('provider');
            $table->string('remote_id');
            $table->string('display_name');
            $table->string('handle')->nullable();

            $table->text('credentials')->nullable();

            $table->string('status')->default('connected');
            $table->text('status_reason')->nullable();
            $table->timestamp('last_reached_at')->nullable();

            $table->timestamps();

            $table->unique(['organization_id', 'provider', 'remote_id']);
            $table->index(['organization_id', 'scope_type', 'scope_id']);

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
        });

        Schema::create('pinterest_sign_ins', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('user_id');

            $table->enum('scope_type', ['organization', 'company', 'franchise']);
            $table->uuid('scope_id');

            $table->char('state_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pinterest_sign_ins');
        Schema::dropIfExists('social_connections');
    }
};
