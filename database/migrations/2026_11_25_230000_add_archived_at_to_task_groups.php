<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Row 69 (Monday's group menu): Archive group, restorable; its tasks go with it and come back with it. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_groups', function (Blueprint $t) {
            $t->timestamp('archived_at')->nullable()->after('position');
        });
    }

    public function down(): void
    {
        Schema::table('task_groups', fn (Blueprint $t) => $t->dropColumn('archived_at'));
    }
};
