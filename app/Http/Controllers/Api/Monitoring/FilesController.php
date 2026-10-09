<?php

namespace App\Http\Controllers\Api\Monitoring;

use App\Http\Controllers\Api\Controller;
use App\Models\MonitoredDevice;
use App\Support\Audit\AuditLogger;
use App\Support\Monitoring\ScreenMonitoringExemption;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Remote file access — a manager browsing, and downloading from, a monitored
 * machine's own disk (Jose, 2026-10-01: "me who has access to monitoring being
 * able to access files on one of my employee's computers"). A1 Tools had this;
 * this is the EzBizi rebuild.
 *
 * ── Read-only, and why that is the whole of v1 ─────────────────────
 *
 * Browse and download. No upload, rename or delete — writing to somebody's
 * machine is a far larger blast radius than reading it, and the stated need is
 * to SEE and FETCH. The agent's native side is given two operations and no
 * others, so a write cannot be smuggled through an "opaque" payload the way
 * Remote Control relays input: here the server names the operation.
 *
 * ── A round trip on the Remote Control channel ─────────────────────
 *
 * This is the same poll-driven shape as RemoteControlController, with one
 * difference: control is fire-and-forget (queue input, agent applies it, no
 * answer), and this needs an ANSWER. So there are two cache entries per
 * request, not one:
 *
 *   - a REQUEST QUEUE per device (`files-req:{deviceId}`) — the manager pushes
 *     `{id, op, path}` onto it; the agent pulls it read-once on its poll, the
 *     same clear-on-read delivery `input-pending` uses so a retried poll never
 *     runs an operation twice.
 *   - a RESULT SLOT per request (`files-res:{reqId}`) — the agent writes the
 *     answer there; the manager polls it until it fills. Keyed by request id,
 *     not device, because several listings can be outstanding and each answer
 *     must find its own question.
 *
 * Both are cache, not tables, for the reason the control queue is: a file
 * session is transient, and a dropped viewer's half-finished listing should
 * evaporate, not linger for the next person or wait for a cleanup job.
 *
 * ── What is audited ────────────────────────────────────────────────
 *
 * Reading another person's files is a privileged act §2.2 requires a trail of,
 * so EVERY request is audited — `monitoring.files.listed` with the path, and
 * `monitoring.files.downloaded` with the path — not once per session like
 * control. A session boundary is fuzzy here (a manager browses for minutes,
 * idle between clicks); the path is the fact that matters, and one row per
 * path opened is the honest record of what was looked at.
 *
 * ── The SECURITY exemption holds here too ──────────────────────────
 *
 * No listing or download is ever opened over the Lead Developer's machine, and
 * the agent for an exempt device is never handed a request to run — the same
 * three-point defence (manager POST, manager poll, agent poll) RemoteControl
 * puts around input.
 *
 * ── Why `monitoring.files.view` and not `monitoring.control` ───────
 *
 * Seeing somebody's files and driving their mouse are different authorities an
 * organization must be able to grant apart, exactly as control was split from
 * `screenshots.view`. See MonitoredDevicePolicy::files.
 */
class FilesController extends Controller
{
    /**
     * How long a queued request waits for the agent to pull it. Longer than
     * control's 10s queue because a listing is a round trip a manager is
     * actively waiting on, and an agent poll is ~1-2s: 60s is many polls of
     * head-room without letting a truly dropped request outlive the session.
     */
    private const REQUEST_TTL_SECONDS = 60;

    /**
     * How long an answer sits in its slot for the manager to collect. Longer
     * than the request TTL so a slow manager poll still finds an answer the
     * agent produced near the end of the request window.
     */
    private const RESULT_TTL_SECONDS = 120;

    /** Pending requests held for one device at once — oldest dropped past this. */
    private const MAX_PENDING_PER_DEVICE = 50;

    /**
     * The largest file the agent may return INLINE, in bytes. Download parks
     * the file's bytes (base64) in the result slot, so this is a cache-size
     * ceiling, not a product limit on what exists.
     *
     * // ponytail: inline base64 in cache, capped at 5 MB. It covers "pull a
     * // document / log / config off their machine", which is the need. A file
     * // past this answers `too_large`. The upgrade when somebody needs a big
     * // one is the screenshot path: the agent streams it to the monitoring
     * // disk and the result carries a one-time download URL — more moving
     * // parts, built when a real file needs it, not before.
     */
    private const MAX_DOWNLOAD_BYTES = 5 * 1024 * 1024;

