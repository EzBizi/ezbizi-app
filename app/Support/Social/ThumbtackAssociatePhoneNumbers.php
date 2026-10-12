<?php

namespace App\Support\Social;

use App\Models\SocialConnection;
use RuntimeException;

/**
 * The phone numbers Thumbtack should recognise as belonging to this business.
 *
 * ── What these actually are, and why Thumbtack insists ───────────
 *
 * An "associate" is a person at the business — the office manager, the sweep in
 * the van. Thumbtack matches inbound and outbound calls against this list, so a
 * technician ringing a homeowner from their own handset is shown as the
 * business rather than as an unknown number, and the call is attributed to the
 * lead it belongs to.
 *
 * Thumbtack made this a CONDITION of the integration (2026-09-03: "Please
 * include the business phone number API in your implementation. This is a
 * critical component for ensuring that lead communications and caller ID
 * features function correctly"). It is not an optional extra endpoint.
 *
 * ── Why reconcile() rather than "push on save" ───────────────────
 *
 * Thumbtack holds its own copy of this list, and there is no endpoint that
 * replaces it wholesale. Pushing each change as it happens means the two lists
 * agree only if every single write succeeded, forever — one dropped request and
 * they silently diverge, with no way to notice except a pro asking why their
 * number shows as unknown.
 *
 * reconcile() reads what Thumbtack has, compares, and sends only the
 * difference. It is safe to run repeatedly and it repairs drift instead of
 * accumulating it, which is what makes a nightly sweep sufficient.
 *
 * ── E.164, strictly ──────────────────────────────────────────────
 *
 * Their pattern is ^\+[1]\d{1,14}$ — a plus, a US country code, digits. EzBizi
 * stores `users.phone` as whatever somebody typed. A number this class cannot
 * confidently turn into that pattern is SKIPPED and reported, not sent:
 * Thumbtack rejects the whole request on a malformed one, so one badly typed
 * number would otherwise stop every good number behind it.
 */
final class ThumbtackAssociatePhoneNumbers
{
    private const PATH = '/associate-phone-numbers';

    /** Their documented ceiling for the bulk endpoint. */
    private const BULK_MAX = 100;

    /**
     * Make Thumbtack's list match `$associates`.
     *
     * @param  array<string, string>  $associates  E.164 (or normalisable) number => person's name
     * @return array{created: int, updated: int, deleted: int, skipped: array<string>}
     */
    public function reconcile(SocialConnection $connection, array $associates): array
    {
        $wanted  = [];
        $skipped = [];

        foreach ($associates as $number => $name) {
            $e164 = self::e164((string) $number);

            $e164 === null
                ? $skipped[] = (string) $number
                : $wanted[$e164] = trim((string) $name);
        }

        $existing = [];

        foreach ($this->list($connection) as $row) {
            // isDeleted rows come back from their read endpoint. Treating one as
            // present would mean never re-adding a number somebody removed and
            // then rehired.
            if (($row['isDeleted'] ?? false) === true) {
                continue;
            }

            $existing[(string) $row['phoneNumber']] = $row;
        }

        $created = $updated = $deleted = 0;

        // New numbers, in one call where there is more than one of them.
        $new = array_diff_key($wanted, $existing);

        if ($new !== []) {
            $created = $this->createMany($connection, $new, $skipped);
        }

        // Same number, different person.
        foreach (array_intersect_key($wanted, $existing) as $number => $name) {
            if (trim((string) ($existing[$number]['name'] ?? '')) === $name) {
                continue;
            }

            $this->update($connection, (string) $existing[$number]['phoneNumberID'], $number, $name);
            $updated++;
        }

        // Gone from EzBizi, so gone from Thumbtack — otherwise a departed
        // employee's mobile keeps identifying as the business indefinitely.
        foreach (array_diff_key($existing, $wanted) as $row) {
            $this->delete($connection, (string) $row['phoneNumberID']);
            $deleted++;
        }

        return ['created' => $created, 'updated' => $updated, 'deleted' => $deleted, 'skipped' => $skipped];
    }

    /** @return array<int, array<string, mixed>> */
    public function list(SocialConnection $connection): array
    {
        $client = new ThumbtackClient($connection);
        $body = $client->get($client->businessPath(self::PATH));

        return array_values(array_filter(
            (array) ($body['phoneNumbers'] ?? []),
            static fn ($row) => is_array($row) && isset($row['phoneNumber'], $row['phoneNumberID']),
        ));
    }

