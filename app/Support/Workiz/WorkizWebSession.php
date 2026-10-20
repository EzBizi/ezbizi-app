<?php

namespace App\Support\Workiz;

use App\Models\Franchise;
use App\Models\SocialConnection;
use App\Support\Social\SocialProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * A signed-in Workiz web session, kept on the box and used for the reports
 * Workiz's API does not carry (Jose, 2026-10-20: "a worker on the app box").
 *
 * Workiz's login sits behind a Cloudflare CAPTCHA, so EzBizi never signs in:
 * a person signs in in their own browser and sends the session here
 * (WorkizWebSessionController). Its cookies alone answer "Session Expired";
 * the calls also need Origin, Referer, X-Requested-With, User-Id and
 * Account-Id (A1 Tools' notes, 2026-08-03). The franchise a call reads is
 * chosen server-side by `sajFranchise`, which franchiseLogin moves, so every
 * franchise read runs under one lock per login.
 */
final class WorkizWebSession
{
    public const ORIGIN = 'https://app.workiz.com';

    public const COOKIES = ['sendajob_sessid', 'sendajob_user', 'sajFranchise'];

    public const EXPIRED = 'Workiz signed this session out. Sign in to Workiz and send the session to EzBizi again.';

    /**
     * Keep the cookies a person sent, after one call proves them. Answers the
     * refusal, or null.
     *
     * @param  array<string, string>  $cookies
     */
    public static function adopt(SocialConnection $connection, array $cookies): ?string
    {
        $cookies = array_intersect_key(array_map('strval', $cookies), array_flip(self::COOKIES));
        $user = preg_match('/^userid_(\d+)$/', (string) base64_decode(rawurldecode($cookies['sendajob_user'] ?? ''), true), $m) ? $m[1] : null;

        if (! isset($cookies['sendajob_sessid']) || $user === null) {
            return 'That is not a signed-in Workiz session. Sign in at app.workiz.com first, then send it.';
        }

        // A signed-out session is sent to /login; never followed.
        $home = Http::withoutRedirecting()->withHeaders(['Cookie' => self::cookieHeader($cookies)])->timeout(30)->get(self::ORIGIN.'/root/home');
        $account = preg_match('/accountId["\']?\s*[:=]\s*["\']?(\d+)/', $home->body(), $a) ? $a[1] : null;

        if (! $home->ok() || $account === null) {
            return self::EXPIRED;
        }

        $connection->connect(['cookies' => $cookies, 'user_id' => $user, 'account_id' => $account]
            + array_intersect_key((array) ($connection->credentials ?? []), ['accounts' => 1]));
        $connection->forceFill(['status' => 'connected', 'status_reason' => null])->save();

        return null;
    }

    /**
     * One internal call. JSON in and out; the cookie jar follows Set-Cookie,
     * so a franchise switch carries into the next call.
     *
     * @return array{data: mixed, refused: ?string}
     */
    public static function call(SocialConnection $connection, string $path, ?array $json = null, string $form = ''): array
    {
        $cred = (array) $connection->credentials;
        $request = Http::withoutRedirecting()->withHeaders([
            'Cookie' => self::cookieHeader((array) ($cred['cookies'] ?? [])),
            'Origin' => self::ORIGIN, 'Referer' => self::ORIGIN.'/root/home', 'X-Requested-With' => 'XMLHttpRequest',
            'User-Id' => (string) ($cred['user_id'] ?? ''), 'Account-Id' => (string) ($cred['account_id'] ?? ''), 'Accept' => 'application/json',
        ])->timeout(60);

        $response = match (true) {
            $json !== null => $request->post(self::ORIGIN.$path, $json),
            $form !== '' => $request->withBody($form, 'application/x-www-form-urlencoded; charset=UTF-8')->post(self::ORIGIN.$path),
            default => $request->get(self::ORIGIN.$path),
        };

        if (trim($response->body()) === 'Session Expired' || $response->status() === 401 || $response->redirect()) {
            $connection->forceFill(['status' => 'expired', 'status_reason' => self::EXPIRED])->save();

            return ['data' => null, 'refused' => self::EXPIRED];
        }

        // Not the URL in a message: only the status.
        if (! $response->successful()) {
            return ['data' => null, 'refused' => 'Workiz answered '.$response->status().'.'];
        }

        $jar = (array) ($cred['cookies'] ?? []);
        foreach ($response->cookies() as $cookie) {
            if (in_array($cookie->getName(), self::COOKIES, true)) {
                $jar[$cookie->getName()] = (string) $cookie->getValue();
            }
        }

        if ($jar !== ($cred['cookies'] ?? [])) {
            $connection->connect(['cookies' => $jar] + $cred);
            $connection->save();
        }

        return ['data' => $response->json() ?? $response->body(), 'refused' => null];
    }

    /**
     * The accounts this login reaches: 'main' plus each sub-account by its
     * Workiz id, with its name.
     *
     * @return array<string, string>
     */
    public static function accounts(SocialConnection $connection): array
    {
        $read = self::call($connection, '/ajaxc/franchise/index/', ['getRelated' => true]);
        $accounts = ['main' => 'Main account'];

        foreach ((array) ($read['data']['data'] ?? []) as $row) {
            if (is_array($row) && (string) ($row['invite_status'] ?? '') === '1' && (string) ($row['status'] ?? '') !== '0'
                && ! in_array(strtolower((string) ($row['account_status'] ?? '')), ['canceled', 'expired'], true)) {
                $accounts[(string) $row['fid']] = trim((string) ($row['account_custom_name'] ?? ''));
            }
        }

        return $accounts;
    }

    /**
     * Run $read inside one Workiz account, one account at a time per login,
     * and come back to the main account after.
     */
    public static function inAccount(SocialConnection $connection, string $account, callable $read): mixed
    {
        return Cache::lock('workiz-web:'.$connection->getKey(), 300)->block(120, function () use ($connection, $account, $read) {
            if ($account !== 'main') {
                $in = self::call($connection, '/ajaxc/franchise/franchiseLogin/', ['acid' => $account, 'loginToRelated' => false]);

                if ($in['refused'] !== null || ($in['data']['flag'] ?? null) !== true) {
                    return ['data' => null, 'refused' => $in['refused'] ?? 'Workiz would not open account '.$account.'.'];
                }
            }

            try {
                return $read();
            } finally {
                if ($account !== 'main') {
                    self::call($connection, '/ajaxc/franchise/goToMaster/');
                }
            }
        });
    }

    /**
     * Which EzBizi franchise each account's books belong to: the tenant's own
     * mapping on the connection, kept as data (`credentials.accounts`), so
     * nothing about one tenant's names lives in code.
     */
    public static function franchiseFor(SocialConnection $connection, string $account): ?Franchise
    {
        $id = ((array) ($connection->credentials['accounts'] ?? []))[$account] ?? null;

        return $id === null ? null : Franchise::query()->find($id);
    }

    /** @param  array<string, string>  $cookies */
    private static function cookieHeader(array $cookies): string
    {
        return implode('; ', array_map(fn ($k, $v) => $k.'='.$v, array_keys($cookies), $cookies));
    }

    public static function isConnection(SocialConnection $connection): bool
    {
        return $connection->provider === SocialProvider::WorkizWeb->value;
    }
}
