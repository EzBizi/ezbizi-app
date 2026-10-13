<?php

namespace App\Support\Ai;

/**
 * Whether a name AI heard may change a lead's name: Rodrigo's merge table
 * (docs/agents/rodrigo-suggestions/EZBIZI-TRANSCRIPTION-AND-CUSTOMER-IDENTITY-
 * HANDOFF.md §3), approved by Jose 2026-10-12 (AI item 9).
 *
 * Never last-write-wins: a name a person set is kept and a different one is
 * raised as a conflict; a phone other people share renames nobody.
 */
final class NameDecision
{
    public const BASES = ['self_introduction', 'answer_to_name_question', 'spelled_or_corrected', 'none'];

    /**
     * @param  ?string  $current  the lead's name now
     * @param  ?string  $aiWrote  the name AI wrote earlier, if any (ai_set)
     * @param  bool  $sharedPhone  another lead on this phone has a different name
     * @return array{0: 'fill'|'enrich'|'correct'|'retain'|'conflict', 1: ?string} the action and the name it writes or flags
     */
    public static function decide(?string $current, ?string $aiWrote, ?string $candidate, string $basis, bool $sharedPhone): array
    {
        $candidate = trim((string) $candidate);
        $current = trim((string) $current);

        if ($candidate === '' || $basis === 'none' || ! in_array($basis, self::BASES, true)) {
            return ['retain', null];
        }
        if (self::placeholder($current)) {
            return ['fill', $candidate];
        }
        if (self::same($current, $candidate)) {
            return ['retain', null];
        }
        if ($sharedPhone) {
            return ['conflict', $candidate];
        }

        // "Nora" then "Nora Vale": enrich, never invent the missing part.
        $first = preg_split('/\s+/u', $current);
        if (count($first) === 1 && mb_stripos($candidate, $current.' ') === 0) {
            return ['enrich', $candidate];
        }

        $byAi = $aiWrote !== null && self::same($current, $aiWrote);
        if ($byAi && $basis === 'spelled_or_corrected') {
            return ['correct', $candidate];
        }

        return ['conflict', $candidate];
    }

    private static function placeholder(string $name): bool
    {
        return $name === ''
            || preg_match('/^(unknown|customer|caller|n\/?a|none|[\d\s()+.-]+)$/i', $name) === 1;
    }

    private static function same(string $a, string $b): bool
    {
        return mb_strtolower($a) === mb_strtolower($b);
    }
}
