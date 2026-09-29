<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\Attributes\WithoutRelations;
use Illuminate\Support\Carbon;

/**
 * "Set your EzBizi password", for somebody whose account was brought over from
 * A1 Tools (docs/A1-TOOLS-PEOPLE-MIGRATION.md).
 *
 * An EzBizi account email, sent as EzBizi — Jose's rule that EzBizi's own
 * address is only for account actions such as recovery, resets and
 * verification covers exactly this. It redeems through the same /reset page
 * and PasswordResets as a reset does; only the words and the lifetime differ,
 * because nobody asked for this one and they may read it days later.
 */
class WelcomeSetPassword extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        // Serialized bare, for the reason PasswordResetLink gives: the queue
        // worker has no tenant, and a tenant-scoped relation reloaded there
        // throws. Only ->name is read.
        #[WithoutRelations]
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
        $url = config('ezbizi.hosts.customer')."/reset?token={$this->token}";

        return (new MailMessage)
            ->subject('Set your EzBizi password')
            ->greeting("Hello {$this->subject->name},")
            ->line('Your account has moved from A1 Tools to EzBizi. Your details, your '
                .'paperwork and your schedule came with it.')
            ->line('Choose a password to sign in for the first time. You will sign in with '
                .'this email address.')
            ->action('Choose your password', $url)
            ->line("The link stops working at {$this->expiresAt->toDayDateTimeString()} UTC, "
                .'or as soon as it has been used once. If it runs out, ask your manager for a '
                .'new one.')
            ->salutation('— EzBizi');
    }
}
