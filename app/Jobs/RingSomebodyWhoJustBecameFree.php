<?php

namespace App\Jobs;

use App\Models\Organization;
use App\Models\PhoneCall;
use App\Models\PhoneConnection;
use App\Models\PhoneNumber;
use App\Models\PhoneRingGroup;
use App\Models\User;
use App\Support\Phone\ClientIdentity;
use App\Support\Phone\DialPlan;
use App\Support\Phone\TwilioGateway;
use App\Support\Phone\TwimlRenderer;
use App\Support\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Somebody made themselves available while a call was already ringing.
 *
 * ── What Jose asked for ──────────────────────────────────────────
 *
 * 2026-09-17: *"A call started ringing, bar was available i was unavailable.
 * Bar receives the call... Nothing rings me since im unavailable which is
 * good. But, i put myself available, and said call should start ringing on me
 * as well if it's still going on."*
 *
 * ── Why this is a REDIRECT and not an extra leg ──────────────────
 *
 * A `<Dial>` is fixed once it starts. There is no way to add a `<Client>` noun
 * to one already ringing, so the only way to put a new phone into a live
 * attempt is to replace the attempt: redirect the caller's own leg to a fresh
 * `<Dial>` naming everybody now reachable.
 *
 * THE COST IS REAL AND WORTH SAYING PLAINLY: every phone already ringing stops
 * and rings again. Their ring timer restarts too. The caller hears continuous
 * ringback either way, because `transfer()` sets `ringTone` — so the person
 * who notices is a colleague, not the customer.
 *
 * ── Why only a simultaneous group ───────────────────────────────
 *
 * Because the others already do this by themselves, and better. Sequential and
 * longest-idle groups ring in BATCHES, and each batch is re-derived from the
 * dial plan when the previous one ends — so somebody who becomes available
 * during batch one is simply in batch two, with nobody's ring interrupted. A
 * simultaneous group has exactly one batch, so there is no later attempt to be
 * included in: it is this or nothing until the call overflows.
 *
 * Redirecting a longest-idle group would also throw away the whole point of
 * it. The remaining batches are an ORDER somebody chose, and a redirect rings
 * everybody at once.
 *
 * ── Why nobody having answered is checked against TWILIO ────────
 *
 * `phone_calls.status` is moved by Twilio's callbacks and lags. Redirecting a
 * call that has just been answered would tear down the answered leg — it would
 * hang up on a customer mid-sentence, which is strictly worse than the thing
 * this job improves. So the legs are read from Twilio, which is the only place
 * that knows the answer at the moment it matters, and anything other than a
 * still-ringing set stops the job.
 */
class RingSomebodyWhoJustBecameFree implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** Statuses a leg can hold while still only ringing. */
    private const STILL_RINGING = ['queued', 'initiated', 'ringing'];

    public function __construct(
        private readonly string $organizationId,
        private readonly string $connectionId,
        private readonly string $userId,
    ) {
        // A caller is waiting: never behind a backfill (Automations 11-18).
        $this->onQueue(config('queue.automations_realtime', 'default'));
    }

    public function handle(
        TenantContext $tenant,
        TwilioGateway $twilio,
        TwimlRenderer $renderer,
    ): void {
        $org = Organization::withoutGlobalScopes()->find($this->organizationId);
        if ($org === null) {
            return;
        }

        $tenant->run($org, function () use ($twilio, $renderer): void {
            $connection = PhoneConnection::query()->find($this->connectionId);
            $user = User::query()->find($this->userId);

            if ($connection === null || $user === null) {
                return;
            }

            // Ringing, and recently. The same two-minute ceiling the rest of
            // this module applies to `status = ringing`, for the same reason:
            // a call whose final callback never arrived stays ringing in this
            // table for ever, and without a ceiling every availability change
            // would redirect a call that ended days ago.
            $calls = PhoneCall::query()
                ->where('connection_id', $connection->id)
                ->where('status', PhoneCall::STATUS_RINGING)
                ->where('created_at', '>=', now()->subMinutes(2))
                ->get();

            foreach ($calls as $call) {
                $this->pullInto($call, $connection, $user, $twilio, $renderer);
            }
        });
    }

    private function pullInto(
        PhoneCall $call,
        PhoneConnection $connection,
        User $user,
        TwilioGateway $twilio,
        TwimlRenderer $renderer,
    ): void {
        $number = $call->number_id === null
            ? null
            : PhoneNumber::query()->find($call->number_id);

        if ($number === null) {
            return;
        }

        $groupId = $number->resolved('ring_group_id');
        $group = $groupId === null ? null : PhoneRingGroup::query()->find($groupId);

        if ($group === null || $group->mode !== PhoneRingGroup::MODE_SIMULTANEOUS) {
            return;
        }

        // Reachable NOW, asked of the dial plan rather than of the presence
        // row this job was dispatched from. Between the request and the job
        // they may have gone back to Do Not Disturb, been taken out of the
        // group, or had their last device go stale — and the dial plan is the
        // one place that knows all three.
        $identities = [];
        foreach (DialPlan::batches($group, $connection) as $batch) {
            foreach ($batch as $identity) {
                $identities[] = $identity;
            }
        }

        $mine = array_filter(
            $identities,
            fn (string $identity) => ClientIdentity::belongsTo($identity, $user),
        );

        if ($mine === []) {
            return;
        }

        try {
            $legs = $twilio->childLegs($connection, (string) $call->twilio_sid);
        } catch (\Throwable) {
            // Twilio unreachable. Doing nothing leaves the call ringing the
            // people it was already ringing, which is the behaviour this
            // improves on rather than one it breaks.
            return;
        }

        // NOBODY MAY HAVE ANSWERED. See the class docblock: a redirect after
        // an answer hangs up on a live conversation.
        foreach ($legs as $leg) {
            if (! in_array((string) ($leg['status'] ?? ''), self::STILL_RINGING, true)) {
                return;
            }

            // And if one of MY phones is already in this attempt, there is
            // nothing to add — redirecting would restart every colleague's
            // ring to achieve exactly nothing.
            $to = (string) ($leg['to'] ?? '');
            if (! str_starts_with($to, 'client:')) {
                continue;
            }

            if (ClientIdentity::belongsTo(substr($to, strlen('client:')), $user)) {
                return;
            }
        }

        // A call nobody was ringing at all is not a call to pull into. It has
        // already fallen through to voicemail or a forward, and redirecting it
        // would take a caller out of the message they are leaving.
        if ($legs === []) {
            return;
        }

        try {
            $twilio->redirectCall(
                $connection,
                (string) $call->twilio_sid,
                $renderer->transfer($number, array_values($identities)),
            );
        } catch (\Throwable) {
            // Almost always the call ending while this was in flight. Nothing
            // is broken and there is nobody to tell.
        }
    }
}
