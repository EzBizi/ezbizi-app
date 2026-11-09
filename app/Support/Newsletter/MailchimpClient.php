<?php

namespace App\Support\Newsletter;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Mailchimp, spoken to with the tenant's own API key.
 *
 * ── Why a key and not OAuth ─────────────────────────────────────
 *
 * OAuth would need EzBizi to register an app with Mailchimp and every tenant to
 * pass through a consent screen we would have to host a callback for. A key is
 * something the company already has, pastes once, and can revoke at Mailchimp
 * without asking us — which is the same bargain Bluesky's app password makes,
 * and this reuses that path rather than inventing a second Connect shape.
 *
 * It is a swap, not a fork: OAuth later writes different values into the same
 * connection row, and nothing above this class learns about it.
 *
 * ── Nothing here knows about HTTP status codes ──────────────────
 *
 * Every failure is a `NewsletterSendFailed` carrying a sentence. Turning that
 * into a 422 at connect time or a red row at send time is the caller's job,
 * because the same refusal means different things in those two places and this
 * class has no way to tell which it is in.
 *
 * ── The datacentre lives in the key ─────────────────────────────
 *
 * A Mailchimp key ends `-us14`, and that suffix names the API host. So the key
 * is sufficient to reach the account, and a key without one cannot be used at
 * all — which is worth refusing loudly at connect time rather than discovering
 * as a DNS failure on the morning somebody sends to twelve hundred people.
 */
class MailchimpClient
{
    private const TIMEOUT = 30;

    /** @return array{account_name: string, email: string, dc: string, total_subscribers: int} */
    public function verify(string $apiKey): array
    {
        $body = $this->call($apiKey, 'get', '/');

        return [
            'account_name' => (string) ($body['account_name'] ?? 'Mailchimp'),
            'email' => (string) ($body['email'] ?? ''),
            'dc' => $this->datacentre($apiKey),
            'total_subscribers' => (int) ($body['total_subscribers'] ?? 0),
        ];
    }

    /**
     * The audiences this account holds.
     *
     * @return array<int, array{id: string, name: string, members: int}>
     */
    public function lists(string $apiKey): array
    {
        $body = $this->call($apiKey, 'get', '/lists', ['count' => 100, 'fields' => 'lists.id,lists.name,lists.stats.member_count']);

        return array_map(fn (array $l) => [
            'id' => (string) ($l['id'] ?? ''),
            'name' => (string) ($l['name'] ?? 'Untitled audience'),
            'members' => (int) ($l['stats']['member_count'] ?? 0),
        ], (array) ($body['lists'] ?? []));
    }

    /**
     * Put addresses into one of the account's audiences.
     *
     * Only new members receive a subscription status. Existing provider opt-outs
     * must survive an address refresh. Mailchimp's PUT member endpoint defines
     * status_if_new for exactly this distinction; status would resubscribe them.
     * https://mailchimp.com/developer/marketing/api/list-members/add-or-update-list-member/
     *
     * @param  array<int, array{email: string, first_name?: string, last_name?: string}>  $people
     * @return int how many were accepted
     */
    public function syncMembers(string $apiKey, string $listId, array $people): int
    {
        if ($people === []) {
            return 0;
        }

        $accepted = 0;
        foreach ($people as $p) {
            $email = mb_strtolower(trim($p['email']));
            $body = $this->call($apiKey, 'put', '/lists/'.rawurlencode($listId).'/members/'.md5($email), [
                'email_address' => $email,
                // Mailchimp PUT: existing subscription status remains untouched.
                'status_if_new' => 'subscribed',
                'merge_fields' => array_filter([
                    'FNAME' => $p['first_name'] ?? null,
                    'LNAME' => $p['last_name'] ?? null,
                ], fn ($v) => $v !== null && $v !== ''),
            ]);
            $accepted += ($body['status'] ?? null) === 'subscribed' ? 1 : 0;
        }

        return $accepted;
    }

    /**
     * Put a newsletter's customers into one of the account's audiences, 500 per call.
     *
     * Batch subscribe (`POST /lists/{id}`) rather than one PUT per person: a
     * PUT each took a 1,200-customer list past the worker's timeout. The
     * subscription status applies to NEW members only: `update_existing` is
     * false, so a member who unsubscribed or was cleaned at Mailchimp keeps that
     * status. Mailchimp reports those as "already a member", which is not a
     * refusal.
     * https://mailchimp.com/developer/marketing/api/lists/batch-subscribe-or-unsubscribe/
     *
     * One bad address is reported per member and skipped; it does not stop the
     * rest of the list.
     *
     * @param  array<int, array{email: string, first_name?: string, last_name?: string}>  $people
     * @return list<string> the addresses Mailchimp refused, lowercased
     */
    public function batchSubscribe(string $apiKey, string $listId, array $people): array
    {
        $refused = [];
        foreach (array_chunk($people, 500) as $chunk) {
            $body = $this->call($apiKey, 'post', '/lists/'.rawurlencode($listId), [
                'update_existing' => false,
                'members' => array_map(fn (array $p) => [
                    'email_address' => mb_strtolower(trim($p['email'])),
                    'status' => 'subscribed',
                    'merge_fields' => (object) array_filter([
                        'FNAME' => $p['first_name'] ?? null,
                        'LNAME' => $p['last_name'] ?? null,
                    ], fn ($v) => $v !== null && $v !== ''),
                ], $chunk),
            ]);
            foreach ((array) ($body['errors'] ?? []) as $error) {
                if (($error['error_code'] ?? '') === 'ERROR_CONTACT_EXISTS') {
                    continue;
                }
                $refused[] = mb_strtolower(trim((string) ($error['email_address'] ?? '')));
            }
        }

        return array_values(array_unique(array_filter($refused)));
    }

