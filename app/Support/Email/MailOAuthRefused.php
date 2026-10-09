<?php

namespace App\Support\Email;

use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * A Google or Microsoft mail sign-in that will not be finished, and the
 * sentence saying why (R8).
 *
 * `$provider` is the name the person knows the sign-in by - 'Google' or
 * 'Microsoft' - the same way the social SocialOAuthRefused takes the network.
 *
 * ── Why this is not CannotReadMailbox ────────────────────────────
 *
 * They describe different moments and are read by different code.
 * {@see CannotReadMailbox} is about an inbox that is CONNECTED and cannot be
 * opened right now; it carries a {@see MailboxFault}, and that fault decides
 * whether the mailbox row should be marked broken and whether a "connect again"
 * button should appear. Every one of those decisions is wrong here.
 *
 * A sign-in that fails has not connected anything. The mailbox is exactly as it
 * was a minute ago — usually unconfigured, which is not broken — and marking it
 * would replace the accurate "finish connecting this" with a scare about an
 * inbox that has never worked. So this is its own type, it never touches the
 * row, and {@see RecordingMailboxReader} never sees it.
 *
 * ── Every message here is read by a person in a browser ──────────
 *
 * These are not API errors a client parses. The provider finishes a flow by
 * redirecting a browser to this server, so whoever is looking at the result is
 * a human being on a page — and often the same administrator who pressed the
 * button thirty seconds earlier. So each sentence names what happened, says
 * whether anything was stored, and ends with what to do (R8). None of them
 * names a class, an OAuth error code or a status number.
 */
final class MailOAuthRefused extends RuntimeException
{
    private function __construct(string $message, public readonly int $status)
    {
        parent::__construct($message);
    }

    /**
     * The installation's own configuration is not usable.
     *
     * 501, matching {@see MailboxFault::NotBuiltHere}: it is at EzBizi's end and
     * there is nothing for the person in front of the screen to press.
     *
     * @param  string  $provider  as the product is named: 'Google', 'Microsoft 365'
     */
    public static function notConfigured(string $provider, string $why): self
    {
        return new self(
            "EzBizi cannot start a {$provider} sign-in on this installation. ".$why
            .' This is at EzBizi\'s end — nothing is wrong with the address, and signing in '
            .'again would not help.',
            Response::HTTP_NOT_IMPLEMENTED,
        );
    }

    /**
     * The `state` did not name a sign-in that is still open.
     *
     * ── One sentence for four different causes, deliberately ─────
     *
     * Never issued, already used, expired, and pointing at something that has
     * since been deleted all answer identically. That is the same argument
     * InvitationAcceptanceController makes about dead tokens, and it applies
     * with more force here: telling a stranger that a state was "already used"
     * confirms they hold a value this server once issued, which is precisely
     * what somebody replaying a link out of a browser history wants to learn.
     *
     * 400 rather than 403, because nobody is being denied anything they might
     * be granted — the value simply does not name an open sign-in.
     */
    public static function noOpenSignIn(string $provider): self
    {
        return new self(
            "This {$provider} sign-in link is no longer valid. Sign-ins can only be finished once "
            .'and they stop working after ten minutes, so this usually means the page was '
            .'opened twice or left sitting for a while. Nothing has been connected and '
            .'nothing has been changed. Go back to Email settings and start connecting the '
            .'address again.',
            Response::HTTP_BAD_REQUEST,
        );
    }

    /**
     * The consent screen came back with a refusal rather than a code.
     *
     * @param  string  $button  the consent screen's own word for yes: Google's
     *                          says "Allow", Microsoft's says "Accept"
     */
    public static function declined(string $provider, string $button): self
    {
        return new self(
            "The sign-in was not completed at {$provider}, so nothing has been connected. That is "
            ."usually because the \"{$button}\" button was not pressed, or the window was closed "
            .'part-way through. Go back to Email settings and try connecting the address '
            .'again.',
            Response::HTTP_BAD_REQUEST,
        );
    }

