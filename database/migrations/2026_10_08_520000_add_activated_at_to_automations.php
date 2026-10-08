<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When a rule was last switched on. The engine does not run a rule on
 * anything that happened before then (Jose, 2026-10-08: the job-done text
 * "should [send] from now", never to every past Done customer). Null on rules
 * that predate it, which keep their old behaviour.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('automations', function (Blueprint $table) {
            $table->timestamp('activated_at')->nullable()->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('automations', function (Blueprint $table) {
            $table->dropColumn('activated_at');
        });
    }
};
