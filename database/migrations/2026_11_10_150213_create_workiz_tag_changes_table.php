<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every rename, recolour, new or deleted tag seen in a Workiz account (Jose,
 * 2026-11-10: "if someone in the future changes a tag, we know about it").
 * Written by SyncWorkizRecords' tag read; read by the Workiz tags tab.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workiz_tag_changes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id')->index();
            $table->uuid('franchise_id');
            $table->string('tag_remote_id', 64);
            // renamed, recoloured, added, deleted
            $table->string('change', 16);
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();
            $table->index(['organization_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workiz_tag_changes');
    }
};
