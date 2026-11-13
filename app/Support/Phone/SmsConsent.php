<?php

namespace App\Support\Phone;

use App\Models\PhoneConnection;
use Illuminate\Support\Facades\DB;

/**
 * STOP / START for every text a company sends (Jose, 2026-11-03).
 *
 * Every tenant send path (Messages, lead texts, automations, invoices, review
 * and membership texts through those) calls prepare() before Twilio. It
 * refuses an opted-out number and adds the opt-out line to the first text a
 * company ever sends a number.
 *
 * ponytail: no own STOP/HELP reply. Twilio's default opt-out handling already
 * answers STOP/START/HELP on these lines; a second reply would double up.
 */
final class SmsConsent
{
    public const STOP = ['STOP', 'STOPALL', 'UNSUBSCRIBE', 'CANCEL', 'END', 'QUIT', 'OPTOUT', 'REVOKE'];

    public const START = ['START', 'UNSTOP', 'YES'];

    public const FOOTER = 'Reply STOP to opt out.';

    public const REFUSAL = 'This number replied STOP, so it cannot be texted until it replies START.';

    /** Record an inbound STOP or START. Returns the keyword it acted on, if any. */
    public static function absorb(string $organizationId, string $from, string $body): ?string
    {
        $word = strtoupper(trim($body, " \t\n\r.!"));
        if ($from === '' || $word === '') {
            return null;
        }

        if (in_array($word, self::STOP, true)) {
            DB::table('sms_opt_outs')->updateOrInsert(
                ['organization_id' => $organizationId, 'e164' => $from],
                ['keyword' => $word, 'created_at' => now()],
            );

            return $word;
        }

        if (in_array($word, self::START, true)) {
            DB::table('sms_opt_outs')->where(['organization_id' => $organizationId, 'e164' => $from])->delete();

            return $word;
        }

        return null;
    }

    /**
     * Twilio refused because the number is opted out on Twilio's side
     * (21610), e.g. a STOP sent before we listened. Recorded here so the guard
     * refuses the next one before it reaches Twilio. True when it was that.
     */
    public static function absorbCarrierRefusal(string $organizationId, string $to, \Throwable $e): bool
    {
        if ($e->getCode() !== 21610 && ! str_contains($e->getMessage(), 'unsubscribed recipient')) {
            return false;
        }
        DB::table('sms_opt_outs')->updateOrInsert(
            ['organization_id' => $organizationId, 'e164' => $to],
            ['keyword' => 'STOP', 'created_at' => now()],
        );

        return true;
    }

    public static function optedOut(string $organizationId, string $to): bool
    {
        return DB::table('sms_opt_outs')->where(['organization_id' => $organizationId, 'e164' => $to])->exists();
    }

    /**
     * The body to send, or null when the number opted out of this company.
     *
     * Automated texts (automations, invoices, reviews, membership) always carry
     * the opt-out line; a person's 1:1 text carries it only on the first text
     * (desk for Jose, 2026-11-03).
     */
    public static function prepare(PhoneConnection $connection, string $to, string $body, bool $automated = false): ?string
    {
        $org = (string) $connection->organization_id;
        if (self::optedOut($org, $to)) {
            return null;
        }

        $texted = ! $automated && DB::table('phone_messages')
            ->where('organization_id', $org)
            ->where('direction', 'outbound')
            ->where('to_e164', $to)
            ->exists();

        return $texted || stripos($body, 'STOP') !== false ? $body : rtrim($body)."\n\n".self::FOOTER;
    }
}
