<?php

namespace App\Support\Newsletter;

use App\Models\Newsletter;
use App\Models\NewsletterSend;
use Illuminate\Support\Carbon;

/**
 * What can honestly be said about a newsletter's delivery (P1c, 2026-11-18),
 * read only from its newest NewsletterSend and the provider's answer stored on it.
 *
 *   no send, draft          -> null (nothing has been asked of a provider)
 *   send queued, time ahead -> scheduled  "Scheduled"
 *   send queued, time due   -> queued     "Queued"
 *   send sending            -> sending    "Sending"
 *   send sent               -> accepted   "Accepted by Mailchimp" (the provider took it)
 *   send failed             -> failed     "Failed"
 *
 * Never "delivered": EzBizi stores no provider delivery event (no webhook
 * writes one), so acceptance is the furthest the evidence goes.
 */
final class NewsletterDelivery
{
    public const SCHEDULED = 'scheduled';

    public const QUEUED = 'queued';

    public const SENDING = 'sending';

    public const ACCEPTED = 'accepted';

    public const FAILED = 'failed';

    /** @return array{state: string, label: string, provider: ?string, recipients: ?int, at: ?string, failure: ?string}|null */
    public static function of(Newsletter $n): ?array
    {
        /** @var NewsletterSend|null $send */
        $send = $n->relationLoaded('sends') ? $n->sends->last() : $n->sends()->latest()->first();
        if ($send === null) {
            return null;
        }

        $provider = $send->provider === 'mailchimp' ? 'Mailchimp' : ($send->provider ? ucfirst((string) $send->provider) : null);
        $due = ! is_string($n->scheduled_for)
            || Carbon::parse($n->scheduled_for, $n->scheduled_timezone ?: config('app.timezone'))->lte(now());

        [$state, $label] = match ($send->status) {
            NewsletterSend::STATUS_QUEUED => $due ? [self::QUEUED, 'Queued'] : [self::SCHEDULED, 'Scheduled'],
            NewsletterSend::STATUS_SENDING => [self::SENDING, 'Sending'],
            NewsletterSend::STATUS_SENT => [self::ACCEPTED, 'Accepted by '.($provider ?? 'provider')],
            default => [self::FAILED, 'Failed'],
        };

        return [
            'state' => $state,
            'label' => $label,
            'provider' => $provider,
            'recipients' => $state === self::ACCEPTED ? (int) $send->recipients : null,
            'at' => $send->sent_at?->toIso8601String(),
            'failure' => $state === self::FAILED ? $send->failure : null,
        ];
    }
}
