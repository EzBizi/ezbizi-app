<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * In-app notifications (plan §12).
 *
 * Deliberately NOT Laravel's built-in notifications table. That one is a
 * polymorphic bag with a JSON `data` column and no tenant column, and this
 * system's whole boundary is that every tenant-owned row carries an
 * organization_id (§5.1). Sharing a table with the framework's conventions here
 * would mean the one table nobody thought about is the one without the boundary.
 *
 * Email and push are §12 too and are not built. This is the in-app half, which
 * needs no external service and therefore no excuse.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('user_id');

            // 'workflow', 'system'. Not an enum: the set grows with every module
            // that learns to tell somebody something.
            $table->string('source', 40)->default('system');

            $table->string('title');
            $table->text('body')->nullable();

            // Where clicking it should go — a record, not a URL. The client
            // decides how to route to a job; a stored URL would bake today's
            // routing into a row that outlives it.
            $table->string('subject_type')->nullable();
            $table->uuid('subject_id')->nullable();

            $table->timestamp('read_at')->nullable();

            // Who or what caused it, so a flood can be traced to the rule that
            // produced it rather than guessed at.
            $table->uuid('workflow_id')->nullable();
            $table->uuid('event_id')->nullable();

            $table->timestamp('created_at');

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();

            // The query the bell icon makes on every page load, so it is the one
            // that gets the index.
            $table->index(['organization_id', 'user_id', 'read_at', 'created_at'], 'notifications_unread');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
