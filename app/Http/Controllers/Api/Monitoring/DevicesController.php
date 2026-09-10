<?php

namespace App\Http\Controllers\Api\Monitoring;

use App\Http\Controllers\Api\Controller;
use App\Models\MonitoredDevice;
use App\Support\Access\ScopeReach;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The devices roster — who is signed in, from what, and (with the extra
 * grant) what the machine looks like underneath (2026-08-28-monitoring-pc-specs,
 * Task 5).
 *
 * ── §3/§4 reach: scoped by the DEVICE'S OWNER, not by a place column ──
 *
 * A device has no `company_id`/`franchise_id` of its own — it belongs to a
 * `user_id`, and the question a reach check has to answer is "may this caller
 * see THAT PERSON", not "may this caller see that company's catalogue". That
 * is exactly the question `ScopeReach::constrainToVisiblePeople()` answers —
 * the same predicate HrPeopleController's Records tab uses, extracted there
 * so a `users` query built directly (HrPeople) and one reached THROUGH a
 * relation (`whereHas('user', ...)` here) share one implementation instead of
 * two copies that could drift. It deliberately does not iterate
 * `$user->companies` / `$user->companies->first()`: that walk offers every
 * company a person happens to sit in, siblings included, which is the §3 leak
 * this endpoint exists not to have.
 *
 * InventoryVisibility/VehicleVisibility are the OTHER shape of reach in this
 * codebase — they constrain a query by a `company_id`/`franchise_id` column
 * the record carries itself, which a device does not have. They are the wrong
 * copy for this row for that reason, not because reach here is any looser.
 */
class DevicesController extends Controller
{
    public function __construct(private readonly ScopeReach $reach) {}

    /** GET /api/v1/monitoring/devices */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', MonitoredDevice::class);

        $actor = $request->user();
        $canSeeSpecs = $actor->hasPermission('monitoring.specs.view');
        $canSeeCapture = $actor->hasPermission('monitoring.screenshots.view');

        $devices = MonitoredDevice::query()
            ->with('user:id,name,first_name,last_name')
            ->whereHas('user', fn (Builder $u) => $this->reach->constrainToVisiblePeople($u, $actor))
            ->orderByDesc('last_seen_at')
            ->get();

        return response()->json([
            'data' => $devices
                ->map(fn (MonitoredDevice $d) => $this->present($d, $canSeeSpecs, $canSeeCapture))
                ->all(),
        ]);
    }

    /** @return array<string, mixed> */
    private function present(MonitoredDevice $d, bool $canSeeSpecs, bool $canSeeCapture): array
    {
        $row = [
            'id' => $d->id,
            'user' => ['id' => $d->user->id, 'name' => $d->user->name],
            'device_key' => $d->device_key,
            'label' => $d->label,
            'platform' => $d->platform,
            'capabilities' => $d->capabilities,
            'user_agent' => $d->user_agent,
            'ip_address' => $d->ip_address,
            'last_seen_at' => $d->last_seen_at?->toIso8601String(),
            'current_module' => $d->current_module,
        ];

        // The key is genuinely ABSENT without the grant, not present-and-null:
        // a caller who lacks `monitoring.specs.view` should not learn from the
        // shape of the response that specs exist to be granted.
        if ($canSeeSpecs) {
            $specs = $d->specs;

            // `active_window` rides in the specs blob because the agent reads
            // it in the same pass, but it is NOT a spec: it is the title of
            // what is on screen right now, which is the same class of thing a
            // screenshot shows. So it needs the screenshot grant, not the
            // hardware one — `monitoring.specs.view` alone buys you what the
            // machine IS, never what is on it.
            if (! $canSeeCapture && is_array($specs)) {
                unset($specs['active_window']);
            }

            $row['specs'] = $specs;
        }

        // Why the last capture did not happen, for whoever can see captures at
        // all — same absent-not-null rule as `specs` above, and gated on the
        // SAME grant as the screenshots themselves, since it says nothing a
        // holder of that grant could not already infer from the captures.
        if ($canSeeCapture) {
            $row['capture'] = [
                'last_attempt_at' => $d->capture_last_attempt_at?->toIso8601String(),
                'last_error' => $d->capture_last_error,
            ];
        }

        return $row;
    }
}
