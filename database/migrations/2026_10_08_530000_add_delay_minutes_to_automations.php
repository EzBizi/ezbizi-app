<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How long a rule waits after its event before it acts (Jose, 2026-10-08: the
 * membership text after a happy review goes "after a delay on about 10 mins so
 * we dont interrupt their review process"). Null is straight away.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('automations', function (Blueprint $table) {
            $table->unsignedSmallInteger('delay_minutes')->nullable()->after('activated_at');
        });
    }

    public function down(): void
    {
        Schema::table('automations', function (Blueprint $table) {
            $table->dropColumn('delay_minutes');
        });
    }
};
