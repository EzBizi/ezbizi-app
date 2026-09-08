<?php

namespace App\Services\Leads;

use App\Models\Lead;
use App\Models\LeadConversation;
use App\Models\SocialConnection;
use App\Support\Automations\EventEmitter;
use App\Support\Leads\LeadThread;
use App\Support\Social\ThumbtackNegotiations;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * A Thumbtack enquiry, turned into a Lead row.
 *
 * ── Push, not pull, and that is forced on us ─────────────────────
 *
 * LocalServicesLeads next door SYNCS: it asks Google for everything since a
 * window and reconciles. Nothing here can work that way, because Thumbtack's
 * supply API has no endpoint that lists negotiations — only
 * `GET /v4/negotiations/{id}`. An id arrives by webhook or it does not arrive.
 *
 * Three consequences worth stating once:
 *
 *   NOTHING BACKFILLS. Enquiries from before the webhook was registered are
 *   unreachable forever. There is no catch-up run to write later.
 *
 *   A DROPPED CALLBACK IS A LOST LEAD. With Google a missed page is picked up
 *   by the next sweep. Here the only repair is Thumbtack's own retry.
 *
 *   THE ENTRY POINT IS A PUBLIC ENDPOINT. Everything below is reached from an
 *   unauthenticated route, so it is written to be safe when handed nonsense:
 *   ids that do not resolve, payloads missing halves, the same event twice.
 *
 * ── Idempotent by construction ───────────────────────────────────
 *
 * Thumbtack retries on any non-2xx and can deliver the same event more than
 * once. Leads are keyed on (organization, source, remote_id) and messages on
 * the source's own id through LeadThread, so a replay refreshes rows instead of
 * duplicating them. That is what lets the controller answer 200 to work it has
 * already done, rather than trying to remember what it has seen.
 */
class ThumbtackLeads
{
    public function __construct(
        private readonly EventEmitter $events,
        private readonly LeadThread $threads,
    ) {}

    /**
     * Fetch one negotiation and file it, with everything said on it so far.
     *
     * @return array{lead: Lead, created: bool, messages: int}
     */
    public function absorb(SocialConnection $connection, string $negotiationId): array
    {
        $negotiationId = trim($negotiationId);

        if ($negotiationId === '') {
            throw new RuntimeException('Thumbtack sent an event with no negotiation id.');
        }

        $raw = ThumbtackNegotiations::for($connection)->find($negotiationId);

        $result = $this->store($connection, $negotiationId, $raw);

        // Outside the transaction, and only when the row is new. An automation
        // firing inside the write would run against a row nothing has committed
        // yet; `emitAfterCommit` exists for that reason.
        if ($result['created']) {
            $this->events->emitAfterCommit('lead.received', $result['lead'], [
                'after' => $result['lead']->only([
                    'source', 'contact_name', 'phone', 'category', 'received_at',
                ]),
            ]);
        }

        $messages = $this->messages($connection, $result['lead'], $negotiationId);

        return [...$result, 'messages' => $messages];
    }

    /**
     * The thread on a negotiation we already hold.
     *
     * Called for its own event too: a `MessageCreatedV4` for an enquiry that
     * arrived before the connection existed would otherwise have nowhere to go,
     * so the caller absorbs the negotiation first and this fills the thread.
     *
     * @return int  how many rows were new
     */
    public function messages(SocialConnection $connection, Lead $lead, string $negotiationId): int
    {
        $new = 0;

        foreach (ThumbtackNegotiations::for($connection)->messages($negotiationId) as $message) {
            $remoteId = trim((string) ($message['messageID'] ?? ''));

            if ($remoteId === '') {
                continue;
            }

            $attachments = [];

            foreach ((array) ($message['attachments'] ?? []) as $attachment) {
                $url = trim((string) ($attachment['url'] ?? ''));

                if ($url !== '') {
                    $attachments[] = $url;
                }
            }

            $stored = $this->threads->store($lead, $remoteId, [
                'channel' => LeadConversation::CHANNEL_MESSAGE,
                /*
                 * Their vocabulary is Business/Customer; ours is
                 * ADVERTISER/CONSUMER. Getting this backwards would make the
                 * business's own replies emit `lead.contact_received` and alert
                 * somebody that the customer had written, when it was them.
                 */
                'participant' => ($message['from'] ?? null) === 'Customer'
                    ? LeadConversation::FROM_CONSUMER
                    : LeadConversation::FROM_ADVERTISER,
                'occurred_at'  => self::when($message['sentAt'] ?? null),
                'text'         => trim((string) ($message['text'] ?? '')) ?: null,
                'attachments'  => $attachments !== [] ? $attachments : null,
            ]);

            $new += $stored ? 1 : 0;
        }

        // The list's preview line is a cache rebuilt from the thread, never a
        // record. Skipping this leaves a lead showing an older message than the
        // one that just arrived.
        $this->threads->refresh($lead);

        return $new;
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array{lead: Lead, created: bool}
     */
    private function store(SocialConnection $connection, string $remoteId, array $raw): array
    {
        $customer = (array) ($raw['customer'] ?? []);
        $request  = (array) ($raw['request'] ?? []);
        $category = (array) ($request['category'] ?? []);

        $name = trim(implode(' ', array_filter([
            trim((string) ($customer['firstName'] ?? '')),
            trim((string) ($customer['lastName'] ?? '')),
        ])));

        $phone = trim((string) ($customer['phone'] ?? '')) ?: null;

        return DB::transaction(function () use ($connection, $remoteId, $raw, $name, $phone, $category) {
            $lead = Lead::query()
                ->where('organization_id', $connection->organization_id)
                ->where('source', Lead::SOURCE_THUMBTACK)
                ->where('remote_id', $remoteId)
                ->first();

            $created = $lead === null;

            $fromSource = [
                'contact_name' => $name !== '' ? $name : null,
                'phone'        => $phone,
                'phone_digits' => Lead::digits($phone),
                'category'     => trim((string) ($category['name'] ?? '')) ?: null,
                'received_at'  => self::when($raw['createdAt'] ?? null),
                'synced_at'    => now(),
            ];

            if ($created) {
                $lead = new Lead([
                    'organization_id' => $connection->organization_id,
                    'company_id'      => $connection->scope_type === SocialConnection::SCOPE_COMPANY
                        ? $connection->scope_id
                        : null,
                    // Placement first, then scope — the same two-column split
                    // LocalServicesLeads explains: which franchise OWNS these
                    // enquiries is a different fact from who can SEE the
                    // connection.
                    'franchise_id' => $connection->leads_franchise_id
                        ?? ($connection->scope_type === SocialConnection::SCOPE_FRANCHISE
                            ? $connection->scope_id
                            : null),
                    'connection_id' => $connection->getKey(),
                    'source'        => Lead::SOURCE_THUMBTACK,
                    'remote_id'     => $remoteId,
                    'status'        => Lead::STATUS_NEW,
                    ...$fromSource,
                ]);
            } else {
                // `status` is deliberately absent from $fromSource. A replayed
                // webhook must not drag a lead somebody archived back to NEW.
                $lead->fill($fromSource);
            }

            $lead->save();

            return ['lead' => $lead, 'created' => $created];
        });
    }

    /**
     * Their timestamps are ISO-8601 with an offset, so they carry their own
     * zone and need no account clock — unlike Google's, which do not and cost
     * a day of wrong times to find out.
     */
    private static function when(mixed $value): Carbon
    {
        $value = trim((string) $value);

        if ($value === '') {
            return now();
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            // A timestamp we cannot read is not a reason to lose the lead.
            return now();
        }
    }
}
