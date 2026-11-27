<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\Attributes\WithoutRelations;
use Illuminate\Support\Carbon;

/**
 * Confirm the address somebody signed up with (TODO #22).
 *
 * Queued, like PasswordResetLink and unlike InvitationLink. Signup already
 * writes six rows in one transaction; making the person wait on an SMTP
 * round-trip as well would slow the one request where somebody is watching a
 * spinner and deciding whether this product works. The account exists either
 * way — the caller records whether the message went and says so.
 *
 * ── What it deliberately does not carry ──────────────────────────
 *
 * No password, obviously, but also no organization name in the subject. The
 * subject line is read on a lock screen, and "Confirm your address for A-1
 * Chimney" tells a bystander where somebody works. The body names it, because
 * by then the reader is the person who typed it in ten seconds ago.
 */
class EmailVerificationLink extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Retried like PasswordResetLink: the worker runs --tries=1, and one SES
     * 451 on 2026-11-16 lost a signup's verification mail. The failed job
     * drops the dead connection (AppServiceProvider), so the retry is fresh.
     */
    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60];

    public function __construct(
        // Serialized bare: the queue worker has no tenant, and re-loading a
        // tenant-scoped relation there throws — NotificationNotice lost 7,705
        // mails that way. ->organization is then lazy-loaded in the worker,
        // which is safe only because Organization is unscoped and strict mode
        // is off. See QueuedAuthMailWorkerTest.
        #[WithoutRelations]
        private readonly User $user,
        /** The raw token. Held for the life of one job and never written down. */
        private readonly string $token,
        private readonly Carbon $expiresAt,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        // From config, never a literal: a second installation of this software
        // would otherwise email its own users a link to production. Signup makes
        // a User, never a PlatformStaff, so this is always the customer host —
        // there is no branch here of the kind PasswordResetLink needs.
        $host = config('ezbizi.hosts.customer');

        $url = "{$host}/verify?token={$this->token}";

        return (new MailMessage)
            ->subject('Confirm your email address')
            ->greeting("Hello {$this->user->name},")
            ->line(sprintf(
                'Thanks for signing up to EzBizi. Confirming this address finishes '
                .'setting up %s.',
                $this->user->organization?->name ?? 'your account',
            ))
            ->action('Confirm my address', $url)
            ->line(sprintf(
                'The link works once, and stops working on %s.',
                $this->expiresAt->toDayDateTimeString(),
            ))
            // Not "ignore this if it was not you". Somebody whose address has
            // been typed into a signup they did not make should be able to tell
            // us, and this is the only message that can say so — the account
            // cannot do anything until this link is used, so the honest thing is
            // to say that plainly rather than imply harm has already happened.
            ->line('If you did not sign up, nothing can be done with this account until '
                .'somebody confirms this address, and nobody can confirm it but you. '
                .'Reply to this message and we will remove it.')
            ->salutation('— EzBizi');
    }
}
