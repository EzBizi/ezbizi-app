<?php

namespace App\Support\Social;

/**
 * Third-party accounts a tenant connects, and the OAuth shape they share.
 *
 * Not a table. The names are ours, not the tenant's, so a new network is a
 * code change rather than a row somebody typed. Pinterest is the first one
 * this installation can actually finish: Trial access is approved. The rest
 * stay in the catalogue and say why Connect will not work yet.
 *
 * ── Two kinds live here, and only one is a "network" ──────────
 *
 * The first nine are places a tenant PUBLISHES — Marketing lists them and
 * calls the act "connect a page or profile". The last two are places EzBizi
 * READS from: a Business Profile the company already owns, and the Google Ads
 * account behind its Local Services enquiries. Nobody posts as their Google
 * Ads account, so `all()` — the Marketing catalogue — deliberately excludes
 * them and `dataSources()` names them instead.
 *
 * They share this enum because the connection is structurally the same thing:
 * one tenant-scoped, encrypted OAuth grant to an account somebody else owns,
 * with the same consent window, the same state row, the same masking and the
 * same revocation. Giving the read side its own parallel copy of that would
 * mean a second implementation of the security-critical half, and the two
 * would drift. The cost is that `social_connections` now holds rows nobody
 * would call social; if that becomes confusing before it becomes useful,
 * split the STORAGE and keep the drivers.
 */
enum SocialProvider: string
{
    case FacebookPage = 'facebook_page';
    case InstagramProfessional = 'instagram_professional';
    case Threads = 'threads';
    case LinkedIn = 'linkedin';
    case TikTok = 'tiktok';
    case Pinterest = 'pinterest';
    case YouTube = 'youtube';
    case X = 'x';
    case Bluesky = 'bluesky';

    case GoogleBusinessProfile = 'google_business_profile';
    case GoogleAds = 'google_ads';

    public function label(): string
    {
        return match ($this) {
            self::FacebookPage => 'Facebook',
            self::InstagramProfessional => 'Instagram',
            self::Threads => 'Threads',
            self::LinkedIn => 'LinkedIn',
            self::TikTok => 'TikTok',
            self::Pinterest => 'Pinterest',
            self::YouTube => 'YouTube',
            self::X => 'X',
            self::Bluesky => 'Bluesky',
            self::GoogleBusinessProfile => 'Google Business Profile',
            self::GoogleAds => 'Google Ads',
        };
    }

    /** Path segment in /social/{key}/start — the reserved callback name. */
    public function routeKey(): string
    {
        return match ($this) {
            self::FacebookPage => 'facebook',
            self::InstagramProfessional => 'instagram',
            self::Threads => 'threads',
            self::LinkedIn => 'linkedin',
            self::TikTok => 'tiktok',
            self::Pinterest => 'pinterest',
            self::YouTube => 'youtube',
            self::X => 'x',
            self::Bluesky => 'bluesky',
            self::GoogleBusinessProfile => 'business-profile',
            self::GoogleAds => 'google-ads',
        };
    }

    public function connectsBy(): string
    {
        return $this === self::Bluesky ? 'password' : 'sign_in';
    }

    /**
     * Is this a place the tenant publishes to, rather than one EzBizi reads?
     *
     * The question the Marketing catalogue asks. Written as "not a data
     * source" rather than a list of nine, so a network added later is in the
     * catalogue by default — the failure of forgetting is then a network
     * missing from a screen somebody is looking at, not a silent omission.
     */
    public function isPublishingNetwork(): bool
    {
        return ! in_array($this, self::dataSources(), true);
    }

    /**
     * Accounts EzBizi reads from on the tenant's behalf (EZ Leads).
     *
     * @return self[]
     */
    public static function dataSources(): array
    {
        return [self::GoogleBusinessProfile, self::GoogleAds];
    }

    /**
     * Which permission lets somebody connect this account.
     *
     * Not one answer for all eleven, and the reason is what the connection is
     * FOR. Connecting a Facebook page is a Marketing act — it is a place the
     * tenant will publish. Connecting a Google Ads account is not: nobody
     * posts as it, and the only screen that reads it is Leads. Gating it on
     * `marketing.edit` would mean somebody holding every `leads.*` permission
     * could not connect the account the module runs on, while somebody with
     * Marketing and no access to Leads at all could.
     *
     * The rule is that the permission follows the module that USES the
     * connection, because that is who can tell whether it is the right account.
     */
    public function connectPermission(): string
    {
        return match ($this) {
            self::GoogleAds => 'leads.edit',
            self::GoogleBusinessProfile => 'reviews.edit',
            default => 'marketing.edit',
        };
    }

