<?php

namespace App\Http\Controllers\Api\Phone;

use App\Http\Controllers\Api\Controller;
use App\Models\Company;
use App\Models\PhoneConnection;
use App\Models\PhoneServiceRequest;
use App\Models\User;
use App\Support\Phone\TwilioGateway;
use App\Support\Scoping\ViewScope;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Connect / list / disconnect Twilio accounts for Phone (spec §5).
 *
 * BYO verifies tenant keys; provisioned creates a subaccount under platform
 * env. Credentials are written via forceFill — never mass-assigned.
 */
class ConnectionsController extends Controller
{
    private const PLATFORM_GAP = 'EzBizi has not been given a Twilio account to create phone service with.';

    public function __construct(
        private readonly TwilioGateway $twilio,
        private readonly TenantContext $tenant,
    ) {}

    /** GET /api/v1/phone/connections */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', PhoneConnection::class);

        $user = $request->user();
        $rows = $this->inView($this->connectionsInReach($user), $user)
            ->sortBy(fn (PhoneConnection $c) => $c->scope_type.'|'.$c->scope_id)
            ->values()
            ->map(fn (PhoneConnection $c) => $this->row($c))
            ->all();

        return response()->json(['data' => $rows]);
    }

    /**
     * GET /api/v1/phone/connections/subaccounts
     *
     * Phone accounts already under EzBizi's platform Twilio, so Settings can
     * offer "use the one that already exists" instead of silently making a
     * second one with the same name.
     *
     * Already-connected accounts are marked rather than hidden: an account
     * missing from the list with no explanation reads as a loading failure,
     * and "this one is already in use by another place" is the answer somebody
     * is actually looking for.
     *
     * ── Why this list is filtered to names this tenant owns ──────
     *
     * Twilio lists EVERY subaccount under the platform account. On a platform
     * with more than one customer that is every other customer's phone account
     * name — who else buys EzBizi phone service, and what they are called.
     * Answering that to any tenant administrator is a tenant-boundary break of
     * exactly the kind §5 refuses for webhook tokens.
     *
     * Adoption itself is gated by the auth token, which only the real owner
     * has, so this filter is not what makes adoption safe. It is what stops the
     * LIST being an answer to a question nobody was entitled to ask.
     *
     * The match is by name against this organization and the companies in
     * reach, because a hand-made subaccount has no other tie to a tenant. It is
     * deliberately narrow: an account this filter hides can still be adopted by
     * pasting its SID, where the token remains the gate.
     */
    public function subaccounts(Request $request): JsonResponse
    {
        $this->authorize('create', [PhoneConnection::class, 'organization', null]);

        if (! $this->platformTwilioReady()) {
            return response()->json(['data' => [], 'message' => self::PLATFORM_GAP]);
        }

        try {
            $available = $this->twilio->listSubaccounts();
        } catch (Throwable) {
            // Twilio being unreachable must not take the Settings screen down;
            // the connect form still works without this list.
            return response()->json(['data' => [], 'message' => 'EzBizi could not reach Twilio to list phone accounts.']);
        }

        $taken = PhoneConnection::query()
            ->where('organization_id', $this->tenant->idOrFail())
            ->pluck('account_sid')
            ->all();

        $mine = $this->namesInReach($request->user());

        $rows = [];
        foreach ($available as $sub) {
            if (! in_array($this->normalise($sub['friendly_name']), $mine, true)) {
                continue;
            }

            $rows[] = $sub + ['in_use' => in_array($sub['account_sid'], $taken, true)];
        }

        return response()->json(['data' => $rows]);
    }

    /**
     * Lowercased, punctuation-stripped names of this organization and the
     * companies this person can reach.
     *
     * "A1 Chimney - Tools" and "A-1 Chimney Tools" are the same business typed
     * by two people on two different days, and a match that insisted on the
     * punctuation would hide the account somebody is looking straight at.
     *
     * @return list<string>
     */
    private function namesInReach(User $user): array
    {
        $names = [$user->organization?->name];

        foreach ($user->reachableCompanies() as $company) {
            $names[] = $company->name;
        }

        $out = [];
        foreach ($names as $name) {
            if (is_string($name) && $name !== '') {
                $out[] = $this->normalise($name);
            }
        }

        return array_values(array_unique($out));
    }

    private function normalise(string $name): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', mb_strtolower($name)));
    }

    /** POST /api/v1/phone/connections */
    public function store(Request $request): JsonResponse
    {
        $input = $request->validate([
            'scope_type' => ['required', 'in:organization,company'],
            'scope_id' => ['required', 'uuid'],
            // Subaccount only. Phone service is something EzBizi provides on
            // its own Twilio — a customer bringing their own account is not a
            // product on offer, and while it WAS accepted here it was also a
            // way round gate 2: BYO spent no EzBizi money, so it was allowed
            // without approval, which made "kind=byo" a bypass for anyone who
            // could post to this endpoint.
            'kind' => ['required', Rule::in([PhoneConnection::KIND_SUBACCOUNT])],
            'account_sid' => ['nullable', 'string', 'size:34'],

            // Adoption: `kind=subaccount` WITH an account_sid means "take over
            // this existing phone account" rather than "create a new one".
            //
            // Not `required_if:kind,subaccount` — that would demand a token for
            // CREATING one too, where Twilio hands it back and nobody has it to
            // type. Required in the adopt branch below, where it is the one
            // thing that cannot be recovered.
            'auth_token' => ['nullable', 'string', 'min:1'],
        ]);

        $company = null;
        if ($input['scope_type'] === PhoneConnection::SCOPE_COMPANY) {
            $company = Company::find($input['scope_id']);
            abort_if($company === null, Response::HTTP_NOT_FOUND, 'That company was not found.');
        } elseif ($this->tenant->idOrFail() !== $input['scope_id']) {
            abort(Response::HTTP_NOT_FOUND, 'That organization was not found.');
        }

        $this->authorize('create', [PhoneConnection::class, $input['scope_type'], $company]);

        if (! $this->platformTwilioReady()) {
            return response()->json(['message' => self::PLATFORM_GAP], Response::HTTP_NOT_IMPLEMENTED);
        }

        // §4 gate 2. The Twilio usage bills EzBizi (spec §5), so activation is
        // not something an organization may do to itself — a company has phone
        // service because somebody at EzBizi said yes to that company by name.
        //
        // There is no unguarded path past this. Bringing your own Twilio used
        // to be one, on the reasoning that a customer spending their own money
        // needs no permission from us; since it is not a product EzBizi offers,
        // all it did was answer "how do I skip the approval".
        if ($company === null) {
            throw ValidationException::withMessages([
                'scope_id' => 'Phone service is set up per company.',
            ]);
        }

        if (! PhoneServiceRequest::approvedFor($company->getKey())) {
            return response()->json([
                'message' => 'EzBizi has not switched phone service on for '
                    .$company->name.' yet. Ask for it on the Phone tab.',
            ], Response::HTTP_FORBIDDEN);
        }

        $existing = PhoneConnection::query()
            ->where('scope_type', $input['scope_type'])
            ->where('scope_id', $input['scope_id'])
            ->exists();

        if ($existing) {
            return response()->json([
                'message' => 'A phone connection is already set up for that place. Disconnect it before connecting another.',
            ], Response::HTTP_CONFLICT);
        }

        // Twilio HTTP must not sit inside a DB transaction: a rolled-back local
        // row would orphan a real subaccount / TwiML App with nothing to disconnect.
        // Provision first, persist a disconnectable row, then attach the App.
        try {
            if (filled($input['account_sid'] ?? null)) {
                // Adopting a phone account that already exists.
                //
                // Without this branch, connecting a company whose Twilio
                // subaccount was made by hand creates a SECOND account with the
                // same name and strands the first — numbers, campaigns and all.
                // Three such accounts already existed when this was written.
                if (! filled($input['auth_token'] ?? null)) {
                    throw ValidationException::withMessages([
                        'auth_token' => 'Paste the auth token for that phone account. '
                            .'Twilio only shows it on the account itself, and EzBizi cannot read it back.',
                    ]);
                }

                $adopted = $this->twilio->adoptSubaccount(
                    (string) $input['account_sid'],
                    (string) $input['auth_token'],
                );

                $accountSid = $adopted['account_sid'];
                $apiKeySid = $adopted['api_key_sid'];
                $apiSecret = $adopted['api_secret'];
                $authToken = $adopted['auth_token'];
            } else {
                $friendly = $company?->name ?? (string) $this->tenant->get()?->name;
                $sub = $this->twilio->createSubaccount($friendly);
                $key = $this->twilio->createApiKey($sub['account_sid'], 'EzBizi');
                $accountSid = $sub['account_sid'];
                $apiKeySid = $key['sid'];
                $apiSecret = $key['secret'];
                $authToken = $sub['auth_token'] ?? null;
            }
        } catch (RuntimeException $e) {
            if (str_contains($e->getMessage(), 'Twilio account to create phone service')) {
                return response()->json(['message' => self::PLATFORM_GAP], Response::HTTP_NOT_IMPLEMENTED);
            }

            throw ValidationException::withMessages([
                'account_sid' => $e->getMessage() !== ''
                    ? $e->getMessage()
                    : 'Those Twilio credentials could not be verified.',
            ]);
        }

        if (! is_string($authToken) || $authToken === '') {
            throw ValidationException::withMessages([
                'account_sid' => 'Twilio did not return an account auth token for this connection.',
            ]);
        }

        $connection = new PhoneConnection([
            'organization_id' => $this->tenant->idOrFail(),
            'scope_type' => $input['scope_type'],
            'scope_id' => $input['scope_id'],
            'kind' => $input['kind'],
            'account_sid' => $accountSid,
            'api_key_sid' => $apiKeySid,
            'status' => PhoneConnection::STATUS_CONNECTED,
        ]);
        $connection->writeSecrets($apiSecret, $authToken);

        $host = rtrim((string) config('app.url'), '/');
        $voiceUrl = $host.'/api/v1/phone/voice/'.$connection->organization_id.'/'.$connection->id;
        $statusUrl = $host.'/api/v1/phone/status/'.$connection->organization_id.'/'.$connection->id;

        try {
            $app = $this->twilio->createTwimlApp(
                $accountSid,
                $apiKeySid,
                $apiSecret,
                'EzBizi Phone',
                $voiceUrl,
                $statusUrl,
            );
        } catch (RuntimeException $e) {
            // Row stays so Settings can disconnect / finish; do not roll it back.
            throw ValidationException::withMessages([
                'account_sid' => $e->getMessage() !== ''
                    ? $e->getMessage()
                    : 'Twilio could not create the voice application for this connection.',
            ]);
        }

        $connection->update(['twiml_app_sid' => $app['sid']]);

        // Comes out answering calls: an "Everyone" ring group and a voicemail box.
        \App\Support\Phone\ConnectionDefaults::ensure($connection);

        return response()->json(['data' => $this->row($connection->fresh())], Response::HTTP_CREATED);
    }

    /** GET /api/v1/phone/connections/{connection}/trust */
    public function trustShow(Request $request, PhoneConnection $connection): JsonResponse
    {
        $this->authorize('view', $connection);

        return response()->json(['data' => $this->twilio->trustStatus($connection)]);
    }

    /** POST /api/v1/phone/connections/{connection}/trust */
    public function trustStore(Request $request, PhoneConnection $connection): JsonResponse
    {
        $this->authorize('update', $connection);

        $input = $request->validate([
            'email' => ['sometimes', 'nullable', 'email', 'max:191'],
            'friendly_name' => ['sometimes', 'nullable', 'string', 'max:191'],
            'policy_sid' => ['sometimes', 'nullable', 'string', 'max:34'],
        ]);

        return response()->json(['data' => $this->twilio->submitTrust($connection, $input)]);
    }

    /** DELETE /api/v1/phone/connections/{connection} */
    public function destroy(Request $request, PhoneConnection $connection): JsonResponse
    {
        $this->authorize('delete', $connection);

        app(\App\Support\Integrations\DisconnectCode::class)->require($request);

        $close = $request->boolean('close');

        try {
            foreach ($connection->numbers as $number) {
                if (filled($number->twilio_sid)) {
                    $this->twilio->updateIncoming($connection, (string) $number->twilio_sid, [
                        'voiceUrl' => '',
                        'smsUrl' => '',
                        'statusCallback' => '',
                    ]);
                }
            }

            if (filled($connection->twiml_app_sid)) {
                $this->twilio->clearTwimlApp($connection);
            }

            if ($connection->kind === PhoneConnection::KIND_SUBACCOUNT && $close) {
                $this->twilio->closeSubaccount($connection);
            }
        } catch (Throwable) {
            // Still drop our row so Settings is not stuck on a dead link.
        }

        $connection->delete();

        return response()->json(['message' => 'That phone connection has been removed.']);
    }

    /**
     * Org connection plus company connections the caller can act on.
     *
     * @return Collection<int, PhoneConnection>
     */
    private function scopeName(PhoneConnection $connection): string
    {
        if ($connection->scope_type === PhoneConnection::SCOPE_ORGANIZATION) {
            return (string) ($this->tenant->get()?->name ?? 'The group');
        }

        return (string) (Company::query()
            ->whereKey($connection->scope_id)
            ->value('name') ?? 'A company');
    }

    /**
     * Narrow reach to what the switcher is looking at.
     *
     * Reach answers "may this person ever act there"; the switcher answers
     * "where are they standing now". Standing in A-1 Chimney, this listed
     * Everdoors and the group as well — three rows all named "company" — which
     * is the exact thing AGENTS.md says a company view must never do.
     *
     * An organization view still sees everything, because that IS the view.
     */
    private function inView(Collection $connections, User $user): Collection
    {
        $view = ViewScope::forUser($user);

        if ($view->isOrganization()) {
            return $connections;
        }

        return $connections->filter(function (PhoneConnection $c) use ($view) {
            // The organization's own connection stays visible from a company
            // view: a company with no Twilio of its own answers on the group's,
            // so hiding it would hide the account its numbers actually use.
            if ($c->scope_type === PhoneConnection::SCOPE_ORGANIZATION) {
                return true;
            }

            return $view->coversCompany($c->scope_id);
        })->values();
    }

    private function connectionsInReach(User $user): Collection
    {
        if ($user->canActOnOrganization($user->organization)) {
            return PhoneConnection::query()->get();
        }

        $companyIds = $user->reachableCompanies()->pluck('id')->all();

        return PhoneConnection::query()
            ->where(function ($q) use ($user, $companyIds) {
                $q->where(function ($inner) use ($user) {
                    $inner->where('scope_type', PhoneConnection::SCOPE_ORGANIZATION)
                        ->where('scope_id', $user->organization_id);
                })->orWhere(function ($inner) use ($companyIds) {
                    $inner->where('scope_type', PhoneConnection::SCOPE_COMPANY)
                        ->whereIn('scope_id', $companyIds);
                });
            })
            ->get();
    }

    private function platformTwilioReady(): bool
    {
        foreach (['platform_account_sid', 'platform_api_key_sid', 'platform_api_secret'] as $key) {
            $value = config('services.twilio.'.$key);
            if (! is_string($value) || $value === '') {
                return false;
            }
        }

        return true;
    }

    /**
     * PATCH /api/v1/phone/connections/{connection} — the company's own rules.
     *
     * Separate from the connect/disconnect endpoints on purpose: those change
     * whether a phone system exists, this changes how the company runs the one
     * it has, and the permission for the second should not imply the first.
     */
    public function update(Request $request, PhoneConnection $connection): JsonResponse
    {
        // The one method in this controller that shipped without this line.
        //
        // Everything else here authorizes, and the omission was invisible
        // because the endpoint works perfectly for the administrator who
        // built it. What it allowed was a technician holding read-only Phone,
        // or no Phone permission at all, changing which menu answers every
        // call, which group rings, and whether calls are recorded — and a
        // branch manager reaching up to do it to the parent company by naming
        // its id. §4 reach and `phone.edit`, the same pair connecting needs,
        // because this decides what the phone DOES all day and connecting only
        // decides that it exists.
        $this->authorize('update', $connection);

        // `sometimes` throughout, so General can save the answering settings
        // without restating the tag rule and the Tags screen can save the tag
        // rule without restating everything else. A required field here would
        // make each screen responsible for the other's values, and whichever
        // one was written second would quietly blank the first.
        $input = $request->validate([
            'require_call_tag' => ['sometimes', 'boolean'],

            // Capped at five minutes. Somebody typing 3600 would take the
            // whole desk out of the ring group for an hour after one call,
            // and would find out when the phone stopped ringing.
            'wrap_up_seconds' => ['sometimes', 'integer', 'min:0', 'max:300'],
            'default_flow_id' => ['sometimes', 'nullable', 'string', 'max:191'],
            'default_hours_schedule_id' => ['sometimes', 'nullable', 'string', 'max:191'],
            'default_ring_group_id' => ['sometimes', 'nullable', 'string', 'max:191'],
            'default_voicemail_box_id' => ['sometimes', 'nullable', 'string', 'max:191'],
            'default_fallback_number' => ['sometimes', 'nullable', 'string', 'max:32'],
            'default_recording_notice' => ['sometimes', 'nullable', 'string', 'max:500'],
            'default_record' => ['sometimes', 'boolean'],
            'default_transcribe' => ['sometimes', 'boolean'],
            'default_voicemail_transcribe' => ['sometimes', 'boolean'],
            'default_after_hours' => ['sometimes', 'nullable', 'in:voicemail,forward,closed'],
            'default_after_hours_number' => ['sometimes', 'nullable', 'string', 'max:32'],
        ]);

        $connection->forceFill($input)->save();

        return response()->json(['data' => $this->row($connection->refresh())]);
    }

    /** @return array<string, mixed> */
    private function row(PhoneConnection $connection): array
    {
        return [
            'id' => $connection->id,
            'organization_id' => $connection->organization_id,
            'scope_type' => $connection->scope_type,
            'scope_id' => $connection->scope_id,

            // What the place is CALLED. Without this every picker fell back to
            // the scope type, so three connections all read "company" and there
            // was no way to tell which was which.
            'scope_name' => $this->scopeName($connection),
            'kind' => $connection->kind,
            'account_sid' => $connection->account_sid,
            'api_key_sid' => $connection->api_key_sid,
            'twiml_app_sid' => $connection->twiml_app_sid,
            'status' => $connection->status,
            'status_reason' => $connection->status_reason,

            // A company-wide rule the desk reads, not a per-line one.
            'require_call_tag' => (bool) $connection->require_call_tag,
            'wrap_up_seconds' => (int) $connection->wrap_up_seconds,

            // What answers a call, for every line on this account unless the
            // line says otherwise. General edits these; the lines inherit.
            'default_flow_id' => $connection->default_flow_id,
            'default_hours_schedule_id' => $connection->default_hours_schedule_id,
            'default_ring_group_id' => $connection->default_ring_group_id,

            // Whether the company's default group would reach anybody, judged
            // the way a line's answer step is (DialPlan::batches): a group set
            // but empty, or gone, is nobody. For the Call flow screen's
            // company boxes (UI/UX queue 51).
            'default_reaches' => $this->defaultReaches($connection),
            'default_voicemail_box_id' => $connection->default_voicemail_box_id,
            'default_fallback_number' => $connection->default_fallback_number,
            'default_recording_notice' => $connection->default_recording_notice,
            'default_record' => (bool) $connection->default_record,
            'default_transcribe' => (bool) $connection->default_transcribe,
            'default_voicemail_transcribe' => (bool) $connection->default_voicemail_transcribe,
            'default_after_hours' => $connection->default_after_hours ?? 'voicemail',
            'default_after_hours_number' => $connection->default_after_hours_number,
        ];
    }

    private function defaultReaches(PhoneConnection $connection): bool
    {
        $group = $connection->default_ring_group_id === null
            ? null
            : \App\Models\PhoneRingGroup::query()->find($connection->default_ring_group_id);

        return $group !== null && \App\Support\Phone\DialPlan::batches($group, $connection) !== [];
    }
}