    /** @return array<string, mixed> */
    public function create(SocialConnection $connection, string $number, string $name = ''): array
    {
        $e164 = self::e164($number) ?? throw new RuntimeException($number.' is not a phone number Thumbtack will take.');

        $client = new ThumbtackClient($connection);

        return $client->post($client->businessPath(self::PATH), array_filter([
            'phoneNumber' => $e164,
            'name'        => $name !== '' ? $name : null,
        ], static fn ($v) => $v !== null));
    }

    /**
     * @param  array<string, string>  $numbers  E.164 => name
     * @param  array<int, string>  $rejected  numbers Thumbtack called invalid, appended
     * @return int  how many were created
     */
    public function createMany(SocialConnection $connection, array $numbers, array &$rejected = []): int
    {
        if (count($numbers) === 1) {
            $number = array_key_first($numbers);

            try {
                $this->create($connection, (string) $number, $numbers[$number]);
            } catch (RuntimeException $e) {
                // Once the batch has gone up, the number Thumbtack calls invalid
                // is the only new one left, and it comes here every night.
                if (! str_contains($e->getMessage(), 'invalid phone number: '.$number)) {
                    throw $e;
                }
                $rejected[] = (string) $number;

                return 0;
            }

            return 1;
        }

        $client = new ThumbtackClient($connection);
        $created = 0;

        // Their limit is 100 per call, and exceeding it is a validation error
        // that names the array rather than the count.
        foreach (array_chunk($numbers, self::BULK_MAX, preserve_keys: true) as $chunk) {
            $payload = [];

            foreach ($chunk as $number => $name) {
                $payload[] = array_filter([
                    'phoneNumber' => (string) $number,
                    'name'        => $name !== '' ? $name : null,
                ], static fn ($v) => $v !== null);
            }

            // One number Thumbtack calls invalid (a new area code, 2026-10-09:
            // +1227...) refuses the whole batch, every night. Drop it, name it,
            // send the rest.
            while (true) {
                try {
                    $body = $client->post($client->businessPath(self::PATH.'-bulk-create'), ['phoneNumbers' => $payload]);
                    break;
                } catch (RuntimeException $e) {
                    if (preg_match('/invalid phone number: (\+\d+)/', $e->getMessage(), $m) !== 1) {
                        throw $e;
                    }
                    $left = array_values(array_filter($payload, static fn ($row) => $row['phoneNumber'] !== $m[1]));
                    if (count($left) === count($payload)) {
                        throw $e;
                    }
                    $rejected[] = $m[1];
                    $payload = $left;
                    if ($payload === []) {
                        continue 2;
                    }
                }
            }

            $created += count((array) ($body['phoneNumbers'] ?? []));
        }

        return $created;
    }

    /** @return array<string, mixed> */
    public function update(SocialConnection $connection, string $phoneNumberId, string $number, string $name = ''): array
    {
        $e164 = self::e164($number) ?? throw new RuntimeException($number.' is not a phone number Thumbtack will take.');

        $client = new ThumbtackClient($connection);

        return $client->put($client->businessPath(self::PATH.'/'.$phoneNumberId), array_filter([
            'phoneNumber' => $e164,
            'name'        => $name !== '' ? $name : null,
        ], static fn ($v) => $v !== null));
    }

    public function delete(SocialConnection $connection, string $phoneNumberId): void
    {
        $client = new ThumbtackClient($connection);
        $client->delete($client->businessPath(self::PATH.'/'.$phoneNumberId));
    }

    /**
     * A US number in Thumbtack's pattern, or null when it cannot be sure.
     *
     * Null rather than a best guess. A wrong number here is worse than a
     * missing one: it hands somebody else's handset the business's caller ID.
     */
    public static function e164(string $raw): ?string
    {
        $digits = preg_replace('/\D+/', '', $raw) ?? '';

        // 10 digits is a US number without its country code, which is how
        // almost everybody types one.
        if (strlen($digits) === 10) {
            $digits = '1'.$digits;
        }

        if (strlen($digits) !== 11 || ! str_starts_with($digits, '1')) {
            return null;
        }

        // A US area code and exchange never begin with 0 or 1. This is what
        // separates a real number from an extension or a typo'd date.
        if (in_array($digits[1], ['0', '1'], true) || in_array($digits[4], ['0', '1'], true)) {
            return null;
        }

        return '+'.$digits;
    }
}
