<?php

namespace App\Support\Social;

use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * A social sign-in that will not be finished, and the sentence saying why.
 *
 * The callback is a browser tab, so every sentence names the network, says
 * whether anything was stored, and ends with what to do.
 */
final class SocialOAuthRefused extends RuntimeException
{
    private function __construct(string $message, public readonly int $status)
    {
        parent::__construct($message);
    }

    public static function notConfigured(string $network, string $why): self
    {
        return new self(
            "EzBizi cannot start a {$network} sign-in on this installation. {$why}"
            .' This is at EzBizi\'s end — nothing is wrong with the account, and signing in '
            .'again would not help.',
            Response::HTTP_NOT_IMPLEMENTED,
        );
    }

    public static function noOpenSignIn(string $network): self
    {
        return new self(
            "This {$network} sign-in link is no longer valid. Sign-ins can only be finished "
            .'once and they stop working after ten minutes, so this usually means the page '
            .'was opened twice or left sitting for a while. Nothing has been connected and '
            .'nothing has been changed. Go back to Social Media Management and start '
            .'connecting the account again.',
            Response::HTTP_BAD_REQUEST,
        );
    }

    public static function declined(string $network): self
    {
        return new self(
            "The sign-in was not completed at {$network}, so nothing has been connected. That "
            .'is usually because access was not allowed, or the window was closed part-way '
            .'through. Go back to Social Media Management and try connecting the account '
            .'again.',
            Response::HTTP_BAD_REQUEST,
        );
    }

    public static function exchangeFailed(string $network): self
    {
        return new self(
            "{$network} would not finish the sign-in, so nothing has been connected. Nothing "
            .'is wrong with the account and there is nothing to undo. Wait a moment and '
            .'connect it again; if it keeps happening, whoever looks after this EzBizi '
            .'installation needs to check its '.$network.' app.',
            Response::HTTP_BAD_GATEWAY,
        );
    }

    public static function noLastingPermission(string $network): self
    {
        return new self(
            "{$network} allowed the sign-in but did not give EzBizi lasting permission, so "
            .'nothing has been connected — a connection that expired within the hour would '
            .'be worse than none. Connect the account again and leave the permissions ticked.',
            Response::HTTP_BAD_GATEWAY,
        );
    }

    public static function unidentified(string $network, ?string $why = null): self
    {
        return new self(
            $why ?? (
                "{$network} allowed the sign-in but EzBizi could not tell which account that "
                .'was, so nothing has been connected. Wait a moment and connect it again.'
            ),
            Response::HTTP_BAD_GATEWAY,
        );
    }

    public static function noLongerPermitted(string $why): self
    {
        return new self(
            'The sign-in could not be finished, so nothing has been connected. '.$why,
            Response::HTTP_CONFLICT,
        );
    }

    /** A WhatsApp number another organization already routes - never re-pointed by a connect. */
    public static function numberTaken(): self
    {
        return new self(
            'This WhatsApp number is already connected to another EzBizi account.',
            Response::HTTP_CONFLICT,
        );
    }
}
