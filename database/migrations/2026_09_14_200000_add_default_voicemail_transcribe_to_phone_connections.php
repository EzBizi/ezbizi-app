<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Voicemail transcription, decided once for the company instead of per line.
 *
 * Jose: "we should be able to turn them all on or all off at the same time,
 * not per line." Measured 2026-09-14 before this: thirteen numbers, every one
 * of them off, and the only way to change that was thirteen visits to
 * thirteen line settings — which is why none of them had ever been changed.
 *
 * `default_record` and `default_transcribe` already work exactly this way, and
 * `PhoneNumber::resolvedFlag()` already looks for `default_<key>` on the
 * connection. So this column is the whole mechanism: there is nothing to teach
 * the resolver, only a value for it to find.
 *
 * Note what resolvedFlag's `?:` means and that it is wanted here — a company
 * default of ON turns every line on, and a line cannot hold itself off against
 * it. That is the "all on or all off" that was asked for rather than a
 * limitation of it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('phone_connections', function (Blueprint $table) {
            $table->boolean('default_voicemail_transcribe')
                ->default(false)
                ->after('default_transcribe');
        });
    }

    public function down(): void
    {
        Schema::table('phone_connections', function (Blueprint $table) {
            $table->dropColumn('default_voicemail_transcribe');
        });
    }
};
