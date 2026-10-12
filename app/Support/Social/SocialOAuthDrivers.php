<?php

namespace App\Support\Social;

final class SocialOAuthDrivers
{
    /** @var array<string, SocialOAuth> */
    private array $byRoute = [];

    /** @var array<string, SocialOAuth> */
    private array $byProvider = [];

    public function __construct(
        FacebookOAuth $facebook,
        InstagramOAuth $instagram,
        ThreadsOAuth $threads,
        LinkedInOAuth $linkedin,
        TikTokOAuth $tiktok,
        PinterestOAuth $pinterest,
        YouTubeOAuth $youtube,
        XOAuth $x,
        BusinessProfileOAuth $businessProfile,
        GoogleAdsOAuth $googleAds,
        ThumbtackOAuth $thumbtack,
        FacebookAdsOAuth $facebookAds,
    ) {
        foreach ([$facebook, $instagram, $threads, $linkedin, $tiktok, $pinterest, $youtube, $x,
            $businessProfile, $googleAds, $thumbtack, $facebookAds] as $driver) {
            $this->byRoute[$driver->routeKey()] = $driver;
            $this->byProvider[$driver->provider()->value] = $driver;
        }
    }

    public function for(SocialProvider $provider): ?SocialOAuth
    {
        return $this->byProvider[$provider->value] ?? null;
    }

    public function byRouteKey(string $key): ?SocialOAuth
    {
        return $this->byRoute[$key] ?? null;
    }

    /** @return SocialOAuth[] */
    public function all(): array
    {
        return array_values($this->byProvider);
    }
}
