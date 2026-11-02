<?php

namespace App\Services\Reviews;

use App\Models\SocialConnection;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * What an eKomi account offers beyond its reviews (Jose, 2026-11-02: "a whole
 * screen for Ekomi"): the account snapshot, the review-request funnel, who
 * was asked, and asking one more. The same calls A1 Tools made, read from
 * eKomi with the shop id and password on the `ekomi` connection.
 */
class EkomiAccount
{
    private const SNAPSHOT = 'https://api.ekomi.de/v3/getSnapshot';

    private const SRR = 'https://srr.ekomi.com/api/v1/';

    private const ADD_RECIPIENT = 'https://srr.ekomi.com/add-recipient';

    /** eKomi's answer keys for a recipient it will never ask: duplicate, unsubscribed, blacklisted. */
    public const NEVER_ASKS = [9, 26, 27];

    /** @return array{account_name: string, review_count: int, average: float, active: bool, certificate_url: ?string} */
    public static function snapshot(SocialConnection $c): array
    {
        [$shop, $password] = self::credentials($c);
        $json = Http::timeout(20)->acceptJson()->get(self::SNAPSHOT, ['auth' => $shop.'|'.$password, 'version' => 'cms-v3', 'type' => 'json'])
            ->throw()->json();
        if (empty($json['done'])) {
            throw new RuntimeException('eKomi did not accept the shop id and password.');
        }
        $info = (array) ($json['info'] ?? []);

        return [
            'account_name' => (string) ($info['account_name'] ?? ''),
            'review_count' => (int) ($info['fb_count'] ?? 0),
            'average' => round((float) ($info['fb_avg'] ?? 0), 2),
            'active' => (bool) ($info['active'] ?? false),
            'certificate_url' => ($info['ekomi_certificate'] ?? '') ?: null,
        ];
    }

    /**
     * Requested, delivered, opened, clicked, reviewed and bounced between two days, with rates.
     *
     * @return array<string, mixed>
     */
    public static function funnel(SocialConnection $c, string $from, string $to): array
    {
        $n = array_fill_keys(['requested', 'delivered', 'opened', 'clicked', 'reviewed', 'bounced'], 0);
        // ponytail: walks every page (100 a page, 200 pages at most), as A1 Tools did; cache it if the screen gets slow.
        for ($page = 1; $page <= 200; $page++) {
            $json = self::srr($c)->get(self::SRR.'recipients/activity_stream',
                ['start_date' => $from, 'end_date' => $to, 'page' => $page, 'records_per_page' => 100])->throw()->json();
            foreach ((array) ($json['data'] ?? []) as $row) {
                $n['requested']++;
                foreach (['delivered', 'opened', 'clicked', 'reviewed'] as $step) {
                    $n[$step] += empty($row[$step.'_at']) ? 0 : 1;
                }
                $n['bounced'] += ($row['workflow_status'] ?? '') === 'interrupted' ? 1 : 0;
            }
            if (empty($json['meta']['_links']['next']['href'] ?? null)) {
                break;
            }
        }
        $rate = fn (int $a, int $b) => $b > 0 ? round($a * 100 / $b, 1) : 0.0;
        $base = $n['delivered'] ?: $n['requested'];

        return $n + ['rates' => [
            'delivered' => $rate($n['delivered'], $n['requested']), 'open' => $rate($n['opened'], $base),
            'click' => $rate($n['clicked'], $base), 'review' => $rate($n['reviewed'], $n['requested']),
            'bounce' => $rate($n['bounced'], $n['requested']),
        ]];
    }

    /**
     * Who was asked for a review, newest page of 100.
     *
     * @param  array<string, string>  $filters  start_date, end_date, workflow_status, recipient_type
     * @return list<array<string, mixed>>
     */
    public static function requests(SocialConnection $c, array $filters): array
    {
        $json = self::srr($c)->get(self::SRR.'recipients', ['detail' => 'true', 'records_per_page' => 100] + $filters)->throw()->json();

        return array_map(function ($r) {
            $d = (array) ($r['detail'] ?? []);

            return [
                'name' => trim(($d['first_name'] ?? '').' '.($d['last_name'] ?? '')) ?: null,
                'email' => ($d['email'] ?? '') ?: null,
                'phone' => ($d['telephone'] ?? '') ?: null,
                'order_id' => (string) ($r['order_id'] ?? ''),
                'channel' => ($r['recipient_type'] ?? '') ?: null,
                'status' => ($d['workflow_status'] ?? '') ?: null,
                'bounce_reason' => ($d['workflow_interrupted_reason'] ?? '') ?: null,
                'reviewed_at' => ($d['review_given_at'] ?? '') ?: null,
                'created_at' => ($r['created_at'] ?? '') ?: null,
            ];
        }, array_values((array) ($json['data'] ?? [])));
    }

    /**
     * Asks one customer for a review by email; the order id is the job, so eKomi never asks twice for it.
     *
     * @return array{ok: bool, never: bool, message: string}
     */
    public static function ask(SocialConnection $c, string $orderId, string $email, string $firstName, string $lastName): array
    {
        [$shop, $password] = self::credentials($c);
        $json = Http::asForm()->timeout(20)->post(self::ADD_RECIPIENT, [
            'recipient_type' => 'email', 'shop_id' => $shop, 'password' => $password, 'transaction_id' => $orderId,
            'email' => $email, 'first_name' => $firstName, 'last_name' => $lastName,
        ])->json();
        $key = isset($json['key']) ? (int) $json['key'] : null;
        $ok = strtolower((string) ($json['status'] ?? '')) === 'success' && ($key === 0 || $key === null);

        return ['ok' => $ok, 'never' => ! $ok && in_array($key, self::NEVER_ASKS, true),
            'message' => (string) ($json['message'] ?? ($ok ? 'Asked.' : 'eKomi refused the request.'))];
    }

    /** @return array{0: string, 1: string} */
    private static function credentials(SocialConnection $c): array
    {
        $creds = (array) ($c->credentials ?? []);
        $shop = (string) ($creds['shop_id'] ?? '');
        $password = (string) ($creds['password'] ?? '');
        if ($shop === '' || $password === '') {
            throw new RuntimeException('This eKomi connection has no shop id and password.');
        }

        return [$shop, $password];
    }

    private static function srr(SocialConnection $c): PendingRequest
    {
        [$shop, $password] = self::credentials($c);

        return Http::timeout(20)->acceptJson()->withHeaders(['shop-id' => $shop, 'interface-password' => $password]);
    }
}
