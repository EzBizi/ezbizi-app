<?php

namespace App\Support\Email;

use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * A Workspace domain that will not be connected or listed, in a sentence (R8).
 *
 * Separate from {@see CannotReadMailbox}: that type is about an inbox that
 * already exists and cannot be opened. This is about the DOMAIN grant — nothing
 * has been attached yet, or the Directory cannot be read — and marking a
 * mailbox broken would be the wrong record.
 */
final class GoogleWorkspaceRefused extends RuntimeException
{
    private function __construct(string $message, public readonly int $status)
    {
        parent::__construct($message);
    }

    public static function notConfigured(string $why): self
    {
        return new self($why, Response::HTTP_NOT_IMPLEMENTED);
    }

    public static function notConnected(): self
    {
        return new self(
            'This organization has not connected its Google Workspace yet. A Workspace '
            .'administrator needs to sign in under Email → Settings first, then pick shared '
            .'addresses from the directory.',
            Response::HTTP_CONFLICT,
        );
    }

    public static function notOnDirectory(string $address): self
    {
        return new self(
            $address.' is not a user or alias on the connected Google Workspace, so EzBizi '
            .'will not open it this way. Pick an address from the directory, or connect it '
            .'with Google sign-in or IMAP instead.',
            Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }

    public static function impersonationFailed(string $address): self
    {
        return new self(
            'Google refused to let EzBizi open '.$address.' through the connected Workspace. '
            .'That usually means domain-wide delegation has not been allowed for this address, '
            .'or the address is not a user mailbox. Nothing about IMAP or a personal Google '
            .'sign-in has changed.',
            Response::HTTP_CONFLICT,
        );
    }
    public static function directoryFailed(): self
    {
        return new self(
            'EzBizi could not read the Google Workspace directory. That usually means domain-wide '
            .'delegation has not been allowed yet in Google Admin, or the administrator who '
            .'signed in cannot list users. Nothing has been changed. Check the client ID on '
            .'Email settings, allow the two Gmail scopes and the directory scope, then try again.',
            Response::HTTP_CONFLICT,
        );
    }

    public static function domainTaken(string $domain): self
    {
        return new self(
            'The Google Workspace '.$domain.' is already connected to another EzBizi organization, '
            .'so it cannot be connected here. If this is your domain, that other organization '
            .'has to disconnect it first.',
            Response::HTTP_CONFLICT,
        );
    }

    public static function notAWorkspace(): self
    {
        return new self(
            'That Google account is not part of a Google Workspace organization, so it cannot '
            .'connect a company domain. Sign in with a Workspace administrator account '
            .'(somebody@your-company.com), not a personal Gmail address.',
            Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }

    public static function wrongDomain(string $expected, string $got): self
    {
        return new self(
            'The Google account that signed in belongs to '.$got.', not '.$expected
            .'. Nothing has been connected. Sign in with an administrator on '.$expected.'.',
            Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }
}
