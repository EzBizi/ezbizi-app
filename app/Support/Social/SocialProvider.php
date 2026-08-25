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
}
