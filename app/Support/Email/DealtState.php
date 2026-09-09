<?php

namespace App\Support\Email;

use App\Models\Mailbox;
use App\Models\MessageDealt;
use App\Models\User;

/**
 * "Has this been handled?" — one answer per message, for the whole inbox.
 *
 * ── Why this is a class beside {@see ReadState} and not part of it ──
 *
 * They read alike and they are different facts. ReadState resolves a SETTING
 * (`email.read_state`, shared or private) and answers a per-person question;
 * this answers a per-mailbox one and has no setting to resolve, because there
 * is no version of "handled" that is true for one colleague and false for
 * another.
 *
 * Folding them together would put a per-person cascade lookup on a path that
 * must not have one — and would invite the next edit to make dealt-with
 * per-person too, which is the whole thing this separation exists to prevent.
 *
 * ── Marking is idempotent, and the database enforces it ──────────
 *
 * Two people clicking at once, or a rule racing a person, must produce one row
 * and must not fail the second attempt. `firstOrCreate` against the unique key
 * on (mailbox, message) is what makes that true rather than hoped for.
 *
 * ── Unmarking DELETES, rather than writing a second state ────────
 *
 * "Not dealt with" is the absence of the fact, not a flag set to false. A
 * `dealt = false` row would be a row asserting something nobody asserted, and
 * it would have to carry who un-asserted it and when, which is a second history
 * nobody asked for.
 */
final class DealtState
{
    /**
     * Mark it handled. Returns the row, whether it was just made or already there.
     *
     * `$user` is null when an automation did it. That is not a defensive
     * default — it is the honest value, because a rule has no user, and the
     * screen says "an automation" in those words rather than leaving a blank
     * where a name goes.
     */
    public function markDealt(Mailbox $mailbox, string $providerMessageId, ?User $user): MessageDealt
    {
        return MessageDealt::firstOrCreate(
            [
                'mailbox_id'          => $mailbox->getKey(),
                'provider_message_id' => $providerMessageId,
            ],
            [
                'organization_id'  => $mailbox->organization_id,
                'dealt_by_user_id' => $user?->getKey(),
                'dealt_at'         => now(),
            ],
        );
    }

    /**
     * No longer handled.
     *
     * ANYBODY who can read the inbox may undo it, including somebody other than
     * whoever marked it — which is the opposite of {@see ReadState::forgetSeen},
     * and deliberately so. "Marta has seen this" is Marta's own fact and only
     * she may withdraw it. "This is handled" is the inbox's fact: if it turns
     * out the customer was not actually answered, the person who notices must
     * be able to say so without hunting down whoever marked it.
     */
    public function forgetDealt(Mailbox $mailbox, string $providerMessageId): void
    {
        MessageDealt::query()
            ->where('mailbox_id', $mailbox->getKey())
            ->where('provider_message_id', $providerMessageId)
            ->delete();
    }

    /**
     * Which of these messages are handled, and by whom.
     *
     * One query for the page rather than one per row — the list asks this about
     * every message it draws, and a per-row lookup is the shape that makes a
     * mail list slow in exactly the case that matters, a busy inbox.
     *
     * @param  list<string>  $providerMessageIds
     * @return array<string, array{at: string, by: string|null}>
     */
    public function dealtWith(Mailbox $mailbox, array $providerMessageIds): array
    {
        if ($providerMessageIds === []) {
            return [];
        }

        $rows = MessageDealt::query()
            ->with('dealtBy:id,name')
            ->where('mailbox_id', $mailbox->getKey())
            ->whereIn('provider_message_id', $providerMessageIds)
            ->get();

        $out = [];

        foreach ($rows as $row) {
            $out[$row->provider_message_id] = [
                'at' => $row->dealt_at?->toIso8601String(),

                // Null is "an automation", and the client says so in words. A
                // name here is always a person, so the screen never has to
                // guess which it is looking at.
                'by' => $row->dealtBy?->name,
            ];
        }

        return $out;
    }
}
