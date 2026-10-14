<?php

namespace App\Support\Social;

use App\Models\Lead;
use App\Models\LeadConversation;
use App\Models\SocialConnection;
use App\Support\Automations\EventEmitter;
use App\Support\Leads\LeadThread;
use App\Support\Notifications\LeadNotificationText;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Messages sent to a business's Facebook Page and to its Instagram.
 *
 * ── One class for two products, because Meta made them one API ───
 *
 * Messenger and Instagram Direct arrive on the same webhook, in the same
 * envelope, and are answered by the same `POST /{id}/messages`. The only things
 * that differ are the `object` on the delivery — `page` or `instagram` — and
 * which connection the id belongs to. Splitting this in two would be two copies
 * of the echo rule and the threading rule for no gain.
 *
 * ── An enquiry is a PERSON, not a message ───────────────────────
 *
 * The lead is keyed on the sender's scoped id, so the second message somebody
 * sends lands on the thread they already have. Keying on the message id instead
 * would look right on the first test and produce a new "lead" for every line of
 * a conversation — a list of forty enquiries from one person who was asking
 * about one job.
 *
 * Meta's ids are scoped per business, so the same human writing to two
 * different customers of ours is two different ids. That is Meta's decision
 * and it is the right one for us: a lead belongs to a business, not to a
 * person.
 */
final class MetaMessages
{
    private const TIMEOUT = 20;

    public function __construct(
        private readonly LeadThread $thread,
        private readonly EventEmitter $events,
    ) {}

    /**
     * Which of our connections a delivery belongs to, or null.
     *
     * The id in `recipient` is the BUSINESS's — our Page, our Instagram
     * account — because the message was sent TO them. It is matched against
     * `social_connections.remote_id`, which is a row we wrote when somebody
     * connected the account, so the tenant is decided by something we hold and
     * never by anything in the body.
     *
     * Read without a tenant in context on purpose: which tenant this is is the
     * thing being worked out. `withoutGlobalScopes()` rather than `DB::table`
     * because the caller needs the model's encrypted credentials.
     */
    public function connectionFor(string $object, string $businessId): ?SocialConnection
    {
        $provider = match ($object) {
            'page' => SocialProvider::FacebookPage,
            'instagram' => SocialProvider::InstagramProfessional,
            default => null,
        };

        if ($provider === null || $businessId === '') {
            return null;
        }

        return SocialConnection::query()
            ->withoutGlobalScopes()
            ->where('provider', $provider->value)
            ->where('remote_id', $businessId)
            ->first();
    }

    /** The lead source a delivery's `object` means. */
    public function sourceFor(string $object): ?string
    {
        return match ($object) {
            'page' => Lead::SOURCE_FACEBOOK,
            'instagram' => Lead::SOURCE_INSTAGRAM,
            default => null,
        };
    }

    /**
     * Absorb one `messaging` event. True when something was recorded.
     *
     * @param  array<string, mixed>  $event
     */
    public function absorb(array $event, SocialConnection $connection, string $source): bool
    {
        $message = (array) ($event['message'] ?? []);
        $mid = (string) ($message['mid'] ?? '');
        $senderId = (string) ($event['sender']['id'] ?? '');

        if ($mid === '' || $senderId === '') {
            return false;
        }

        /*
         * OUR OWN REPLY, COMING BACK.
         *
         * Meta echoes everything the Page sends, including what this class
         * sent a second ago. Without this the business's own answer is
         * recorded as the customer speaking: the enquiry leaves the Unreplied
         * list, `lead.contact_received` fires, and any rule watching for a
         * customer message answers itself in a loop.
         *
         * Checked before anything else because an echo must not even create
         * the lead - a business messaging somebody first would otherwise
         * manufacture an enquiry nobody made.
         */
        if (($message['is_echo'] ?? false) === true) {
            return false;
        }

        $lead = $this->lead($connection, $source, $senderId, $event);

        // Meta's timestamp is milliseconds since the epoch. Read as seconds it
        // lands in the year 57000 and the thread sorts wrongly forever.
        $at = isset($event['timestamp'])
            ? Carbon::createFromTimestampMs((int) $event['timestamp'])
            : now();

        return $this->thread->store($lead, $mid, [
            'channel' => LeadConversation::CHANNEL_MESSAGE,
            'participant' => LeadConversation::FROM_CONSUMER,
            'occurred_at' => $at,
            // Only text is read. An image or a sticker becomes a row with a
            // null body and the right channel, which is honest - an empty
            // string would read as somebody having sent nothing.
            'text' => isset($message['text']) && $message['text'] !== '' ? (string) $message['text'] : null,
        ]);
    }

