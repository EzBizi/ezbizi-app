<?php

namespace App\Support\Ai;

use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * Reads a staff WhatsApp message asking for a caller's calls ("please send me
 * all the calls w this number 9524572594") into a number and a date range
 * (Jose, 2026-10-13: Grok interprets it; today unless a date is asked; "grok
 * can also reply asking for clarifications").
 */
final class CallRecordingsRequest
{
    /** Cheap gate before any Grok call: a phone-length number, or call words. */
    public static function looksLikeOne(string $text): bool
    {
        return preg_match('/(\d[\s().-]*){10}/', $text) === 1
            && preg_match('/\b(call|calls|recording|recordings|audio|llamada|llamadas|grabaci)/iu', $text) === 1;
    }

    /**
     * @return array{phone: ?string, from: string, to: string, clarify: ?string}|null
     *                                                                            null: not a request for calls
     *
     * @throws RuntimeException when Grok fails
     */
    public static function read(string $text, CarbonImmutable $today): ?array
    {
        $a = GrokCli::ask(
            "A staff member of a home-services business wrote this on WhatsApp. Decide if they are asking "
            ."for the phone calls (recordings) with a customer's number. Today is {$today->toDateString()} "
            ."({$today->format('l')}).\n"
            ."- is_request: true only if they want calls/recordings for a phone number.\n"
            ."- phone: the 10-digit US number, digits only, or empty if none.\n"
            ."- from / to: dates Y-m-d they asked for; if they gave no date, both are today. \"yesterday\", "
            ."\"last week\", \"since Monday\" etc. become real dates. Never later than today.\n"
            ."- clarify: empty, or ONE short question if the number or dates are unclear.\n\n"
            ."MESSAGE: ".$text,
            [
                'type' => 'object',
                'properties' => [
                    'is_request' => ['type' => 'boolean'],
                    'phone' => ['type' => 'string'],
                    'from' => ['type' => 'string'],
                    'to' => ['type' => 'string'],
                    'clarify' => ['type' => 'string'],
                ],
                'required' => ['is_request', 'phone', 'from', 'to', 'clarify'],
                'additionalProperties' => false,
            ],
            30,
        );

        if (($a['is_request'] ?? false) !== true) {
            return null;
        }

        $phone = substr(preg_replace('/\D/', '', (string) $a['phone']), -10);
        $date = fn (string $d) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) === 1
            ? min(CarbonImmutable::parse($d, $today->getTimezone()), $today)->toDateString()
            : $today->toDateString();
        $from = $date((string) $a['from']);
        $to = $date((string) $a['to']);
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }
        // ponytail: a month at most per ask, so one message cannot pull a year of audio.
        if (CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)) > 31) {
            $from = CarbonImmutable::parse($to)->subDays(31)->toDateString();
        }

        $clarify = trim((string) $a['clarify']);

        return [
            'phone' => strlen($phone) === 10 ? $phone : null,
            'from' => $from,
            'to' => $to,
            'clarify' => $clarify !== '' ? $clarify : (strlen($phone) === 10 ? null : 'Which phone number? Send the 10 digits.'),
        ];
    }
}
