<?php

namespace App\Http\Controllers\Api\Monitoring;

use App\Http\Controllers\Api\Controller;
use App\Models\MonitoredDevice;
use App\Models\MonitoringScreenshot;
use App\Support\Monitoring\LiveFrameStore;
use App\Support\Monitoring\ScreenMonitoringExemption;
use App\Support\Tenancy\TenantContext;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

/**
 * Live View — a manager watching a monitored machine's screen update every
 * ~1-2s, not a browse through the 30-day `monitoring_screenshots` history
 * ScreenshotsController serves.
 *
 * ── Why this writes no row, ever ──────────────────────────────────
 *
 * // ponytail: no new table. This stack has no websocket server — the house
 * // pattern everywhere else is HTTP polling (see HeartbeatController,
 * // ScreenshotsController) — so a live view is necessarily poll-driven and
 * // EPHEMERAL by construction: the agent only captures and uploads while
 * // somebody is actually watching, the viewer polls a single overwriting
 * // frame, and neither side keeps anything once the viewer looks away. A
 * // migration earns its place when something needs to survive past "is
 * // anyone watching right now" — replaying the last five minutes of a
 * // shift, say. Nothing today asks for that, and bolting a table onto an
 * // ephemeral-by-design feature "just in case" is exactly the kind of
 * // schema nothing will ever query.
 *
 * ── The two signals, and why each is a Cache key rather than a column ──
 *
 * `watch` records that SOMEBODY wants live frames from a device — a viewer
 * re-POSTs it every few seconds while a live view is open, so the key's own
 * TTL (`WANTED_TTL_SECONDS`) is the "is anyone still watching" answer: no
 * fresher POST inside the window and the key falls out of the cache on its
 * own, no cleanup job required. `liveFrame` (the agent's upload) records
 * when a device last actually sent one, on a longer TTL than the freshness
 * window a viewer is held to — see `liveFrameShow` for why those are two
 * different numbers rather than one.
 *
 * ── Route grouping mirrors ScreenshotsController exactly ──────────
 *
 * `watch` and `liveFrameShow` are a person looking at another device's
 * screen — real navigation, §3-scoped, gated by `monitoring.screenshots.view`
 * — so both live in the MAIN idle-bearing `v1` group. `liveWanted` and
 * `liveFrame` are the AGENT polling and uploading in the background, the
 * same shape as the heartbeat and the screenshot upload, so both live in the
 * idle-less group next to them: a background loop must never be what keeps
 * a walked-away session's idle window open. See routes/api.php.
 *
 * ── Why `monitoring.screenshots.view` and not a new grant ─────────
 *
 * Seeing a live frame is seeing the screen — the same fact
 * `MonitoringScreenshotPolicy` already gates, one frame away from a capture
 * of the same screen at rest. A second permission would ask an
 * administrator to grant the same thing twice under two names.
 */
class LiveViewController extends Controller
{
    /**
     * How long a `watch` registration stays fresh. A viewer re-POSTs well
     * inside this window while a live view stays open (design: "every few
     * seconds"), so this is generous headroom against one missed beat, not
     * the polling interval itself.
     */
    private const WANTED_TTL_SECONDS = 15;

    /**
     * How long a `live-frame-at` cache entry is KEPT, in seconds. Longer
     * than FRESH_SECONDS on purpose — this is only an outer bound so a
     * long-abandoned key does not sit in the cache store forever, not the
     * "is this live" answer itself. See `liveFrameShow` for that check.
     */
    private const FRAME_AT_KEPT_SECONDS = 60;

    /**
     * How recently the agent must have uploaded for `liveFrameShow` to call
     * it live. Wider than the agent's ~1-2s send interval by an order of
     * magnitude, so a viewer never sees "waiting for the live feed" over a
     * single slow request — but tight enough that a frame from an agent
     * that quietly stopped sending is never presented as current.
     */
    private const FRESH_SECONDS = 30;

    /** Same ceiling as ScreenshotsController::MAX_KILOBYTES, and for the same reason. */
    private const MAX_KILOBYTES = 2048;

    /** The SECURITY exemption's refusal sentence — same wording every screen-reaching guard uses. */
    private const EXEMPT_REFUSAL = ScreenMonitoringExemption::REFUSAL;

    public function __construct(
        private readonly TenantContext $tenant,
    ) {}

    /**
     * POST /api/v1/monitoring/devices/{id}/watch — MAIN group.
     *
     * A viewer saying "I have a live view open for this device". Re-POSTed
     * every few seconds for as long as that is true; the cache key's own
     * TTL is what makes "nobody re-posted" and "nobody is watching" the
     * same fact, with nothing to clean up when a viewer simply closes the tab.
     */
    public function watch(Request $request, string $id): Response
    {
        $this->authorize('viewAny', MonitoringScreenshot::class);

        $device = MonitoredDevice::query()->visibleTo($request->user())->find($id);

        if ($device === null) {
            abort(404);
        }

        // SECURITY exemption: no live frame of the Lead Developer's screen is
        // ever requested, stored or served — refuse before registering interest.
        if (ScreenMonitoringExemption::isExempt($device->user)) {
            abort(403, self::EXEMPT_REFUSAL);
        }

        Cache::put($this->wantedKey($device->id), now()->toIso8601String(), self::WANTED_TTL_SECONDS);

        return response()->noContent();
    }

