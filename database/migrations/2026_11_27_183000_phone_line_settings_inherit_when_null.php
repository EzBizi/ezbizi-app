<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A line's record / transcribe / after-hours: NULL now means "follow the
 * company" (audit 11-27).
 *
 * Before, `false` and 'voicemail' were the inherit markers, so a line could
 * never switch recording OFF under a company that records, and a company's
 * "forward after hours" never applied (every line stored 'voicemail').
 *
 * Existing false / 'voicemail' values become NULL, which is exactly how they
 * were being resolved, so no line changes behaviour on deploy. Prod rows were
 * dumped to /var/backups/ezbizi-dumps/phone_numbers_flags_2026-11-27.json.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('phone_numbers', function (Blueprint $table) {
            $table->boolean('record')->nullable()->default(null)->change();
            $table->boolean('transcribe')->nullable()->default(null)->change();
            $table->boolean('voicemail_transcribe')->nullable()->default(null)->change();
            $table->string('after_hours', 32)->nullable()->default(null)->change();
        });

        DB::table('phone_numbers')->where('record', false)->update(['record' => null]);
        DB::table('phone_numbers')->where('transcribe', false)->update(['transcribe' => null]);
        DB::table('phone_numbers')->where('voicemail_transcribe', false)->update(['voicemail_transcribe' => null]);
        DB::table('phone_numbers')->where('after_hours', 'voicemail')->update(['after_hours' => null]);
    }

    public function down(): void
    {
        DB::table('phone_numbers')->whereNull('record')->update(['record' => false]);
        DB::table('phone_numbers')->whereNull('transcribe')->update(['transcribe' => false]);
        DB::table('phone_numbers')->whereNull('voicemail_transcribe')->update(['voicemail_transcribe' => false]);
        DB::table('phone_numbers')->whereNull('after_hours')->update(['after_hours' => 'voicemail']);

        Schema::table('phone_numbers', function (Blueprint $table) {
            $table->boolean('record')->default(true)->change();
            $table->boolean('transcribe')->default(false)->change();
            $table->boolean('voicemail_transcribe')->default(false)->change();
            $table->string('after_hours', 32)->change();
        });
    }
};
