<?php

namespace App\Support\Jobs;

use App\Models\Job;

/**
 * A Workiz job's money, read live from its mirror row (spec 2026-09-29 §2).
 *
 * Workiz has no invoice endpoint: its invoice IS the job's money, refreshed into
 * `workiz_jobs.payload` every minute by the Workiz sync. Reading it here keeps
 * one copy (Jose, decision 2), until EzBizi holds the money natively: from
 * 2026-10-27 EzBizi is the master and Workiz a mirror. Nothing is written.
 *
 * `Cost` on a line is left out: it is the company's own cost, and nobody has
 * asked to show it.
 */
final class WorkizMoney
{
    /** @return array{source: string, currency: string, subtotal: int, total: int, due: int, paid: int, credit: int, due_on: ?string, state: string, lines: list<array>}|null */
    public static function for(Job $job): ?array
    {
        $payload = $job->workizJob?->payload;
        if (! is_array($payload)) {
            return null;
        }

        $total = self::minor($payload['JobTotalPrice'] ?? 0);
        $due = self::minor($payload['JobAmountDue'] ?? 0);
        $dueOn = substr((string) ($payload['PaymentDueDate'] ?? ''), 0, 10);

        return [
            'source' => 'workiz',
            'currency' => $job->currency,
            'subtotal' => self::minor($payload['SubTotal'] ?? 0),
            'total' => $total,
            'due' => max($due, 0),
            'paid' => max($total - max($due, 0), 0),
            'credit' => $due < 0 ? -$due : 0,
            'due_on' => $dueOn === '' ? null : $dueOn,
            'state' => self::state($total, $due),
            'lines' => array_map(static fn (array $l): array => [
                'name' => (string) ($l['Name'] ?? ''),
                'description' => ($l['Description'] ?? '') === '' ? null : (string) $l['Description'],
                'quantity' => (float) ($l['Quantity'] ?? 0),
                'unit_price' => self::minor($l['Price'] ?? 0),
                'taxable' => (bool) ($l['Taxable'] ?? false),
            ], array_values(array_filter($payload['LineItems'] ?? [], 'is_array'))),
        ];
    }

    /** Measured 2026-09-29: 102 jobs owe a negative amount, which is a credit. */
    public static function state(int $total, int $due): string
    {
        return match (true) {
            $due < 0 => 'credit',
            $total <= 0 => 'none',
            $due === 0 => 'paid',
            $due < $total => 'partial',
            default => 'unpaid',
        };
    }

    /** Rounded, never cast: (int) (1.15 * 100) is 114. */
    private static function minor(mixed $dollars): int
    {
        return is_numeric($dollars) ? (int) round((float) $dollars * 100) : 0;
    }
}
