<?php

namespace App\Support\Workiz;

use App\Models\SocialConnection;
use App\Support\Reports\Experimental\WorkizJobs;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Workiz's v2 API (developer.workiz.com/v2), for a branch connected with a key
 * from the Developer page's v2 tab. Jose, 2026-10-05: every branch moves to
 * its v2 key.
 *
 * ── TRANSLATED AT THE BOUNDARY, NOT FORKED ──────────────────────
 *
 * The mirror, the projection, the backfill, the writer, the board and the
 * money panel all read v1 payloads, measured against 7,739 captured jobs. A
 * v2 job is put into that shape here, once, so every one of them works the
 * same for a v2 branch. The v2 ids a write needs (JOB-, CL-, JT-, AG-, USR-)
 * ride along under `v2`.
 *
 * ── MEASURED 2026-10-05 on Massachusetts' key ────────────────────
 *
 *  - Base https://api.workiz.com/crm/api/v2, `Authorization: Bearer <key>`. A
 *    v2 key gets 403 from v1 ("Invalid API path or malformed API key").
 *  - Lists page from 1, `pageSize` up to 300 (500 answered 300), and answer
 *    {pageSize, page, totalResults, hasMore, data}. With no date filter a list
 *    covers jobs dated from 30 days back.
 *  - One job is read by its `JOB-` id; the six-character `uuid` answers 404.
 *  - `uuid`, `serialId` and the client's `serialId` are what v1 calls UUID,
 *    SerialId and ClientId. Times are the account's own wall clock,
 *    'Y-m-d H:i:s', as in v1; `utcUpdatedAt` is UTC.
 *  - Python's default user agent is refused with an HTML 403; Laravel's is not.
 */
final class WorkizV2
{
    public const BASE = 'https://api.workiz.com/crm/api/v2';

    public static function uses(SocialConnection $connection): bool
    {
        return ($connection->credentials['api_version'] ?? 'v1') === 'v2';
    }

    public static function http(string $key, int $timeout = 30): PendingRequest
    {
        return Http::acceptJson()->withToken($key)->timeout($timeout);
    }

    /**
     * A v2 job (or lead: same envelope) in v1's shape.
     *
     * @param  array<string, mixed>  $job
     * @return array<string, mixed>
     */
    public static function job(array $job): array
    {
        $client = self::map($job['clientInfo'] ?? null);
        $address = self::map($client['addressDetails'] ?? null);

        $payload = [
            'UUID' => $job['uuid'] ?? null,
            'SerialId' => $job['serialId'] ?? null,
            'ClientId' => $client['serialId'] ?? null,
            'JobName' => $job['name'] ?? null,
            'Status' => $job['status'] ?? null,
            'SubStatus' => self::name($job['subStatus'] ?? null),
            'JobDateTime' => $job['date'] ?? null,
            'JobEndDateTime' => $job['endDate'] ?? null,
            'CreatedDate' => $job['createdAt'] ?? null,
            'LastStatusUpdate' => $job['statusUpdated'] ?? null,
            'JobType' => self::name($job['jobType'] ?? null),
            // v2 calls the job source an ad group.
            'JobSource' => self::name($job['adGroup'] ?? null),
            'ServiceArea' => self::name($client['serviceArea'] ?? null),
            'JobNotes' => $job['description'] ?? null,
            // v1 says 0 where v2 says null (17 of New York's 244 jobs,
            // measured 2026-10-05); null would read as a price change.
            'SubTotal' => $job['subTotal'] ?? 0,
            'JobTotalPrice' => $job['totalPrice'] ?? 0,
            'JobAmountDue' => $job['amountDue'] ?? 0,
            'PaymentDueDate' => $job['amountDueDate'] ?? null,
            'FirstName' => $client['firstName'] ?? null,
            'LastName' => $client['lastName'] ?? null,
            'Company' => $client['companyName'] ?? null,
            'Email' => $client['email'] ?? null,
            'Phone' => $client['primaryPhone'] ?? null,
            'PhoneExt' => $client['primaryExt'] ?? null,
            'SecondPhone' => $client['secondaryPhone'] ?? null,
            'SecondPhoneExt' => $client['secondaryExt'] ?? null,
            'Address' => $address['address'] ?? null,
            'Unit' => $address['unit'] ?? null,
            'City' => $address['city'] ?? null,
            'State' => $address['state'] ?? null,
            'PostalCode' => $address['zipCode'] ?? null,
            'Country' => $address['country'] ?? null,
            'Latitude' => $address['latitude'] ?? null,
            'Longitude' => $address['longitude'] ?? null,
            'Team' => array_values(array_map(
                fn (array $tech) => ['id' => $tech['id'] ?? null, 'Name' => $tech['name'] ?? null],
                array_filter($job['team'] ?? [], 'is_array'),
            )),
            'Tags' => array_values(array_filter(array_map(fn ($tag) => self::name($tag), $job['tags'] ?? []))),
            'LineItems' => array_values(array_map(fn (array $line) => [
                'Name' => $line['name'] ?? null,
                'Description' => $line['description'] ?? null,
                'Quantity' => $line['quantity'] ?? null,
                'Price' => $line['price'] ?? null,
                'Taxable' => $line['taxable'] ?? null,
            ], array_filter($job['lineItems'] ?? [], 'is_array'))),
            // v2 only (2026-10-06): the branch's own job fields -- System Type,
            // Time Frames, Pricing -- with their values.
            'CustomFields' => array_values(array_map(fn (array $field) => [
                'Name' => trim((string) ($field['fieldName'] ?? '')),
                'Value' => $field['value'] ?? null,
            ], array_filter($job['customFields'] ?? [], 'is_array'))),
            // v2 only: the notes staff leave on the job, which the list
            // carries as well as a single read (Connecticut, 2026-10-07).
            'Notes' => array_values(array_map(fn (array $note) => [
                'id' => $note['id'] ?? null,
                'Note' => $note['note'] ?? null,
                'TechName' => $note['techName'] ?? null,
                'Timestamp' => $note['timestamp'] ?? null,
            ], array_filter($job['notes'] ?? [], 'is_array'))),
            'v2' => array_filter([
                'id' => $job['id'] ?? null,
                'client_id' => $client['clientId'] ?? null,
                'job_type_id' => self::map($job['jobType'] ?? null)['id'] ?? null,
                'ad_group_id' => self::map($job['adGroup'] ?? null)['id'] ?? null,
                'sub_status_id' => self::map($job['subStatus'] ?? null)['id'] ?? null,
                'utc_updated_at' => $job['utcUpdatedAt'] ?? null,
            ], fn ($value) => $value !== null),
        ];

        // The job's files, public links on Workiz's st.sendajob.com. Only a
        // single read carries them, never the list (measured 2026-10-06), so a
        // list read leaves the key out rather than saying there are none, and
        // the row keeps what it had (WorkizJob::keepWhatV2CannotSay).
        if (array_key_exists('attachments', $job)) {
            $payload['Attachments'] = array_values(array_map(fn (array $file) => [
                'id' => $file['id'] ?? null,
                'Name' => $file['name'] ?? null,
                'Url' => $file['fileUrl'] ?? null,
            ], array_filter((array) $job['attachments'], 'is_array')));
            // Which version of the job its files were read at (ReadWorkizJobFiles).
            $payload['AttachmentsReadAt'] = $job['utcUpdatedAt'] ?? null;
        }

        return $payload;
    }

