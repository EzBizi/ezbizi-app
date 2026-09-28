<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a published post can be seen, as the network reported it.
 *
 * Read back after publishing (LinksToPosts) so the Social tab can offer
 * "View post". Null for a network that gives no address, and for every post
 * sent before this column existed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('social_post_targets', function (Blueprint $table) {
            $table->string('url', 2048)->nullable()->after('remote_id');
        });
    }

    public function down(): void
    {
        Schema::table('social_post_targets', function (Blueprint $table) {
            $table->dropColumn('url');
        });
    }
};
