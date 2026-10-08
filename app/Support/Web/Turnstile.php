<?php

namespace App\Support\Web;

use App\Models\WebSite;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Cloudflare Turnstile: the "verify you are human" box on a website's forms.
 *
 * On top of the proof of work (FormChallenge), not instead of it. Jose,
 * 2026-10-08: "an actual captcha", since the proof of work asks nothing of the
 * visitor and an AI driving a browser passes it like a person. Turnstile
 * watches the browser and, when unsure, makes the visitor tick.
 *
 * Asked of a site only once it runs a plugin that draws the box, so a site
 * still on an older plugin is not refused for a token it cannot send.
 */
final class Turnstile
{
    /** The plugin release that first draws the box. */
    public const PLUGIN = '1.25.0';

    /** @return array{site: string, secret: string}|null the widget covering this site's domain */
    public static function for(WebSite $site): ?array
    {
        $host = strtolower((string) $site->host);

        foreach ((array) config('ezbizi.turnstile', []) as $w) {
            if (($w['site'] ?? '') === '' || ($w['secret'] ?? '') === '') {
                continue;
            }

            foreach ($w['domains'] as $domain) {
                if ($host === $domain || str_ends_with($host, '.'.$domain)) {
                    return ['site' => $w['site'], 'secret' => $w['secret']];
                }
            }
        }

        return null;
    }

    public static function required(WebSite $site): bool
    {
        return self::for($site) !== null
            && version_compare((string) $site->plugin_version, self::PLUGIN, '>=');
    }

    /** Whether Cloudflare accepts the token the box handed the form. */
    public static function passes(WebSite $site, ?string $token): bool
    {
        $widget = self::for($site);

        if ($widget === null || $token === null || $token === '') {
            return false;
        }

        try {
            $answer = Http::asForm()->timeout(10)->post('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
                'secret' => $widget['secret'],
                'response' => $token,
            ]);
        } catch (ConnectionException $e) {
            // ponytail: Cloudflare unreachable lets the form through on the proof of work alone, rather than losing the enquiry.
            report($e);

            return true;
        }

        return $answer->json('success') === true;
    }
}