    /**
     * Addresses that left this list at Mailchimp: unsubscribed or cleaned.
     *
     * Only members Mailchimp itself reports in that status are returned.
     *
     * @return list<string> lowercased
     */
    public function leftMembers(string $apiKey, string $listId): array
    {
        $emails = [];
        foreach (['unsubscribed', 'cleaned'] as $status) {
            $offset = 0;
            do {
                $body = $this->call($apiKey, 'get', '/lists/'.rawurlencode($listId).'/members', [
                    'status' => $status, 'count' => 1000, 'offset' => $offset,
                    'fields' => 'members.email_address,members.status,total_items',
                ]);
                $members = $body['members'] ?? [];
                if (! is_array($members) || ! isset($body['total_items'])) {
                    throw new NewsletterSendFailed('Mailchimp did not return who unsubscribed. Nothing was sent.');
                }
                foreach ($members as $member) {
                    $email = mb_strtolower(trim((string) ($member['email_address'] ?? '')));
                    if (($member['status'] ?? '') === $status && $email !== '') {
                        $emails[] = $email;
                    }
                }
                $offset += count($members);
            } while ($members !== [] && $offset < (int) $body['total_items']);
        }

        return array_values(array_unique($emails));
    }

    /** @return list<string> Current subscribed addresses, all pages; other statuses stay excluded. */
    public function subscribedMembers(string $apiKey, string $listId): array
    {
        $emails = [];
        $offset = 0;
        do {
            $body = $this->call($apiKey, 'get', '/lists/'.rawurlencode($listId).'/members', [
                'status' => 'subscribed', 'count' => 1000, 'offset' => $offset,
                'fields' => 'members.email_address,total_items',
            ]);
            $members = $body['members'] ?? [];
            if (! is_array($members) || ! isset($body['total_items'])) {
                throw new NewsletterSendFailed('Mailchimp did not return the recipient list. Nothing was sent.');
            }
            foreach ($members as $member) {
                $email = mb_strtolower(trim((string) ($member['email_address'] ?? '')));
                if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $emails[] = $email;
                }
            }
            $offset += count($members);
            if ($members === [] && $offset < (int) $body['total_items']) {
                throw new NewsletterSendFailed('Mailchimp returned an incomplete recipient list. Nothing was sent.');
            }
        } while ($offset < (int) $body['total_items']);

