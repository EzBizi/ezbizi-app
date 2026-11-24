<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Chaim 11-24 (Monday's update panel): a reply hangs under its update, and
 * an update keeps who liked it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_updates', function (Blueprint $table) {
            $table->uuid('parent_update_id')->nullable()->after('task_id')->index();
            $table->json('likes')->nullable()->after('mentions');
        });

        // Replies already brought from Monday ("monday:<update>.<reply>") hang
        // under their update: the part before the dot is the parent's ref.
        DB::statement("UPDATE task_updates c JOIN task_updates p ON p.external_ref = SUBSTRING_INDEX(c.external_ref, '.', 1)
            SET c.parent_update_id = p.id
            WHERE c.external_ref LIKE 'monday:%.%' AND c.external_ref NOT LIKE 'monday:files.%' AND c.parent_update_id IS NULL AND p.id <> c.id");
    }

    public function down(): void
    {
        Schema::table('task_updates', function (Blueprint $table) {
            $table->dropIndex(['parent_update_id']);
            $table->dropColumn(['parent_update_id', 'likes']);
        });
    }
};
