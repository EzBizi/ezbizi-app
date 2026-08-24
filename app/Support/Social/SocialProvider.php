<?php

namespace App\Support\Social;

/**
 * Networks a tenant can connect a page or profile on.
 *
 * Not a table. The names are ours, not the tenant's, so a new network is a
 * code change rather than a row somebody typed. Pinterest is the first one
 * this installation can actually finish: Trial access is approved. The rest
 * stay in the catalogue and say why Connect will not work yet.
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
        };
    }

    public function connectsBy(): string
    {
        return $this === self::Bluesky ? 'password' : 'sign_in';
    }

    public function startPath(): string
    {
        return $this === self::Bluesky
            ? '/social/bluesky/connect'
            : '/social/'.$this->routeKey().'/start';
    }

    /** @return self[] */
    public static function all(): array
    {
        return self::cases();
    }
}
