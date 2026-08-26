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

    /**
     * Threads signs with its own pair.
     *
     * Meta issues a separate Threads app id and secret alongside the Facebook
     * ones. ThreadsOAuth used the Facebook pair, which Meta rejects - it just
     * had never been switched on, so nothing had failed yet.
     */
    public static function threadsId(): string
    {
        return (string) config('services.meta.threads_id');
    }

    public static function threadsSecret(): string
    {
        return (string) config('services.meta.threads_secret');
    }

    public static function threadsNotReadyBecause(): ?string
    {
        if (RedirectUri::credentialsPresent(config('services.meta.threads_id'), config('services.meta.threads_secret'))) {
            return null;
        }

        return 'EzBizi has no Threads app set up on this installation yet, so a Threads '
            .'profile cannot be connected. Threads uses its own keys, separate from '
            .'Facebook and Instagram - the missing piece is at EzBizi\'s end.';
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
