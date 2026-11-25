<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Row 68 (Jose 11-25): a folder that is someone's own Monday workspace ("dieuve ticoit Vibes") says whose. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_board_folders', function (Blueprint $t) {
            $t->uuid('owner_user_id')->nullable()->after('parent_id');
        });
    }

    public function down(): void
    {
        Schema::table('task_board_folders', fn (Blueprint $t) => $t->dropColumn('owner_user_id'));
    }
};
