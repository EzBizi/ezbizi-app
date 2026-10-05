<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the person chose for THIS account, per post.
 *
 * TikTok's Direct Post rules make the poster pick who sees it, whether
 * comments/duets/stitches are allowed, and whether it is branded, every time,
 * with no defaults. Those answers belong to one post at one account, so they
 * live on the target row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('social_post_targets', function (Blueprint $table) {
            $table->json('options')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('social_post_targets', function (Blueprint $table) {
            $table->dropColumn('options');
        });
    }
};
