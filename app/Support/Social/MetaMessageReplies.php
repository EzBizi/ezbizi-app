<?php

namespace App\Support\Social;

use App\Models\Lead;
use App\Models\LeadConversation;
use App\Models\SocialConnection;
use App\Support\Leads\LeadReplyChannel;
use App\Support\Leads\LeadThread;
use RuntimeException;

/**
 * Answering a Facebook Page message or an Instagram DM from the Leads thread.
 *
 * ── One class, registered twice ──────────────────────────────────
 *
 * `LeadReplyChannel::source()` answers for exactly one source, and Messenger
 * and Instagram are two. They are the same code though - same envelope, same
 * endpoint, same window - so this takes its source as a constructor argument
 * and the provider registers it once per source. Two subclasses would be two
 * places for the window rule to drift.
 *
 * ── Twenty-four hours, and it is Meta's rule not ours ────────────
 *
 * A Page may reply freely for 24 hours after the person's last message. After
 * that a reply needs a message tag, and the tags that exist are for confirmed
 * appointments and account updates rather than for answering an enquiry late.
 *
 * `canReply()` therefore reads the thread rather than guessing, and reads it
 * from OUR rows, which is sound here in a way it would not be for WhatsApp:
 * every inbound message on these two sources arrives through the webhook that
 * creates the lead, so there is no history that predates our record. The
 * enquiry cannot exist without its first message.
 */
final class MetaMessageReplies implements LeadReplyChannel
{
    /** Meta's standard messaging window. */
    private const WINDOW_HOURS = 24;

    public function __construct(
        private readonly string $source,
        private readonly MetaMessages $messages,
        private readonly LeadThread $thread,
    ) {}

    public function source(): string
    {
        return $this->source;
    }

    /**
     * YES, since 2026-09-24 — argued up, which is what this used to ask for.
     *
     * Nothing forbade it the way Google's filing forbids theirs; the argument
     * against was the audience, and whether a business wants its Page to
     * answer automatically is the business's call. Jose made it: *"it should
     * be for any lead, i don't know why you set a restriction on it."*
     *
     * META'S OWN RULE STILL HOLDS, and it is the one that matters: a reply is
     * only accepted within the window after the person last wrote. A rule
     * fired by the enquiry itself is inside that window by construction; the
     * window check below stays, so a rule firing late is refused in words.
     */
    public function allowsUnattendedReply(): bool
    {
        return true;
    }

    public function canReply(Lead $lead): bool
    {
        return $this->connection($lead) !== null && $this->lastInboundWithinWindow($lead);
    }

    public function send(Lead $lead, string $text, bool $automatic = false): LeadConversation
    {
        $connection = $this->connection($lead);

        if ($connection === null) {
            throw new RuntimeException(
                'The account this enquiry came through is no longer connected, so there is '
                .'nowhere to send the reply.'
            );
        }

        if (! $this->lastInboundWithinWindow($lead)) {
            throw new RuntimeException(
                'Meta only allows a reply within '.self::WINDOW_HOURS.' hours of the last '
                .'message from this person, and that has passed.'
            );
        }

        $mid = $this->messages->send($connection, (string) $lead->remote_id, $text);

        /*
         * The row is written AFTER the send here, not before, and that is the
         * opposite of the WhatsApp outbox on purpose. This method throws on
         * failure and the composer shows the sentence, so a person knows
         * immediately that nothing went; a queued row would put an unsent
         * message in the thread that looks sent. The outbox shape earns its
         * keep where nobody is watching, which is a rule firing, and this is
         * somebody pressing Send.
         *
         * The id is Meta's, so the echo that comes straight back matches this
         * row and refreshes it rather than adding the business's own reply to
         * the thread twice.
         */
        $this->thread->store($lead, $mid !== '' ? $mid : 'sent_'.$lead->getKey().'_'.now()->getTimestampMs(), [
            'channel' => LeadConversation::CHANNEL_MESSAGE,
            'participant' => LeadConversation::FROM_ADVERTISER,
            'occurred_at' => now(),
            'text' => $text,
            // Marked, or an automatic reply reads as a person having answered
            // and takes the enquiry off the Unreplied list.
            'is_automatic' => $automatic,
        ]);

        $this->thread->refresh($lead);

        return LeadConversation::query()
            ->where('lead_id', $lead->getKey())
            ->orderByDesc('occurred_at')
            ->firstOrFail();
    }

    private function connection(Lead $lead): ?SocialConnection
    {
        if ($lead->connection_id === null) {
            return null;
        }

        return SocialConnection::query()->whereKey($lead->connection_id)->first();
    }

    private function lastInboundWithinWindow(Lead $lead): bool
    {
        $last = LeadConversation::query()
            ->where('lead_id', $lead->getKey())
            ->where('participant', LeadConversation::FROM_CONSUMER)
            ->max('occurred_at');

        if ($last === null) {
            return false;
        }

        return now()->diffInHours($last, absolute: true) < self::WINDOW_HOURS;
    }
}
