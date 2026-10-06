<?php

namespace App\Support\Workiz;

/**
 * The Workiz v2 record types EzBizi mirrors into `workiz_records`, and how
 * each one is read and filed. Measured against the v2 spec and live accounts
 * on 2026-10-06.
 */
final class WorkizRecords
{
    public const TYPES = ['invoice', 'payment', 'estimate', 'time_off', 'event', 'timesheet', 'custom_field', 'item'];

    /** v2 caps a page at 300. */
    public const PAGE = 300;

    /** Where a full read starts: the jobs mirror goes back as far. */
    public const SINCE = '2023-01-01';

    /**
     * Free text Workiz keeps on these records, which can say anything about
     * anybody (a time off's reason can be medical). Not copied: no reader
     * needs it, and a field not held is one nothing has to erase.
     */
    public const UNCOPIED = ['notes', 'description', 'details', 'reason', 'techName', 'imageUrl', 'confirmationCode'];

    public static function path(string $type): string
    {
        return match ($type) {
            'invoice' => '/invoices',
            'payment' => '/payments',
            'estimate' => '/estimates',
            'time_off' => '/timeoff',
            'event' => '/event',
            'timesheet' => '/timeSheet',
            'custom_field' => '/customfields',
            'item' => '/items',
        };
    }

    /**
     * The query for page `$page` (from 1) of the records changed since
     * `$since` (Y-m-d). Invoices, estimates and items filter on when they were
     * updated, payments on their date; time off is read from a month back,
     * timesheets newest first, and events and custom fields whole -- v2 offers
     * them nothing else.
     *
     * @return array<string, scalar>
     */
    public static function query(string $type, string $since, int $page): array
    {
        $paging = ['page' => $page, 'pageSize' => self::PAGE];

        return $paging + match ($type) {
            'invoice', 'estimate' => ['field' => 'created', 'order' => 'ASC', 'dateProperty' => 'updated', 'dateOperator' => 'next', 'date' => $since],
            'item' => ['dateProperty' => 'updated', 'dateOperator' => 'next', 'date' => $since],
            'payment' => ['field' => 'date', 'order' => 'ASC', 'dateProperty' => 'date', 'dateOperator' => 'next', 'date' => $since],
            'time_off' => ['baseDate' => now()->subMonth()->toDateString(), 'timeline' => 'next'],
            'timesheet' => ['field' => 'startDate', 'order' => 'DESC'],
            'event' => ['field' => 'start', 'order' => 'ASC'],
            'custom_field' => [],
        };
    }

    /**
     * Whether a type's list has no date filter, so every run reads it whole.
     * Timesheets come newest first and stop at the cursor instead.
     */
    public static function whole(string $type): bool
    {
        return in_array($type, ['time_off', 'event', 'custom_field'], true);
    }

    /**
     * The row for one record: the columns readers filter on, and the payload
     * less the free text.
     *
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>
     */
    public static function row(string $type, array $record): array
    {
        $at = fn (?string $key) => self::moment($key === null ? null : ($record[$key] ?? null));

        return [
            'type' => $type,
            'remote_id' => (string) ($record['id'] ?? ''),
            'job_remote_id' => self::text($record['jobId'] ?? null),
            'user_remote_id' => self::text($record['techId'] ?? $record['collectedById'] ?? null),
            'starts_at' => $at(match ($type) {
                'invoice' => 'created',
                'payment' => 'date',
                'estimate' => 'estimateDate',
                'time_off', 'timesheet' => 'startDate',
                'event' => 'start',
                default => null,
            }),
            'ends_at' => $at(match ($type) {
                'time_off', 'timesheet' => 'endDate',
                'event' => 'end',
                default => null,
            }),
            'amount' => self::number(match ($type) {
                'invoice' => $record['totalPrice'] ?? null,
                'payment' => $record['amount'] ?? null,
                'estimate' => $record['total'] ?? null,
                'timesheet' => $record['laborCost'] ?? null,
                'item' => $record['price'] ?? null,
                default => null,
            }),
            'amount_due' => $type === 'invoice' ? self::number($record['amountDue'] ?? null) : null,
            'status' => $type === 'event' && ($record['isDeleted'] ?? false) ? 'deleted' : self::text($record['status'] ?? null),
            'remote_updated_at' => $at(match ($type) {
                'invoice', 'estimate', 'item' => 'updated',
                'time_off', 'event' => 'updatedAt',
                default => null,
            }),
            'payload' => array_diff_key($record, array_flip(self::UNCOPIED)),
        ];
    }

    /** The cursor a type's next read starts from: the newest it holds, less two days of overlap. */
    public static function cursor(?string $newest): string
    {
        return $newest === null ? self::SINCE : now()->parse($newest)->subDays(2)->toDateString();
    }

    /** "2026-10-05 22:41:13" as Workiz writes it; anything else is no date. */
    private static function moment(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^[1-9]\d{3}-\d\d-\d\d[ T]\d\d:\d\d(:\d\d)?/', $value, $m)
            ? str_replace('T', ' ', $m[0]) : null;
    }

    private static function number(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    private static function text(mixed $value): ?string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? mb_substr((string) $value, 0, 64) : null;
    }
}
