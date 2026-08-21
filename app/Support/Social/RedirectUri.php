<?php

namespace App\Support\Social;

/**
 * The redirect URI is config, never the request Host.
 *
 * Laravel's URL generator would take the origin from the current request,
 * which is how authorization codes leak. Every network's OAuth class asks
 * this instead of `route()`.
 */
final class RedirectUri
{
    /**
     * @throws SocialOAuthRefused
     */
    public static function fromConfig(string $configured, string $callbackPath, string $network): string
    {
        $configured = trim($configured);
        $parts      = $configured === '' ? false : parse_url($configured);

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            throw SocialOAuthRefused::notConfigured(
                $network,
                "The web address {$network} is meant to return people to has not been set up, so "
                .'there is nowhere for the sign-in to come back to.',
            );
        }

        $scheme = strtolower((string) $parts['scheme']);
        $host   = strtolower((string) $parts['host']);

        if ($scheme !== 'https' && ! in_array($host, ['localhost', '127.0.0.1', '[::1]'], true)) {
            throw SocialOAuthRefused::notConfigured(
                $network,
                "The web address {$network} is meant to return people to is not a secure (https) "
                ."address, and {$network} will not send anybody to an insecure one.",
            );
        }

        if (($parts['path'] ?? '') !== $callbackPath
            || isset($parts['query'])
            || isset($parts['fragment'])) {
            throw SocialOAuthRefused::notConfigured(
                $network,
                "The web address {$network} is meant to return people to does not point at this "
                .'EzBizi installation\'s sign-in page, so a sign-in would end up somewhere '
                .'else.',
            );
        }

        return $configured;
    }

    public static function credentialsPresent(mixed $id, mixed $secret): bool
    {
        return is_string($id) && $id !== '' && is_string($secret) && $secret !== '';
    }
}