    /** Entries the agent may return for one directory. A huge folder is paged by the agent, not dumped. */
    private const MAX_ENTRIES = 2000;

    /** The SECURITY exemption's refusal sentence — the same wording every screen-reaching guard uses. */
    private const EXEMPT_REFUSAL = 'This device belongs to a Lead Developer and is exempt from screen monitoring.';

    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    /**
     * POST /api/v1/monitoring/devices/{id}/files — MAIN group.
     *
     * The manager asking for one thing: a directory listing, or a download.
     * Body `{op: 'list'|'download', path}`. Returns `{request_id}`; the answer
     * is collected from the result endpoint below. A manager opening and
     * reading files IS navigation, which is why this is in the idle-bearing
     * group and the agent's poll is not.
     */
    public function request(Request $request, string $id)
    {
        $this->authorize('files', MonitoredDevice::class);

        $device = MonitoredDevice::query()->visibleTo($request->user())->find($id);

        if ($device === null) {
            abort(404);
        }

        if (ScreenMonitoringExemption::isExempt($device->user)) {
            abort(403, self::EXEMPT_REFUSAL);
        }

        $data = $request->validate([
            'op' => ['required', 'string', 'in:list,download'],
            // A path the agent resolves on its own disk. `present`, not
            // `required`: the EMPTY string is the roots ("This PC" → the
            // drives), and `required` rejects an empty string, which 422'd
            // every roots request before it reached the agent. Bounded so
            // nobody parks a huge string; the agent is the only thing that
            // knows which paths are real, so content beyond the length is its
            // call. `nullable` because the ConvertEmptyStringsToNull middleware
            // rewrites the empty roots path to null before this runs; it is
            // coerced back to '' below.
            'path' => ['present', 'nullable', 'string', 'max:4096'],
        ]);

        // The roots: '' reaches the agent, which reads it as "the drives".
        $path = $data['path'] ?? '';

        $requestId = (string) Str::uuid();

        $queueKey = $this->requestQueueKey($device->id);
        $queue = Cache::get($queueKey, []);
        $queue[] = ['id' => $requestId, 'op' => $data['op'], 'path' => $path];

        if (count($queue) > self::MAX_PENDING_PER_DEVICE) {
            $queue = array_slice($queue, -self::MAX_PENDING_PER_DEVICE);
        }

        Cache::put($queueKey, $queue, self::REQUEST_TTL_SECONDS);

        // Audited on the REQUEST, not the answer: the record is "this manager
        // asked to see this path on this device", which is true whether or not
        // the agent could read it.
        $this->audit->log(
            $data['op'] === 'download'
                ? 'monitoring.files.downloaded'
                : 'monitoring.files.listed',
            $device,
            [],
            ['path' => $path, 'device' => $device->label],
        );

        return response()->json(['data' => ['request_id' => $requestId]]);
    }

    /**
     * GET /api/v1/monitoring/devices/{id}/files/{requestId} — MAIN group.
     *
     * The manager collecting the answer. `{status:'pending'}` until the agent
     * has written it; then the agent's own `{status:'ready', ...}` or
     * `{status:'error', error}`. The slot is NOT cleared on read — a manager
     * may poll twice, and the TTL is what retires it — unlike the agent-facing
     * queue, where read-once matters.
     */
    public function result(Request $request, string $id, string $requestId): array
    {
        $this->authorize('files', MonitoredDevice::class);

        $device = MonitoredDevice::query()->visibleTo($request->user())->find($id);

        if ($device === null) {
            abort(404);
        }

        $slot = Cache::get($this->resultSlotKey($requestId));

        if ($slot === null) {
            return ['data' => ['status' => 'pending']];
        }

        return ['data' => $slot];
    }

    /**
     * GET /api/v1/monitoring/file-requests — IDLE-LESS group.
     *
     * The agent pulling what it should do, resolved by the same
     * (org, user, device_key) identity every agent endpoint uses. Read-once:
     * the queue is cleared in the request that returns it, so a retried poll
     * cannot run an operation twice — the same delivery `input-pending` uses.
     */
    public function pending(Request $request): array
    {
        $data = $request->validate([
            'device_key' => ['required', 'string', 'max:64'],
        ]);

        $device = MonitoredDevice::query()->ownedBy($request->user(), $data['device_key'])->first();

        if ($device === null) {
            abort(404);
        }

        if (ScreenMonitoringExemption::isExempt($device->user)) {
            abort(403, self::EXEMPT_REFUSAL);
        }

        $key = $this->requestQueueKey($device->id);
        $requests = Cache::get($key, []);
        Cache::forget($key);

        // Bare top-level, matching input-pending / live-wanted: an agent reading
        // two monitoring poll endpoints meets one shape, not a wrapped one here
        // and an unwrapped one there. The manager-facing request/result keep the
        // {data:...} resource envelope the viewer side uses.
        return ['requests' => $requests];
    }

