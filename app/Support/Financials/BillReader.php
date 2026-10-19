<?php

namespace App\Support\Financials;

use App\Support\Ai\GrokCli;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Reads a photo of a month's bills into line items and the total printed on
 * it (Grok, vision). Nothing is saved here: the draft goes back to a person,
 * and saving refuses lines that do not add up to the total to the cent.
 */
final class BillReader
{
    private const SCHEMA = [
        'type' => 'object',
        'properties' => [
            'readable' => ['type' => 'boolean'],
            'lines' => ['type' => 'array', 'items' => [
                'type' => 'object',
                'properties' => ['name' => ['type' => 'string'], 'amount' => ['type' => 'number']],
                'required' => ['name', 'amount'],
            ]],
            'total' => ['type' => ['number', 'null']],
            'period_from' => ['type' => ['string', 'null']],
            'period_to' => ['type' => ['string', 'null']],
        ],
        'required' => ['readable', 'lines', 'total'],
    ];

    /** @return array{lines: list<array{name:string, cents:int}>, total_cents: ?int, adds_up: bool, period_from: ?string, period_to: ?string} */
    public static function read(string $bytes, string $extension): array
    {
        if (! GrokCli::configured()) {
            throw ValidationException::withMessages(['image' => 'Reading photos needs the AI connection, which is not set up on this server.']);
        }

        try {
            $answer = GrokCli::ask(
                'The file bill.'.$extension.' is a photo of a business\'s bills for one period: a list of bill names with amounts and a total. '
                .'Read every line exactly as printed, amounts in dollars. Give the total printed on the photo, not one you add up. '
                .'If it states the period, give it as YYYY-MM-DD dates. If the photo is not a list of bills or cannot be read, set readable to false.',
                self::SCHEMA,
                120,
                ['bill.'.$extension => $bytes],
            );
        } catch (RuntimeException) {
            throw ValidationException::withMessages(['image' => 'The photo could not be read just now. Try again, or type the lines in.']);
        }

        if (($answer['readable'] ?? false) !== true) {
            throw ValidationException::withMessages(['image' => 'That photo does not look like a list of bills. Try a clearer photo, or type the lines in.']);
        }

        $lines = array_values(array_map(fn ($l) => ['name' => trim((string) $l['name']), 'cents' => (int) round(((float) $l['amount']) * 100)], $answer['lines'] ?? []));
        $total = isset($answer['total']) ? (int) round(((float) $answer['total']) * 100) : null;
        $date = fn ($v) => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : null;

        return [
            'lines' => $lines,
            'total_cents' => $total,
            'adds_up' => $total !== null && array_sum(array_column($lines, 'cents')) === $total,
            'period_from' => $date($answer['period_from'] ?? null),
            'period_to' => $date($answer['period_to'] ?? null),
        ];
    }

    /**
     * The rules a saved bill keeps, from A-1's photo flow: 1–100 lines with
     * distinct names, no negative amounts, a period of at most 62 days, and the
     * lines adding up to the total.
     *
     * @param  list<array{name:string, cents:int}>  $lines
     */
    public static function check(array $lines, int $totalCents, string $from, string $to): void
    {
        $names = array_map(fn ($l) => mb_strtolower(trim($l['name'])), $lines);
        $fail = fn (string $why) => throw ValidationException::withMessages(['lines' => $why]);

        if ($lines === [] || count($lines) > 100) {
            $fail('A bill has between 1 and 100 lines.');
        }
        if (count(array_unique($names)) !== count($names) || in_array('', $names, true)) {
            $fail('Every line needs its own name.');
        }
        if (min(array_column($lines, 'cents')) < 0) {
            $fail('No line can be negative.');
        }
        if (array_sum(array_column($lines, 'cents')) !== $totalCents) {
            $fail('The lines do not add up to the total on the photo.');
        }
        if (now()->parse($from)->diffInDays(now()->parse($to)) > 62) {
            $fail('A bill covers at most two months.');
        }
    }
}
