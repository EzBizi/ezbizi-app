<?php

namespace App\Http\Controllers\Api\Monitoring;

use App\Http\Controllers\Api\Controller;
use App\Models\MonitoredDevice;
use App\Models\MonitoringScreenshot;
use App\Models\User;
use App\Support\Access\ScopeReach;
use App\Support\Monitoring\MonitoringView;
use App\Support\Monitoring\ScreenMonitoringExemption;
use App\Support\Monitoring\ScreenshotStore;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * A device's own periodic screen captures — ingest (`store`, Task 3) and the
 * read side (`index`/`show`, Task 4) of 2026-08-31-monitoring-screenshots.
 *
 * `store` is deliberately not gated by a permission, same reasoning as
 * HeartbeatController: this is not "view another device's screenshots", it
 * is a device reporting a capture of itself. `routes/api.php` keeps it
 * OUTSIDE the `idle` middleware group next to the heartbeat — a background
 * upload must not slide the session idle window either. `index`/`show` are
 * the opposite of that: they ARE somebody viewing another device's
 * captures, so they live in the MAIN `v1` (idle-bearing) group, gated by
 * `monitoring.screenshots.view` (MonitoringScreenshotPolicy) and §3-scoped
 * through `visibleTo()` — see its docblock.
 *
 * ── Why this never creates the device ─────────────────────────────
 *
 * Device creation, its `capabilities`, and the `monitoring.new_device_seen`
 * emission all belong in exactly one place: `HeartbeatController`. This
 * controller and the heartbeat share the same `device_key` from the same
 * desktop app, which heartbeats every 60s from launch while the first
 * screenshot capture is ~5 minutes in — so by the time a screenshot can
 * possibly land, a heartbeat already has. An earlier version of this
 * controller bootstrapped the device defensively for a case that cannot
 * happen, and that bootstrap was wrong twice over: it guessed
 * `capabilities = ['screenshot']`, which the heartbeat's later
 * `fill(['capabilities' => ...])` can clobber, and it never emitted
 * `monitoring.new_device_seen`, so a screenshot-first device silently
 * bypassed that automation trigger. Requiring the device to already exist
 * removes both failure modes instead of patching either one.
 */
class ScreenshotsController extends Controller
{
    /**
     * A compressed primary-monitor JPEG runs ~60-120KB; this leaves headroom
     * for a multi-monitor or unusually busy capture without opening the door
     * to a multi-megabyte upload every capture interval.
     */
    private const MAX_KILOBYTES = 2048;

    /** `index`'s page size cap — a caller asking for more gets this instead, never an error. */
    private const MAX_LIMIT = 100;

    private const DEFAULT_LIMIT = 50;

    /** The SECURITY exemption's refusal sentence, shared by every screen-reaching guard. */
    private const EXEMPT_REFUSAL = ScreenMonitoringExemption::REFUSAL;

    public function __construct(
        private readonly ScreenshotStore $store,
        private readonly TenantContext $tenant,
        private readonly ScopeReach $reach,
    ) {}

    public function store(Request $request): Response
    {
        $data = $request->validate([
            'image' => ['required', 'file', 'image', 'mimes:jpeg', 'max:'.self::MAX_KILOBYTES],
            'device_key' => ['required', 'string', 'max:64'],
        ]);

        $organizationId = $this->tenant->idOrFail();
        $userId = $request->user()->getKey();

        // Same three-key resolution as HeartbeatController::store() — this
        // MUST land on the identical row a heartbeat would, never a
        // divergent one. Not found is a real rejection, not a row to create:
        // see the class docblock for why.
        $device = MonitoredDevice::query()->ownedBy($request->user(), $data['device_key'])->first();

        if ($device === null) {
            abort(404, 'No registered device for this session — send a heartbeat first.');
        }

        // SECURITY exemption, defence-in-depth: even though an exempt device
        // never advertises `screenshot` (the heartbeat strips it), a tampered
        // client could still POST a capture. Refuse BEFORE a single byte is
        // stored — the Lead Developer's screen is never written to disk.
        if (ScreenMonitoringExemption::isExempt($device->user)) {
            abort(403, self::EXEMPT_REFUSAL);
        }

        $image = $request->file('image');
        $bytes = file_get_contents($image->getRealPath());
        $dimensions = getimagesize($image->getRealPath());
        if ($bytes === false || $dimensions === false) {
            // The `image` rule already passed, so a read failure here means
            // the tmp upload vanished or is unreadable mid-request — that is
            // exceptional, not a valid zero-byte/zero-dimension row.
            abort(422, 'The uploaded image could not be read.');
        }

        $capturedAt = now();

        $path = $this->store->put(
            $organizationId,
            $device->id,
            $capturedAt->toIso8601String(),
            $bytes,
        );

        MonitoringScreenshot::create([
            'organization_id' => $organizationId,
            'device_id' => $device->id,
            'user_id' => $userId,
            'captured_at' => $capturedAt,
            'path' => $path,
            'bytes' => strlen($bytes),
            'width' => $dimensions[0],
            'height' => $dimensions[1],
        ]);

        return response()->noContent();
    }

