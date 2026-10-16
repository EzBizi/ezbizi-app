<?php

namespace App\Support\Notifications;

/**
 * The words a lead notification uses — one table, for every source.
 *
 * Jose's spec, 2026-10-06, "Source Names for notification - automations",
 * as amended the next day — he wrote each title "You got a new ...", then:
 * "for all these, let's get rid of the You got a, so it's only New "Local
 * Services Call" lead, etc". The reply title followed suit on the deploy
 * desk's recommendation, told to him so he could object.
 *
 *     New "Local Services Call" lead
 *     (319) 210-2005 - 1:10 min - A1 Chimney - Toms River
 *
 *     New reply on "Local Services Text"
 *     <the reply, up to 55 characters>
 *
 * ── Why one table here, not a label per module ───────────────────
 *
 * Leads, Meta, Workiz and WhatsApp each raise these, and each is a different
 * session. Thirteen sentences written in four places would drift the first
 * time anybody tidied one of them, and Jose would be reading four phrasings
 * of the same idea. So the modules supply FACTS — source, lead_type, phone,
 * duration, company, branch — and this file owns every word.
 *
 * ── lead_type is the module's to set, and these are the values ───
 *
 * Local Services already records PHONE_CALL / MESSAGE / BOOKING (measured on
 * production: 38,553 / 5,241 / 616). Meta and Workiz record nothing today, so
 * their constants below are the contract: a Meta lead from a message is
 * MESSAGE and from a lead form is FORM; a Workiz lead booked online is ONLINE
 * and one that texted is TEXT. Until a module sets them, its leads read
 * "Meta" / "Workiz" without the second word — still correct, just less said.
 */
final class LeadNotificationText
{
    public const LSA_CALL = 'PHONE_CALL';

    public const LSA_TEXT = 'MESSAGE';

    public const LSA_BOOKING = 'BOOKING';

    public const META_TEXT = 'MESSAGE';

    public const META_FORM = 'FORM';

    public const WORKIZ_ONLINE = 'ONLINE';

    public const WORKIZ_TEXT = 'TEXT';

    /** How a WhatsApp conversation was classified, for its own three sentences. */
    public const WHATSAPP_UNKNOWN = 'unknown';

    public const WHATSAPP_LEAD = 'lead';

    public const WHATSAPP_HIRING = 'hiring';

    /** Jose: a reply is shown "up to 55 characters". */
    public const REPLY_PREVIEW_MAX = 55;

    /**
     * The quoted name — "Local Services Call", "Live Chat", "Meta Form".
     */
    public static function label(string $source, ?string $leadType): string
    {
        $type = $leadType === null ? null : strtoupper($leadType);

        return match ($source) {
            'local_services' => match ($type) {
                self::LSA_CALL => 'Local Services Call',
                self::LSA_TEXT => 'Local Services Text',
                self::LSA_BOOKING => 'Local Services Online Booking',
                default => 'Local Services',
            },
            'thumbtack' => 'Thumbtack',
            'website_chat' => 'Live Chat',
            'website_form' => 'Website Form',
            'website_booking' => 'Online Booking',
            'facebook', 'instagram' => match ($type) {
                self::META_TEXT => 'Meta Text',
                self::META_FORM => 'Meta Form',
                default => 'Meta',
            },
            'workiz' => match ($type) {
                self::WORKIZ_ONLINE => 'Workiz Online',
                self::WORKIZ_TEXT => 'Workiz Text',
                default => 'Workiz',
            },
            'whatsapp' => 'WhatsApp',
            'ringcentral' => 'RingCentral Call',
            // A source added later still says something true rather than
            // throwing inside a queue worker; its owner adds its line here.
            default => ucwords(str_replace('_', ' ', $source)),
        };
    }

    /**
     * The title of a new lead: `New "Local Services Call" lead`.
     *
     * The noun is not always "lead", and that is Jose's wording, not a slip:
     * Thumbtack is a "text lead" and Live Chat is a "request".
     */
    public static function headline(string $source, ?string $leadType): string
    {
        $noun = match ($source) {
            'thumbtack' => 'text lead',
            'website_chat' => 'request',
            default => 'lead',
        };

        return sprintf('New "%s" %s', self::label($source, $leadType), $noun);
    }

