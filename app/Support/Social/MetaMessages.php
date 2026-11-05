<?php

namespace App\Support\Social;

use App\Http\Controllers\Api\MetaMediaController;
use App\Models\Lead;
use App\Models\LeadConversation;
use App\Models\SocialConnection;
use App\Support\Automations\EventEmitter;
use App\Support\Leads\LeadReferral;
use App\Support\Leads\LeadThread;
use App\Support\Notifications\LeadNotificationText;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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

        // The ad they came from, on the first message that carries it.
        if ($lead->referral === null && ($referral = self::referral($event, $source)) !== null) {
            $lead->forceFill(['referral' => $referral])->save();
        }

        // Instagram gives a @handle and a picture; Messenger's PSID profile is
        // refused without Business Asset User Profile Access (see nameOf).
        if ($lead->handle === null && $connection->provider === SocialProvider::InstagramProfessional->value) {
            $profile = $this->instagramProfile($connection, $senderId);
            $lead->forceFill(array_filter([
                'handle' => isset($profile['username']) ? '@'.$profile['username'] : null,
                'avatar_url' => $profile['profile_pic'] ?? null,
            ]))->save();
        }

        // Meta's timestamp is milliseconds since the epoch. Read as seconds it
        // lands in the year 57000 and the thread sorts wrongly forever.
        $at = isset($event['timestamp'])
            ? Carbon::createFromTimestampMs((int) $event['timestamp'])
            : now();

        $stored = $this->thread->store($lead, $mid, [
            'channel' => LeadConversation::CHANNEL_MESSAGE,
            'participant' => LeadConversation::FROM_CONSUMER,
            'occurred_at' => $at,
            // A photo alone keeps a null body: an empty string would read as
            // somebody having sent nothing.
            'text' => isset($message['text']) && $message['text'] !== '' ? (string) $message['text'] : null,
            // Streamed from Meta on demand (MetaMediaController), never stored.
            'attachments' => MetaMediaController::refs($connection, $mid, $message),
            'is_ad_question' => self::adQuestion($event),
        ]);

        // Or the list keeps sorting her by her first message (Jose, 11-04).
        $this->thread->refresh($lead);

        return $stored;
    }

    /**
     * The phone a lead-ad form put in the chat's first message ("Phone number:
     * (973) 678-2560"), as its last ten digits, or null.
     *
     * @param  array<string, mixed>  $event
     */
    public static function formPhone(array $event): ?string
    {
        $text = (string) ($event['message']['text'] ?? '');

        // The last ten digits: the form stores "+1973...", the chat says "(973) ...".
        return preg_match('/^\s*Phone number:\s*(.+)$/mi', $text, $m) === 1 && strlen((string) Lead::digits($m[1])) >= 10
            ? substr((string) Lead::digits($m[1]), -10) : null;
    }

    /**
     * A question the customer TAPPED from a Click-to-Message ad (A-1,
     * 2026-10-29). Meta sends it as their message: the first carries the ad's
     * `referral`, a suggested question carries a `quick_reply` payload.
     *
     * @param  array<string, mixed>  $event
     */
    public static function adQuestion(array $event): bool
    {
        $message = (array) ($event['message'] ?? []);

        return isset($message['quick_reply']) || isset($message['referral'])
            || (($event['referral']['source'] ?? null) === 'ADS');
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

        /*
         * ONE PERSON, ONE LEAD (A-1, 2026-10-29). A lead ad that opens
         * Messenger delivers twice: the form (MetaLeadForms, no sender id) and
         * then this chat, whose first message repeats the answers. The form's
         * lead becomes this chat's lead, so replies go through Messenger.
         */
        $digits = self::formPhone($event);
        $form = $digits === null ? null : Lead::query()->withoutGlobalScopes()
            ->where('organization_id', $connection->organization_id)
            // Source, not connection: an Instagram form arrives through the
            // Page, its DM through the Instagram account (A-1, "Pritesh").
            ->where('source', $source)
            ->where('lead_type', LeadNotificationText::META_FORM)
            ->where('phone_digits', 'like', '%'.$digits)
            ->where('created_at', '>=', now()->subDay())
            ->latest()->first();

        if ($form !== null) {
            $form->forceFill([
                'lead_type' => LeadNotificationText::META_TEXT,
                'remote_id' => $senderId,
                'contact_name' => $this->nameOf($connection, $senderId) ?? $form->contact_name,
            ])->save();

            return $form;
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
     * Messages a Page or Instagram account received while EzBizi was not
     * listening - the gap a disconnect leaves, or a webhook Meta never
     * delivered - replayed through absorb() from Meta's own conversation
     * history (Jose, 2026-11-05: 37 Messenger enquiries missed while the
     * A1 Chimney Page was disconnected; "recovery should be automatic").
     *
     * Only customer messages newer than $since and not already stored: a
     * stored message is skipped before absorb(), whose store would overwrite
     * its attachments with this text-only copy. Inside the tenant.
     */
    public function catchUp(SocialConnection $connection, Carbon $since): int
    {
        $instagram = $connection->provider === SocialProvider::InstagramProfessional->value;
        $token = $connection->credentials['access_token'] ?? null;
        $source = $this->sourceFor($instagram ? 'instagram' : 'page');

        if (! is_string($token) || $token === '' || $source === null) {
            return 0;
        }

        $url = $instagram
            ? 'https://graph.instagram.com/v21.0/me/conversations'
            : WhatsAppCloud::endpoint().'/'.$connection->remote_id.'/conversations';
        $query = [
            'platform' => $instagram ? 'instagram' : 'messenger',
            'fields' => 'updated_time,messages.limit(25){id,from,message,created_time}',
            'limit' => 50,
            'access_token' => $token,
        ];
        $stored = 0;

        // ponytail: 10 pages of 50 conversations, newest first; a gap longer than that needs a wider sweep.
        for ($page = 0; $page < 10 && is_string($url); $page++) {
            // Meta's next link carries every parameter; a query array would replace them.
            $response = $query === [] ? Http::timeout(self::TIMEOUT)->get($url) : Http::timeout(self::TIMEOUT)->get($url, $query);

            if ($response->failed()) {
                Log::warning('Meta conversation catch-up refused.', [
                    'connection_id' => $connection->getKey(),
                    'reason' => $response->json('error.message'),
                ]);
                break;
            }

            $query = [];
            $url = $response->json('paging.next');

            foreach ((array) $response->json('data', []) as $conversation) {
                // Newest first: the first one older than $since ends the sweep.
                if (isset($conversation['updated_time']) && Carbon::parse($conversation['updated_time'])->lt($since)) {
                    $url = null;
                    break;
                }

                foreach (array_reverse((array) ($conversation['messages']['data'] ?? [])) as $message) {
                    $from = (string) ($message['from']['id'] ?? '');
                    $mid = (string) ($message['id'] ?? '');

                    if ($from === '' || $from === (string) $connection->remote_id || $mid === ''
                        || ! isset($message['created_time']) || Carbon::parse($message['created_time'])->lt($since)
                        || LeadConversation::query()->where('remote_id', $mid)->exists()) {
                        continue;
                    }

                    $stored += (int) $this->absorb([
                        'sender' => ['id' => $from],
                        'recipient' => ['id' => $connection->remote_id],
                        'timestamp' => Carbon::parse($message['created_time'])->getTimestampMs(),
                        'message' => ['mid' => $mid, 'text' => (string) ($message['message'] ?? '')],
                    ], $connection, $source);
                }
            }
        }

        return $stored;
    }

    /**
     * A Click-to-Messenger / Instagram ad's context as a lead referral, or null.
     *
     * @param  array<string, mixed>  $event
     */
    public static function referral(array $event, string $source): ?array
    {
        $r = (array) ($event['referral'] ?? $event['message']['referral'] ?? []);
        if (($r['source'] ?? null) !== 'ADS') {
            return null;
        }
        $ad = (array) ($r['ads_context_data'] ?? []);

        return LeadReferral::clean([
            'source' => $source === Lead::SOURCE_INSTAGRAM ? 'Instagram ad' : 'Facebook ad',
            'ad_name' => $ad['ad_title'] ?? ($r['ad_id'] ?? null),
            'headline' => $ad['ad_title'] ?? null,
            'page_url' => $ad['photo_url'] ?? ($ad['video_url'] ?? null),
        ]);
    }

    /** @return array{username?: string, profile_pic?: string} */
    public function instagramProfile(SocialConnection $connection, string $senderId): array
    {
        $token = $connection->credentials['access_token'] ?? null;
        if (! is_string($token) || $token === '') {
            return [];
        }
        try {
            $p = (array) Http::timeout(self::TIMEOUT)->get('https://graph.instagram.com/v21.0/'.$senderId,
                ['fields' => 'username,profile_pic', 'access_token' => $token])->json();
        } catch (ConnectionException) {
            return [];
        }

        return array_filter(['username' => $p['username'] ?? null, 'profile_pic' => $p['profile_pic'] ?? null],
            fn ($v) => is_string($v) && $v !== '');
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
            // The screen gets one sentence; this keeps Meta's code, so a 502 on
            // POST leads/{id}/messages can be told apart (11-04: eight, unread).
            Log::warning('Meta refused a reply.', ['status' => $response->status(),
                'provider' => $connection->provider] + array_intersect_key((array) $response->json('error'),
                    array_flip(['code', 'error_subcode', 'type', 'message', 'error_user_msg'])));

            // 551/1545041: blocked the Page or left; 100/2018001: no such user.
            // Permanent, so the screen says so with a 422 rather than a 502
            // (health sweep, 2026-11-05: 7 of 8 refusals were these).
            $code = [(int) $response->json('error.code'), (int) $response->json('error.error_subcode')];
            if ($code[0] === 551 || $code === [100, 2018001]) {
                throw new RuntimeException('This person cannot receive messages from this account any more, so the '
                    .'reply was not sent. Call or text them instead.', 422);
            }

            throw new RuntimeException((string) ($response->json('error.error_user_msg')
                ?? $response->json('error.message')
                ?? 'Meta refused the reply (HTTP '.$response->status().').'));
        }

        return (string) ($response->json('message_id') ?? '');
    }
}
