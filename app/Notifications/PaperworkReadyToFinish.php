<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "Your paperwork is ready to finish": sent when the employer has signed their
 * side (Jose, 2026-10-01: "once they sign, it should send his part via email,
 * with a link, which should open the onboarding for him to finish").
 *
 * Sent as EzBizi, like the invitation that brought them in. The link opens the
 * app, where "Your paperwork" is the first thing a person with an unfinished
 * onboarding lands on; it carries nothing secret, so it is safe to forward.
 * The contract's name and the organization are in it; no answer is.
 */
class PaperworkReadyToFinish extends Notification implements ShouldQueue
{
    use Queueable;

    /** One SES hiccup must not lose the mail for good (audit 2026-11-27). */
    public int $tries = 3;

    /** @return list<int> seconds between tries */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function __construct(
        private readonly string $organization,
        private readonly string $contract,
        private readonly string $employer,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Your paperwork with {$this->organization} is ready to finish")
            ->greeting("Hello {$notifiable->name},")
            ->line("{$this->employer} has filled in and signed their part of your {$this->contract}.")
            ->line('What is left is yours: a few details, your documents, and your signature. '
                .'You can stop and come back at any time; what you have answered is kept.')
            ->action('Finish your paperwork', rtrim((string) config('ezbizi.hosts.customer'), '/').'/')
            ->line('Sign in with this email address. Your paperwork is the first thing you will see.')
            ->salutation('— EzBizi');
    }
}
