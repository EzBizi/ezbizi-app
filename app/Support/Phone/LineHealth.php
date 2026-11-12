<?php

namespace App\Support\Phone;

use App\Models\PhoneNumber;
use App\Models\PhonePresence;

/**
 * Pass or fail for one line, each problem keyed so a reader never has to
 * match on wording (Numbers card, Integrations overview; 2026-11-12).
 */
class LineHealth
{
    public const KEYS = ['pointed', 'route', 'texting', 'e911', 'signed_in'];

    /**
     * @param  bool|null  $answers  whether the call flow reaches somebody; worked out when not given
     * @return array{ok: bool, problems: list<array{key: string, sentence: string}>}
     */
    public static function for(PhoneNumber $number, ?bool $answers = null): array
    {
        $answers ??= (bool) (collect((new TwimlRenderer)->describe($number))->firstWhere('step', 'answer')['usable'] ?? false);

        $problems = array_filter([
            'pointed' => blank($number->twilio_sid) ? 'Not pointed at EzBizi yet, so calls never arrive here.' : null,
            'route' => $number->override_on || $answers ? null : 'Calls reach nobody: choose who answers, or a ring group.',
            'texting' => $number->sms_outbound_allowed ? null : 'Texting: not registered (A2P), so texts cannot be sent.',
            'e911' => $number->emergency_address_set ? null : (EmergencyAddress::for($number) === null
                ? EmergencyAddress::NO_ADDRESS
                : 'E911: missing — the address on file is attached within the hour.'),
            'signed_in' => PhonePresence::query()->where('connection_id', $number->connection_id)->available()->exists()
                ? null : 'Nobody is signed in to take calls on this phone account right now.',
        ]);

        return [
            'ok' => $problems === [],
            'problems' => array_map(fn (string $key) => ['key' => $key, 'sentence' => $problems[$key]], array_keys($problems)),
        ];
    }
}
