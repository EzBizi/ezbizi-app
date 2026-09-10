<?php

namespace App\Http\Controllers\Api\Phone;

use App\Http\Controllers\Api\Controller;
use App\Models\PhoneCall;
use App\Models\PhoneConnection;
use App\Models\User;
use App\Support\Phone\ClientIdentity;
use App\Support\Phone\TwilioWebhook;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Twilio\TwiML\VoiceResponse;

/**
 * The one leg that answered, saying so.
 *
 * ── Why a whole endpoint for a single column ─────────────────────
 *
 * An inbound call rings a ring group: one `<Dial>` with a `<Client>` per
 * person per device, and the first to pick up wins. Twilio then tells us the
 * dial completed. It does not tell us who won — `DialCallSid` names the child
 * leg, and we hold no row for child legs.
 *
 * `<Client url="...">` is the mechanism that does: TwiML fetched on the leg
 * that ANSWERS, before the two are joined, and on no other. So this request
 * arriving is itself the fact — the user in the path is the person holding the
 * phone. `ParentCallSid` names the call they answered.
 *
 * It returns an empty document because there is nothing to say to them. The
 * cost is one HTTP round trip in the moment between picking up and hearing the
 * caller, which is why it is only attached to inbound group dials and not to
 * every `<Client>` in the renderer.
 *
 * ── First one wins ───────────────────────────────────────────────
 *
 * Written only when the column is still null. A transfer or a second answer
 * must not rewrite who took the call originally, and Twilio retries callbacks.
 */
class AnsweredWebhookController extends Controller
{
    public function __construct(private readonly TenantContext $tenant) {}

    public function __invoke(
        Request $request,
        string $organization,
        string $connection,
        string $user,
    ): Response {
        return TwilioWebhook::open(
            $request,
            $organization,
            $connection,
            $this->tenant,
            fn (PhoneConnection $row) => $this->stamp($request, $user),
        );
    }

    /**
     * @param  string  $identity  the Voice identity of the leg that answered
     */
    private function stamp(Request $request, string $identity): Response
    {
        $parent = (string) $request->input('ParentCallSid', '');

        // The identity is a user id with its hyphens taken out, because that
        // is all Twilio permits in one. Anything that does not put back
        // together into a uuid names nobody, and nothing is written — a column
        // holding a string no user has is worse than a column holding null.
        $userId = ClientIdentity::uuidOf($identity);

        if ($parent !== '' && $userId !== null && User::query()->whereKey($userId)->exists()) {
            DB::transaction(function () use ($parent, $userId) {
                $call = PhoneCall::query()
                    ->where('twilio_sid', $parent)
                    ->lockForUpdate()
                    ->first();

                if ($call !== null && $call->answered_by === null) {
                    $call->forceFill(['answered_by' => $userId])->save();
                }
            });
        }

        // Nothing is said to the person who picked up. An empty document is
        // valid TwiML and bridges immediately.
        return response((string) new VoiceResponse, Response::HTTP_OK)
            ->header('Content-Type', 'text/xml');
    }
}
