<?php

namespace App\Support\Auth;

use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Making a session list recognisable (plan §4).
 *
 * Five rows all reading "web" tell nobody which one to sign out, which leaves
 * "sign out everywhere else" as the only usable control — a blunt instrument
 * for a question that is usually about one specific machine.
 *
 * ── Why the parsing is deliberately shallow ──────────────────────
 *
 * User-agent strings are a swamp: every browser lies about being every other
 * browser for historical reasons, and a full parser is a dependency that needs
 * updating forever. This looks for a handful of markers and gives up honestly
 * when it does not recognise one, because "Unknown device" beside a real IP and
 * a real timestamp is still enough to identify a session — and a confidently
 * wrong "iPhone" is worse than no answer at all.
 */
class SessionContext
{
    /** Record where a token was minted, for the list that shows it later. */
    public static function stamp(PersonalAccessToken $token, Request $request): void
    {
        $token->forceFill([
            'user_agent' => substr((string) $request->userAgent(), 0, 400),
            'ip_address' => $request->ip(),
        ])->save();
    }

    /**
     * @return array{platform: string, browser: string, kind: string, label: string}
     */
    public static function describe(?string $userAgent): array
    {
        $ua = (string) $userAgent;

        $platform = match (true) {
            str_contains($ua, 'iPhone')       => 'iPhone',
            str_contains($ua, 'iPad')         => 'iPad',
            str_contains($ua, 'Android')      => 'Android',
            // Checked BEFORE Macintosh: an iPhone's user agent contains "like
            // Mac OS X", so the obvious order labels every phone a Mac.
            str_contains($ua, 'Windows')      => 'Windows',
            str_contains($ua, 'Macintosh'),
            str_contains($ua, 'Mac OS X')     => 'macOS',
            str_contains($ua, 'CrOS')         => 'ChromeOS',
            str_contains($ua, 'Linux')        => 'Linux',
            default                           => 'Unknown',
        };

        $browser = match (true) {
            // Order matters throughout. Edge contains "Chrome", Chrome contains
            // "Safari", and Safari contains neither — so the most specific
            // marker has to be tested first or everything is Chrome.
            str_contains($ua, 'Edg/')                            => 'Edge',
            str_contains($ua, 'OPR/'), str_contains($ua, 'Opera') => 'Opera',
            str_contains($ua, 'Firefox')                         => 'Firefox',
            str_contains($ua, 'Chrome')                          => 'Chrome',
            str_contains($ua, 'Safari')                          => 'Safari',
            default                                              => '',
        };

        $kind = match ($platform) {
            'iPhone', 'iPad', 'Android' => 'mobile',
            'macOS'                     => 'apple',
            'Windows'                   => 'windows',
            'Linux', 'ChromeOS'         => 'desktop',
            default                     => 'unknown',
        };

        $label = match (true) {
            $platform === 'Unknown' && $browser === '' => 'Unknown device',
            $browser === ''                           => $platform,
            default                                   => "{$browser} on {$platform}",
        };

        return [
            'platform' => $platform,
            'browser'  => $browser,
            'kind'     => $kind,
            'label'    => $label,
        ];
    }
}
