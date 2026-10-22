<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * One in-app notification, sent again as an email (plan §12).
 *
 * The registry has told administrators since August that
 * `notifications.email_enabled` "takes effect when notifications can be sent by
 * email". This is that. It carries the SAME title and body the `notifications`
 * row carries — not a richer message written for the mail channel — because two
 * wordings for one event is how the bell and the inbox come to disagree about
 * what happened.
 *
 * ── Queued, and the reason is not politeness ─────────────────────
 *
 * `NotificationDispatcher::dispatch` runs inside the automation queue worker,
 * writes every recipient in ONE insert, and is capped at 200 people. Sending
 * inline would put up to 200 SMTP conversations on the critical path of one
 * event's delivery — so a rule that fires on every job in a busy franchise
 * would hold the whole outbox open behind a mail server. Same call
 * PasswordResetLink makes, for the same reason.
 *
 * ── No deep link, deliberately ───────────────────────────────────
 *
 * A notification row names a subject by class and id, and there is no map from
 * those to a screen — the client decides what opening one means. Inventing a
 * URL here would produce a link that is right for a Job today and wrong for
 * whatever the next module raises. The mail points at the app and the bell
 * takes it from there.
 */
class NotificationNotice extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly string $title,
        private readonly ?string $body = null,
        /**
         * Where a reply should go, or null to let it go back to whatever
         * address EzBizi sends from.
         *
         * The organization's `notifications.reply_to_email`, resolved by the
         * dispatcher against the same scope that decided whether to send at
         * all. Resolved THERE rather than here because this class is queued:
         * reading a setting inside a job means reading it at delivery time,
         * which is minutes after the decision it belongs to.
         */
        private readonly ?string $replyTo = null,
        /** "EzBizi - Company - Branch", leading the subject like every push. */
        private readonly ?string $header = null,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject($this->header === null ? $this->title : "{$this->header}: {$this->title}")
            ->greeting("Hello {$notifiable->name},")
            ->line($this->title);

        if (filled($this->body)) {
            $mail->line($this->body);
        }

        return $mail
            ->action('Open EzBizi', (string) config('ezbizi.hosts.customer'))
            ->line('You are receiving this because your organization sends notifications by '
                .'email. An administrator can turn that off in Settings.')
            ->salutation('— EzBizi')
            ->when($this->replyTo !== null, fn (MailMessage $m) => $m->replyTo($this->replyTo));
    }
}
