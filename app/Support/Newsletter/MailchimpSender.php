<?php

namespace App\Support\Newsletter;

use App\Models\Newsletter;
use App\Models\NewsletterAudience;
use App\Models\NewsletterUnsubscribe;
use App\Models\SocialConnection;
use App\Support\Automations\EventEmitter;

/**
 * A newsletter, as a Mailchimp campaign.
 *
 * ── The two audience kinds meet here ────────────────────────────
 *
 * A `mailchimp` audience already lives in their account: name the list, send.
 * A `customers` audience lives in EzBizi, so its addresses are pushed into the
 * list first and then the campaign goes to that list. One send path either way,
 * which is what keeps "tenant's choice" from being two half-tested features.
 *
 * The push is the moment customer addresses leave EzBizi for a third party. It
 * is the tenant's own data going to the tenant's own provider at the tenant's
 * instruction, which is why it is allowed — and it is worth naming rather than
 * leaving as a side effect nobody wrote down.
 */
final class MailchimpSender
{
    public function __construct(private readonly MailchimpClient $mailchimp) {}

    public function send(
        SocialConnection $connection,
        Newsletter $newsletter,
        string $html,
        array $people,
    ): array {
        $key = $this->key($connection);
        $audience = $newsletter->audience;

        if ($audience === null) {
            throw new NewsletterSendFailed('This newsletter has no audience, so there is nobody to send it to.');
        }

        $listId = (string) ($audience->mailchimp_list_id ?? '');

        if ($listId === '') {
            throw new NewsletterSendFailed(
                'That audience has no Mailchimp list behind it. Open the audience and choose '
                .'which Mailchimp list it sends to.'
            );
        }

        // An EzBizi-held list has to reach Mailchimp before a campaign can go to
        // it. A Mailchimp-held one is already there and must not be written to.
        $refused = [];
        if ($audience->kind === NewsletterAudience::KIND_CUSTOMERS && $people !== []) {
            // Who left at Mailchimp is recorded first, so nobody with an
            // unsubscribe row is ever pushed as a new subscriber, on this list
            // or any other.
            $this->recordLeft($newsletter, $this->mailchimp->leftMembers($key, $listId));
            $gone = NewsletterSenders::unsubscribedIn($newsletter);
            $people = array_values(array_filter($people,
                fn (array $p) => ! in_array(mb_strtolower(trim($p['email'])), $gone, true)));
            $refused = $people === [] ? [] : $this->mailchimp->batchSubscribe($key, $listId, $people);
        }

        $gone = NewsletterSenders::unsubscribedIn($newsletter);
        $segmentId = null;
        $tag = $audience->isMailchimp() ? trim((string) $audience->tag) : '';
        if ($audience->kind === NewsletterAudience::KIND_CUSTOMERS || $gone !== [] || $tag !== '') {
            $emails = $tag !== ''
                ? $this->mailchimp->taggedMembers($key, $listId, $tag)
                : $this->mailchimp->subscribedMembers($key, $listId);
            if ($audience->kind === NewsletterAudience::KIND_CUSTOMERS) {
                $wanted = array_map(fn (array $p) => mb_strtolower(trim($p['email'])), $people);
                $emails = array_intersect($emails, $wanted);
            }
            $segmentId = $this->mailchimp->recipientSegment($key, $listId, array_diff($emails, $gone));
        }

        // Custom HTML also needs a working, recipient-authenticated provider footer.
        if (! preg_match('/href\s*=\s*[\x22\x27]\*\|UNSUB\|\*[\x22\x27]/i', $html)) {
            $footer = '<p style="text-align:center;font-size:12px"><a href="*|UNSUB|*">Unsubscribe from these emails</a></p>';
            $html = stripos($html, '</body>') === false ? $html.$footer : str_ireplace('</body>', $footer.'</body>', $html);
        }

        $identity = $this->identityFor($connection, $newsletter);

        $result = $this->mailchimp->sendCampaign(
            $key,
            $listId,
            (string) $newsletter->subject,
            $identity['from_name'],
            $identity['reply_to'],
            $html,
            $segmentId,
        );

        return [
            'remote_id' => $result['id'],
            // Mailchimp counts the list; our own count is what we pushed, and
            // the two can differ legitimately (somebody already unsubscribed at
            // their end). Theirs is the number that describes what was sent.
            'recipients' => $result['recipients'],
            'refused' => count($refused),
        ];
    }

