<?php

namespace App\Notifications;

use App\Models\Invitation;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The invitation email — written now, delivered when TODO #1 lands.
 *
 * ── Why this is a real mailable and not a comment saying "email here" ──
 *
 * The owner's decision is that the invitation is a copyable link today and an
 * email when mail works, and the build note offered a choice: write the mailable
 * and let it fail into the log the way everything else does, or leave a named
 * seam. This is the first, for one reason — a seam is a promise, and the promise
 * that rots is the one nobody has to keep. Nothing on this platform can send
 * mail: AWS blocks outbound port 25 on both instances and SES has no credential
 * (TODO #1), so `MAIL_MAILER=log` accepts this message and writes it to the log.
 * The day a credential exists, the invitation email starts arriving with no code
 * change at all, and the wording will already have been read by somebody.
 *
 * What that costs, stated plainly: this text has never been delivered to a real
 * mailbox, so it has never been seen rendered by a mail client. That is worth
 * checking on the day TODO #1 lands, and it is a smaller job than writing it
 * then.
 *
 * ── Queued, and the send is not allowed to fail the request ──────
 *
 * ── NOT ShouldQueue, unlike PasswordResetLink ────────────────────
 *
 * It was, and that put a live credential in the database in plaintext.
 *
 * A queued notification is SERIALISED, and this one holds the accept URL — the
 * raw single-use token and all. Production runs `QUEUE_CONNECTION=database`, so
 * the token was written verbatim into `jobs.payload`; production also runs
 * `MAIL_MAILER=ses` with no credential (TODO #1), so every one of those jobs
 * then failed and the payload was RETAINED in `failed_jobs`, which nothing
 * prunes and every database backup carries. A seven-day credential, readable,
 * in a table nobody thinks of as sensitive.
 *
 * Sent inline instead. The token exists for the duration of one request and is
 * never handed to anything that persists it. The cost is that the send happens
 * in the request — which is why the caller wraps it — and the cost is nil today
 * because nothing can send at all.
 *
 * When TODO #1 lands and this is worth queueing again, queue the INVITATION ID
 * and rebuild nothing: the raw token cannot be recovered from the row (it is
 * stored as a hash, deliberately), so the honest options are an inline send or
 * a mail transport that accepts the message during the request. Do not solve it
 * by putting the token back in the payload.
 *
 * The caller additionally swallows
 * a throw into the log (see HrInvitationsController::deliver) because the queue
 * connection is `sync` in some environments, and an SES failure surfacing inline
 * would 500 the request AFTER the invitation had already committed — telling the
 * inviter it broke while a perfectly good link existed and they could not see it.
 *
 * ── What it deliberately does not carry ──────────────────────────
 *
 * No role name and no franchise. The acceptance screen names both, because by
 * then the token has proved the reader is the intended person; an email is
 * forwarded, quoted and left open on shared screens, and "you have been invited
 * as Regional Manager for the Buenos Aires branch" is a sentence about somebody
 * else's business.
 */
class InvitationLink extends Notification
{
    use Queueable;

    public function __construct(
        private readonly Invitation $invitation,
        /** The raw token. Held for the life of one job and never written down. */
        private readonly string $acceptUrl,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $organization = $this->invitation->organization->name;
        $inviter = $this->invitation->invitedBy?->name;

        $greeting = $this->invitation->first_name !== null
            ? "Hello {$this->invitation->first_name},"
            : 'Hello,';

        return (new MailMessage)
            ->subject("You have been invited to join {$organization} on EzBizi")
            ->greeting($greeting)
            ->line($inviter === null
                ? "{$organization} has invited you to join them on EzBizi."
                : "{$inviter} has invited you to join {$organization} on EzBizi.")
            ->action('Set up your account', $this->acceptUrl)
            ->line(sprintf(
                'The link works once, and stops working on %s UTC.',
                $this->invitation->expires_at->copy()->utc()->toDayDateTimeString(),
            ))
            // Jose, 2026-10-08: the invitation also points at the apps.
            ->line('EzBizi works in your browser, and there are apps for your phone and computer: '
                .'https://www.ezbizi.com/download/')
            // Not "ignore this if it was not you". Somebody who has been invited
            // to a business they have never heard of should say so to that
            // business, and this is the only message that can tell them who to
            // ask.
            ->line('If you were not expecting this, nothing has been created in your name — '
                .'the account only exists once you have set a password. Tell whoever sent it.')
            ->salutation('— EzBizi');
    }
}
