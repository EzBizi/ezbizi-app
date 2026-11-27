<?php

namespace App\Http\Controllers\Api\Phone;

use App\Http\Controllers\Api\Controller;
use App\Models\Company;
use App\Models\PhoneServiceRequest;
use App\Models\PhoneServiceRequestCompany;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * A tenant asking EzBizi for phone service (§4 gate 2).
 *
 * ── What the customer sees, and why this shape ───────────────────
 *
 * Phone is a paid extra billed to EzBizi, so it cannot be self-serve from
 * nothing. But a tab that simply refuses is a dead end, and §6 forbids those.
 * So the tab is visible, it explains itself, and it offers the one thing that
 * moves: asking.
 *
 * The customer never meets Twilio here. They say which of their companies need
 * phone service, roughly how busy those lines will be, whether they need
 * texting, and what for. That is what a person needs to decide, and nothing
 * beyond it is asked.
 */
class ServiceRequestsController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenant,
    ) {}

    /**
     * GET /api/v1/phone/service-request
     *
     * The state the Phone tab draws itself from: what was asked, what was
     * answered, which companies may activate, and whether asking again is
     * open yet.
     */
    public function show(Request $request): JsonResponse
    {
        $this->authorize('viewAny', PhoneServiceRequest::class);

        $latest = PhoneServiceRequest::query()
            ->with(['companies.company:id,name', 'requester:id,name'])
            ->latest('created_at')
            ->first();

        $approved = PhoneServiceRequestCompany::query()
            ->where('approved', true)
            ->pluck('company_id')
            ->all();

        $cooldownEndsAt = $latest?->cooldownEndsAt();
        $open = $cooldownEndsAt !== null && $cooldownEndsAt->isFuture();

        return response()->json([
            'data' => [
                'latest' => $latest === null ? null : $this->row($latest),
                'approved_company_ids' => $approved,

                // One answer, computed once here, so the screen never has to
                // work out whether the button should be there.
                'may_request' => $latest === null
                    || ($latest->status !== PhoneServiceRequest::STATUS_PENDING && ! $open),
                'cooldown_ends_at' => $open ? $cooldownEndsAt->toIso8601String() : null,
                'volumes' => PhoneServiceRequest::VOLUMES,
            ],
        ]);
    }

    /** POST /api/v1/phone/service-request */
    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', PhoneServiceRequest::class);

        $input = $request->validate([
            'company_ids' => ['required', 'array', 'min:1'],
            'company_ids.*' => [
                'uuid',
                'distinct',
                Rule::exists('companies', 'id')->where('organization_id', $this->tenant->idOrFail()),
            ],
            'expected_volume' => ['required', Rule::in(array_keys(PhoneServiceRequest::VOLUMES))],
            'wants_sms' => ['required', 'boolean'],
            'purpose' => ['required', 'string', 'min:20', 'max:2000'],
        ]);

        $latest = PhoneServiceRequest::query()->latest('created_at')->first();

        if ($latest?->status === PhoneServiceRequest::STATUS_PENDING) {
            throw ValidationException::withMessages([
                'company_ids' => 'You already have a request waiting for an answer.',
            ]);
        }

        $cooldownEndsAt = $latest?->cooldownEndsAt();

        if ($cooldownEndsAt !== null && $cooldownEndsAt->isFuture()) {
            throw ValidationException::withMessages([
                'company_ids' => 'You can ask again on '
                    .$cooldownEndsAt->translatedFormat('j F Y').'.',
            ]);
        }

        // Already-approved companies are dropped rather than refused: somebody
        // ticking every company because they want one more should not be told
        // off, they should get the one more.
        $already = PhoneServiceRequestCompany::query()
            ->where('approved', true)
            ->pluck('company_id')
            ->all();

        $wanted = array_values(array_diff($input['company_ids'], $already));

        if ($wanted === []) {
            throw ValidationException::withMessages([
                'company_ids' => 'Every company you picked already has phone service.',
            ]);
        }

        $created = DB::transaction(function () use ($request, $input, $wanted) {
            $row = PhoneServiceRequest::create([
                'requested_by' => $request->user()->id,
                'status' => PhoneServiceRequest::STATUS_PENDING,
                'expected_volume' => $input['expected_volume'],
                'wants_sms' => (bool) $input['wants_sms'],
                'purpose' => trim((string) $input['purpose']),
            ]);

            foreach ($wanted as $companyId) {
                PhoneServiceRequestCompany::create([
                    'request_id' => $row->id,
                    'company_id' => $companyId,
                    'approved' => null,
                ]);
            }

            return $row;
        });

        return response()->json(
            ['data' => $this->row($created->fresh(['companies.company:id,name', 'requester:id,name']))],
            Response::HTTP_CREATED,
        );
    }

    /** @return array<string, mixed> */
    private function row(PhoneServiceRequest $row): array
    {
        $row->loadMissing(['companies.company:id,name', 'requester:id,name']);

        return [
            'id' => $row->id,
            'status' => $row->status,
            'expected_volume' => $row->expected_volume,
            'expected_volume_label' => PhoneServiceRequest::VOLUMES[$row->expected_volume] ?? '',
            'wants_sms' => $row->wants_sms,
            'purpose' => $row->purpose,
            'requested_by' => $row->requester?->name,
            'requested_at' => $row->created_at?->toIso8601String(),
            'decided_at' => $row->decided_at?->toIso8601String(),

            // The sentence a person wrote when they decided. Shown to the
            // customer as-is: a refusal explains itself or it is a dead end.
            'decision_note' => $row->decision_note,

            'companies' => $row->companies->map(fn (PhoneServiceRequestCompany $line) => [
                'company_id' => $line->company_id,
                'name' => $line->company?->name ?? 'A company',
                'approved' => $line->approved,
            ])->values()->all(),
        ];
    }

    /** Companies this organization has, for the ticklist. */
    public function companies(Request $request): JsonResponse
    {
        $this->authorize('create', PhoneServiceRequest::class);

        $approved = PhoneServiceRequestCompany::query()
            ->where('approved', true)
            ->pluck('company_id')
            ->all();

        $rows = Company::query()
            ->switchedOn()
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Company $company) => [
                'id' => $company->id,
                'name' => $company->name,
                'has_service' => in_array($company->id, $approved, true),
            ])
            ->all();

        return response()->json(['data' => $rows]);
    }
}