    /**
     * GET /api/v1/monitoring/screenshots — paged metadata, newest first.
     *
     * `monitoring.screenshots.view`-gated (MonitoringScreenshotPolicy) and
     * §3-scoped: see [visibleTo] for why the reach check goes through the
     * DEVICE's user rather than this row's own `user_id` column.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', MonitoringScreenshot::class);

        $query = $this->visibleTo($request->user());

        if ($deviceId = $request->query('device_id')) {
            $query->where('device_id', $deviceId);
        }

        if ($before = $request->query('before')) {
            $query->where('captured_at', '<', $before);
        }

        $limit = max(1, min((int) $request->query('limit', self::DEFAULT_LIMIT), self::MAX_LIMIT));

        $screenshots = $query->orderByDesc('captured_at')->limit($limit)->get();

        return response()->json([
            'data' => $screenshots->map(fn (MonitoringScreenshot $s) => [
                'id' => $s->id,
                'device_id' => $s->device_id,
                'captured_at' => $s->captured_at?->toIso8601String(),
                'bytes' => $s->bytes,
                'width' => $s->width,
                'height' => $s->height,
            ])->all(),
        ]);
    }

    /**
     * GET /api/v1/monitoring/screenshots/{id} — the JPEG itself.
     *
     * Same gate and same [visibleTo] scope as `index`: a caller who could not
     * see this row on the list must not be able to fetch it by guessing (or
     * being handed) its id either — §3 has to hold for a direct id, not only
     * for a list a UI happens to render.
     *
     * 404 covers two different absences with the same word, deliberately —
     * see ScopeReach::covers for why that is the right answer rather than a
     * convenient one: a row outside the caller's reach and a row that never
     * existed must look identical, or the id space itself becomes an oracle.
     * A row whose file is gone (pruned, or never written) 404s the same way.
     */
    public function show(Request $request, string $id): Response
    {
        $this->authorize('viewAny', MonitoringScreenshot::class);

        $screenshot = $this->visibleTo($request->user())->find($id);

        if ($screenshot === null) {
            abort(404);
        }

        $bytes = $this->store->get($screenshot->path);

        if ($bytes === '') {
            abort(404);
        }

        return response($bytes, 200, [
            'Content-Type' => 'image/jpeg',
            'Content-Length' => (string) strlen($bytes),
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /**
     * §3: a `monitoring_screenshots` query narrowed to what this actor may
     * see — mirrors DevicesController::index's
     * `whereHas('user', fn ($u) => $reach->constrainToVisiblePeople($u, $actor))`,
     * one relation deeper: a screenshot belongs to a DEVICE, which belongs to
     * the person it is reach-scoped by, so the same predicate is applied
     * through `device.user` rather than directly. This row does carry its own
     * `user_id` (stamped at ingest, see `store()`), but that is deliberately
     * NOT what is scoped against here — mirroring the device's own reach
     * keeps one predicate for "may you see this person" shared with
     * DevicesController rather than a second copy that could drift from it.
     *
     * @return Builder<MonitoringScreenshot>
     */
    private function visibleTo(User $actor): Builder
    {
        $query = MonitoringScreenshot::query()
            ->whereHas('device', fn (Builder $d) => $d->whereHas(
                'user', function (Builder $u) use ($actor) {
                    // GROUPED: constrainToVisiblePeople adds bare OR arms (you, OR
                    // assigned within reach, OR unassigned). Appended after them
                    // ungrouped, the view narrowed only the LAST arm and a sibling
                    // company's person walked in through the middle one.
                    $u->where(fn (Builder $q) => $this->reach->constrainToVisiblePeople($q, $actor));
                    MonitoringView::people($u, $actor);
                }
            ));

        // SECURITY: a Lead Developer's screen is exempt from monitoring, so even
        // a screenshot captured before the exemption shipped (or before the
        // staff account existed) is never listed OR served — `show` then 404s
        // it, the same "you cannot see this" answer §3 gives. A SEPARATE
        // whereDoesntHave, not a condition inside the reach closure above:
        // constrainToVisiblePeople builds an OR-group (yourself OR assigned OR
        // …), and a where added inside it would fold into one arm and leave the
        // others — notably "yourself", the Lead Developer viewing their own
        // device — unfiltered. AND-ed at the top level, it holds for every arm.
        $exemptEmails = ScreenMonitoringExemption::exemptEmails();
        if ($exemptEmails !== []) {
            $query->whereDoesntHave(
                'device.user',
                fn (Builder $u) => $u->whereIn(DB::raw('LOWER(email)'), $exemptEmails)
            );
        }

        return $query;
    }
}
