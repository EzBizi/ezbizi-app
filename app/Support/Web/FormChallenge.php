<?php

namespace App\Support\Web;

use Illuminate\Support\Facades\Cache;

/**
 * The website form's CAPTCHA: a proof of work, not a picture puzzle.
 *
 * Toula asked for a real CAPTCHA in place of A1's "I'm not a robot" tick, which
 * a script ticks as easily as a person. The visitor's browser is handed a hash
 * and must find the number that produced it — a second of work for one
 * enquiry, and the whole cost of a spam run for a bot sending thousands. It is
 * the scheme ALTCHA uses, kept here so no Google or Cloudflare account, cookie
 * or third-party script sits on somebody's contact page.
 *
 * Signed, so a challenge cannot be invented; stamped, so an old one expires;
 * and spent on use, so one solved answer does not send a thousand forms.
 */
final class FormChallenge
{
    /** The search space: ~50,000 hashes on average, about a second on a phone. */
    public const MAX = 100000;

    private const TTL = 600;

    /** @return array{algorithm: string, salt: string, challenge: string, max: int, signature: string} */
    public static function issue(): array
    {
        $salt = bin2hex(random_bytes(12)).'?expires='.(time() + self::TTL);
        $challenge = hash('sha256', $salt.random_int(0, self::MAX));

        return ['algorithm' => 'SHA-256', 'salt' => $salt, 'challenge' => $challenge,
            'max' => self::MAX, 'signature' => self::sign($challenge)];
    }

    /** The solved challenge a form sent: `{salt, number, challenge, signature}` as JSON. */
    public static function passes(?string $solution): bool
    {
        $s = json_decode((string) $solution, true);

        if (! is_array($s) || ! isset($s['salt'], $s['number'], $s['challenge'], $s['signature'])) {
            return false;
        }

        [$salt, $number, $challenge] = [(string) $s['salt'], (string) $s['number'], (string) $s['challenge']];

        return hash_equals(self::sign($challenge), (string) $s['signature'])
            && preg_match('/\?expires=(\d+)$/', $salt, $m) === 1 && (int) $m[1] >= time()
            && ctype_digit($number) && hash_equals($challenge, hash('sha256', $salt.$number))
            // Spent: the first form through with it wins, the rest are refused.
            && Cache::add('form-challenge:'.$challenge, true, self::TTL);
    }

    private static function sign(string $challenge): string
    {
        return hash_hmac('sha256', $challenge, (string) config('app.key'));
    }
}
