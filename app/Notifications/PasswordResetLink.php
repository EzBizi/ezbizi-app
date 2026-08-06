<?php

namespace App\Notifications;

use App\Models\PlatformStaff;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;

/**
 * The reset email.
 *
 * Queued, so a slow SMTP server never delays the request — and because the reset
 * endpoint answers identically whether or not an account matched, which it could
 * not do if it waited for a send to succeed.
 */
class PasswordResetLink extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly Model $subject,
        private readonly string $token,
        private readonly Carbon $expiresAt,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        // Staff reset at the control plane, everybody else at the app. Sending
        // the wrong host would work — the API is shared — but it would drop
        // somebody into a sign-in page that is not theirs.
        //
        // From config, not written in here: these were literal strings, which
        // meant any second installation of this software would have emailed
        // production links to its own users.
        $host = config($this->subject instanceof PlatformStaff
            ? 'ezbizi.hosts.staff'
            : 'ezbizi.hosts.customer');

        $url = "{$host}/reset?token={$this->token}";

        return (new MailMessage)
            ->subject('Reset your EzBizi password')
            ->greeting("Hello {$this->subject->name},")
            ->line('Somebody asked to reset the password for this account.')
            ->action('Choose a new password', $url)
            ->line("The link stops working at {$this->expiresAt->toDayDateTimeString()} UTC, "
                .'or as soon as it has been used once.')
            // Not "if this was not you, ignore this" — a reset request nobody
            // made is worth knowing about, and the account holder is the only
            // person who can tell.
            ->line('If you did not ask for this, nothing has changed yet. Somebody knows '
                .'your email address and tried; if that is unexpected, say so.')
            ->salutation('— EzBizi');
    }
}
