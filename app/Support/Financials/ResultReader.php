<?php

namespace App\Support\Financials;

use App\Support\Ai\GrokCli;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Reads a photo of a lead source's results (ACE's report) into rows of date,
 * area, calls, jobs, cost, sales and profit (Grok, vision). Nothing is saved
 * here; the rows go back to a person.
 */
final class ResultReader
{
    private const SCHEMA = [
        'type' => 'object',
        'properties' => [
            'readable' => ['type' => 'boolean'],
            'source' => ['type' => ['string', 'null']],
            'rows' => ['type' => 'array', 'items' => [
                'type' => 'object',
                'properties' => [
                    'date' => ['type' => ['string', 'null']],
                    'area' => ['type' => ['string', 'null']],
                    'calls' => ['type' => 'number'],
                    'jobs' => ['type' => 'number'],
                    'cost' => ['type' => 'number'],
                    'sales' => ['type' => 'number'],
                    'profit' => ['type' => ['number', 'null']],
                ],
                'required' => ['calls', 'jobs', 'cost', 'sales'],
            ]],
        ],
        'required' => ['readable', 'rows'],
    ];

    /** @return array{source: ?string, rows: list<array{date: ?string, area: ?string, calls:int, jobs:int, cost_cents:int, sales_cents:int, profit_cents:int}>} */
    public static function read(string $bytes, string $extension): array
    {
        if (! GrokCli::configured()) {
            throw ValidationException::withMessages(['image' => 'Reading photos needs the AI connection, which is not set up on this server.']);
        }

        try {
            $answer = GrokCli::ask(
                'The file results.'.$extension.' is a photo of a lead source\'s results report: for each date and area, the calls, jobs, cost, sales and profit. '
                .'Read every row exactly as printed, money in dollars. Give dates as YYYY-MM-DD. Leave profit null when it is not printed. '
                .'Give the lead source\'s name if it is printed. If the photo is not such a report or cannot be read, set readable to false.',
                self::SCHEMA,
                120,
                ['results.'.$extension => $bytes],
            );
        } catch (RuntimeException) {
            throw ValidationException::withMessages(['image' => 'The photo could not be read just now. Try again, or type the figures in.']);
        }

        if (($answer['readable'] ?? false) !== true || ($answer['rows'] ?? []) === []) {
            throw ValidationException::withMessages(['image' => 'That photo does not look like a results report. Try a clearer photo, or type the figures in.']);
        }

        return ['source' => self::text($answer['source'] ?? null), 'rows' => array_map(self::row(...), array_values($answer['rows']))];
    }

    /** One row as Grok gave it, in cents. Profit printed on the report wins; otherwise sales less cost. */
    public static function row(array $r): array
    {
        $cents = fn ($v) => (int) round(((float) $v) * 100);

        return [
            'date' => is_string($r['date'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $r['date']) ? $r['date'] : null,
            'area' => self::text($r['area'] ?? null),
            'calls' => max(0, (int) $r['calls']),
            'jobs' => max(0, (int) $r['jobs']),
            'cost_cents' => $cents($r['cost']),
            'sales_cents' => $cents($r['sales']),
            'profit_cents' => isset($r['profit']) ? $cents($r['profit']) : $cents($r['sales']) - $cents($r['cost']),
        ];
    }

    private static function text(mixed $v): ?string
    {
        return is_string($v) && trim($v) !== '' ? trim($v) : null;
    }
}
