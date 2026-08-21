<?php

namespace App\Support\Social;

/**
 * One Meta app for Facebook Pages, Instagram professional, and Threads.
 *
 * Three redirect URIs, one client id/secret. Ads and Messenger are not
 * products this app asks for.
 */
final class MetaApp
{
    public static function id(): string
    {
        return (string) config('services.meta.client_id');
    }

    public static function secret(): string
    {
        return (string) config('services.meta.client_secret');
    }

    public static function notReadyBecause(): ?string
    {
        if (RedirectUri::credentialsPresent(config('services.meta.client_id'), config('services.meta.client_secret'))) {
            return null;
        }

        return 'EzBizi has no Meta app set up on this installation yet, so Facebook, Instagram '
            .'and Threads cannot be connected. Nothing is wrong with the page — the missing '
            .'piece is at EzBizi\'s end.';
    }
}
