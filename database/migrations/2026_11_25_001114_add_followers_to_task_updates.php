<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Monday's bell on an update (Chaim 11-24): who is told of each reply to it. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_updates', function (Blueprint $table) {
            $table->json('followers')->nullable()->after('likes');
        });
    }

    public function down(): void
    {
        Schema::table('task_updates', function (Blueprint $table) {
            $table->dropColumn('followers');
        });
    }
};
