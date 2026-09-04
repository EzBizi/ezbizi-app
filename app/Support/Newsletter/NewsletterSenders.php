<?php

namespace App\Support\Newsletter;

use App\Models\Newsletter;
use App\Models\NewsletterUnsubscribe;
use App\Models\SocialConnection;
use App\Support\Social\SocialProvider;

/**
 * Which providers a newsletter can go out through, and why one cannot.
 *
 * Deliberately the same shape as `Publishers` in the social half: one place
 * that knows the map, a sentence for every network that is connected but not
 * yet sendable, and callers that ask rather than hold their own copy. The
 * lesson that shape came from is written up there — a client that derives
 * sendability from a provider key is a second copy that drifts into a wrong row
 * on a customer's screen with nothing failing.
 *
 * ── Why "EzBizi sends it" is not in this map ────────────────────
 *
 * Because EzBizi does not send newsletters, by design rather than by omission:
 * a mailing goes out on the tenant's own reputation, to their list, through
 * their provider. See the design doc §1. When SES leaves the sandbox and that
 * decision is revisited, it becomes another arm of this match — not a rewrite.
 */
final class NewsletterSenders
{
    public static function for(?SocialProvider $provider): ?NewsletterSender
    {
        return match ($provider) {
            SocialProvider::Mailchimp => app(MailchimpSender::class),
            default                   => null,
        };
    }

    /**
     * Why this connection cannot carry a newsletter, or null when it can.
     *
     * The sentence is the server's. A screen that wrote its own would be a
     * second list of which providers work.
     */
    public static function refusalFor(?SocialProvider $provider): ?string
    {
        if (self::for($provider) !== null) {
            return null;
        }

        return $provider === null
            ? 'EzBizi does not recognise that account, so it cannot send through it.'
            : 'EzBizi cannot send a newsletter through '.$provider->label().' yet.';
    }

    /**
     * The one thing every caller wants: can this view send at all, and if not,
     * what is the sentence?
     *
     * Answered from the connections the person can actually see, so a company
     * with no Mailchimp of its own is told that plainly rather than being shown
     * a sibling's.
     *
     * @param  \Illuminate\Support\Collection<int, SocialConnection>  $connectionsInView
     */
    public static function refusalForView(\Illuminate\Support\Collection $connectionsInView): ?string
    {
        $sendable = $connectionsInView->first(
            fn (SocialConnection $c) => self::for($c->providerEnum()) !== null
        );

        if ($sendable !== null) {
            return null;
        }

        return 'No mailing account is connected here yet. Connect Mailchimp — in Settings › '
            .'Integrations, or from Newsletter — and this will send through it. Writing and '
            .'saving a newsletter works without one.';
    }

    /**
     * Addresses that must not be mailed in this scope.
     *
     * Read at SEND time rather than when the audience is built, because the
     * whole point of an unsubscribe is that it takes effect on the next send —
     * a list assembled yesterday must not carry somebody who left this morning.
     *
     * @return array<int, string> lowercased
     */
    public static function unsubscribedIn(Newsletter $newsletter): array
    {
        return NewsletterUnsubscribe::query()
            ->where('scope_type', $newsletter->scope_type)
            ->where('scope_id', $newsletter->scope_id)
            ->pluck('email')
            ->map(fn (string $e) => mb_strtolower($e))
            ->all();
    }
}