    /**
     * A v2 lead in v1's shape: a job's envelope, plus the two names
     * WorkizLeadAttributes reads for the appointment and the notes.
     *
     * @param  array<string, mixed>  $lead
     * @return array<string, mixed>
     */
    public static function lead(array $lead): array
    {
        return self::job($lead) + ['LeadDateTime' => $lead['date'] ?? null, 'LeadNotes' => $lead['description'] ?? null];
    }

    /**
     * One v2 request, answered the way WorkizJobs::send() answers v1: Workiz's
     * data when it accepts, its own sentence when it refuses, UNANSWERED when
     * it does not answer. A 404 is `missing` rather than a refusal, so a reader
     * can tell "no such job" from "could not ask".
     *
     * @param  array<string, mixed>  $data
     * @return array{data: mixed, refused: ?string, missing: bool, status: int}
     */
    public static function send(SocialConnection $connection, string $method, string $path, array $data = [], int $timeout = 30): array
    {
        $key = (string) ($connection->credentials['api_token'] ?? '');

        if ($key === '') {
            return ['data' => null, 'refused' => 'This branch has no stored Workiz key.', 'missing' => false, 'status' => 0];
        }

        try {
            // GET sends $data as the query, the others as a JSON body.
            $response = self::http($key, $timeout)->{strtolower($method)}(self::BASE.$path, $data);
        } catch (ConnectionException) {
            return ['data' => null, 'refused' => WorkizJobs::UNANSWERED, 'missing' => false, 'status' => 0];
        }

        if ($response->successful()) {
            return ['data' => $response->json(), 'refused' => null, 'missing' => false, 'status' => $response->status()];
        }

        // v2 refuses as {"message", "error", "statusCode"}, sometimes with a
        // list of messages for a validation failure.
        $message = $response->json('message');
        $said = is_array($message) ? implode('; ', array_map('strval', $message)) : (string) ($message ?: 'Workiz refused it ('.$response->status().').');

        return ['data' => null, 'refused' => $said, 'missing' => $response->status() === 404, 'status' => $response->status()];
    }

    /**
     * A v2 user in v1's team shape (WorkizTeamAttributes reads it).
     *
     * @param  array<string, mixed>  $user
     * @return array<string, mixed>
     */
    public static function member(array $user): array
    {
        return [
            'id' => $user['id'] ?? null,
            'name' => $user['name'] ?? null,
            'email' => $user['emailAddress'] ?? null,
            'role' => $user['role'] ?? null,
            'fieldTech' => $user['fieldTech'] ?? null,
            'active' => $user['active'] ?? null,
            'serviceAreas' => array_values(array_filter(array_map(fn ($area) => self::name($area), $user['serviceAreas'] ?? []))),
            // Empty means every job type (v2's own definition).
            'skills' => array_values(array_filter(array_map(fn ($type) => self::name($type), $user['jobTypes'] ?? []))),
            'v2' => $user,
        ];
    }

    /** `{id, name}` objects (and plain strings) to their name. */
    private static function name(mixed $value): ?string
    {
        if (is_array($value)) {
            $value = $value['name'] ?? null;
        }

        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    /** @return array<string, mixed> */
    private static function map(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }
}
