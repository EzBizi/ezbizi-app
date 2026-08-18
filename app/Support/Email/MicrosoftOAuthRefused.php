<?php

namespace App\Support\Email;

use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * A Microsoft sign-in that will not be finished, and the sentence saying why (R8).
 *
 * Same job as {@see GoogleOAuthRefused}: a failed sign-in has not connected
 * anything, so this never marks a mailbox broken.
 */
final class MicrosoftOAuthRefused extends RuntimeException
{
    private function __construct(string $message, public readonly int $status)
    {
        parent::__construct($message);
    }

    public static function notConfigured(string $why): self
    {
        return new self(
            'EzBizi cannot start a Microsoft 365 sign-in on this installation. '.$why
            .' This is at EzBizi\'s end — nothing is wrong with the address, and signing in '
            .'again would not help.',
            Response::HTTP_NOT_IMPLEMENTED,
        );
    }

    public static function noOpenSignIn(): self
    {
        return new self(
            'This Microsoft sign-in link is no longer valid. Sign-ins can only be finished once '
            .'and they stop working after ten minutes, so this usually means the page was '
            .'opened twice or left sitting for a while. Nothing has been connected and '
            .'nothing has been changed. Go back to Email settings and start connecting the '
            .'address again.',
            Response::HTTP_BAD_REQUEST,
        );
    }

    public static function declined(): self
    {
        return new self(
            'The sign-in was not completed at Microsoft, so nothing has been connected. That is '
            .'usually because the "Accept" button was not pressed, or the window was closed '
            .'part-way through. Go back to Email settings and try connecting the address '
            .'again.',
            Response::HTTP_BAD_REQUEST,
        );
    }

    public static function wrongAccount(string $expected, string $signedInAs): self
    {
        return new self(
            sprintf(
                'That sign-in was for %s, but this inbox is %s, so nothing has been connected '
                .'and the permission has already been handed back to Microsoft. Connecting them '
                .'would have let everybody with access to this shared inbox read the mail for '
                .'%s. If you have more than one Microsoft account, sign out of the others or use '
                .'a private window, then connect the address again and choose %s.',
                $signedInAs, $expected, $signedInAs, $expected,
            ),
            Response::HTTP_CONFLICT,
        );
    }

    public static function permissionNotGiven(string $address): self
    {
        return new self(
            sprintf(
                'The sign-in for %s did not include permission to read and reply to the mail, '
                .'so nothing has been connected — EzBizi would not have been able to show you '
                .'anything. That usually means one of the tick boxes on the Microsoft screen was '
                .'cleared. Connect the address again and leave all of them ticked.',
                $address,
            ),
            Response::HTTP_CONFLICT,
        );
    }

    public static function exchangeFailed(): self
    {
        return new self(
            'Microsoft would not finish the sign-in, so nothing has been connected. Nothing is '
            .'wrong with the address and there is nothing to undo. Wait a moment and connect '
            .'it again; if it keeps happening, whoever looks after this EzBizi installation '
            .'needs to check its Microsoft registration.',
            Response::HTTP_BAD_GATEWAY,
        );
    }

    public static function noLastingPermission(): self
    {
        return new self(
            'Microsoft allowed the sign-in but did not give EzBizi lasting permission, so nothing '
            .'has been connected — a connection that expired within the hour would be worse '
            .'than none. Open your Microsoft account\'s security settings, remove EzBizi from the '
            .'apps that have access, then connect the address again.',
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
}
