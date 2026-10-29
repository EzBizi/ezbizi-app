<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The window an offer named, and when the job was moved onto it (2026-10-28). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scheduling_reschedule_offers', function (Blueprint $table) {
            $table->time('start_time')->nullable()->after('open_date');
            $table->time('end_time')->nullable()->after('start_time');
            $table->timestamp('moved_at')->nullable()->after('sent_by');
        });
    }

    public function down(): void
    {
        Schema::table('scheduling_reschedule_offers', function (Blueprint $table) {
            $table->dropColumn(['start_time', 'end_time', 'moved_at']);
        });
    }
};
