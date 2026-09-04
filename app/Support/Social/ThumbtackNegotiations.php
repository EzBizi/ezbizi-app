<?php

namespace App\Support\Social;

use App\Models\SocialConnection;
use InvalidArgumentException;

/**
 * One Thumbtack enquiry: what was asked, what was said, and where it got to.
 *
 * A "negotiation" is Thumbtack's word for the thread between a customer and a
 * business about one request. It is the lead.
 *
 * ── There is no list endpoint, and that decides the architecture ──
 *
 * The whole supply-side API offers `GET /v4/negotiations/{id}` and nothing that
 * enumerates them. There is no "give me my negotiations", no since-cursor, no
 * paging. A negotiation can only be fetched once you already know its id.
 *
 * The only way to learn an id is a webhook — `NegotiationCreatedV4` arrives,
 * carrying the id, and then this fetches it. So webhooks are not an optional
 * extra on top of lead reading; they ARE lead delivery, and a build that
 * implemented negotiations without them would read every lead it was never
 * told about, which is none of them. See ThumbtackWebhooks.
 *
 * A consequence worth stating: nothing here can backfill. Enquiries that
 * arrived before the webhook was registered are unreachable, permanently,
 * because there is no endpoint that would find them.
 *
 * ── Job status is a promise to a third party ─────────────────────
 *
 * `job-status` tells Thumbtack what happened to work that came through them,
 * which is how a Pro's completion rate is measured. Sending a wrong one is not
 * a display bug — it is a claim about a job, made to somebody who reports on
 * it. So the status is checked against Thumbtack's enum here rather than passed
 * through, and `partnerStatusChangedAt` is required by them precisely so the
 * timeline is ours rather than the moment our request happened to arrive.
 */
final class ThumbtackNegotiations
{
    /** Thumbtack's own vocabulary. Anything else is refused before it is sent. */
    public const STATUSES = [
        'not_scheduled',
        'appt_scheduled',
        'job_complete',
        'invoice_paid',
        'customer_cancel',
        'pro_cancel',
    ];

    /** Their cap on one message. */
    private const MESSAGE_MAX = 16384;

    public function __construct(private readonly ThumbtackClient $client) {}

    public static function for(SocialConnection $connection): self
    {
        return new self(new ThumbtackClient($connection));
    }

    /**
     * The enquiry itself — who asked, what for, and when.
     *
     * @return array<string, mixed>
     */
    public function find(string $negotiationId): array
    {
        return $this->client->get('/v4/negotiations/'.$negotiationId);
    }

    /**
     * The thread, oldest first as Thumbtack returns it.
     *
     * @return array<int, array<string, mixed>>
     */
    public function messages(string $negotiationId): array
    {
        $body = $this->client->get('/v4/negotiations/'.$negotiationId.'/messages');

        return array_values(array_filter(
            (array) ($body['data'] ?? []),
            static fn ($row) => is_array($row) && isset($row['messageID']),
        ));
    }

    /**
     * Say something to the customer, as the business.
     *
     * @return array<string, mixed>
     */
    public function reply(string $negotiationId, string $text): array
    {
        $text = trim($text);

        if ($text === '') {
            throw new InvalidArgumentException('An empty message would not say anything. Write something.');
        }

        return $this->client->post('/v4/negotiations/'.$negotiationId.'/messages', [
            // Cut here rather than let Thumbtack refuse the whole send. Losing
            // the tail of a long message beats losing the message.
            'text' => mb_substr($text, 0, self::MESSAGE_MAX),
        ]);
    }

    /**
     * Tell Thumbtack what happened to the job.
     *
     * @param  \DateTimeInterface|null  $changedAt  when it actually changed, not
     *                                              when this call was made
     * @param  int|null  $invoiceCents  only meaningful for invoice_paid
     */
    public function setJobStatus(
        string $negotiationId,
        string $status,
        ?\DateTimeInterface $changedAt = null,
        ?int $invoiceCents = null,
    ): void {
        if (! in_array($status, self::STATUSES, true)) {
            // Refused here, with the list. Thumbtack's own refusal names the
            // field and not the value, so it reads as "we sent something wrong"
            // without saying what would have been right.
            throw new InvalidArgumentException(
                $status.' is not a Thumbtack job status. It is one of: '.implode(', ', self::STATUSES).'.',
            );
        }

        $this->client->post('/v4/negotiations/'.$negotiationId.'/job-status', array_filter([
            'status' => $status,
            // THEIR clock, from OUR record. Thumbtack requires this rather than
            // stamping receipt, so a status that took a day to sync still
            // reports the day it happened.
            'partnerStatusChangedAt' => ($changedAt ?? now())->format(\DateTimeInterface::ATOM),
            'invoiceAmountInCents'   => $invoiceCents,
        ], static fn ($v) => $v !== null));
    }
}
