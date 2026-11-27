<?php

namespace App\Http\Controllers\Api\Phone;

use App\Http\Controllers\Api\Controller;
use App\Http\Controllers\Api\Phone\Concerns\ResolvesPhoneConnections;
use App\Models\PhoneCall;
use App\Models\PhoneConnection;
use App\Models\PhoneNumber;
use App\Models\PhoneTrustObservation;
use App\Support\Phone\Attestation;
use App\Support\Phone\PhoneScope;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

/**
 * Phone › Settings › Trust & caller identity.
 *
 * Answers one question — "why do my calls show up as spam" — which needs three
 * unlike things: how carriers graded the calls, what is registered with
 * Twilio, and what a person actually saw on a handset.
 */
class TrustController extends Controller
{
    use ResolvesPhoneConnections;

    public function __construct(
        private readonly TenantContext $tenant,
    ) {}

    /**
     * GET /api/v1/phone/trust/attestation
     *
     * How outbound calls were graded, over a window.
     *
     * ── Three buckets, two of which are bad news and one of which is not ──
     *
     * A     — the carrier vouched for the number.
     * B/C/none — degraded. `none` belongs HERE: a call the carrier sent
     *           unsigned is a genuine bad answer, not a gap in our records.
     * null  — not captured. We were not looking.
     *
     * The rate is computed against GRADED calls only, and the uncaptured count
     * travels separately so the screen can draw it as the gap it is. Folding
     * null in with `none` would report every call from before capture as
     * untrusted — the more alarming reading, on a screen somebody opens
     * because they are already worried.
     */
    public function attestation(Request $request): JsonResponse
    {
        $input = $request->validate([
            'days' => ['sometimes', 'integer', 'min:1', 'max:3650'],
        ]);

        $this->authorize('viewAny', PhoneNumber::class);

        $connectionIds = $this->connectionsInReach($request->user())->pluck('id')->all();

        if ($connectionIds === []) {
            return response()->json(['data' => $this->emptyAttestation()]);
        }

        $days = (int) ($input['days'] ?? 30);
        $since = Carbon::now('UTC')->subDays($days)->startOfDay();

        $calls = PhoneCall::query()
            ->whereIn('connection_id', $connectionIds)
            ->where('direction', PhoneCall::DIRECTION_OUTBOUND)
            ->where('created_at', '>=', $since)
            // Counted in SQL (audit 11-27: it loaded every outbound call).
            ->groupBy('attestation')
            ->selectRaw('attestation, count(*) as n')
            ->pluck('n', 'attestation');

        $counts = ['A' => 0, 'B' => 0, 'C' => 0, Attestation::NONE => 0];
        $uncaptured = 0;
        $total = 0;

        foreach ($calls as $grade => $n) {
            $total += (int) $n;

            if ($grade === '' || ! array_key_exists($grade, $counts)) {
                $uncaptured += (int) $n;

                continue;
            }

            $counts[$grade] += (int) $n;
        }

        $graded = $counts['A'] + $counts['B'] + $counts['C'] + $counts[Attestation::NONE];
        $degraded = $counts['B'] + $counts['C'] + $counts[Attestation::NONE];

        return response()->json(['data' => [
            'days' => $days,
            'total' => $total,
            'graded' => $graded,
            'uncaptured' => $uncaptured,
            'full' => $counts['A'],
            'degraded' => $degraded,
            'counts' => $counts,

            // Null rather than zero when nothing is graded: "0% degraded" out
            // of no measurements reads as a clean bill of health.
            'degraded_rate' => $graded === 0
                ? null
                : round(($degraded / $graded) * 100, 1),

            // Separates "capture is on and there were no calls" from "capture
            // has never recorded anything", which look identical on screen.
            'capturing' => $graded > 0,
        ]]);
    }

    /** GET /api/v1/phone/trust/observations */
    public function observations(Request $request): JsonResponse
    {
        $this->authorize('viewAny', PhoneNumber::class);

        $connectionIds = $this->connectionsInReach($request->user())->pluck('id')->all();

        if ($connectionIds === []) {
            return response()->json(['data' => []]);
        }

        $rows = PhoneScope::apply(
            PhoneTrustObservation::query()->whereIn('connection_id', $connectionIds),
            $request->user(),
        )
            ->with('observer')
            ->orderByDesc('observed_at')
            ->limit(100)
            ->get()
            ->map(fn (PhoneTrustObservation $row) => $this->observationRow($row))
            ->all();

        return response()->json(['data' => $rows]);
    }

    /** POST /api/v1/phone/trust/observations */
    public function storeObservation(Request $request): JsonResponse
    {
        $input = $request->validate([
            'connection_id' => ['required', 'uuid'],
            'e164' => ['required', 'string', 'max:16'],
            'carrier' => ['required', 'string', 'max:64'],

            // Free text on purpose: carrier labels differ and change without
            // notice, so a fixed list would be wrong within months.
            'observed' => ['required', 'string', 'max:191'],
            'observed_at' => ['sometimes', 'date'],
        ]);

        $connection = PhoneConnection::query()->find($input['connection_id']);
        abort_if($connection === null, Response::HTTP_NOT_FOUND);
        $this->authorize('update', $connection);

        [$scopeType, $scopeId] = PhoneScope::filing($request->user());

        $row = PhoneTrustObservation::query()->create([
            'organization_id' => $this->tenant->idOrFail(),
            'connection_id' => $connection->id,
            'scope_type' => $scopeType,
            'scope_id' => $scopeId,
            'e164' => trim($input['e164']),
            'carrier' => trim($input['carrier']),
            'observed' => trim($input['observed']),
            'observed_by' => $request->user()->id,
            'observed_at' => $input['observed_at'] ?? now(),
        ]);

        return response()->json(
            ['data' => $this->observationRow($row->fresh('observer'))],
            Response::HTTP_CREATED,
        );
    }

    /** DELETE /api/v1/phone/trust/observations/{observation} */
    public function destroyObservation(Request $request, PhoneTrustObservation $observation): JsonResponse
    {
        $connection = $observation->connection;
        abort_if($connection === null, Response::HTTP_NOT_FOUND);
        $this->authorize('update', $connection);

        $observation->delete();

        return response()->json(['data' => ['id' => $observation->id, 'deleted' => true]]);
    }

    /** @return array<string, mixed> */
    private function emptyAttestation(): array
    {
        return [
            'days' => 30,
            'total' => 0,
            'graded' => 0,
            'uncaptured' => 0,
            'full' => 0,
            'degraded' => 0,
            'counts' => ['A' => 0, 'B' => 0, 'C' => 0, Attestation::NONE => 0],
            'degraded_rate' => null,
            'capturing' => false,
        ];
    }

    /** @return array<string, mixed> */
    private function observationRow(PhoneTrustObservation $row): array
    {
        return [
            'id' => $row->id,
            'e164' => $row->e164,
            'carrier' => $row->carrier,
            'observed' => $row->observed,
            'observed_at' => $row->observed_at?->toIso8601String(),
            'observed_by' => $row->observer?->name,
        ];
    }
}
