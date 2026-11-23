<?php

namespace App\Jobs;

use App\Models\Organization;
use App\Models\PhoneCallback;
use App\Models\PhoneConnection;
use App\Models\PhoneDeviceRegistration;
use App\Models\PhoneNumber;
use App\Models\User;
use App\Support\Phone\ClientIdentity;
use App\Support\Phone\TwilioGateway;
use App\Support\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;
use Twilio\TwiML\VoiceResponse;

/**
 * Keep one callback promise, now that somebody is free to keep it.
 *
 * ── Why a job and not the request ────────────────────────────────
 *
 * The trigger is somebody setting themselves available, and that request must
 * return at the speed of a button. Placing a call is a round trip to Twilio
 * that can be slow and can fail, and neither belongs in the path of the
 * control that says "I am back". A failure here must not make availability
 * look broken.
 *
 * ── Agent first, then the customer ───────────────────────────────
 *
 * The originated call rings the EMPLOYEE, and only once they answer does the
 * TwiML dial the customer. The other order is easier — ring the customer and
 * dial the employee when they pick up — and it is the wrong one: if nobody
 * answers at our end, the customer has been rung by a company that then had
 * nothing to say. Ringing ourselves first means a missed callback costs the
 * person who was owed it nothing.
 *
 * ── Why the claim is a row and not a lock ────────────────────────
 *
 * Two desks becoming free in the same second would both find the same oldest
 * promise. The claim is an UPDATE with the outstanding condition still in the
 * WHERE, so only one of them changes a row and the other finds nothing to do.
 * See [PhoneCallback::scopeClaimable] for why the claim expires.
 */
class PlaceQueueCallback implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        private readonly string $organizationId,
        private readonly string $connectionId,
        private readonly string $userId,
    ) {
        // A caller is waiting: never behind a backfill (Automations 11-18).
        $this->onQueue(config('queue.automations_realtime', 'default'));
    }

    public function handle(TenantContext $tenant, TwilioGateway $twilio): void
    {
        $org = Organization::withoutGlobalScopes()->find($this->organizationId);
        if ($org === null) {
            return;
        }

        $tenant->run($org, function () use ($twilio): void {
            $connection = PhoneConnection::query()->find($this->connectionId);
            $user = User::query()->find($this->userId);

            if ($connection === null || $user === null) {
                return;
            }

            // The device to ring. They have just told us they are available,
            // so the desk they said it from is live — but presence does not
            // carry which one, so this rings the one Twilio has heard from
            // most recently, which is the same answer in every case that
            // matters and a defensible one in the rest.
            $device = PhoneDeviceRegistration::liveFor(
                (string) $connection->id,
                [(string) $user->id],
            )->first();

            if ($device === null) {
                return;
            }

            $owed = PhoneCallback::query()
                ->claimable()
                ->where('connection_id', $connection->id)
                ->orderBy('requested_at')
                ->first();

            if ($owed === null) {
                return;
            }

            // The claim, and the race. `outstanding()` is still in the WHERE,
            // so a desk that lost the race updates nothing and stops.
            $claimed = PhoneCallback::query()
                ->whereKey($owed->id)
                ->outstanding()
                ->update(['taken_at' => now(), 'taken_by' => $user->id]);

            if ($claimed === 0) {
                return;
            }

            $line = $owed->number_id === null
                ? null
                : PhoneNumber::query()->find($owed->number_id);

            $from = (string) ($line?->e164 ?? '');
            if ($from === '') {
                return;
            }

            $response = new VoiceResponse;
            $response->say(
                'Returning a call for '.$this->spoken($owed->to_e164)
                .'. Connecting you now.',
                ['language' => (string) ($line?->language ?: 'en-US')],
            );

            $dial = $response->dial(null, [
                'callerId' => $from,
                'answerOnBridge' => true,
                'timeout' => 30,
            ]);
            $dial->number($owed->to_e164);

            try {
                $twilio->createCall(
                    $connection,
                    $from,
                    'client:'.ClientIdentity::of($user, (string) $device->device_id),
                    (string) $response,
                );
            } catch (Throwable) {
                // Leave the claim standing. It expires in two minutes and the
                // next desk to become free tries again — which is better than
                // releasing it instantly and having the same broken thing
                // retried by everybody at once.
                return;
            }

            $owed->forceFill(['placed_at' => now()])->save();
        });
    }

    /**
     * A number read out digit by digit, because a phone reading "+13055550100"
     * as a quantity is not a number anybody can write down.
     */
    private function spoken(string $e164): string
    {
        return trim(implode(' ', str_split(preg_replace('/\D/', '', $e164) ?? '')));
    }
}
