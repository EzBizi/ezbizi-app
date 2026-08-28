<?php

namespace App\Http\Controllers\Api;

use App\Models\PhoneServiceRequest;
use App\Models\PhoneServiceRequestCompany;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The Development-side queue: who has asked for phone service, and answering.
 *
 * ── Why this reads across tenants ────────────────────────────────
 *
 * Everything else in the API is scoped to one organization by TenantContext.
 * This is the control plane: the whole point is to see every customer's
 * request in one list, and it sits behind the `staff` middleware rather than
 * `tenant`. Queries here are deliberately unscoped and must stay in this
 * controller — a tenant-facing endpoint that did this would be a leak.
 *
 * ── Why approving does not provision ─────────────────────────────
 *
 * The answer is per company, but which companies actually want a line is the
 * customer's business and may be months apart. So approving opens the door and
 * the customer activates each company when they are ready. A Twilio subaccount
 * costs nothing to create; the money is numbers, usage, and A2P brand fees,
 * all of which come later and are visible on the bill.
 */
class StaffPhoneRequestsController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenant,
    ) {}

    /** GET /api/v1/staff/phone-requests */
    public function index(Request $request): JsonResponse
    {
        $rows = PhoneServiceRequest::query()
            ->withoutGlobalScopes()
            ->with(['organization:id,name', 'requester:id,name', 'companies.company:id,name'])
            ->orderByRaw("CASE WHEN status = ? THEN 0 ELSE 1 END", [PhoneServiceRequest::STATUS_PENDING])
            ->latest('created_at')
            ->limit(200)
            ->get()
            ->map(fn (PhoneServiceRequest $row) => $this->row($row))
            ->all();

        return response()->json(['data' => $rows]);
    }

    /**
     * POST /api/v1/staff/phone-requests/{request}/decide
     *
     * Approve some companies, refuse others, in one act. A note is required on
     * anything less than a full yes: a refusal without a reason is the dead end
     * §6 forbids, and the customer sees this sentence verbatim.
     */
    public function decide(Request $httpRequest, string $requestId): JsonResponse
    {
        $input = $httpRequest->validate([
            'approved_company_ids' => ['present', 'array'],
            'approved_company_ids.*' => ['uuid'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        /** @var PhoneServiceRequest|null $row */
        $row = PhoneServiceRequest::query()
            ->withoutGlobalScopes()
            ->with('companies')
            ->find($requestId);

        if ($row === null) {
            return response()->json(['message' => 'That request no longer exists.'], 404);
        }

        if ($row->status !== PhoneServiceRequest::STATUS_PENDING) {
            throw ValidationException::withMessages([
                'status' => 'That request has already been answered.',
            ]);
        }

        $approved = $input['approved_company_ids'];
        $onRequest = $row->companies->pluck('company_id')->all();
        $unknown = array_diff($approved, $onRequest);

        if ($unknown !== []) {
            throw ValidationException::withMessages([
                'approved_company_ids' => 'That company is not on this request.',
            ]);
        }

        $everyone = count($approved) === count($onRequest) && $onRequest !== [];
        $nobody = $approved === [];

        if (! $everyone && ! filled($input['note'] ?? null)) {
            throw ValidationException::withMessages([
                'note' => 'Say why, in a sentence. The customer reads it.',
            ]);
        }

        DB::transaction(function () use ($row, $approved, $everyone, $nobody, $input, $httpRequest) {
            foreach ($row->companies as $line) {
                $line->approved = in_array($line->company_id, $approved, true);
                $line->save();
            }

            $row->status = match (true) {
                $everyone => PhoneServiceRequest::STATUS_APPROVED,
                $nobody => PhoneServiceRequest::STATUS_DECLINED,
                default => PhoneServiceRequest::STATUS_PARTIAL,
            };
            $row->decided_by = $httpRequest->user()?->id;
            $row->decided_at = now();
            $row->decision_note = filled($input['note'] ?? null) ? trim((string) $input['note']) : null;
            $row->save();
        });

        return response()->json(['data' => $this->row($row->fresh([
            'organization:id,name', 'requester:id,name', 'companies.company:id,name',
        ]))]);
    }

    /** @return array<string, mixed> */
    private function row(PhoneServiceRequest $row): array
    {
        return [
            'id' => $row->id,
            'organization' => $row->organization?->name ?? 'Unknown',
            'organization_id' => $row->organization_id,
            'status' => $row->status,
            'expected_volume' => $row->expected_volume,
            'expected_volume_label' => PhoneServiceRequest::VOLUMES[$row->expected_volume] ?? '',
            'wants_sms' => $row->wants_sms,
            'purpose' => $row->purpose,
            'requested_by' => $row->requester?->name,
            'requested_at' => $row->created_at?->toIso8601String(),
            'decided_at' => $row->decided_at?->toIso8601String(),
            'decision_note' => $row->decision_note,
            'companies' => $row->companies->map(fn (PhoneServiceRequestCompany $line) => [
                'company_id' => $line->company_id,
                'name' => $line->company?->name ?? 'A company',
                'approved' => $line->approved,
            ])->values()->all(),
        ];
    }
}
