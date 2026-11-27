<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Task Board → Monday while both are in use (Jose, 2026-11-13: "keep the sync
 * parity for a week or two"). One row per change waiting to be written to the
 * Monday item: which fields (`kinds`), or which update to post. The worker
 * reads the task as it is when it sends, so a row never carries a stale value.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monday_outbox', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('task_id')->constrained('tasks')->cascadeOnDelete();
            $table->foreignUuid('task_update_id')->nullable()->constrained('task_updates')->cascadeOnDelete();
            $table->json('kinds')->nullable(); // create, title, status, due, owners, group, values:<column id>
            $table->string('state', 16)->default('pending'); // pending, sent, superseded, failed
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('changed_at'); // when the change was made here (last writer wins)
            $table->timestamp('available_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->string('error', 500)->nullable();
            $table->timestamps();
            $table->index(['state', 'available_at']);
            $table->index(['task_id', 'state']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monday_outbox');
    }
};
