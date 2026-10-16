<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the apps report about themselves going wrong — Dart errors, native
 * crashes, freezes, failed server calls (Jose, 2026-10-15: "We should have the
 * app log crashes and issues as well"). Platform-wide, like password_resets:
 * an error before sign-in has no organization, and staff read across all of
 * them in the console's Health.
 *
 * One row per (fingerprint, version, user) per 10 minutes; repeats bump `count`
 * rather than adding rows, so a broken loop cannot flood the table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_issues', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id')->nullable()->index();
            $table->uuid('user_id')->nullable()->index();
            $table->string('kind', 20);
            $table->char('fingerprint', 40)->index();
            $table->string('message', 500);
            $table->text('detail')->nullable();
            $table->string('platform', 16)->nullable();
            $table->string('app_version', 32)->nullable();
            $table->string('module', 64)->nullable();
            $table->unsignedInteger('count')->default(1);
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at')->index();
            $table->timestamp('created_at');

            $table->index(['app_version', 'last_seen_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_issues');
    }
};
