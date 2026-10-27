<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One finished day of an outside phone system's call log, kept in EzBizi
 * (Jose, 2026-10-27: every integration is a removable add-on and its data stays
 * here). RingCentral's days lived only in a 7-day cache, so disconnecting it or
 * flushing the cache took the call reports' history with it.
 *
 * Keyed as the cache was (source, day, zone, account): a finished day never
 * changes, so it is written once and read forever.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('call_log_days', function (Blueprint $table) {
            $table->id();
            $table->string('day_key', 191)->unique();
            $table->longText('calls');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('call_log_days');
    }
};
