<?php

namespace App\Http\Controllers\Api\Monitoring;

use App\Http\Controllers\Api\Controller;
use App\Models\MonitoredDevice;
use App\Support\Access\ScopeReach;
use App\Support\Monitoring\MonitoringView;
use App\Support\Monitoring\ScreenMonitoringExemption;
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
 * InventoryVisibility is the OTHER shape of reach in this codebase — it
 * constrains a query by a `company_id`/`franchise_id` column the record
 * carries itself, which a device does not have. It is the wrong copy for this
 * row for that reason, not because reach here is any looser.
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
        $canSetLighting = $actor->hasPermission('monitoring.lighting.manage');

        // ONE query for the whole roster rather than a lookup per row, and
        // `email` has to be selected below for it to mean anything: the
        // exemption is anchored on the address, so a relation that omitted the
        // column would answer "nobody is exempt" for everyone, silently and
        // without an error — a wrong answer rather than a slow one.
        $exemptEmails = ScreenMonitoringExemption::exemptEmails();

        $devices = MonitoredDevice::query()
            ->with('user:id,name,first_name,last_name,email')
            ->whereHas('user', function (Builder $u) use ($actor) {
                // GROUPED: constrainToVisiblePeople adds bare OR arms (you, OR
                // assigned within reach, OR unassigned). Appended after them
                // ungrouped, the view narrowed only the LAST arm and a sibling
                // company's person walked in through the middle one.
                $u->where(fn (Builder $q) => $this->reach->constrainToVisiblePeople($q, $actor));
                MonitoringView::people($u, $actor);
                $u->where('is_active', true); // removed/erased people are not listed
            })
            ->orderByDesc('last_seen_at')
            ->get();

        return response()->json([
            'data' => $devices
                ->map(fn (MonitoredDevice $d) => $this->present(
                    $d, $canSeeSpecs, $canSeeCapture, $exemptEmails, $canSetLighting
                ))
                ->all(),
        ]);
    }

    /** @return array<string, mixed> */
    /** @param  string[]  $exemptEmails  lower-cased, from ScreenMonitoringExemption */
    private function present(
        MonitoredDevice $d,
        bool $canSeeSpecs,
        bool $canSeeCapture,
        array $exemptEmails = [],
        bool $canSetLighting = false,
    ): array
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

            // Whether this device's owner is exempt from screen monitoring, so
            // the roster can say so plainly instead of leaving a machine that
            // reports no captures looking broken.
            //
            // Told rather than inferred: a caller COULD deduce it today from
            // a windows device that declares `specs` but not `screenshot`,
            // and that deduction would silently become wrong the first time
            // any other reason to withhold `screenshot` appears. It leaks
            // nothing new — the capability list already implies it.
            'screen_exempt' => $d->user->email !== null
                && in_array(mb_strtolower($d->user->email), $exemptEmails, true),
        ];

        // The key is genuinely ABSENT without the grant, not present-and-null:
        // a caller who lacks `monitoring.specs.view` should not learn from the
        // shape of the response that specs exist to be granted.
        if ($canSeeSpecs) {
            $row['specs'] = $d->specs;
        }

        // The lighting a manager set, beside what the PC reports it has
        // (specs.rgb_devices). Absent without the grant, like specs.
        if ($canSetLighting) {
            $row['lighting'] = $d->lighting;
        }

        // What is ON the screen — the window in front, the open tabs — rather
        // than what the machine is. Gated on the SAME grant as the screenshots
        // themselves, because it is the same class of thing: a tab list is a
        // slower screenshot. `monitoring.specs.view` alone buys what the
        // machine IS, never what is on it.
        if ($canSeeCapture) {
            $row['screen_context'] = $d->screen_context;
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
