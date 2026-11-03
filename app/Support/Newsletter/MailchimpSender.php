<?php

namespace App\Support\Newsletter;

use App\Models\Newsletter;
use App\Models\NewsletterAudience;
use App\Models\SocialConnection;

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
        if ($audience->kind === NewsletterAudience::KIND_CUSTOMERS && $people !== []) {
            $this->mailchimp->syncMembers($key, $listId, $people);
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

        $identity = $this->identityFor($connection);

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
        ];
    }

    /** @return array{from_name: string, reply_to: string} */
    private function identityFor(SocialConnection $connection): array
    {
        $credentials = $connection->getAttribute('credentials');
        $email = is_array($credentials) ? (string) ($credentials['account_email'] ?? '') : '';

        return [
            'from_name' => (string) ($connection->display_name ?: 'Newsletter'),
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
