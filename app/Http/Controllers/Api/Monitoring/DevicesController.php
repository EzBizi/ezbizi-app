<?php

namespace App\Http\Controllers\Api\Monitoring;

use App\Http\Controllers\Api\Controller;
use App\Models\MonitoredDevice;
use App\Models\User;
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
 * is exactly the question HrPeopleController::visibleTo() already answers for
 * the Records tab, so [visibleTo] below is a direct copy of it: the same
 * three arms (yourself, always; anybody whose assignment ScopeReach::constrain
 * covers; the unassigned, but only for someone who reaches the whole
 * organization) built from the same ScopeReach primitive. It is copied rather
 * than shared because the query ROOT differs — HrPeopleController runs it
 * against `users` directly, this runs it against `users` reached THROUGH the
 * device — but the predicate is identical, and it deliberately does not
 * iterate `$user->companies` / `$user->companies->first()`: that walk offers
 * every company a person happens to sit in, siblings included, which is the
 * §3 leak this endpoint exists not to have.
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

        $devices = MonitoredDevice::query()
            ->with('user:id,name,first_name,last_name')
            ->whereHas('user', fn (Builder $u) => $this->visibleTo($u, $actor))
            ->orderByDesc('last_seen_at')
            ->get();

        return response()->json([
            'data' => $devices
                ->map(fn (MonitoredDevice $d) => $this->present($d, $canSeeSpecs))
                ->all(),
        ]);
    }

    /**
     * The people this caller may see — copied verbatim from
     * HrPeopleController::visibleTo(). See the class docblock for why this is
     * the reach shape a user-owned row needs, and not Inventory/Fleet's.
     */
    private function visibleTo(Builder $query, User $actor): void
    {
        $query->whereKey($actor->getKey())
            ->orWhereHas('assignments', fn (Builder $a) => $this->reach->constrain($a, $actor));

        if ($actor->canActOnOrganization($actor->organization)) {
            $query->orWhereDoesntHave('assignments');
        }
    }

    /** @return array<string, mixed> */
    private function present(MonitoredDevice $d, bool $canSeeSpecs): array
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
            $row['specs'] = $d->specs;
        }

        return $row;
    }
}
