<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Monday's update feed tabs (gap review, 11-24): updates people bookmark, and
 * updates written now to post later. A scheduled update carries its posting
 * time as created_at too, so nothing lists it before then.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_updates', function (Blueprint $table) {
            $table->json('bookmarks')->nullable()->after('followers');
            $table->timestamp('scheduled_for')->nullable()->after('bookmarks')->index();
        });
    }

    public function down(): void
    {
        Schema::table('task_updates', function (Blueprint $table) {
            $table->dropIndex(['scheduled_for']);
            $table->dropColumn(['bookmarks', 'scheduled_for']);
        });
    }
};
