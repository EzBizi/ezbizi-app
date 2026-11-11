<?php

namespace App\Support\Leads;

use App\Models\Lead;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Carbon;

/**
 * The reply channels the application has, keyed by lead source.
 *
 * Collected from the container tag `lead-reply-channels`. Google's is tagged
 * in AppServiceProvider; Web Management tags its own for website chat in its
 * provider, and nothing here changes when it does.
 */
final class LeadReplies
{
    public const TAG = 'lead-reply-channels';

    public function __construct(private readonly Container $container) {}

    public function for(Lead $lead): ?LeadReplyChannel
    {
        return $this->channels()[$lead->source] ?? null;
    }

    /** False when no channel exists for the source, or that channel says no. */
    public function canReply(Lead $lead): bool
    {
        return $this->for($lead)?->canReply($lead) ?? false;
    }

    /**
     * May a rule send on this lead's channel?
     *
     * Asked separately from `canReply()` on purpose: a Google enquiry can be
     * replied to perfectly well, and must not be replied to by software. One
     * method answering both would let a caller test the wrong thing and get a
     * plausible answer.
     *
     * False when no channel exists for the source, which is the right answer
     * for a source nothing can reply to at all.
     */
    public function allowsUnattendedReply(Lead $lead): bool
    {
        return $this->for($lead)?->allowsUnattendedReply() ?? false;
    }

    /**
     * Why not, in the refusing channel's own words.
     *
     * Null when a reply CAN be sent. The generic sentence is deliberately
     * source-free: it is what a channel with nothing specific to say falls
     * back to, and the screen must never fill that gap with one provider's
     * wording — that is the defect this method exists to end.
     */
    public function whyNot(Lead $lead): ?string
    {
        $channel = $this->for($lead);

        if ($channel !== null && $channel->canReply($lead)) {
            return null;
        }

        if ($channel instanceof ExplainsRefusal) {
            return $channel->whyNot($lead);
        }

        return $channel === null
            ? 'Enquiries from this source cannot be replied to from here.'
            : 'This enquiry cannot be replied to right now.';
    }

    /** When this lead's reply window closes, for a channel that has one; null otherwise. */
    public function windowEndsAt(Lead $lead): ?Carbon
    {
        $channel = $this->for($lead);

        return $channel instanceof HasReplyWindow ? $channel->replyWindowEndsAt($lead) : null;
    }

    /**
     * Resolved on every call, never memoised. A route keeps its controller
     * instance, and in tests every request in a file shares it, so a list
     * cached at construction outlived a channel tagged afterwards and read
     * "no channel" for a source that had one. tagged() is a handful of
     * container lookups; caching it bought nothing and cost a false answer.
     *
     * @return array<string, LeadReplyChannel>
     */
    private function channels(): array
    {
        $channels = [];

        foreach ($this->container->tagged(self::TAG) as $channel) {
            if ($channel instanceof LeadReplyChannel) {
                $channels[$channel->source()] = $channel;
            }
        }

        return $channels;
    }
}