    /**
     * The enquiry this person already has, or a new one.
     *
     * @param  array<string, mixed>  $event
     */
    private function lead(
        SocialConnection $connection,
        string $source,
        string $senderId,
        array $event,
    ): Lead {
        $lead = Lead::query()
            ->withoutGlobalScopes()
            ->where('organization_id', $connection->organization_id)
            ->where('source', $source)
            ->where('remote_id', $senderId)
            ->first();

        if ($lead !== null) {
            /*
             * AN ORPHAN IS ADOPTED, the same rule Thumbtack and Local Services
             * follow. Disconnecting nulls `connection_id`; reconnecting the
             * same account should make its enquiries visible again rather than
             * leaving them hidden with no way back.
             */
            if ($lead->connection_id === null) {
                $lead->forceFill(['connection_id' => $connection->getKey()])->save();
            }

            // Enquiries from before names were read get theirs on the next
            // message. Only a blank is filled: staff may have typed the real one.
            if ($lead->contact_name === null && ($name = $this->nameOf($connection, $senderId)) !== null) {
                $lead->forceFill(['contact_name' => $name])->save();
            }

            return $lead;
        }

        $lead = Lead::query()->withoutGlobalScopes()->create([
            'organization_id' => $connection->organization_id,
            'company_id' => $connection->scope_type === SocialConnection::SCOPE_COMPANY
                ? $connection->scope_id
                : null,
            // Placement first, then scope - the split Local Services explains:
            // which franchise OWNS these enquiries is a different fact from
            // who can SEE the connection.
            'franchise_id' => $connection->leads_franchise_id
                ?? ($connection->scope_type === SocialConnection::SCOPE_FRANCHISE
                    ? $connection->scope_id
                    : null),
            'connection_id' => $connection->getKey(),
            'source' => $source,
            // A DM, so its notification reads "Meta Text". Lead-ad forms
            // (META_FORM) have no producer yet.
            'lead_type' => LeadNotificationText::META_TEXT,
            'remote_id' => $senderId,
            // The name they message under, so the list stops reading
            // "Unnamed enquiry" (Jose, 2026-10-12). No phone or email: Meta
            // has none to give.
            'contact_name' => $this->nameOf($connection, $senderId),
            'summary' => $connection->display_name,
            'status' => Lead::STATUS_NEW,
            'received_at' => now(),
        ]);

        // Until 2026-10-06 a DM lead never raised this, so no "new lead" rule
        // ever heard about Messenger or Instagram.
        $this->events->emitAfterCommit('lead.received', $lead, [
            'after' => $lead->only(['source', 'contact_name', 'phone', 'category', 'received_at']) + [
                'kind' => $lead->kind(),
            ],
        ]);

        return $lead;
    }

    /**
     * Re-adopt the hidden enquiries a reconnected Page or Instagram account
     * brought before (Lead::scopeSourceStillConnected hides a lead whose
     * connection is gone). The old row is deleted, so which account a lead
     * came through is asked of Meta: a sender id is scoped to one Page / one
     * Instagram account, so only this account's conversation with it answers;
     * a lead-ad form id reads only with its own Page's token. Inside the tenant.
     */
    public function reclaim(SocialConnection $connection): int
    {
        $instagram = $connection->provider === SocialProvider::InstagramProfessional->value;
        $token = $connection->credentials['access_token'] ?? null;

        if (! is_string($token) || $token === '') {
            return 0;
        }

        $orphans = Lead::query()
            ->where('source', $instagram ? Lead::SOURCE_INSTAGRAM : Lead::SOURCE_FACEBOOK)
            ->whereNull('connection_id')
            ->get();

        $adopted = 0;

        foreach ($orphans as $lead) {
            try {
                $ours = match (true) {
                    str_starts_with((string) $lead->remote_id, 'form:') => ! $instagram && Http::timeout(self::TIMEOUT)
                        ->get(WhatsAppCloud::endpoint().'/'.substr((string) $lead->remote_id, 5), ['fields' => 'id', 'access_token' => $token])->successful(),
                    $instagram => (array) (Http::timeout(self::TIMEOUT)
                        ->get('https://graph.instagram.com/v21.0/me/conversations', ['user_id' => $lead->remote_id, 'platform' => 'instagram', 'access_token' => $token])->json('data')) !== [],
                    default => (array) (Http::timeout(self::TIMEOUT)
                        ->get(WhatsAppCloud::endpoint().'/'.$connection->remote_id.'/conversations', ['user_id' => $lead->remote_id, 'fields' => 'id', 'access_token' => $token])->json('data')) !== [],
                };
            } catch (ConnectionException) {
                $ours = false;   // asked again on the next reconnect
            }

            if ($ours) {
                $lead->forceFill(['connection_id' => $connection->getKey()])->save();
                $adopted++;
            }
        }

        return $adopted;
    }