    /**
     * POST /api/v1/monitoring/file-requests/{requestId} — IDLE-LESS group.
     *
     * The agent returning one answer. The server validates the SHAPE, never
     * the contents of the disk: a listing is entries with a name and the three
     * facts a browser shows, a download is a base64 blob under the cap, and an
     * error is one of the agent's known refusals. What a path actually is on
     * that machine is the agent's to report.
     */
    public function answer(Request $request, string $requestId)
    {
        $data = $request->validate([
            'device_key' => ['required', 'string', 'max:64'],
            'status' => ['required', 'string', 'in:ready,error'],
            'op' => ['required_if:status,ready', 'string', 'in:list,download'],
            'error' => ['required_if:status,error', 'string', 'in:access_denied,not_found,too_large,unreadable'],

            'path' => ['sometimes', 'string', 'max:4096'],

            // op:list
            'entries' => ['sometimes', 'array', 'max:'.self::MAX_ENTRIES],
            'entries.*.name' => ['required_with:entries', 'string', 'max:1024'],
            'entries.*.is_dir' => ['required_with:entries', 'boolean'],
            'entries.*.size_bytes' => ['nullable', 'integer', 'min:0'],
            'entries.*.modified_at' => ['nullable', 'string', 'max:64'],

            // op:download
            'name' => ['sometimes', 'string', 'max:1024'],
            'size' => ['sometimes', 'integer', 'min:0'],
            'content_base64' => ['sometimes', 'string'],
        ]);

        $device = MonitoredDevice::query()->ownedBy($request->user(), $data['device_key'])->first();

        if ($device === null) {
            abort(404);
        }

        if (ScreenMonitoringExemption::isExempt($device->user)) {
            abort(403, self::EXEMPT_REFUSAL);
        }

        // A download over the inline cap is refused HERE rather than stored:
        // the agent should have sent `too_large`, but the server is the thing
        // that owns the cache-size ceiling, so it enforces it regardless.
        if (
            ($data['content_base64'] ?? null) !== null
            && strlen($data['content_base64']) > self::base64Ceiling()
        ) {
            Cache::put(
                $this->resultSlotKey($requestId),
                ['status' => 'error', 'op' => 'download', 'error' => 'too_large'],
                self::RESULT_TTL_SECONDS,
            );

            return response()->noContent();
        }

        Cache::put(
            $this->resultSlotKey($requestId),
            $this->slotFrom($data),
            self::RESULT_TTL_SECONDS,
        );

        return response()->noContent();
    }

    /** The result slot the manager reads, built from the agent's validated answer. */
    private function slotFrom(array $data): array
    {
        if ($data['status'] === 'error') {
            return [
                'status' => 'error',
                'op' => $data['op'] ?? null,
                'error' => $data['error'],
                'path' => $data['path'] ?? null,
            ];
        }

        if ($data['op'] === 'list') {
            return [
                'status' => 'ready',
                'op' => 'list',
                'path' => $data['path'] ?? null,
                'entries' => array_map(fn (array $e) => [
                    'name' => $e['name'],
                    'is_dir' => (bool) $e['is_dir'],
                    'size_bytes' => $e['size_bytes'] ?? null,
                    'modified_at' => $e['modified_at'] ?? null,
                ], $data['entries'] ?? []),
            ];
        }

        return [
            'status' => 'ready',
            'op' => 'download',
            'name' => $data['name'] ?? null,
            'size' => $data['size'] ?? null,
            'content_base64' => $data['content_base64'] ?? null,
        ];
    }

    /** base64 of N raw bytes is at most 4*ceil(N/3); the cap on the encoded string. */
    private static function base64Ceiling(): int
    {
        return intdiv(self::MAX_DOWNLOAD_BYTES + 2, 3) * 4;
    }

    private function requestQueueKey(string $deviceId): string
    {
        return "monitoring:files-req:{$deviceId}";
    }

    private function resultSlotKey(string $requestId): string
    {
        return "monitoring:files-res:{$requestId}";
    }
}
