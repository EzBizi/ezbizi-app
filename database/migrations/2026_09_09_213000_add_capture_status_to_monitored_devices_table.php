<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What happened the last time this device tried to capture its screen.
 *
 * Until this existed, a capture that failed was silent everywhere: the native
 * side returned an empty buffer for ~20 distinct reasons, the channel turned
 * every one of them into `Success(null)`, and the client logged it only under
 * kDebugMode — which is to say nowhere in a shipped build. So a machine whose
 * JPEG encoder is missing, one whose owner keeps the workstation locked, and
 * one that simply had not reached its first five-minute tick all presented
 * identically as "can capture screenshots, but none have landed yet", and no
 * query against this database could tell them apart, because the fact had
 * never been written down.
 *
 * Two scalar columns rather than one JSON blob: "which devices are failing,
 * and how" is a question worth a WHERE clause, and `capture_last_error IS NOT
 * NULL` answers it without unpacking a document.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monitored_devices', function (Blueprint $table) {
            // When the device last TRIED, which is not when it last succeeded —
            // the pair is the point. A recent attempt with an error is a live
            // fault; an old attempt with no error is simply a quiet machine.
            $table->timestamp('capture_last_attempt_at')->nullable()->after('specs');

            // Null means the last attempt succeeded. Otherwise a short slug
            // from the client's fixed vocabulary (`screen_locked`,
            // `jpeg_encoder_missing`, `upload_404`, …) — never free text and
            // never anything read off the screen, since this is shown to a
            // human and is not the screen's content.
            $table->string('capture_last_error', 40)->nullable()->after('capture_last_attempt_at');
        });
    }

    public function down(): void
    {
        Schema::table('monitored_devices', function (Blueprint $table) {
            $table->dropColumn(['capture_last_attempt_at', 'capture_last_error']);
        });
    }
};
