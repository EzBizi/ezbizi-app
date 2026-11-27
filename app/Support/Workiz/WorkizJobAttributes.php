<?php

namespace App\Support\Workiz;

/**
 * One Workiz job payload, reduced to one landing row's attributes.
 *
 * Pure: no I/O, no models, no clock. Everything that comes from the connection
 * rather than from Workiz — organization, franchise, connection, synced_at —
 * is the caller's, so this can be tested against a payload alone and the
 * placement rule stays in one place.
 *
 * ── WHAT THE PUBLISHED SPEC WOULD HAVE GOT WRONG ────────────────
 *
 * Measured over 400 real payloads stored by A1 Tools, 2026-09-23. Each of
 * these is a defect a plausible importer written from
 * `developer.workiz.com/api.json` would have shipped:
 *
 *   - NOTHING IS EVER NULL. Absent is `''`, so `?? null` never fires and the
 *     table fills with empty strings no `whereNull` will ever find.
 *   - Keys change type between rows: Tags is string|array, Unit/PhoneExt/
 *     SecondPhone/JobName string|integer, the money fields integer|double.
 *   - Dates are 'Y-m-d H:i:s' with NO zone. The doc shows ISO-8601 with a Z.
 *     Reading them as UTC moves every A-1 appointment by four or five hours.
 *   - The key set is OPEN: the account's own custom fields arrive at the top
 *     level, undocumented, on most rows. Hence `payload` kept whole.
 *
 * The doc also lists two fields that are absent from every real row
 * (`Timezone`, `ReferralCompany`) and omits four that are present
 * (`JobName`, `LineItems`, `LocationId`, `LocationKey`). It is wrong in both
 * directions, which is why this class was written against captured responses.
 */
final class WorkizJobAttributes
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function fromPayload(array $payload): array
    {
        return [
            'remote_id' => self::text($payload['UUID'] ?? null) ?? '',
            'serial_id' => self::int($payload['SerialId'] ?? null),
            'client_id' => self::int($payload['ClientId'] ?? null),

            // Trimmed, because four live values carry a trailing space and the
            // zip map groups on this column.
            'service_area' => self::text($payload['ServiceArea'] ?? null),

            'postal_code' => self::text($payload['PostalCode'] ?? null),
            'city' => self::text($payload['City'] ?? null),
            'state' => self::text($payload['State'] ?? null),
            'country' => self::text($payload['Country'] ?? null),
            'address' => self::text($payload['Address'] ?? null),
            'unit' => self::text($payload['Unit'] ?? null),
            'latitude' => self::float($payload['Latitude'] ?? null),
            'longitude' => self::float($payload['Longitude'] ?? null),

            'status' => self::text($payload['Status'] ?? null),
            'sub_status' => self::text($payload['SubStatus'] ?? null),
            'job_type' => self::text($payload['JobType'] ?? null),
            'job_source' => self::text($payload['JobSource'] ?? null),

            'booked_at' => WorkizValue::moment($payload['CreatedDate'] ?? null),
            'scheduled_for' => WorkizValue::moment($payload['JobDateTime'] ?? null),
            'scheduled_end' => WorkizValue::moment($payload['JobEndDateTime'] ?? null),
            'last_status_update' => WorkizValue::moment($payload['LastStatusUpdate'] ?? null),

            'created_by' => self::text($payload['CreatedBy'] ?? null),

            // Whole and unedited, including the untrimmed ServiceArea and every
            // custom field nobody flattened.
            'payload' => $payload,
        ];
    }

    /**
     * Workiz's absent value is the empty string, not null — so this is where
     * the table's NULLs actually come from.
     *
     * An array is null rather than "Array": `Tags` and `Team` are arrays on
     * some rows, and a flat column that stringified one would store a PHP
     * notice as data.
     */
    private static function text(mixed $value): ?string
    {
        if ($value === null || is_array($value)) {
            return null;
        }

        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    private static function int(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private static function float(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }
}
