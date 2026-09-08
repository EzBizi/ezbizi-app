<?php

namespace App\Support\Leads;

use App\Models\Lead;
use App\Models\LeadConversation;
use App\Services\Leads\LeadInsights;
use App\Support\Automations\EventEmitter;

/**
 * The thread on a lead, for every source that has one.
 *
 * Google's Local Services put it here first; website chat and forms (Web
 * Management, coming) store their messages through the same door so the
 * list's preview line, the unreplied dot and the `lead.contact_received`
 * trigger mean the same thing whatever the source. A source knows how to
 * FETCH or RECEIVE a message; this knows how to HOLD one.
 */
final class LeadThread
{
    public function __construct(
        private readonly EventEmitter $events,
        private readonly LeadInsights $insights,
    ) {}

    /**
     * Hold one message or call on a lead. True when the row is new.
     *
     * Keyed on the source's own id, unique per organization, so a sync that
     * overlaps its last window, or a reply the next sync brings back, refreshes
     * the row rather than doubling it. An existing row is refreshed because
     * sources add to a row after it appears - Google attaches a recording URL
     * a little after the call itself.
     *
     * Emits `lead.contact_received` when the ENQUIRER spoke. The business's
     * own replies land here too, but they are not something to be told about.
     *
     * @param  array{channel: string, participant: string, occurred_at: \DateTimeInterface, duration_ms?: ?int, recording_url?: ?string, text?: ?string, attachments?: ?array<int, string>}  $fields
     */
    public function store(Lead $lead, string $remoteId, array $fields): bool
    {
        $existing = LeadConversation::query()
            ->where('organization_id', $lead->organization_id)
            ->where('remote_id', $remoteId)
            ->first();

        if ($existing !== null) {
            $existing->fill($fields)->save();

            return false;
        }

        $row = LeadConversation::create([
            'organization_id' => $lead->organization_id,
            'lead_id'         => $lead->getKey(),
            'remote_id'       => $remoteId,
            ...$fields,
        ]);

        if ($row->participant === LeadConversation::FROM_CONSUMER) {
            $this->events->emitAfterCommit('lead.contact_received', $lead, [
                'after' => [
                    'channel'      => $row->channel,
                    'occurred_at'  => $row->occurred_at,
                    'preview'      => self::preview($row),
                    'contact_name' => $lead->contact_name,
                    'phone'        => $lead->phone,
                ],
            ]);
        }

        return true;
    }

    /**
     * The list's preview line, rewritten from the newest row.
     *
     * A cache, never the record: `last_contact_*` on the lead exist so the
     * list shows a preview without a query per row, and they are rebuilt from
     * the thread here every time the thread changes.
     */
    public function refresh(Lead $lead): void
    {
        $last = LeadConversation::query()
            ->where('lead_id', $lead->getKey())
            ->orderByDesc('occurred_at')
            ->first();

        $lead->forceFill([
            'last_contact_at'      => $last?->occurred_at,
            'last_contact_channel' => $last?->channel,
            'last_contact_from'    => $last?->participant,
            'last_contact_preview' => $last !== null ? self::preview($last) : null,
        ])->save();

        $this->classifyOnce($lead);
    }

    /**
     * BOOK / SUPPORT / ARCHIVE, once, the first time the enquirer has said
     * something in words. Every source goes through refresh(), so a website
     * chat is sorted the same way a Google text is. Never re-run: the pile
     * a lead landed on is a fact people act on, and `status` is theirs.
     * `lead.classified` was declared the day the module was drawn, marked
     * not yet raised; this raises it.
     */
    private function classifyOnce(Lead $lead): void
    {
        if ($lead->classification !== null || ! $this->insights->enabled()) {
            return;
        }

        $read = $this->insights->classify($lead);

        if ($read === null) {
            return;
        }

        $lead->forceFill([
            'classification' => $read['classification'],
            'summary'        => $read['summary'] !== '' ? $read['summary'] : $lead->summary,
            'classified_at'  => now(),
        ])->save();

        $this->events->emitAfterCommit('lead.classified', $lead, [
            'after' => [
                'classification' => $lead->classification,
                'summary'        => $lead->summary,
                'contact_name'   => $lead->contact_name,
                'phone'          => $lead->phone,
            ],
        ]);
    }

    /**
     * One line. A message is its own first line; a call is its length, because
     * "Call · 0:00" and "Call · 4:32" are the two things a person wants to
     * know about it from the list.
     */
    public static function preview(LeadConversation $row): ?string
    {
        if ($row->text !== null) {
            $line = trim((string) preg_replace('/\s+/', ' ', $row->text));

            return mb_substr($line, 0, 255);
        }

        if ($row->channel === LeadConversation::CHANNEL_PHONE_CALL) {
            $seconds = intdiv((int) ($row->duration_ms ?? 0), 1000);

            return sprintf('Call · %d:%02d', intdiv($seconds, 60), $seconds % 60);
        }

        return null;
    }
}