    /**
     * The sender's display name, or null when Meta will not say.
     *
     * Messenger: read from the conversation's participants, which
     * pages_messaging covers. The direct `GET /{PSID}` profile needs Business
     * Asset User Profile Access, which this app does not hold - measured
     * 2026-10-12, it refuses with code 100 for every real customer.
     * Instagram: the IGSID profile; a name, else the @username.
     */
    public function nameOf(SocialConnection $connection, string $senderId): ?string
    {
        $token = $connection->credentials['access_token'] ?? null;

        if (! is_string($token) || $token === '') {
            return null;
        }

        try {
            if ($connection->provider === SocialProvider::InstagramProfessional->value) {
                $profile = Http::timeout(self::TIMEOUT)
                    ->get('https://graph.instagram.com/v21.0/'.$senderId, [
                        'fields' => 'name,username',
                        'access_token' => $token,
                    ])->json();

                $name = trim((string) ($profile['name'] ?? ''));
                $username = trim((string) ($profile['username'] ?? ''));

                return $name !== '' ? $name : ($username !== '' ? '@'.$username : null);
            }

            $participants = Http::timeout(self::TIMEOUT)
                ->get(WhatsAppCloud::endpoint().'/'.$connection->remote_id.'/conversations', [
                    'user_id' => $senderId,
                    'fields' => 'participants',
                    'access_token' => $token,
                ])->json('data.0.participants.data');

            foreach ((array) $participants as $person) {
                if (is_array($person) && (string) ($person['id'] ?? '') === $senderId) {
                    $name = trim((string) ($person['name'] ?? ''));

                    return $name !== '' ? $name : null;
                }
            }
        } catch (ConnectionException) {
            // Unnamed is better than no lead at all.
        }

        return null;
    }

    /**
     * Reply, and return Meta's id for the message.
     *
     * @throws RuntimeException with a sentence for the screen
     */
    public function send(SocialConnection $connection, string $recipientId, string $text): string
    {
        $credentials = $connection->credentials;
        $token = is_array($credentials) ? ($credentials['access_token'] ?? null) : null;

        if (! is_string($token) || $token === '') {
            throw new RuntimeException(
                'That account is connected but has no usable token, so nothing was sent. '
                .'Reconnecting it from Settings will fix that.'
            );
        }

        try {
            $response = Http::withToken($token)
                ->timeout(self::TIMEOUT)
                ->post(
                    // Instagram Login tokens are only accepted on Instagram's
                    // own host; a Page token only on Facebook's.
                    ($connection->provider === SocialProvider::InstagramProfessional->value
                        ? 'https://graph.instagram.com/v21.0'
                        : WhatsAppCloud::endpoint()).'/'.$connection->remote_id.'/messages',
                    [
                        'recipient' => ['id' => $recipientId],
                        'message' => ['text' => $text],
                        /*
                         * RESPONSE, not the default.
                         *
                         * Meta requires a messaging type, and the default
                         * `UPDATE` is for a business-initiated notification -
                         * which is refused outside the 24-hour window and
                         * needs a tag we do not hold. Every send from here is
                         * somebody answering a message a person sent, which is
                         * exactly what RESPONSE means.
                         */
                        'messaging_type' => 'RESPONSE',
                    ],
                );
        } catch (ConnectionException) {
            throw new RuntimeException('Meta did not answer, so the reply was not sent.');
        }

        if ($response->failed()) {
            throw new RuntimeException((string) ($response->json('error.error_user_msg')
                ?? $response->json('error.message')
                ?? 'Meta refused the reply (HTTP '.$response->status().').'));
        }

        return (string) ($response->json('message_id') ?? '');
    }
}
