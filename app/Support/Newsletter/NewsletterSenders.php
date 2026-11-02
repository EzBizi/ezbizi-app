<?php

namespace App\Support\Newsletter;

use App\Models\Newsletter;
use App\Models\NewsletterUnsubscribe;
use App\Models\SocialConnection;
use App\Support\Social\SocialProvider;
use Illuminate\Support\Collection;

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
 * **Read this as being about NEWSLETTERS only.** EzBizi's own SES does now
 * carry tenant-authored automated mail — Jose asked for the `Send an email`
 * automation action on 2026-09-16, "the Ezbizi SES one, not the company one",
 * and it is live. Nothing below reverses that or applies to it. The two are
 * different questions: an automation email is one message to one recipient
 * taken from the event, and a newsletter is a list send whose bounce and
 * complaint rate sets a domain's reputation.
 *
 * Because EzBizi does not send newsletters, by design rather than by omission:
 * a mailing goes out on the tenant's own reputation, to their list, through
 * their provider. See the design doc §1.
 *
 * **The SES condition this paragraph used to wait on has now happened, and
 * nothing here changed.** Production access was granted 2026-09-14 — 50,000 a
 * day, 14 a second, sandbox gone. The sentence used to read "when SES leaves
 * the sandbox and that decision is revisited", which by today reads as a wait
 * that is over while the code sits exactly where it was, and that is how a
 * reader concludes somebody forgot.
 *
 * Nobody forgot. The sandbox was never the reason — it was a second reason.
 * The first one is unchanged: one tenant's list hygiene would set the sending
 * reputation for every other tenant on the same domain, and 50,000 a day is
 * ONE account's budget shared across all of them, which a single large list
 * would spend by itself. Bounce handling, complaint feedback and unsubscribe
 * enforcement are what the tenant's provider is doing for them today and what
 * EzBizi would have to build to replace it.
 *
 * **Asked and answered: no.** Jose, 2026-09-14, on the recommendation above —
 * EzBizi does not send newsletters itself, and SES being out of the sandbox
 * does not change that. A tenant sends through their own provider.
 *
 * So this is a settled decision and not a pending one. If it is ever reopened
 * it becomes another arm of this match — still not a rewrite — but do not
 * reopen it on the strength of SES capacity alone, because capacity was never
 * the argument.
 */
final class NewsletterSenders
{
    public static function for(?SocialProvider $provider): ?MailchimpSender
    {
        return match ($provider) {
            SocialProvider::Mailchimp => app(MailchimpSender::class),
            default => null,
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
     * @param  Collection<int, SocialConnection>  $connectionsInView
     */
    public static function refusalForView(Collection $connectionsInView): ?string
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
