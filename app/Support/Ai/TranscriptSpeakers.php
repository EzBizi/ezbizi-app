<?php

namespace App\Support\Ai;

use RuntimeException;

/**
 * A call transcript divided by who is speaking (Jose, 2026-10-12: "have grok
 * divide them properly as in IVR Menu: / Dispatcher: / Customer: ... so it's
 * easier to read them"). Whisper gives one run of words; Grok splits it.
 *
 * The words are Whisper's, not Grok's: the answer is refused when it lost a
 * large part of them, and the caller keeps showing the raw text.
 */
final class TranscriptSpeakers
{
    public const LABELS = ['IVR menu', 'Dispatcher', 'Customer'];

    /**
     * "IVR menu: …\n\nDispatcher: …\n\nCustomer: …", or null when the answer
     * cannot be trusted to be the same call.
     *
     * @throws RuntimeException when Grok fails, so the caller tries again later
     */
    public static function label(string $raw): ?string
    {
        return self::read($raw)['labelled'];
    }

    /**
     * The speakers plus what the call was about, in the one Grok call (Jose,
     * 2026-10-12: "summary should show first with the option to see the full
     * transcript"; the signals feed the follow-up queue and complaint alert).
     *
     * @return array{labelled: ?string, summary: ?string, next_step: ?string,
     *               signals: array{complaint: bool, asked_price: bool, asked_availability: bool, booked: bool}}
     *
     * @throws RuntimeException when Grok fails, so the caller tries again later
     */
    public static function read(string $raw): array
    {
        $read = self::readMany([$raw])[0];

        if ($read instanceof RuntimeException) {
            throw $read;
        }

        return $read;
    }

    /**
     * read() for several calls side by side (GrokCli::askMany).
     *
     * @param  list<string>  $raws
     * @return list<array|RuntimeException> in order; a failure stays with its call
     */
    public static function readMany(array $raws): array
    {
        $answers = GrokCli::askMany(array_map(fn ($raw) => self::PROMPT.$raw, $raws), self::SCHEMA);

        return array_map(fn ($json, $raw) => $json instanceof RuntimeException ? $json : [
            'labelled' => self::assemble((array) ($json['turns'] ?? []), $raw),
            'summary' => self::line($json['summary'] ?? null),
            'next_step' => self::line($json['next_step'] ?? null),
            'signals' => [
                'complaint' => ($json['complaint'] ?? false) === true,
                'asked_price' => ($json['asked_price'] ?? false) === true,
                'asked_availability' => ($json['asked_availability'] ?? false) === true,
                'booked' => ($json['booked'] ?? false) === true,
            ],
        ], $answers, $raws);
    }

    private const PROMPT = "Below is a machine transcript of one phone call to a home-services business (chimneys, "
        ."fireplaces, garage doors). It starts with the business's recorded phone menu, which may "
        ."repeat.\n\n"
        ."1. Split it into turns by who is speaking:\n"
        ."- \"IVR menu\": the recorded menu or any recorded message. Give it ONCE, as one turn, even if it repeated.\n"
        ."- \"Dispatcher\": the business's person who answers.\n"
        ."- \"Customer\": the caller.\n"
        ."Copy the words exactly as written. Do not summarise, correct or add anything.\n"
        ."2. summary: one plain sentence a dispatcher can read in two seconds: what the caller wanted "
        ."and what was agreed (service, day, price if said). Empty if nobody spoke.\n"
        ."3. next_step: what the business should do next, in a few words (\"Call back with a quote\"), "
        ."or empty if nothing is left to do.\n"
        ."4. complaint: the caller is unhappy with work done, a no-show, a charge or how they were treated.\n"
        ."5. asked_price / asked_availability: the caller asked what it costs / when someone can come.\n"
        ."6. booked: an appointment was agreed on this call.\n\n";

    private const SCHEMA = [
        'type' => 'object',
        'properties' => [
            'turns' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'speaker' => ['type' => 'string', 'enum' => self::LABELS],
                        'text' => ['type' => 'string'],
                    ],
                    'required' => ['speaker', 'text'],
                    'additionalProperties' => false,
                ],
            ],
            'summary' => ['type' => 'string'],
            'next_step' => ['type' => 'string'],
            'complaint' => ['type' => 'boolean'],
            'asked_price' => ['type' => 'boolean'],
            'asked_availability' => ['type' => 'boolean'],
            'booked' => ['type' => 'boolean'],
        ],
        'required' => ['turns', 'summary', 'next_step', 'complaint', 'asked_price', 'asked_availability', 'booked'],
        'additionalProperties' => false,
    ];

    private static function line(mixed $text): ?string
    {
        $text = trim((string) $text);

        return $text === '' ? null : mb_substr($text, 0, 500);
    }

    /** @param  array<int, mixed>  $turns */
    public static function assemble(array $turns, string $raw): ?string
    {
        $blocks = [];
        $spoken = 0;
        $menuSeen = false;

        foreach ($turns as $turn) {
            $who = is_array($turn) ? ($turn['speaker'] ?? null) : null;
            $text = trim((string) (is_array($turn) ? ($turn['text'] ?? '') : ''));
            if (! in_array($who, self::LABELS, true) || $text === '') {
                continue;
            }
            if ($who === 'IVR menu') {
                // The menu once: a loop of it is the same words again.
                if ($menuSeen) {
                    continue;
                }
                $menuSeen = true;
            } else {
                $spoken += mb_strlen($text);
            }
            // One speaker twice in a row is one turn.
            if ($blocks !== [] && $blocks[array_key_last($blocks)][0] === $who) {
                $blocks[array_key_last($blocks)][1] .= ' '.$text;
            } else {
                $blocks[] = [$who, $text];
            }
        }

        // ponytail: a length check, not a word diff. People's words under a
        // quarter of the raw call means Grok summarised or lost them; the
        // menu loop can be most of a call, so the bar is low.
        // Only the menu and a hang-up (4 of 6 on prod, Leads, 2026-10-12): the
        // menu is the whole call.
        if ($spoken === 0 && $menuSeen) {
            return implode("\n\n", array_map(fn ($b) => $b[0].': '.$b[1], $blocks));
        }

        if ($spoken === 0 || $spoken < mb_strlen($raw) / 4) {
            return null;
        }

        return implode("\n\n", array_map(fn ($b) => $b[0].': '.$b[1], $blocks));
    }
}
