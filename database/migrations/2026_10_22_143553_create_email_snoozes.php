<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Snooze (design 137, 2026-10-22). Gmail's API has no snooze, so EzBizi
 * archives the conversation at Gmail and puts it back in the Inbox when
 * `until` passes. The row keeps what the Snoozed folder shows meanwhile.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_snoozes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('mailbox_id');
            $table->string('message_id');
            $table->string('thread_id')->nullable();
            $table->string('subject')->default('');
            $table->string('from_name')->default('');
            $table->string('from_address')->default('');
            $table->text('snippet')->nullable();
            $table->timestamp('until');
            $table->uuid('snoozed_by')->nullable();
            $table->timestamps();

            $table->unique(['mailbox_id', 'message_id']);
            $table->index('until');
            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('mailbox_id')->references('id')->on('mailboxes')->cascadeOnDelete();
            $table->foreign('snoozed_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_snoozes');
    }
};
