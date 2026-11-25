<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Chat: EzBizi's own WhatsApp, between EzBizi accounts (Jose, 2026-11-25:
 * "instead of phone numbers they work with just ezbizi accounts ... for
 * messages it should be internal messaging, not related to twilio").
 *
 * Three tables. Reactions, stars and "delete for me" are JSON on the message
 * rather than three more tables: each is a handful of user ids per message,
 * read with the message and never queried across the organization except
 * Starred, which whereJsonContains answers.
 *
 * staff_messages (the Team panel's 1:1 notes) is untouched; folding it in is a
 * follow-up once Chat is live.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chats', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('type', 10); // direct | group
            $table->string('name', 100)->nullable();
            $table->string('description', 500)->nullable();
            // Group setting, as WhatsApp's "Send messages: only admins".
            $table->boolean('only_admins_send')->default(false);
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            // One direct chat per pair: the two ids sorted and joined.
            $table->string('direct_key', 80)->nullable();
            $table->timestamp('last_message_at', 6)->nullable();
            $table->timestamps(6);

            $table->unique(['organization_id', 'direct_key']);
        });

        Schema::create('chat_members', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('chat_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_admin')->default(false);
            // Receipts are moments, not ids: a message is delivered to / read
            // by this member when its created_at is at or before these.
            $table->timestamp('delivered_at', 6)->nullable();
            $table->timestamp('read_at', 6)->nullable();
            $table->timestamp('cleared_at', 6)->nullable();
            $table->timestamp('pinned_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamp('muted_until')->nullable();
            $table->boolean('marked_unread')->default(false);
            $table->timestamp('left_at')->nullable();
            $table->timestamps();

            $table->unique(['chat_id', 'user_id']);
            $table->index(['organization_id', 'user_id']);
        });

        Schema::create('chat_messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('chat_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('sender_id')->nullable()->constrained('users')->nullOnDelete();
            // text | image | video | audio | voice | file | call | system
            $table->string('kind', 10)->default('text');
            $table->text('body')->nullable();
            $table->foreignUuid('reply_to_id')->nullable();
            $table->boolean('forwarded')->default(false);
            // {duration, status} for a call, {event, ...} for a system line.
            $table->json('meta')->nullable();
            $table->json('reactions')->nullable();   // {userId: emoji}
            $table->json('starred_by')->nullable();  // [userId]
            $table->json('hidden_for')->nullable();  // [userId] — "delete for me"
            $table->timestamp('edited_at')->nullable();
            $table->timestamp('deleted_at')->nullable(); // "delete for everyone"
            $table->timestamp('created_at', 6)->nullable();
            $table->timestamp('updated_at', 6)->nullable();

            $table->index(['chat_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_messages');
        Schema::dropIfExists('chat_members');
        Schema::dropIfExists('chats');
    }
};