    /**
     * THE DATA LEAK THIS FLOW EXISTS TO PREVENT.
     *
     * ═══════════════════════════════════════════════════════════════
     *  Somebody sets up info@a-1chimney.com, is sent to Google, and
     *  signs in with the account their browser was already signed in
     *  to — their own personal Gmail. Without this refusal the
     *  organization has just connected that person's PRIVATE MAIL to a
     *  shared inbox, and everybody granted that inbox can now read
     *  every message they have ever received.
     *
     *  It is silent, it looks like success, and the person who caused
     *  it is the last one who would notice. So the addresses are
     *  compared and a mismatch stops here — before anything is stored,
     *  and with the token that was just issued handed straight back to
     *  the provider to be revoked.
     * ═══════════════════════════════════════════════════════════════
     *
     * Both addresses are named. The sentence is unreadable without them —
     * "the wrong account" leaves somebody guessing which of their four Google
     * accounts the browser used — and the one that is personal is shown only to
     * the person it belongs to, in their own browser, and is written down
     * nowhere: see GoogleMailAuthController, where the audit entry records the
     * mismatch and the MAILBOX address only.
     */
    public static function wrongAccount(string $provider, string $expected, string $signedInAs): self
    {
        return new self(
            sprintf(
                'That sign-in was for %s, but this inbox is %s, so nothing has been connected '
                .'and the permission has already been handed back to %s. Connecting them '
                .'would have let everybody with access to this shared inbox read the mail for '
                .'%s. If you have more than one %s account, sign out of the others or use '
                .'a private window, then connect the address again and choose %s.',
                $signedInAs, $expected, $provider, $signedInAs, $provider, $expected,
            ),
            Response::HTTP_CONFLICT,
        );
    }

    /**
     * Signed in as the right account, and did not allow enough to be useful.
     *
     * The consent screen lets somebody untick permissions. Accepting that
     * quietly would store a credential that cannot read the mailbox, and the
     * mailbox would read "connected" until the first person opened it and got a
     * permission failure they had no way to connect to a tick box they cleared
     * ten minutes earlier.
     */
    public static function permissionNotGiven(string $provider, string $address): self
    {
        return new self(
            sprintf(
                'The sign-in for %s did not include permission to read and reply to the mail, '
                .'so nothing has been connected — EzBizi would not have been able to show you '
                .'anything. That usually means one of the tick boxes on the %s screen was '
                .'cleared. Connect the address again and leave all of them ticked.',
                $address, $provider,
            ),
            Response::HTTP_CONFLICT,
        );
    }

    /**
     * The provider would not complete the exchange.
     *
     * 502 rather than 400: by this point the person has done everything right
     * and the failure is between two servers. It is also the case most likely
     * to be transient, so the sentence says to try again rather than sending
     * anybody to check a setting.
     */
    public static function exchangeFailed(string $provider): self
    {
        return new self(
            "{$provider} would not finish the sign-in, so nothing has been connected. Nothing is "
            .'wrong with the address and there is nothing to undo. Wait a moment and connect '
            .'it again; if it keeps happening, whoever looks after this EzBizi installation '
            ."needs to check its {$provider} registration.",
            Response::HTTP_BAD_GATEWAY,
        );
    }

    /**
     * The exchange succeeded and produced no LASTING permission.
     *
     * A refresh token comes on first consent and, after that, only when the
     * person is asked to consent again. Both flows always ask — see
     * {@see GoogleOAuth::authorizeUrl} and {@see MicrosoftOAuth::authorizeUrl} —
     * so an absent refresh token is a real anomaly rather than the ordinary
     * second-time-round case, and storing the hour-long access token instead
     * would produce an inbox that worked until lunchtime and then said the
     * sign-in had been revoked.
     */
    public static function noLastingPermission(string $provider): self
    {
        return new self(
            "{$provider} allowed the sign-in but did not give EzBizi lasting permission, so nothing "
            .'has been connected — a connection that expired within the hour would be worse '
            ."than none. Open your {$provider} account's security settings, remove EzBizi from the "
            .'apps that have access, then connect the address again.',
            Response::HTTP_BAD_GATEWAY,
        );
    }

    /**
     * The inbox, the organization or the person is not what it was ten minutes
     * ago.
     *
     * Deliberately one sentence for all three. The window is short and the cases
     * are rare, but each of them is a real state — an inbox disconnected by a
     * colleague mid-flow, an account deactivated, a role changed — and none of
     * them may be allowed to write a credential.
     */
    public static function noLongerPermitted(string $why): self
    {
        return new self(
            'The sign-in could not be finished, so nothing has been connected. '.$why,
            Response::HTTP_CONFLICT,
        );
    }
}