    /**
     * The title of a reply: `New reply on "Local Services Text"`.
     *
     * Null for a Local Services CALL. Jose: "Get rid of a reply on LS Call
     * notification" — there is nothing to reply to on a call, and on
     * production 2,241 "A customer replied on Local Services: Ca…" rows in
     * three days were calls being counted as replies. Null means: do not
     * notify, and a caller must not fall back to a generic sentence.
     */
    public static function replyHeadline(string $source, ?string $leadType): ?string
    {
        if ($source === 'local_services' && strtoupper((string) $leadType) === self::LSA_CALL) {
            return null;
        }

        return sprintf('New reply on "%s"', self::label($source, $leadType));
    }

    /**
     * The WhatsApp sentences, which classify the conversation, not a lead.
     */
    public static function whatsappHeadline(string $kind): string
    {
        return match ($kind) {
            self::WHATSAPP_LEAD => 'New "WhatsApp" lead',
            self::WHATSAPP_HIRING => 'New "WhatsApp" hiring request',
            default => 'New "WhatsApp" message (Unknown)',
        };
    }

    /**
     * A missed call's title: `Missed call - A-1 Chimney`.
     *
     * Bar asked (Jose's voice notes, 2026-10-07) for "you got a missed call"
     * plus the company; Jose chose it shortened, matching the lead titles —
     * "Missed call - A-1 Chimney", then "Shorten it". Without a company the
     * title stops at "Missed call" rather than guessing one.
     */
    public static function missedCallHeadline(?string $company): string
    {
        return $company === null || trim($company) === '' ? 'Missed call' : "Missed call - {$company}";
    }

    /**
     * Its second line: the caller's number, then the name of OUR number they
     * rang — `(319) 210-2005 - Chicago SEO line`. A call that reached voicemail
     * counts as missed (Jose, same day) and says so at the end, because a
     * message waiting is a different errand from a call to return.
     */
    public static function missedCallDetails(?string $callerNumber, ?string $ourNumberName, bool $voicemail = false): string
    {
        // A withheld number is SAID, not dropped: Bar asked for the caller's
        // number first, and with it missing the line read as if our queue's
        // name were the caller — measured on production's second event,
        // "A1 Chimney - Voicemail" with from = null.
        $parts = array_filter(
            [self::phone($callerNumber) ?? 'Unknown caller', $ourNumberName, $voicemail ? 'left a voicemail' : null],
            fn (?string $part) => $part !== null && trim($part) !== '',
        );

        return implode(' - ', $parts);
    }

    /**
     * The second line: `(319) 210-2005 - 1:10 min - A1 Chimney - Toms River`.
     *
     * Each part only when known — a text has no duration, a lead with no
     * branch has no branch — so a missing fact drops out instead of leaving a
     * dangling " - ".
     */
    public static function details(?string $phone, ?int $durationMs, ?string $company, ?string $branch): string
    {
        $parts = [
            self::phone($phone),
            $durationMs === null || $durationMs <= 0 ? null : self::duration($durationMs),
            $company,
            $branch,
        ];

        return implode(' - ', array_filter($parts, fn (?string $part) => $part !== null && trim($part) !== ''));
    }

    /**
     * Cut to at most $max characters, the ellipsis included.
     *
     * Jose's limits are limits on what a person sees ("up to 55 characters"),
     * so the "…" counts toward them rather than landing as character 56.
     */
    public static function clip(?string $text, int $max): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', (string) $text));

        if (mb_strlen($text) <= $max) {
            return $text;
        }

        return rtrim(mb_substr($text, 0, $max - 1)).'…';
    }

    /** A North American number as (319) 210-2005; anything else as given. */
    public static function phone(?string $phone): ?string
    {
        if ($phone === null || trim($phone) === '') {
            return null;
        }

        $digits = preg_replace('/\D/', '', $phone);

        if (strlen($digits) === 11 && $digits[0] === '1') {
            $digits = substr($digits, 1);
        }

        return strlen($digits) === 10
            ? sprintf('(%s) %s-%s', substr($digits, 0, 3), substr($digits, 3, 3), substr($digits, 6))
            : trim($phone);
    }

    /** 70,000 ms as "1:10 min" — Jose's format, seconds always two digits. */
    public static function duration(int $durationMs): string
    {
        $seconds = intdiv($durationMs, 1000);

        return sprintf('%d:%02d min', intdiv($seconds, 60), $seconds % 60);
    }
}
