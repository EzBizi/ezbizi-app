<?php

namespace App\Http\Controllers\Api\Phone;

use App\Http\Controllers\Api\Controller;
use App\Http\Controllers\Api\Phone\Concerns\ResolvesPhoneConnections;
use App\Models\Company;
use App\Models\Franchise;
use App\Models\PhoneCall;
use App\Models\PhoneConnection;
use App\Models\PhoneExtension;
use App\Models\PhoneFlow;
use App\Models\PhoneNumber;
use App\Models\PhoneNumberGrant;
use App\Models\PhoneRingGroup;
use App\Models\PhoneUsageDaily;
use App\Support\Phone\ConnectionResolver;
use App\Support\Phone\PhoneNumberAccess;
use App\Support\Phone\TwilioGateway;
use App\Support\Phone\TwimlRenderer;
use App\Support\Scoping\ViewScope;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Buy, list, and release Twilio numbers hung on org / company / franchise.
 *
 * Buy uses the connection resolved for the chosen scope — never a sibling
 * company's credentials. List is phone.view ∩ grant ∩ reach ∩ view-scope.
 */
class NumbersController extends Controller
{
    use ResolvesPhoneConnections;

    private const NO_CONNECTION = 'Connect Twilio for this place first.';

    /** Search toggles, which arrive as query-string text rather than as JSON booleans. */
    private const SEARCH_FLAGS = [
        'voice', 'sms', 'mms', 'beta',
        'exclude_all_address_required',
        'exclude_local_address_required',
        'exclude_foreign_address_required',
    ];

    public function __construct(
        private readonly TwilioGateway $twilio,
        private readonly ConnectionResolver $resolver,
        private readonly PhoneNumberAccess $access,
        private readonly TenantContext $tenant,
    ) {}

    /** GET /api/v1/phone/numbers */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', PhoneNumber::class);

        $live = $this->linesInUse();

        $numbers = $this->access->visibleTo($request->user());
        $ids = $numbers->pluck('id')->all();

        // WHICH LINES ARE IN USE. Jose, 2026-10-05: "list of phones not in
        // use, deactivated or not assigned". One grouped query each, not one
        // per line: the last call on each, and the person holding it.
        $lastCall = PhoneCall::query()
            ->whereIn('number_id', $ids)
            ->groupBy('number_id')
            ->selectRaw('number_id, MAX(created_at) as last')
            ->pluck('last', 'number_id');
        $holders = PhoneExtension::query()
            ->whereIn('number_id', $ids)
            ->whereNotNull('user_id')
            ->with('user:id,name')
            ->orderBy('created_at')
            ->get()
            ->groupBy('number_id');

        $rows = $numbers
            ->map(fn (PhoneNumber $number) => $this->row($number, $live) + [
                'last_call_at' => isset($lastCall[$number->id])
                    ? \Illuminate\Support\Carbon::parse($lastCall[$number->id], 'UTC')->toIso8601String()
                    : null,
                'assigned_to' => $holders->get($number->id)?->first()?->user?->name,
            ])
            ->all();

