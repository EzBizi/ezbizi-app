<?php

namespace App\Support\Inspection;

use App\Models\Job;
use App\Models\Service;
use Illuminate\Validation\ValidationException;

/**
 * The work a finding recommends, and the quote it becomes.
 *
 * ── Where the old tool pushed an estimate, this writes job lines ──
 *
 * A1's inspection attaches priced items to a failed finding and creates a
 * Workiz estimate on submit. EzBizi already owns the catalogue (`services`) and
 * the job, and `job_lines` already snapshots a line's name and price for
 * exactly the reason a report freezes its answers: "a price raised in September
 * must not rewrite what a job in August came to". So there is no estimate model
 * here and no second place a price lives — the quote falls out of what exists.
 *
 * ── The answer is a SNAPSHOT, not a list of ids ──────────────────
 *
 * A `services` answer holds `{service_id, name, unit_price_minor, qty}` per
 * pick, resolved when the technician chooses. The report renders those words
 * and that number, so what the customer was shown on the day is what the report
 * says forever — even if the service is renamed, re-priced or deleted.
 *
 * The id is kept beside the snapshot so the job line can point back at the
 * catalogue row, which is what makes revenue reporting join up.
 */
final class RecommendedWork
{
    /**
     * Resolve a `services` answer against the catalogue, refusing anything
     * that is not this organization's.
     *
     * Refused rather than skipped: a pick silently dropped is a recommendation
     * the technician believes they made and the customer never sees, and a
     * service from another organization is somebody else's price on this
     * customer's quote.
     *
     * @param  mixed  $answer  the raw answer for a services field
     * @param  string  $at  the field path to hang an error on
     * @return list<array{service_id: string, name: string, unit_price_minor: int, qty: int}>
     *
     * @throws ValidationException
     */
    public static function resolve(mixed $answer, string $at): array
    {
        $rows = is_array($answer) ? array_values($answer) : [];

        if ($rows === []) {
            return [];
        }

        $ids = [];
        foreach ($rows as $row) {
            if (is_array($row) && is_string($row['service_id'] ?? null)) {
                $ids[] = $row['service_id'];
            }
        }

        // Tenant-scoped, so another organization's service is simply absent.
        $services = Service::query()->whereIn('id', array_unique($ids))->get()->keyBy('id');

        $out = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $id = is_string($row['service_id'] ?? null) ? $row['service_id'] : '';
            $service = $services->get($id);

            if ($service === null) {
                throw ValidationException::withMessages([
                    $at => 'That is not a service in this organization\'s catalogue.',
                ]);
            }

            $qty = (int) ($row['qty'] ?? 1);
            if ($qty < 1) {
                $qty = 1;
            }
            // The same ceiling job lines use. A typo of six digits in a quantity
            // box should not become a six-figure quote.
            if ($qty > 100000) {
                $qty = 100000;
            }

            $out[] = [
                'service_id' => $service->getKey(),
                'name' => $service->name,
                'unit_price_minor' => (int) ($service->price_minor ?? 0),
                'qty' => $qty,
            ];
        }

        return $out;
    }

    /**
     * Every services answer on this version, flattened.
     *
     * Nested fields included: the recommendation usually lives INSIDE the
     * failing option, which is the whole point of the pattern.
     *
     * @param  list<array<string, mixed>>  $screens
     * @param  array<string, mixed>  $answers
     * @return list<array{service_id: string, name: string, unit_price_minor: int, qty: int}>
     */
    public static function fromAnswers(array $screens, array $answers): array
    {
        $picks = [];

        foreach ($screens as $screen) {
            if (! is_array($screen)) {
                continue;
            }

            foreach ((is_array($screen['fields'] ?? null) ? $screen['fields'] : []) as $field) {
                if (! is_array($field)) {
                    continue;
                }

                foreach ([$field, ...OptionSet::revealedBy($field, $answers)] as $candidate) {
                    if (($candidate['type'] ?? '') !== FieldType::SERVICES) {
                        continue;
                    }

                    $key = (string) ($candidate['key'] ?? '');
                    if ($key === '' || ! isset($answers[$key]) || ! is_array($answers[$key])) {
                        continue;
                    }

                    foreach ($answers[$key] as $row) {
                        if (is_array($row) && ($row['service_id'] ?? null) !== null) {
                            $picks[] = $row;
                        }
                    }
                }
            }
        }

        return $picks;
    }

    /**
     * Write the recommendation onto the job as ordinary lines.
     *
     * APPENDED, never replacing. A second inspection on the same job adds to
     * the quote — two visits found two things — and clearing what somebody else
     * put there would be this method deciding the job is its own.
     *
     * @param  list<array{service_id: string, name: string, unit_price_minor: int, qty: int}>  $picks
     * @return int  how many lines were written
     */
    public static function quoteOnto(Job $job, array $picks): int
    {
        if ($picks === []) {
            return 0;
        }

        $position = (int) $job->lines()->max('position');

        foreach ($picks as $pick) {
            $unit = (int) ($pick['unit_price_minor'] ?? 0);
            $qty = (int) ($pick['qty'] ?? 1);

            $job->lines()->create([
                'organization_id' => $job->organization_id,
                'service_id' => $pick['service_id'],
                // The snapshot from the answer, NOT a fresh read of the
                // catalogue. The recommendation was made at the price of the
                // day and the line has to agree with the report.
                'name' => (string) ($pick['name'] ?? ''),
                'unit_price_minor' => $unit,
                'qty' => $qty,
                'amount_minor' => $unit * $qty,
                'position' => ++$position,
            ]);
        }

        $job->recomputeTotal();

        return count($picks);
    }
}
