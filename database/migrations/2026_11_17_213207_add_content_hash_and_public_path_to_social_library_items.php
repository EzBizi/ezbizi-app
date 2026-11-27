<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Newsletter P0 (2026-11-17): pictures uploaded from the newsletter composer
 * land in the same media library. `content_hash` lets the same picture
 * uploaded twice in one scope answer the existing item (stable id, no
 * duplicate); `public_path` is the one public copy an inbox loads, so
 * choosing an item again reuses its address instead of copying it again.
 * Both nullable: rows from before this carry neither until next used.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('social_library_items', function (Blueprint $table) {
            $table->char('content_hash', 64)->nullable()->after('size');
            $table->string('public_path')->nullable()->after('content_hash');
            $table->index(['organization_id', 'scope_type', 'scope_id', 'content_hash'], 'social_library_items_scope_hash_index');
        });
    }

    public function down(): void
    {
        Schema::table('social_library_items', function (Blueprint $table) {
            $table->dropIndex('social_library_items_scope_hash_index');
            $table->dropColumn(['content_hash', 'public_path']);
        });
    }
};
