<?php

namespace App\Support\Reviews;

use App\Models\Automation;
use App\Models\User;

/**
 * The switch is the automation (Jose, 2026-11-02): "if i turn it on, it
 * should just start working... it shouldn't require a separate automation
 * being built". Turning on a listing's automatic replies makes this rule if
 * the organization has none that publishes replies, so a new review on that
 * listing is answered with no other step. It shows in Automations like any
 * rule; switching it off there stops every listing's replies at once, and its
 * words can be edited there.
 *
 * The rule runs as the person who first switched a listing on, who holds
 * Reviews' publish grant (BusinessProfileAutoReplyController checks it), so
 * publishReviewReply's "the author could do it by hand" holds. Its other
 * guards stay: off listings, a reply or draft already there, older than 30
 * days.
 */
final class ReviewAutoReplyRule
{
    public const NAME = 'Answer Google reviews automatically';

    /** Words per star rating, editable on the rule. */
    public const REPLIES = [
        '5' => 'Thank you so much for the 5-star review! We are glad we could help, and we look forward to serving you again.',
        '4' => 'Thank you for the great review! We appreciate you choosing us and hope to see you again.',
        '3' => 'Thank you for your feedback. We are always working to do better, and we would love to hear how we can improve. Please give us a call.',
        '2' => 'We are sorry your experience was not what you expected. We would like to make it right, please call our office so we can talk.',
        '1' => 'We are sorry to hear about your experience. Please call our office so we can understand what happened and make it right.',
    ];

    /** The organization's rule that publishes review replies, made now if it has none. */
    public static function ensure(User $by): Automation
    {
        $existing = Automation::query()->where('trigger_key', 'review.received')->with('activeVersion')->get()
            ->first(fn (Automation $a) => collect((array) $a->activeVersion?->actions)
                ->contains(fn ($step) => ($step['type'] ?? null) === 'publish_reply'));
        if ($existing !== null) {
            return $existing;
        }

        $rule = Automation::create([
            'organization_id' => $by->organization_id,
            'name' => self::NAME,
            'description' => 'Posts a reply on Google to each new review, on the listings where automatic replies '
                .'are switched on under Business Profile. Switch this off to stop all of them. Reviews that '
                .'already have a reply, and reviews older than 30 days, are left alone.',
            'trigger_key' => 'review.received',
            'is_active' => true,
            'created_by' => $by->getKey(),
            // An hour after the review is found, so a reply from anywhere else lands first and is respected.
            'delay_minutes' => 60,
        ]);
        // Flat keys: the builder edits and keeps by_rating_5..by_rating_1 (Automations, 2026-11-02).
        $step = ['type' => 'publish_reply'];
        foreach (self::REPLIES as $stars => $words) {
            $step['by_rating_'.$stars] = $words;
        }
        $rule->addVersion(null, [$step], $by);

        return $rule;
    }
}
