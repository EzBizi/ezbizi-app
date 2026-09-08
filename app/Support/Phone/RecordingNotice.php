<?php

namespace App\Support\Phone;

use App\Models\PhoneNumber;

/**
 * The spoken consent sentence. One place so inbound and outbound stay identical.
 *
 * ── Why a line may override it ───────────────────────────────────
 *
 * This sentence is what discharges all-party consent, and consent law is not
 * uniform. A single hardcoded string meant a business needing to name itself,
 * or to say why the call is recorded, had no way to — and was recording
 * regardless. That is the one setting on the phone module with legal weight,
 * so it belongs to the line rather than to us.
 *
 * The default stays here and stays used: a blank override is the built-in
 * sentence, never silence. Recording with nothing spoken is the failure this
 * class exists to prevent, so [forNumber] can only ever return a sentence.
 */
final class RecordingNotice
{
    public static function sentence(): string
    {
        return 'This call may be recorded.';
    }

    /**
     * The sentence this line speaks: its own, then the company's, then ours.
     *
     * Three steps rather than two. The middle one is new and is the point of
     * the company defaults — a line that says nothing takes what the company
     * set, and only a company that set nothing either falls back to the
     * built-in wording.
     */
    public static function forNumber(PhoneNumber $number): string
    {
        $own = trim((string) $number->resolved('recording_notice'));

        return $own === '' ? self::sentence() : $own;
    }
}
