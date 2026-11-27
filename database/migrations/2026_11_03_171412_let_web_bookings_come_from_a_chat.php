<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The booking bot (Jose, 2026-11-03): a window offered or held in a Local
 * Services chat has no website, and carries the Workiz job type the chat
 * settled on, so one tap on Approve can book it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('web_bookings', function (Blueprint $table) {
            $table->uuid('site_id')->nullable()->change();
            $table->string('job_type', 120)->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('web_bookings', function (Blueprint $table) {
            $table->dropColumn('job_type');
        });
    }
};