    /**
     * GET /api/v1/monitoring/live-wanted — IDLE-LESS group.
     *
     * The agent asking "is anyone watching me right now" — cheap on purpose,
     * because it is polled roughly every ~2s for as long as the agent runs,
     * whether or not anybody ever opens a live view. Identifies the device
     * by `device_key` the same way `ScreenshotsController::store` and
     * `HeartbeatController::store` do: the three-key (org, user, device_key)
     * lookup, resolving only to a device the CALLER'S OWN session already
     * registered. An unknown key 404s rather than answering `wanted: false`,
     * matching every other agent-facing endpoint in this module — a
     * device that has never heartbeat-registered is a rejection, not a
     * quiet negative, and a divergent answer here would be one more shape
     * for the agent to special-case.
     */
    public function liveWanted(Request $request): array
    {
        $data = $request->validate([
            'device_key' => ['required', 'string', 'max:64'],
        ]);

        $device = MonitoredDevice::query()->ownedBy($request->user(), $data['device_key'])->first();

        if ($device === null) {
            abort(404);
        }

        // SECURITY exemption: the agent for an exempt device must never be told
        // it is wanted, so its capture loop has nothing to answer.
        if (ScreenMonitoringExemption::isExempt($device->user)) {
            abort(403, self::EXEMPT_REFUSAL);
        }

        return ['wanted' => Cache::has($this->wantedKey($device->id))];
    }

    /**
     * POST /api/v1/monitoring/live-frame — IDLE-LESS group.
     *
     * The agent's upload — same shape as `ScreenshotsController::store`
     * (existing-device-only, jpeg-only, same size ceiling) except the bytes
     * land in `LiveFrameStore`'s single overwriting file instead of a new
     * `monitoring_screenshots` row: see the class docblock for why nothing
     * is written to that table from this path. Rate is bounded by the agent
     * itself (~1-2s), the same trust `HeartbeatController` and
     * `ScreenshotsController::store` already place in the caller.
     */
    public function liveFrame(Request $request): Response
    {
        $data = $request->validate([
            'image' => ['required', 'file', 'image', 'mimes:jpeg', 'max:'.self::MAX_KILOBYTES],
            'device_key' => ['required', 'string', 'max:64'],
        ]);

        $device = MonitoredDevice::query()->ownedBy($request->user(), $data['device_key'])->first();

        if ($device === null) {
            abort(404, 'No registered device for this session — send a heartbeat first.');
        }

        // SECURITY exemption: the Lead Developer's screen bytes are refused
        // before they land in LiveFrameStore — a tampered agent cannot push them.
        if (ScreenMonitoringExemption::isExempt($device->user)) {
            abort(403, self::EXEMPT_REFUSAL);
        }

        $image = $request->file('image');
        $bytes = file_get_contents($image->getRealPath());

        if ($bytes === false) {
            abort(422, 'The uploaded image could not be read.');
        }

        app(LiveFrameStore::class)->put($this->tenant->idOrFail(), $device->id, $bytes);

        Cache::put($this->frameAtKey($device->id), now()->toIso8601String(), self::FRAME_AT_KEPT_SECONDS);

        return response()->noContent();
    }

    /**
     * GET /api/v1/monitoring/devices/{id}/live-frame — MAIN group.
     *
     * The bytes a live view actually paints. §3-scoped and gated exactly
     * like `watch` above (both are "look at another device's screen").
     * Never serves a frame the agent has stopped sending: `FRESH_SECONDS`
     * is the whole promise of "live" here, and a viewer showing a minute-old
     * frame under a "streaming" label would be worse than an honest "waiting
     * for the live feed" — it would look connected while it is not.
     */
    public function liveFrameShow(Request $request, string $id): Response
    {
        $this->authorize('viewAny', MonitoringScreenshot::class);

        $device = MonitoredDevice::query()->visibleTo($request->user())->find($id);

        if ($device === null) {
            abort(404);
        }

        // SECURITY exemption: even a stored frame (there should be none) is
        // never served for the Lead Developer's device.
        if (ScreenMonitoringExemption::isExempt($device->user)) {
            abort(403, self::EXEMPT_REFUSAL);
        }

        $frameAt = Cache::get($this->frameAtKey($device->id));

        if ($frameAt === null || Carbon::parse($frameAt)->diffInSeconds(now()) > self::FRESH_SECONDS) {
            abort(404);
        }

        $bytes = app(LiveFrameStore::class)->get($this->tenant->idOrFail(), $device->id);

        if ($bytes === '') {
            abort(404);
        }

        return response($bytes, 200, [
            'Content-Type' => 'image/jpeg',
            'Content-Length' => (string) strlen($bytes),
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
            'Pragma' => 'no-cache',
        ]);
    }

    private function wantedKey(string $deviceId): string
    {
        return "monitoring:live-wanted:{$deviceId}";
    }

    private function frameAtKey(string $deviceId): string
    {
        return "monitoring:live-frame-at:{$deviceId}";
    }
}