        return array_values(array_unique($emails));
    }

    /** A separate immutable segment per send prevents simultaneous mailings changing each other's recipients. */
    /**
     * Subscribed addresses carrying one Mailchimp tag. Tags are static
     * segments at Mailchimp, so the tag is found by name among them.
     *
     * @return array<int, string>
     */
    public function taggedMembers(string $apiKey, string $listId, string $tag): array
    {
        $segments = $this->call($apiKey, 'get', '/lists/'.rawurlencode($listId).'/segments', [
            'type' => 'static', 'count' => 1000, 'fields' => 'segments.id,segments.name',
        ])['segments'] ?? [];
        $found = collect(is_array($segments) ? $segments : [])
            ->first(fn ($s) => mb_strtolower(trim((string) ($s['name'] ?? ''))) === mb_strtolower($tag));
        if ($found === null) {
            throw new NewsletterSendFailed('Mailchimp has no tag called "'.$tag.'" on that list. Nothing was sent.');
        }

        $emails = [];
        $offset = 0;
        do {
            $body = $this->call($apiKey, 'get', '/lists/'.rawurlencode($listId).'/segments/'.(int) $found['id'].'/members', [
                'count' => 1000, 'offset' => $offset,
                'fields' => 'members.email_address,members.status,total_items',
            ]);
            $members = $body['members'] ?? [];
            if (! is_array($members) || ! isset($body['total_items'])) {
                throw new NewsletterSendFailed('Mailchimp did not return the tagged members. Nothing was sent.');
            }
            foreach ($members as $member) {
                $email = mb_strtolower(trim((string) ($member['email_address'] ?? '')));
                if (($member['status'] ?? '') === 'subscribed' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $emails[] = $email;
                }
            }
            $offset += count($members);
        } while ($members !== [] && $offset < (int) $body['total_items']);

        return array_values(array_unique($emails));
    }

    public function recipientSegment(string $apiKey, string $listId, array $emails): int
    {
        if ($emails === []) {
            throw new NewsletterSendFailed('Nobody in this audience is currently subscribed. Nothing was sent.');
        }
        $chunks = array_chunk(array_values(array_unique($emails)), 500);
        $body = $this->call($apiKey, 'post', '/lists/'.rawurlencode($listId).'/segments', [
            'name' => 'EzBizi '.Str::uuid(),
            'static_segment' => array_shift($chunks),
        ]);
        $id = (int) ($body['id'] ?? 0);
        if ($id <= 0) {
            throw new NewsletterSendFailed('Mailchimp did not create the recipient segment. Nothing was sent.');
        }
        // Official Marketing API: batchSegmentMembers allows at most 500 addresses.
        foreach ($chunks as $chunk) {
            $added = $this->call($apiKey, 'post', '/lists/'.rawurlencode($listId).'/segments/'.$id, [
                'members_to_add' => $chunk,
            ]);
            if (! empty($added['errors']) || (int) ($added['total_added'] ?? -1) !== count($chunk)) {
                throw new NewsletterSendFailed('Mailchimp did not accept the complete recipient segment. Nothing was sent.');
            }
        }

        return $id;
    }

    /**
     * Create a campaign, put the HTML in it, and send it.
     *
     * Three calls because Mailchimp models it as three things, and collapsing
     * them would mean pretending a half-created campaign cannot happen. If
     * content or send fails, the campaign exists as a draft in the tenant's
     * account — which is the recoverable end of the failure, and the sentence
     * says so rather than leaving them hunting.
     *
     * @return array{id: string, recipients: int}
     */
    public function sendCampaign(
        string $apiKey,
        string $listId,
        string $subject,
        string $fromName,
        string $replyTo,
        string $html,
        ?int $segmentId = null,
    ): array {
        $campaign = $this->call($apiKey, 'post', '/campaigns', [
            'type' => 'regular',
            'recipients' => $segmentId === null ? ['list_id' => $listId] : [
                'list_id' => $listId, 'segment_opts' => ['saved_segment_id' => $segmentId],
            ],
            'settings' => [
                'subject_line' => $subject,
                'title' => $subject,
                'from_name' => $fromName,
                'reply_to' => $replyTo,
            ],
        ]);

        $id = (string) ($campaign['id'] ?? '');

        if ($id === '') {
            throw new NewsletterSendFailed('Mailchimp created no campaign and said nothing about why.');
        }

        $recipients = (int) ($campaign['recipients']['recipient_count'] ?? 0);

        $this->call($apiKey, 'put', "/campaigns/{$id}/content", ['html' => $html]);

        /*
         * `actions/send` answers 204 with no body on success. Anything else is
         * a refusal, and Mailchimp's own `detail` is worth far more than a
         * status code — "You must have a From email address" is actionable in a
         * way that "HTTP 400" is not.
         */
        $this->call($apiKey, 'post', "/campaigns/{$id}/actions/send", []);

        return ['id' => $id, 'recipients' => $recipients];
    }

    /**
     * The host for this key, or a refusal naming what is wrong with it.
     */
    public function datacentre(string $apiKey): string
    {
        $parts = explode('-', trim($apiKey));
        $dc = count($parts) > 1 ? end($parts) : '';

        if ($dc === '' || ! preg_match('/^[a-z]{2}[0-9]{1,3}$/', $dc)) {
            throw new NewsletterSendFailed(
                'That does not look like a Mailchimp API key. A key ends with its '
                .'datacentre, like “-us14”, and without it EzBizi cannot tell which '
                .'Mailchimp to talk to.'
            );
        }

        return $dc;
    }

    /** @return array<string, mixed> */
    private function call(string $apiKey, string $method, string $path, array $payload = []): array
    {
        $url = 'https://'.$this->datacentre($apiKey).'.api.mailchimp.com/3.0'.rtrim($path, '/');

        try {
            // Basic auth with any username — Mailchimp's documented shape for a
            // key. `anystring` is theirs, not a placeholder left in by mistake.
            $request = Http::withBasicAuth('anystring', $apiKey)
                ->timeout(self::TIMEOUT)
                ->acceptJson();

            $response = match ($method) {
                'get' => $request->get($url, $payload),
                'put' => $request->put($url, $payload),
                default => $request->post($url, $payload),
            };
        } catch (ConnectionException) {
            throw new NewsletterSendFailed('Mailchimp did not confirm the request outcome. Check the account before sending again.');
        }

        if ($response->status() === 401) {
            throw new NewsletterSendFailed(
                'Mailchimp did not accept that key. Check it was copied whole, and that it '
                .'has not been revoked in your Mailchimp account.'
            );
        }

        if ($response->failed()) {
            throw new NewsletterSendFailed('Mailchimp refused it: '.(string) (
                $response->json('detail') ?? $response->json('title') ?? 'HTTP '.$response->status()
            ));
        }

        return (array) ($response->json() ?? []);
    }
}
