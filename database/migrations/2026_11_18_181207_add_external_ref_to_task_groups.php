<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Desk 11-18: a group renamed on Monday is renamed here (the same group), so
// each group remembers the Monday group it mirrors.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_groups', function (Blueprint $table) {
            $table->string('external_ref', 64)->nullable()->after('position');
        });
    }

    public function down(): void
    {
        Schema::table('task_groups', fn (Blueprint $table) => $table->dropColumn('external_ref'));
    }
};