    /**
     * One test email to one address. Nobody on the list receives it, and
     * nothing is pushed to the list.
     */
    public function sendTest(SocialConnection $connection, Newsletter $newsletter, string $html, string $email): void
    {
        $listId = (string) ($newsletter->audience?->mailchimp_list_id ?? '');

        if ($listId === '') {
            throw new NewsletterSendFailed(
                'Mailchimp needs a list to test against. Choose an audience with a Mailchimp list first.'
            );
        }

        $identity = $this->identityFor($connection, $newsletter);
        $this->mailchimp->sendTest(
            $this->key($connection),
            $listId,
            (string) $newsletter->subject,
            $identity['from_name'],
            $identity['reply_to'],
            $html,
            [$email],
        );
    }

    /** The name a send from this connection would carry. */
    public function fromName(SocialConnection $connection, Newsletter $newsletter): string
    {
        return $this->identityFor($connection, $newsletter)['from_name'];
    }

    /**
     * The address a send carries. Mailchimp's campaign settings have one
     * `reply_to`, which is also the From address; there is no separate field.
     */
    public function fromAddress(SocialConnection $connection, Newsletter $newsletter): string
    {
        return $this->identityFor($connection, $newsletter)['reply_to'];
    }

    /**
     * Opt-outs Mailchimp holds become EzBizi's own rows at the newsletter's
     * scope, and each new one raises `newsletter.unsubscribed`.
     *
     * @param  list<string>  $emails
     */
    private function recordLeft(Newsletter $newsletter, array $emails): void
    {
        foreach ($emails as $email) {
            $row = NewsletterUnsubscribe::query()->firstOrCreate(
                [
                    'organization_id' => $newsletter->organization_id,
                    'scope_type' => $newsletter->scope_type,
                    'scope_id' => $newsletter->scope_id,
                    'email' => $email,
                ],
                ['unsubscribed_at' => now()],
            );
            if ($row->wasRecentlyCreated) {
                app(EventEmitter::class)->emit('newsletter.unsubscribed', $newsletter, [
                    'email' => $email,
                    'subject' => $newsletter->subject,
                    'audience' => $newsletter->audience?->name,
                ]);
            }
        }
    }

    /** @return array{from_name: string, reply_to: string} */
    private function identityFor(SocialConnection $connection, Newsletter $newsletter): array
    {
        $credentials = $connection->getAttribute('credentials');
        $email = is_array($credentials) ? (string) ($credentials['account_email'] ?? '') : '';
        // The company the mailing is from, not whoever owns the Mailchimp login.
        $company = NewsletterIdentity::footer($newsletter)['company'];

        return [
            'from_name' => $company !== '' ? $company : (string) ($connection->display_name ?: 'Newsletter'),
            // Mailchimp requires a reply-to it recognises for the account, so
            // the address the key belongs to is the only one certain to work.
            // A tenant wanting a different one changes it in Mailchimp, where
            // the verification that makes it legitimate also lives.
            'reply_to' => $email,
        ];
    }

    private function key(SocialConnection $connection): string
    {
        $credentials = $connection->getAttribute('credentials');
        $key = is_array($credentials) ? ($credentials['api_key'] ?? null) : null;

        if (! is_string($key) || $key === '') {
            throw new NewsletterSendFailed(
                'That Mailchimp connection has no usable key any more. Reconnect it.'
            );
        }

        return $key;
    }
}