    /**
     * The route constraint for `/social/{network}/…`, built from the enum.
     *
     * It used to be eight names typed into `routes/api.php` three times over.
     * Adding a provider then meant remembering all three, and forgetting was
     * silent in the worst way: the driver loads, `notReadyBecause()` answers
     * READY, the config resolves — and every request 404s, because the route
     * that would reach it does not exist. That is exactly what happened to
     * Google Ads and Business Profile.
     *
     * Derived here so the list cannot drift from the providers again. Bluesky
     * is absent because it connects with a handle and an app password rather
     * than a consent window, and has its own route.
     */
    public static function oauthRoutePattern(): string
    {
        $keys = array_map(
            fn (self $p) => preg_quote($p->routeKey(), '/'),
            array_filter(self::cases(), fn (self $p) => $p !== self::Bluesky),
        );

        return implode('|', $keys);
    }

    /** What the refusal calls the act, when somebody may not do it. */
    public function connectAct(): string
    {
        return match ($this) {
            self::GoogleAds => 'connect a Google Ads account',
            self::GoogleBusinessProfile => 'connect a Business Profile',
            default => 'connect a social account',
        };
    }

    public function startPath(): string
    {
        return $this === self::Bluesky
            ? '/social/bluesky/connect'
            : '/social/'.$this->routeKey().'/start';
    }

    /**
     * The Marketing catalogue: places a tenant publishes.
     *
     * Not `cases()`. A Google Ads account is connectable and is NOT something
     * anybody posts as, so listing it under "connect a page or profile" would
     * offer a Connect button for an act that does not exist.
     *
     * @return self[]
     */
    public static function all(): array
    {
        return array_values(array_filter(
            self::cases(),
            fn (self $p) => $p->isPublishingNetwork(),
        ));
    }

    /**
     * The kinds of post this network will take at all.
     *
     * The network's own rule, not what EzBizi has built yet — those are two
     * different facts and conflating them would have the composer say "Pinterest
     * cannot take a video", which is false. A publisher that has not implemented
     * a kind refuses it in its own words; this says what would be possible.
     *
     * @return SocialMediaKind[]
     */
    public function accepts(): array
    {
        return match ($this) {
            // Words, a picture or a video — the permissive ones.
            self::FacebookPage, self::Threads, self::LinkedIn, self::X => [
                SocialMediaKind::Text, SocialMediaKind::Image, SocialMediaKind::Video,
            ],

            // No video. Bluesky posts pictures and words.
            self::Bluesky => [SocialMediaKind::Text, SocialMediaKind::Image],

            // Media is the post. A caption with nothing attached is not a thing
            // these will accept, so Text is absent rather than optional.
            self::InstagramProfessional, self::Pinterest, self::TikTok => [
                SocialMediaKind::Image, SocialMediaKind::Video,
            ],

            // Video, and only video.
            self::YouTube => [SocialMediaKind::Video],
        };
    }

    public function takes(SocialMediaKind $kind): bool
    {
        return in_array($kind, $this->accepts(), strict: true);
    }

    /** True when words alone are not a post here. */
    public function requiresMedia(): bool
    {
        return ! $this->takes(SocialMediaKind::Text);
    }

    /**
     * Why this network will not take that kind, in a sentence.
     *
     * Written for the person composing, not for a log: it names the network and
     * what would fix it, because the fix is usually "attach a video" rather than
     * anything technical.
     */
    public function refuses(SocialMediaKind $kind): ?string
    {
        if ($this->takes($kind)) {
            return null;
        }

        return match (true) {
            $kind === SocialMediaKind::Text => sprintf(
                '%s does not post words on their own. Attach %s.',
                $this->label(),
                $this->takes(SocialMediaKind::Image) ? 'a picture or a video' : 'a video',
            ),
            $kind === SocialMediaKind::Video => sprintf(
                '%s does not take video.',
                $this->label(),
            ),
            default => sprintf(
                '%s takes video, not pictures.',
                $this->label(),
            ),
        };
    }
}
