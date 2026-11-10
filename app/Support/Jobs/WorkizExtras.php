<?php

namespace App\Support\Jobs;

use App\Models\Job;
use App\Models\WorkizRecord;
use App\Models\WorkizTeamMember;
use Illuminate\Support\Collection;

/**
 * What Workiz v2 knows about a job beyond its money summary (Jose,
 * 2026-10-06: "take advantage of everything v2 has to offer"). Read-only,
 * from the mirror: Workiz's invoices are read here until EzBizi holds the
 * money natively (decision 2; EzBizi is the master from 2026-10-27).
 *
 * For anybody who can open the job: its custom fields, in Workiz's own names,
 * and its files (attachments and inspection-report PDFs), https links only.
 * With Jobs Financials as well: invoices, payments, estimates, timesheets and
 * the "Pricing" field, which carries prices ("$299 Chimney Sweep").
 *
 * No free text: Workiz's notes are not mirrored, and an estimate's
 * declineReason stays in the payload.
 */
final class WorkizExtras
{
    /** Custom fields that are money: Financials only. */
    private const PRICED_FIELDS = ['Pricing'];

    /** Custom fields that hold files: drawn with the attachments. */
    private const FILE_FIELDS = ['Inspection Report'];

    public static function for(Job $job, bool $money): ?array
    {
        $payload = $job->workizJob?->payload;
        $records = WorkizRecord::query()->where('job_id', $job->getKey())->get();

        if (! is_array($payload) && $records->isEmpty()) {
            return null;
        }

        [$fields, $files] = self::fieldsAndFiles(is_array($payload) ? $payload : [], $money);
        $out = ['fields' => $fields, 'files' => $files];

        if (! $money) {
            return $out;
        }

        $who = self::names($records);
        $of = fn (string $type) => $records->where('type', $type);

        return $out + [
            'currency' => $job->currency ?: 'USD',
            'invoices' => $of('invoice')->sortByDesc('starts_at')->map(fn (WorkizRecord $r) => [
                'number' => self::text($r->payload['serialId'] ?? null),
                'total_minor' => self::minor($r->amount),
                'due_minor' => self::minor($r->amount_due),
                'tax_minor' => self::minor($r->payload['taxAmount'] ?? 0),
                'tip_minor' => self::minor($r->payload['tipAmount'] ?? 0),
                'issued_on' => $r->starts_at?->toDateString(),
                'due_on' => self::day($r->payload['amountDueDate'] ?? null),
            ])->values()->all(),
            'payments' => $of('payment')->sortByDesc('starts_at')->map(fn (WorkizRecord $r) => [
                'paid_on' => $r->starts_at?->toDateString(),
                'amount_minor' => self::minor($r->amount),
                'kind' => self::text($r->payload['type'] ?? null),
                'status' => $r->status,
                'collected_by' => $who[$r->franchise_id.'|'.$r->user_remote_id] ?? null,
            ])->values()->all(),
            'estimates' => $of('estimate')->sortByDesc('starts_at')->map(fn (WorkizRecord $r) => [
                'number' => self::text($r->payload['serialId'] ?? null),
                'total_minor' => self::minor($r->amount),
                // Workiz's own word: Won, Declined, Pending, Archived, Unsent, Approved.
                'status' => $r->status,
                'dated_on' => $r->starts_at?->toDateString(),
                // Workiz's client-portal links, where the client approves and pays (Jose, 2026-10-21).
                'portal_url' => $r->links['portal'] ?? null,
                'estimate_link' => $r->links['estimate'] ?? null,
            ])->values()->all(),
            'timesheets' => $of('timesheet')->sortBy('starts_at')->map(fn (WorkizRecord $r) => [
                'who' => $who[$r->franchise_id.'|'.$r->user_remote_id] ?? null,
                'starts_at' => $r->starts_at?->toIso8601String(),
                'ends_at' => $r->ends_at?->toIso8601String(),
                'labor_cost_minor' => self::minor($r->amount),
            ])->values()->all(),
        ];
    }

    /**
     * The job's Workiz files exactly as the panel's "From Workiz" lists them:
     * attachments and file-type custom fields (an inspection-report PDF).
     * Project Management counts these, so the two numbers agree (Jose,
     * 2026-11-10).
     *
     * @return list<array{name: string, url: string}>
     */
    public static function files(array $payload): array
    {
        return self::fieldsAndFiles($payload, false)[1];
    }

    /** @return array{0: list<array{name: string, value: string}>, 1: list<array{name: string, url: string}>} */
    private static function fieldsAndFiles(array $payload, bool $money): array
    {
        $files = [];
        foreach ((array) ($payload['Attachments'] ?? []) as $a) {
            $files[] = is_array($a) ? self::file($a['Name'] ?? null, $a['Url'] ?? null) : null;
        }

        $fields = [];
        foreach ((array) ($payload['CustomFields'] ?? []) as $field) {
            $name = is_array($field) ? ($field['Name'] ?? null) : null;
            $value = is_array($field) ? ($field['Value'] ?? null) : null;

            if (! is_string($name)) {
                continue;
            }

            if (in_array($name, self::FILE_FIELDS, true)) {
                foreach ((array) $value as $f) {
                    $files[] = is_array($f) ? self::file($f['name'] ?? null, $f['url'] ?? null) : null;
                }

                continue;
            }

            if (! is_scalar($value) || trim((string) $value) === '' || (! $money && in_array($name, self::PRICED_FIELDS, true))) {
                continue;
            }

            $fields[] = ['name' => $name, 'value' => trim((string) $value)];
        }

        return [$fields, array_values(array_filter($files))];
    }

    /** A link only when it is https: the payload is Workiz's, and a javascript: url would be ours to have shown. */
    private static function file(mixed $name, mixed $url): ?array
    {
        if (! is_string($url) || ! str_starts_with($url, 'https://')) {
            return null;
        }

        return ['name' => is_string($name) && $name !== '' ? $name : basename((string) parse_url($url, PHP_URL_PATH)), 'url' => $url];
    }

    /** @return array<string, string> "franchise|USR-…" => the Workiz team member's name */
    private static function names(Collection $records): array
    {
        $remote = $records->pluck('user_remote_id')->filter()->unique();

        return $remote->isEmpty() ? [] : WorkizTeamMember::query()
            ->whereIn('franchise_id', $records->pluck('franchise_id')->unique())
            ->whereIn('remote_id', $remote)
            ->get(['franchise_id', 'remote_id', 'name'])
            ->mapWithKeys(fn (WorkizTeamMember $m) => [$m->franchise_id.'|'.$m->remote_id => $m->name])
            ->all();
    }

    private static function minor(mixed $dollars): int
    {
        return (int) round(((float) $dollars) * 100);
    }

    private static function text(mixed $value): ?string
    {
        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }

    private static function day(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}/', $value) === 1 ? substr($value, 0, 10) : null;
    }
}
