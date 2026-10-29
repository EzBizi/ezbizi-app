<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A company may state its own windows (Jose, 2026-10-28: A1 Garage Doors
 * 5-8 PM without moving A-1 Chimney's). Null company_id is the
 * organization's set, which every company without its own inherits.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scheduling_time_slots', function (Blueprint $table) {
            $table->foreignUuid('company_id')->nullable()->after('organization_id')->constrained()->cascadeOnDelete();
            $table->index('organization_id');
        });
        Schema::table('scheduling_time_slots', function (Blueprint $table) {
            $table->dropUnique(['organization_id', 'start_time']);
            $table->unique(['organization_id', 'company_id', 'start_time'], 'sched_slots_org_company_start_unique');
        });
    }

    public function down(): void
    {
        Schema::table('scheduling_time_slots', function (Blueprint $table) {
            $table->dropUnique('sched_slots_org_company_start_unique');
            $table->dropConstrainedForeignId('company_id');
            $table->unique(['organization_id', 'start_time']);
        });
    }
};