        return response()->json(['data' => $rows]);
    }

    /** POST /api/v1/phone/numbers */
    public function store(Request $request): JsonResponse
    {
        $input = $request->validate([
            'scope_type' => ['required', Rule::in([
                PhoneNumber::SCOPE_ORGANIZATION,
                PhoneNumber::SCOPE_COMPANY,
                PhoneNumber::SCOPE_FRANCHISE,
            ])],
            'scope_id' => ['required', 'uuid'],
            'area_code' => ['required_without:e164', 'nullable', 'string', 'max:8'],
            'e164' => ['required_without:area_code', 'nullable', 'string', 'max:16'],
            'label' => ['sometimes', 'nullable', 'string', 'max:191'],
        ]);

        $this->assertScopeExists($input['scope_type'], $input['scope_id']);
        $this->authorize('create', [PhoneNumber::class, $input['scope_type'], $input['scope_id']]);

        $connection = $this->resolver->forScope(
            $this->tenant->idOrFail(),
            $input['scope_type'],
            $input['scope_id'],
        );

        if ($connection === null) {
            throw ValidationException::withMessages(['scope_id' => self::NO_CONNECTION]);
        }

        $phoneNumber = filled($input['e164'] ?? null)
            ? (string) $input['e164']
            : $this->pickAvailable($connection, (string) $input['area_code']);

        $host = rtrim((string) config('app.url'), '/');
        $base = $host.'/api/v1/phone';
        $orgId = $connection->organization_id;
        $connectionId = $connection->id;

        $purchased = $this->twilio->purchaseIncoming($connection, $phoneNumber, array_filter([
            'voiceUrl' => $base.'/voice/'.$orgId.'/'.$connectionId,
            'voiceMethod' => 'POST',
            'smsUrl' => $base.'/sms/'.$orgId.'/'.$connectionId,
            'smsMethod' => 'POST',
            'statusCallback' => $base.'/status/'.$orgId.'/'.$connectionId,
            'statusCallbackMethod' => 'POST',

            // So the Twilio Console shows what EzBizi calls this line. A
            // console full of bare E.164s is how somebody releases the wrong
            // number.
            'friendlyName' => filled($input['label'] ?? null) ? (string) $input['label'] : null,
        ], static fn ($value) => $value !== null));

        $number = PhoneNumber::query()->create([
            'organization_id' => $this->tenant->idOrFail(),
            'connection_id' => $connection->id,
            'scope_type' => $input['scope_type'],
            'scope_id' => $input['scope_id'],
            'twilio_sid' => $purchased['sid'],
            'e164' => $purchased['phone_number'],
            'label' => filled($input['label'] ?? null) ? (string) $input['label'] : $purchased['phone_number'],
            'after_hours' => 'voicemail',
        ]);

        // A new line comes out working: everybody on its account has an extension,
        // and 911 knows where it is.
        \App\Support\Phone\ExtensionAssigner::assignMissing($request->user()->organization);
        \App\Support\Phone\EmergencyAddress::attach($number, $this->twilio);
        $number->refresh();

        return response()->json(['data' => $this->row($number)], Response::HTTP_CREATED);
    }

    /**
     * GET /api/v1/phone/call-flow
     *
     * What actually happens when somebody rings each line — the order the
     * renderer evaluates, with each step saying whether it is usable.
     *
     * Everything built on the other screens is inert until a line points at
     * it, and until today nothing said so anywhere. Five production lines had
     * a ring group of null since import; the Numbers screen reported them in
     * grey as a caption, and every caller heard voicemail for months without
     * one person noticing. This is the screen that answers the question those
     * five lines were silently getting wrong.
     *
     * The trace comes from TwimlRenderer::describe(), which branches on the
     * same private predicates inbound() does — so what this draws and what
     * answers the phone cannot disagree.
     */
    public function callFlow(Request $request): JsonResponse
    {
        $this->authorize('viewAny', PhoneNumber::class);

        $renderer = new TwimlRenderer;

        $rows = $this->access->visibleTo($request->user())
            ->map(fn (PhoneNumber $number) => [
                'id' => $number->id,

                // Which company's answer this line falls back to, so the Call
                // flow screen can list each company's lines under it.
                'connection_id' => $number->connection_id,
                'e164' => $number->e164,
                'label' => $number->label,
                'record' => $number->record,
                'transcribe' => $number->transcribe,
                'steps' => $renderer->describe($number),

                // Added beside `steps`, not instead of it. The list answers
                // "what happens by default" and the graph answers "what CAN
                // happen"; the Call Flow tab reads the first and the flowchart
                // reads the second, and replacing one with the other would
                // have made this a rewrite of a working screen.
                'graph' => $renderer->graph($number),
            ])
            ->values()
            ->all();

        return response()->json(['data' => $rows]);
    }

    /**
     * POST /api/v1/phone/numbers/{number}/move
     *
     * Hand a line to a different company.
     *
     * ── Why only from an organization view ──────────────────────
     *
     * Moving a number is the one action here whose whole point is to cross a
     * company boundary, and a company view cannot see the company it would be
     * moving to. Somebody standing in A-1 Chimney giving a number to Everdoors
     * would be acting on a place their screen does not show them. So this is
     * refused anywhere but the organization view, where both sides are visible.
     *
     * ── What must be cleared, and why ───────────────────────────
     *
     * A number points at a ring group, an opening-hours schedule, an IVR menu
     * and a voicemail box, and all four are now owned by a company. Carrying
     * them across would leave Everdoors' line ringing A-1 Chimney's staff and
     * reading A-1 Chimney's greeting — reintroducing, one line at a time, the
     * exact leak that scoping them was meant to close.
     *
     * Grants go for the same reason: a role was given this line when it
     * belonged to A-1, and that consent does not transfer with the number.
     *
     * ── What does NOT move ──────────────────────────────────────
     *
     * Call history. phone_calls carries no scope, so past calls follow the
     * number to its new owner. That is a deliberate consequence and it is
     * worth knowing: recordings of calls made to A-1 become audible to whoever
     * can read Everdoors' lines. Both companies belong to one organization and
     * this is an organization-level action, so it is defensible — but it is a
     * decision, not an accident, and the alternative needs a scope column on
     * phone_calls that does not exist yet.
     */
    public function move(Request $request, PhoneNumber $number): JsonResponse
    {
        $this->authorize('update', $number);

        $input = $request->validate([
            'scope_type' => ['required', Rule::in([
                PhoneNumber::SCOPE_ORGANIZATION,
                PhoneNumber::SCOPE_COMPANY,
                PhoneNumber::SCOPE_FRANCHISE,
            ])],
            'scope_id' => ['required', 'uuid'],
        ]);

        if (! ViewScope::forUser($request->user())->isOrganization()) {
            throw ValidationException::withMessages([
                'scope_id' => 'Switch to the whole organization to move a number '
                    .'between companies — from inside one company the other is not visible.',
            ]);
        }

        $this->assertScopeExists($input['scope_type'], $input['scope_id']);

        // Reach on the DESTINATION as well as the source. Being able to edit
        // A-1's lines is not permission to give one to a company this person
        // has no authority over.
        $this->authorize('create', [PhoneNumber::class, $input['scope_type'], $input['scope_id']]);

        if ($number->scope_type === $input['scope_type']
            && $number->scope_id === $input['scope_id']) {
            return response()->json(['data' => $this->row($number)]);
        }

        $target = $this->resolver->forScope(
            $this->tenant->idOrFail(),
            $input['scope_type'],
            $input['scope_id'],
        );

        if ($target === null) {
            throw ValidationException::withMessages([
                'scope_id' => 'That place has no phone account, so there is nowhere '
                    .'for the number to go. Connect one first.',
            ]);
        }

        $from = $number->connection;
        $crossesAccounts = $from !== null && $from->id !== $target->id;

        if ($crossesAccounts) {
            $host = rtrim((string) config('app.url'), '/');
            $base = $host.'/api/v1/phone';
            $orgId = $target->organization_id;

            try {
                // The webhooks move WITH the number, in the same call. Our URLs
                // carry the connection in their path, so a moved line left on
                // the old URL keeps being answered by the old company.
                $this->twilio->moveIncomingToAccount(
                    $from,
                    (string) $number->twilio_sid,
                    (string) $target->account_sid,
                    [
                        'voiceUrl' => $base.'/voice/'.$orgId.'/'.$target->id,
                        'voiceMethod' => 'POST',
                        'smsUrl' => $base.'/sms/'.$orgId.'/'.$target->id,
                        'smsMethod' => 'POST',
                        'statusCallback' => $base.'/status/'.$orgId.'/'.$target->id,
                        'statusCallbackMethod' => 'POST',
                    ],
                );
            } catch (Throwable $e) {
                // Nothing is written. A row saying the number moved while
                // Twilio still routes it to the old company is worse than a
                // refusal, because the screen would stop anybody looking.
                throw ValidationException::withMessages([
                    'scope_id' => 'Twilio would not move the number: '.$e->getMessage(),
                ]);
            }
        }

        DB::transaction(function () use ($number, $input, $target, $crossesAccounts) {
            $number->forceFill([
                'scope_type' => $input['scope_type'],
                'scope_id' => $input['scope_id'],
                'connection_id' => $target->id,
            ]);

            // Only when the account changes do the pointers become another
            // company's. Within one account they are still valid — and a move
            // between two companies sharing the organization's Twilio is the
            // common case, so clearing there would destroy working setup for
            // no reason.
            if ($crossesAccounts) {
                $number->forceFill([
                    'ring_group_id' => null,
                    'hours_schedule_id' => null,
                    'flow_id' => null,
                    'voicemail_box_id' => null,
                ]);
            }

            $number->save();

            // The line belongs to somebody else now; who could read it does
            // not carry over.
            PhoneNumberGrant::query()->where('number_id', $number->id)->delete();
        });

        return response()->json(['data' => $this->row($number->fresh())]);
    }

    /**
     * GET /api/v1/phone/lookup
     *
     * What a number actually is — valid or not, mobile or landline, and whose
     * carrier. Twilio Lookup v2.
     *
     * ── Why it is cached, and why the cache can be bypassed ──────
     *
     * Every fresh lookup is billed. A number's line type barely changes, so
     * asking twice in a day is money for the same answer — but it DOES change
     * when somebody ports, and a stale "landline" is how a text silently goes
     * nowhere. So the default is the cached answer and `force` buys a fresh
     * one, with the screen saying which it gave you.
     *
     * `cached` travels in the response for that reason: a figure whose age is
     * unknown gets trusted like a fresh one.
     *
     * phone.edit, because a fresh lookup spends the tenant's money.
     */
    public function lookup(Request $request): JsonResponse
    {
        $input = $request->validate([
            'e164' => ['required', 'string', 'max:24'],
            'force' => ['sometimes'],
        ]);

        $this->authorize('viewAny', PhoneNumber::class);
        abort_unless($request->user()->hasPermission('phone.edit'), Response::HTTP_FORBIDDEN);

        $e164 = trim($input['e164']);
        $force = filter_var($request->query('force'), FILTER_VALIDATE_BOOL);

        $key = 'phone:lookup:'.$this->tenant->idOrFail().':'.$e164;

        if (! $force) {
            $hit = Cache::get($key);

            if (is_array($hit)) {
                return response()->json(['data' => $hit, 'cached' => true]);
            }
        }

        try {
            $result = $this->twilio->lookupDetailed($e164);
        } catch (Throwable) {
            // Not a 500: "we could not ask" is a different answer from "that
            // number is not real", and conflating them would have somebody
            // delete a customer's good number.
            return response()->json([
                'data' => null,
                'cached' => false,
                'message' => 'EzBizi could not reach Twilio to check that number.',
            ]);
        }

        // Thirty days: long enough that repeat checks are free, short enough
        // that a ported number corrects itself within a billing cycle.
        Cache::put($key, $result, now()->addDays(30));

        return response()->json(['data' => $result, 'cached' => false]);
    }

    /**
     * GET /api/v1/phone/usage
     *
     * What this place's phone service has cost, by day and category.
     *
     * Gated on `phone.edit`, not `phone.view`. Not because edit is the natural
     * home for a read, but because it is the same authority that BUYS a line
     * and thereby incurs the cost — whoever can spend the money can see what
     * was spent, and whoever answers the phone need not.
     *
     * A dedicated `phone.financials` would be the better gate, and Fleet and
     * Inventory both have one. It is not added here: a new action changes what
     * PermissionCatalogue answers for the whole registry, and App Store review
     * is running a frozen 0.0.0112 binary against live production. This is a
     * tighter gate than phone.view in the meantime, not a looser one, so
     * moving to phone.financials later can only narrow access further.
     */
    public function usage(Request $request): JsonResponse
    {
        $input = $request->validate([
            'scope_type' => ['required', Rule::in([
                PhoneNumber::SCOPE_ORGANIZATION,
                PhoneNumber::SCOPE_COMPANY,
                PhoneNumber::SCOPE_FRANCHISE,
            ])],
            'scope_id' => ['required', 'uuid'],
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d'],
        ]);

        $this->assertScopeExists($input['scope_type'], $input['scope_id']);
        $this->authorize('create', [PhoneNumber::class, $input['scope_type'], $input['scope_id']]);

        $connection = $this->resolver->forScope(
            $this->tenant->idOrFail(),
            $input['scope_type'],
            $input['scope_id'],
        );

        if ($connection === null) {
            return response()->json(['data' => [], 'totals' => [], 'message' => self::NO_CONNECTION]);
        }

        // Thirty days, because that is the window somebody actually asks about
        // and an unbounded default would read every row a tenant ever had.
        $to = $input['to'] ?? now()->toDateString();
        $from = $input['from'] ?? now()->subDays(30)->toDateString();

        $rows = PhoneUsageDaily::query()
            ->where('connection_id', $connection->id)
            ->whereBetween('day', [$from, $to])
            ->orderBy('day')
            ->orderBy('category')
            ->get();

        // Summed with bcmath, not floats. These are the figures somebody may
        // one day invoice from, and 0.1 + 0.2 is not 0.3 in binary.
        $totals = [];
        foreach ($rows as $row) {
            $unit = $row->price_unit ?: 'USD';
            $totals[$unit] = bcadd($totals[$unit] ?? '0', (string) $row->price, 6);
        }

        return response()->json([
            'data' => $rows->map(fn (PhoneUsageDaily $row) => [
                'day' => $row->day?->toDateString(),
                'category' => $row->category,
                'description' => $row->description,
                'usage' => (string) $row->usage,
                'usage_unit' => $row->usage_unit,
                'count' => (int) $row->count,
                'count_unit' => $row->count_unit,
                'price' => (string) $row->price,
                'price_unit' => $row->price_unit,
            ])->all(),
            // Cast to an object, and this is not cosmetic. An empty PHP array
            // encodes as `[]`, not `{}` — so on a tenant with no usage yet
            // this key changed TYPE from map to list. The client cast it to a
            // map, the cast threw inside the load, and the spinner never
            // cleared: a screen that hung rather than one that said anything.
            //
            // A keyed collection must serialise as an object at every size, or
            // its empty case is a different contract from its normal one.
            'totals' => (object) $totals,
            'from' => $from,
            'to' => $to,
        ]);
    }

    /**
     * GET /api/v1/phone/numbers/available
     *
     * What Twilio has for sale, for the place in view.
     *
     * Buying used to be an area code and nothing else, and it took
     * `available[0]` — whatever Twilio happened to list first. That is fine
     * for a test and wrong for a business, which wants a number in its own
     * town, or one ending in something a customer can remember.
     *
     * Read-only. Nothing is bought and no row is written until somebody picks
     * one and POSTs it to [store].
     *
     * Gated on `create`, not `view`: the only reason to search is to buy, and
     * every search spends the tenant's own Twilio quota.
     */
    public function available(Request $request): JsonResponse
    {
        // This is a GET, so every flag arrives as text. Laravel's `boolean`
        // rule refuses the string "true", which is precisely what a query
        // string carries — so the toggles are read before they are validated.
        foreach (self::SEARCH_FLAGS as $flag) {
            if ($request->has($flag)) {
                $request->merge([
                    $flag => filter_var($request->query($flag), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE),
                ]);
            }
        }

        $input = $request->validate([
            'scope_type' => ['required', Rule::in([
                PhoneNumber::SCOPE_ORGANIZATION,
                PhoneNumber::SCOPE_COMPANY,
                PhoneNumber::SCOPE_FRANCHISE,
            ])],
            'scope_id' => ['required', 'uuid'],

            'type' => ['sometimes', Rule::in(['local', 'toll_free', 'mobile'])],
            'country' => ['sometimes', 'string', 'size:2', 'alpha'],

            // Twilio caps `contains` at 2–16 and allows the meta-characters
            // * % + $ alongside digits and latin letters.
            'area_code' => ['sometimes', 'nullable', 'string', 'max:8'],
            'contains' => ['sometimes', 'nullable', 'string', 'min:2', 'max:16'],

            'voice' => ['sometimes', 'boolean'],
            'sms' => ['sometimes', 'boolean'],
            'mms' => ['sometimes', 'boolean'],

            'near_number' => ['sometimes', 'nullable', 'string', 'max:16'],
            'near_lat_long' => ['sometimes', 'nullable', 'string', 'max:64'],
            'distance' => ['sometimes', 'integer', 'min:1', 'max:500'],

            'in_postal_code' => ['sometimes', 'nullable', 'string', 'max:16'],
            'in_region' => ['sometimes', 'nullable', 'string', 'max:8'],
            'in_locality' => ['sometimes', 'nullable', 'string', 'max:64'],
            'in_rate_center' => ['sometimes', 'nullable', 'string', 'max:64'],
            'in_lata' => ['sometimes', 'nullable', 'string', 'max:16'],

            'exclude_all_address_required' => ['sometimes', 'boolean'],
            'exclude_local_address_required' => ['sometimes', 'boolean'],
            'exclude_foreign_address_required' => ['sometimes', 'boolean'],

            'beta' => ['sometimes', 'boolean'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);

        $this->assertScopeExists($input['scope_type'], $input['scope_id']);
        $this->authorize('create', [PhoneNumber::class, $input['scope_type'], $input['scope_id']]);

        // Twilio's own constraint, enforced here so the answer is a field
        // error rather than a 400 from the carrier.
        if (filled($input['in_rate_center'] ?? null) && blank($input['in_lata'] ?? null)) {
            throw ValidationException::withMessages([
                'in_lata' => 'Searching a rate center needs its LATA as well.',
            ]);
        }

        $connection = $this->resolver->forScope(
            $this->tenant->idOrFail(),
            $input['scope_type'],
            $input['scope_id'],
        );

        if ($connection === null) {
            return response()->json(['data' => [], 'message' => self::NO_CONNECTION]);
        }

        $criteria = array_filter(
            Arr::except($input, ['scope_type', 'scope_id']),
            static fn ($value) => $value !== null && $value !== '',
        );

        // `integer` validates but does not cast, and a query string carries
        // text. Twilio wants a number for the search radius.
        foreach (['distance', 'limit'] as $number) {
            if (isset($criteria[$number])) {
                $criteria[$number] = (int) $criteria[$number];
            }
        }

        try {
            $found = $this->twilio->searchAvailable($connection, $criteria);
        } catch (Throwable) {
            // A search that cannot reach Twilio must not take the Numbers
            // screen down with it — the lines already set up still list.
            return response()->json([
                'data' => [],
                'message' => 'EzBizi could not reach Twilio to search for numbers.',
            ]);
        }

        return response()->json(['data' => $found]);
    }

    /**
     * GET /api/v1/phone/numbers/countries
     *
     * Where this account may buy. Asked of Twilio rather than kept as a list
     * of our own, which would go stale in one direction or the other.
     */
    public function countries(Request $request): JsonResponse
    {
        $input = $request->validate([
            'scope_type' => ['required', Rule::in([
                PhoneNumber::SCOPE_ORGANIZATION,
                PhoneNumber::SCOPE_COMPANY,
                PhoneNumber::SCOPE_FRANCHISE,
            ])],
            'scope_id' => ['required', 'uuid'],
        ]);

        $this->assertScopeExists($input['scope_type'], $input['scope_id']);
        $this->authorize('create', [PhoneNumber::class, $input['scope_type'], $input['scope_id']]);

        $connection = $this->resolver->forScope(
            $this->tenant->idOrFail(),
            $input['scope_type'],
            $input['scope_id'],
        );

        if ($connection === null) {
            return response()->json(['data' => [], 'message' => self::NO_CONNECTION]);
        }

        try {
            return response()->json(['data' => $this->twilio->availableCountries($connection)]);
        } catch (Throwable) {
            // US is the floor, not a guess: every EzBizi account today is US.
            return response()->json([
                'data' => [['country_code' => 'US', 'country' => 'United States']],
            ]);
        }
    }

    /**
     * GET /api/v1/phone/numbers/importable
     *
     * Lines this Twilio account already holds that EzBizi has no row for.
     *
     * Adopting an account means adopting its numbers. A-1 Chimney had been
     * answering five lines for a year; without this the Numbers screen was
     * empty on a connected account, which reads as a broken screen rather
     * than as an unfinished import.
     *
     * Read-only. Nothing is written and no webhook is touched until somebody
     * chooses — see [import].
     */
    public function importable(Request $request): JsonResponse
    {
        $this->authorize('viewAny', PhoneNumber::class);

        $input = $request->validate([
            'scope_type' => ['required', Rule::in([
                PhoneNumber::SCOPE_ORGANIZATION,
                PhoneNumber::SCOPE_COMPANY,
                PhoneNumber::SCOPE_FRANCHISE,
            ])],
            'scope_id' => ['required', 'uuid'],
        ]);

        $connection = $this->resolver->forScope(
            $this->tenant->idOrFail(),
            $input['scope_type'],
            $input['scope_id'],
        );

        if ($connection === null) {
            return response()->json(['data' => [], 'message' => self::NO_CONNECTION]);
        }

        try {
            $held = $this->twilio->listIncoming($connection);
        } catch (Throwable) {
            // Twilio being unreachable must not take the screen down; the
            // numbers already imported still list.
            return response()->json([
                'data' => [],
                'message' => 'EzBizi could not reach Twilio to list the numbers on this phone account.',
            ]);
        }

        $known = PhoneNumber::query()
            ->where('connection_id', $connection->id)
            ->pluck('twilio_sid')
            ->all();

        $rows = [];
        foreach ($held as $line) {
            if (in_array($line['sid'], $known, true)) {
                continue;
            }

            $rows[] = [
                'sid' => $line['sid'],
                'e164' => $line['phone_number'],
                'label' => $line['friendly_name'],

                // Whether this line currently answers to somebody else. Shown
                // rather than hidden: importing rewrites it, and that is a
                // thing somebody should be told before they press the button.
                'answers_elsewhere' => $line['voice_url'] !== ''
                    && ! str_contains($line['voice_url'], rtrim((string) config('app.url'), '/')),
                'voice_url' => $line['voice_url'],
            ];
        }

        return response()->json(['data' => $rows]);
    }

    /**
     * POST /api/v1/phone/numbers/import
     *
     * Take over lines the account already holds: write our row and point the
     * carrier at us.
     *
     * The webhook rewrite is the whole act. A row without it is a number
     * EzBizi lists but never hears from, which is worse than not listing it —
     * the screen would say the line is set up and calls would go on reaching
     * wherever they used to.
     */
    public function import(Request $request): JsonResponse
    {
        $input = $request->validate([
            'scope_type' => ['required', Rule::in([
                PhoneNumber::SCOPE_ORGANIZATION,
                PhoneNumber::SCOPE_COMPANY,
                PhoneNumber::SCOPE_FRANCHISE,
            ])],
            'scope_id' => ['required', 'uuid'],
            'sids' => ['required', 'array', 'min:1'],
            'sids.*' => ['string', 'distinct', 'max:64'],
        ]);

        $this->authorize('create', [
            PhoneNumber::class, $input['scope_type'], $input['scope_id'],
        ]);

        $connection = $this->resolver->forScope(
            $this->tenant->idOrFail(),
            $input['scope_type'],
            $input['scope_id'],
        );

        if ($connection === null) {
            throw ValidationException::withMessages(['scope_id' => self::NO_CONNECTION]);
        }

        $held = [];
        foreach ($this->twilio->listIncoming($connection) as $line) {
            $held[$line['sid']] = $line;
        }

        $host = rtrim((string) config('app.url'), '/');
        $base = $host.'/api/v1/phone';
        $orgId = $connection->organization_id;
        $connectionId = $connection->id;

        $made = [];

        foreach ($input['sids'] as $sid) {
            if (! isset($held[$sid])) {
                throw ValidationException::withMessages([
                    'sids' => 'That line is not on this phone account any more.',
                ]);
            }

            $existing = PhoneNumber::query()
                ->where('connection_id', $connection->id)
                ->where('twilio_sid', $sid)
                ->first();

            if ($existing !== null) {
                continue;
            }

            // Carrier first. If this fails the row is never written, so a
            // retry is a clean retry rather than a half-imported line.
            $this->twilio->updateIncoming($connection, $sid, [
                'voiceUrl' => $base.'/voice/'.$orgId.'/'.$connectionId,
                'voiceMethod' => 'POST',
                'smsUrl' => $base.'/sms/'.$orgId.'/'.$connectionId,
                'smsMethod' => 'POST',
                'statusCallback' => $base.'/status/'.$orgId.'/'.$connectionId,
                'statusCallbackMethod' => 'POST',
            ]);

            $line = $held[$sid];

            $made[] = PhoneNumber::query()->create([
                'organization_id' => $this->tenant->idOrFail(),
                'connection_id' => $connection->id,
                'scope_type' => $input['scope_type'],
                'scope_id' => $input['scope_id'],
                'twilio_sid' => $sid,
                'e164' => $line['phone_number'],
                'label' => $line['friendly_name'] !== ''
                    ? $line['friendly_name']
                    : $line['phone_number'],
                'after_hours' => 'voicemail',
            ]);
        }

        \App\Support\Phone\ExtensionAssigner::assignMissing($request->user()->organization);
        foreach ($made as $line) {
            \App\Support\Phone\EmergencyAddress::attach($line, $this->twilio);
            $line->refresh();
        }

        return response()->json([
            'data' => array_map(fn (PhoneNumber $n) => $this->row($n), $made),
        ], Response::HTTP_CREATED);
    }

    /**
     * PATCH /api/v1/phone/numbers/{number}
     *
     * How this line behaves. Chiefly which ring group answers it: without this
     * `ring_group_id` stayed null forever and TwimlRenderer sent every inbound
     * call to voicemail on an otherwise working account.
     *
     * The E.164 and the Twilio SID are NOT editable. Twilio owns those; a local
     * edit would only make our row disagree with the carrier.
     */
    public function update(Request $request, PhoneNumber $number): JsonResponse
    {
        $this->authorize('update', $number);

        $input = $request->validate([
            'label' => ['sometimes', 'string', 'max:191'],
            'ring_group_id' => ['sometimes', 'nullable', 'uuid', $this->existsInTenant('phone_ring_groups')],
            'hours_schedule_id' => ['sometimes', 'nullable', 'uuid', $this->existsInTenant('phone_hours_schedules')],

            // Which IVR menu answers this line.
            //
            // Nothing could set this. `flow_id` was fillable, the renderer
            // read it, and no endpoint in the module ever wrote it — the only
            // writes anywhere were FlowsController clearing it on delete. So a
            // menu could be built, named, given digits and options, and then
            // never attached to anything: the column was permanently null and
            // every menu in the system was decoration.
            'flow_id' => ['sometimes', 'nullable', 'uuid', $this->existsInTenant('phone_flows')],

            // Which greeting a caller hears when nobody answers.
            'voicemail_box_id' => ['sometimes', 'nullable', 'uuid', $this->existsInTenant('phone_voicemail_boxes')],
            'record' => ['sometimes', 'boolean'],
            'transcribe' => ['sometimes', 'boolean'],

            // Inbound calls and texts become leads (EZ Calls / EZ Texts).
            'lead_line' => ['sometimes', 'boolean'],
            'after_hours' => ['sometimes', Rule::in(PhoneNumber::AFTER_HOURS)],
            'after_hours_number' => ['sometimes', 'nullable', 'string', 'max:16'],

            // Spoken before a recorded call connects. Blank means the built-in
            // sentence — never silence, because this is the wording that
            // discharges all-party consent.
            'recording_notice' => ['sometimes', 'nullable', 'string', 'max:500'],

            // Dialled when the steps above reach nobody while the line is
            // OPEN. Distinct from after_hours_number, which is for CLOSED.
            'fallback_number' => ['sometimes', 'nullable', 'string', 'max:16'],

            // Carrier-side settings. These are pushed to Twilio below, so a
            // save that Twilio refuses must not leave our row disagreeing.
            'emergency_address_sid' => ['sometimes', 'nullable', 'string', 'max:34'],
            'voice_caller_id_lookup' => ['sometimes', 'boolean'],
            'voicemail_transcribe' => ['sometimes', 'boolean'],

            // WHAT THIS LINE ANSWERS WITH, which used to be decided by the
            // ORDER the renderer read two columns in: a menu if `flow_id` was
            // set, otherwise the ring group, otherwise voicemail. That order
            // is invisible on a screen and cannot say "just voicemail" or
            // "ring this one person" at all.
            //
            // Nullable, and null is not a sixth choice: it means "as before",
            // which is how every production line behaves today.
            'answer_with' => ['sometimes', 'nullable', Rule::in(PhoneNumber::answerChoices())],
            'answer_user_id' => ['sometimes', 'nullable', 'uuid', $this->existsInTenant('users')],

            // Override the call flow (Jose, 2026-11-11): forward, a team, a
            // person, voicemail, or a message then hang up, skipping hours and menu.
            'override_on' => ['sometimes', 'boolean'],
            'override' => ['sometimes', 'nullable', 'array'],
            'override.action' => ['required_with:override', Rule::in(['forward', 'ring_group', 'user', 'voicemail', 'hangup'])],
            'override.number' => ['nullable', 'string', 'regex:/^\+[1-9]\d{6,14}$/'],
            'override.ring_group_id' => ['nullable', 'uuid', $this->existsInTenant('phone_ring_groups')],
            'override.user_id' => ['nullable', 'uuid', $this->existsInTenant('users')],
            'override.say' => ['nullable', 'string', 'max:500'],
        ]);

        $override = array_key_exists('override', $input) ? $input['override'] : $number->override;
        if (($input['override_on'] ?? $number->override_on) === true) {
            $missing = match ($override['action'] ?? null) {
                null => ['override' => 'Choose what this line does instead of the call flow.'],
                'forward' => filled($override['number'] ?? null) ? null : ['override.number' => 'Say which number to forward to, like +13055550100.'],
                'ring_group' => filled($override['ring_group_id'] ?? null) ? null : ['override.ring_group_id' => 'Choose the team to ring.'],
                'user' => filled($override['user_id'] ?? null) ? null : ['override.user_id' => 'Choose the person to ring.'],
                default => null,
            };
            if ($missing !== null) {
                throw ValidationException::withMessages($missing);
            }
        }

        // A DESTINATION THAT NAMES NOTHING IS A LINE THAT RINGS NOBODY.
        //
        // Each of these would otherwise save cleanly and fail at the only
        // moment that matters — a real caller on the line — with the screen
        // still showing the setting as chosen. Refused here so the sentence
        // arrives while somebody is looking at the thing they picked.
        $wanted = $input['answer_with'] ?? $number->answer_with;

        if ($wanted === PhoneNumber::ANSWER_USER) {
            $userId = $input['answer_user_id'] ?? $number->answer_user_id;

            if (! filled($userId)) {
                throw ValidationException::withMessages([
                    'answer_user_id' => 'Choose the person this line should ring.',
                ]);
            }
        }

        if ($wanted === PhoneNumber::ANSWER_MENU && ! filled($input['flow_id'] ?? $number->flow_id)) {
            throw ValidationException::withMessages([
                'answer_with' => 'Choose the menu this line should play.',
            ]);
        }

        if ($wanted === PhoneNumber::ANSWER_RING_GROUP
            && ! filled($input['ring_group_id'] ?? $number->ring_group_id)) {
            throw ValidationException::withMessages([
                'answer_with' => 'Choose the team this line should ring.',
            ]);
        }

        // A group on another Twilio account expands to Client identities this
        // call can never reach, so the Dial would ring nobody and look fine.
        if (filled($input['ring_group_id'] ?? null)) {
            $group = PhoneRingGroup::query()->find($input['ring_group_id']);

            if ($group === null || $group->connection_id !== $number->connection_id) {
                throw ValidationException::withMessages([
                    'ring_group_id' => 'That group belongs to a different phone account.',
                ]);
            }
        }

        // Same rule for the menu, and for the same reason: a flow on another
        // account names groups this line cannot reach, so the menu would read
        // its prompt, take a key, and dial nobody.
        if (filled($input['flow_id'] ?? null)) {
            $flow = PhoneFlow::query()->find($input['flow_id']);

            if ($flow === null || $flow->connection_id !== $number->connection_id) {
                throw ValidationException::withMessages([
                    'flow_id' => 'That menu belongs to a different phone account.',
                ]);
            }
        }

        if (($input['after_hours'] ?? $number->after_hours) === 'forward'
            && ! filled($input['after_hours_number'] ?? $number->after_hours_number)) {
            throw ValidationException::withMessages([
                'after_hours_number' => 'Say which number after-hours calls should go to.',
            ]);
        }

        // Carrier first, then our row. The other order leaves EzBizi claiming
        // an emergency address Twilio never accepted — which is the exact
        // failure this setting exists to prevent.
        $carrier = array_filter([
            'emergencyAddressSid' => array_key_exists('emergency_address_sid', $input)
                ? ($input['emergency_address_sid'] ?? '')
                : null,
            'voiceCallerIdLookup' => array_key_exists('voice_caller_id_lookup', $input)
                ? (bool) $input['voice_caller_id_lookup']
                : null,
        ], static fn ($value) => $value !== null);

        if ($carrier !== []) {
            $connection = $this->resolver->forNumber($number) ?? $number->connection;

            if ($connection === null || blank($number->twilio_sid)) {
                throw ValidationException::withMessages([
                    'emergency_address_sid' => self::NO_CONNECTION,
                ]);
            }

            try {
                $this->twilio->updateIncoming($connection, (string) $number->twilio_sid, $carrier);
            } catch (Throwable $e) {
                throw ValidationException::withMessages([
                    'emergency_address_sid' => 'Twilio refused that change: '.$e->getMessage(),
                ]);
            }

            if (array_key_exists('emergency_address_sid', $input)) {
                $input['emergency_address_set'] = filled($input['emergency_address_sid']);
            }
        }

        $number->fill($input);
        if ($number->isDirty(['override_on', 'override'])) {
            app(\App\Support\Audit\AuditLogger::class)->logChange('phone.number.override', $number);
        }
        $number->save();

        return response()->json(['data' => $this->row($number->fresh())]);
    }

    /**
     * GET /api/v1/phone/numbers/{number}/carrier
     *
     * What Twilio says about this line right now: what it can actually do,
     * and whether 911 works.
     *
     * Read live rather than from our row, because Twilio changes
     * `emergency_status` on its own — an address that stops validating
     * deactivates 911 silently, and a stored copy would go on saying it was
     * fine. That is the one field where being a few minutes stale is not a
     * cosmetic problem.
     *
     * The capabilities snapshot on the row is refreshed here as a side
     * effect; this is the only place it is written.
     */
    public function carrier(Request $request, PhoneNumber $number): JsonResponse
    {
        $this->authorize('view', $number);

        $connection = $this->resolver->forNumber($number) ?? $number->connection;

        if ($connection === null || blank($number->twilio_sid)) {
            return response()->json(['data' => null, 'message' => self::NO_CONNECTION]);
        }

        try {
            $live = $this->twilio->fetchIncoming($connection, (string) $number->twilio_sid, $number->account_sid);
        } catch (Throwable) {
            return response()->json([
                'data' => null,
                'message' => 'EzBizi could not reach Twilio for this line.',
            ]);
        }

        $number->forceFill([
            'capabilities' => $live['capabilities'],
            'emergency_address_sid' => $live['emergency_address_sid'],
            'emergency_address_set' => filled($live['emergency_address_sid']),
            'voice_caller_id_lookup' => $live['voice_caller_id_lookup'],
        ])->save();

        return response()->json(['data' => $live]);
    }

    /** DELETE /api/v1/phone/numbers/{number} */
    public function destroy(Request $request, PhoneNumber $number): JsonResponse
    {
        $this->authorize('delete', $number);

        $connection = $this->resolver->forNumber($number) ?? $number->connection;

        // A number held on another account (account_sid) is not EzBizi's to
        // release: removing it here only stops EzBizi using it.
        if ($connection !== null && filled($number->twilio_sid) && blank($number->account_sid)) {
            $this->twilio->releaseIncoming($connection, (string) $number->twilio_sid);
        }

        $number->delete();

        return response()->json(['data' => ['id' => $number->id, 'deleted' => true]]);
    }

    private function assertScopeExists(string $scopeType, string $scopeId): void
    {
        $orgId = $this->tenant->idOrFail();

        $ok = match ($scopeType) {
            PhoneNumber::SCOPE_ORGANIZATION => $scopeId === $orgId,
            PhoneNumber::SCOPE_COMPANY => Company::query()
                ->whereKey($scopeId)
                ->where('organization_id', $orgId)
                ->exists(),
            PhoneNumber::SCOPE_FRANCHISE => Franchise::query()
                ->whereKey($scopeId)
                ->where('organization_id', $orgId)
                ->exists(),
            default => false,
        };

        abort_unless($ok, Response::HTTP_NOT_FOUND, 'That place was not found.');
    }

    private function pickAvailable(PhoneConnection $connection, string $areaCode): string
    {
        $available = $this->twilio->searchAvailable($connection, [
            'area_code' => $areaCode,
            'voice' => true,
            'sms' => true,
        ]);

        if ($available === []) {
            throw ValidationException::withMessages([
                'area_code' => 'Twilio has no available numbers for that area code right now.',
            ]);
        }

        return (string) $available[0]['phone_number'];
    }

    /** @return array<string, mixed> */
    /** Memoised: row() is called once per line and the answer is the same. */
    private ?string $mineResolved = null;

    private bool $mineLookedUp = false;

    private function myAssignedNumberId(): ?string
    {
        if ($this->mineLookedUp) {
            return $this->mineResolved;
        }

        $this->mineLookedUp = true;

        $user = request()->user();
        if ($user === null) {
            return $this->mineResolved = null;
        }

        return $this->mineResolved = PhoneExtension::query()
            ->where('user_id', $user->id)
            ->whereNotNull('number_id')
            ->value('number_id');
    }

    /**
     * Which lines have a call on them right now, and whose.
     *
     * ── Why the Dial screen needs this ──────────────────────────────
     *
     * The Calling as picker offers every line the reader can see, so it offers
     * lines other people are mid-call on. Dialling out under one of those puts
     * a second call on a number somebody is already using and sends the
     * callback to them — and nothing on the screen said the line was busy,
     * because the list is built from `phone_numbers` and busy is a fact about
     * `phone_calls`.
     *
     * ── One query, computed here and passed down ─────────────────────
     *
     * Deliberately NOT memoised on the controller. A route keeps one
     * controller instance for every request in a test, so a cached map would
     * make the second assertion in a test read the first request's answer —
     * a stale "busy" that no amount of re-reading the code explains.
     *
     * @return array<string, array{name: ?string}> keyed by number id
     */
    private function linesInUse(): array
    {
        return PhoneCall::query()
            ->whereIn('status', [
                PhoneCall::STATUS_RINGING,
                PhoneCall::STATUS_IN_PROGRESS,
            ])
            ->whereNotNull('number_id')
            ->with('user:id,name')
            ->get(['id', 'number_id', 'user_id'])
            ->mapWithKeys(fn (PhoneCall $call) => [
                (string) $call->number_id => ['name' => $call->user?->name],
            ])
            ->all();
    }

    /** @return array{ok: bool, problems: list<string>} */
    private function health(PhoneNumber $number, bool $answers): array
    {
        $problems = array_values(array_filter([
            blank($number->twilio_sid) ? 'Not pointed at EzBizi yet, so calls never arrive here.' : null,
            $number->override_on || $answers ? null : 'Calls reach nobody: choose who answers, or a ring group.',
            $number->sms_outbound_allowed ? null : 'Texting: not registered (A2P), so texts cannot be sent.',
            $number->emergency_address_set ? null : (\App\Support\Phone\EmergencyAddress::for($number) === null
                ? \App\Support\Phone\EmergencyAddress::NO_ADDRESS
                : 'E911: missing — the address on file is attached within the hour.'),
            \App\Models\PhonePresence::query()->where('connection_id', $number->connection_id)->available()->exists()
                ? null : 'Nobody is signed in to take calls on this phone account right now.',
        ]));

        return ['ok' => $problems === [], 'problems' => $problems];
    }

    /**
     * @param  array<string, array{name: ?string}>  $live
     */
    private function row(PhoneNumber $number, array $live = []): array
    {
        $steps = (new TwimlRenderer)->describe($number);

        return [
            'id' => $number->id,
            'organization_id' => $number->organization_id,
            'connection_id' => $number->connection_id,
            'scope_type' => $number->scope_type,
            'scope_id' => $number->scope_id,
            'e164' => $number->e164,
            'label' => $number->label,
            'twilio_sid' => $number->twilio_sid,
            'record' => $number->record,
            'transcribe' => $number->transcribe,
            'after_hours' => $number->after_hours,
            'after_hours_number' => $number->after_hours_number,
            'ring_group_id' => $number->ring_group_id,

            // What this line answers with, and whom when that is a person.
            // Null means "as before" — see the migration; a screen draws that
            // as the inherited behaviour rather than as an empty choice.
            'answer_with' => $number->answer_with,
            'override_on' => (bool) $number->override_on,
            'override' => $number->override,
            'answer_user_id' => $number->answer_user_id,
            'flow_id' => $number->flow_id,
            'voicemail_box_id' => $number->voicemail_box_id,
            'hours_schedule_id' => $number->hours_schedule_id,
            'language' => $number->language,
            'sms_outbound_allowed' => (bool) $number->sms_outbound_allowed,
            'lead_line' => (bool) $number->lead_line,

            'emergency_address_sid' => $number->emergency_address_sid,
            'emergency_address_set' => (bool) $number->emergency_address_set,
            'voice_caller_id_lookup' => (bool) $number->voice_caller_id_lookup,
            'voicemail_transcribe' => (bool) $number->voicemail_transcribe,
            'capabilities' => $number->capabilities ?? [],

            'fallback_number' => $number->fallback_number,

            // Null, not the default sentence: the field must come back empty
            // so the form can show the placeholder rather than presenting the
            // built-in wording as something this line chose.
            'recording_notice' => $number->recording_notice,

            // Whether a call reaches anybody.
            //
            // This used to be `ring_group_id !== null || flow_id !== null`,
            // computed right here — a second rule, and a weaker one: it called
            // a line healthy when its group existed but every member was at
            // Do Not Disturb, which is the state that answers no calls while
            // looking perfectly configured. Exactly the drift the Call flow
            // screen exists to prevent, sitting in the payload that screen's
            // sibling reads.
            //
            // ponytail: describe() runs a few finds per number, so this is
            // O(n) queries over the visible lines. Fine at five; if an
            // organization ever holds hundreds, eager-load the groups and
            // presences rather than reintroducing a separate rule here.
            'answers' => collect($steps)->firstWhere('step', 'answer')['usable'] ?? false,

            // PASS OR FAIL on the card (Jose, 2026-11-12: a connection should
            // come out working). Each problem is a sentence saying what to fix.
            'health' => $this->health($number, (bool) (collect($steps)->firstWhere('step', 'answer')['usable'] ?? false)),

            // Whether THIS is the line the person reading calls out from.
            //
            // Sent with the numbers rather than fetched separately, because
            // the Call tab already loads this list and the alternative is a
            // second request whose answer has to agree with the first.
            'assigned_to_me' => $number->id === $this->myAssignedNumberId(),

            // Whether somebody is on this line at this moment, and who.
            //
            // Only populated by index(), which is the list the Dial picker
            // reads. Every other caller of row() is answering a question about
            // one number's SETTINGS, where a live-call fact would be a
            // different subject and one more thing to keep true.
            //
            // The name is null when the call has no user on it — an inbound
            // call still ringing a group belongs to nobody yet, and naming a
            // person there would be a guess.
            'on_call' => array_key_exists($number->id, $live),
            'on_call_with' => $live[$number->id]['name'] ?? null,

            // The whole trace, not one boolean out of it.
            //
            // describe() was already being run for every row and all but one
            // field of it thrown away, so General could not draw a step as
            // skipped and Call flow needed a second request to say WHICH line
            // reaches nobody. Widening costs no queries; it was only ever
            // withheld by the App Store freeze, which has lifted.
            'steps' => $steps,
        ];
    }
}
