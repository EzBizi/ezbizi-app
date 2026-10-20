<?php

namespace App\Http\Controllers\Api\Monitoring;

use App\Http\Controllers\Api\Controller;
use App\Models\MonitoringCamera;
use App\Support\Audit\AuditLogger;
use App\Support\Monitoring\CameraRelay;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * The org's camera roster — generic-protocol v1
 * (docs/superpowers/specs/2026-09-01-monitoring-cameras-design.md).
 *
 * Plain org-scoped CRUD, gated by `monitoring.cameras.view` /
 * `.cameras.manage` (MonitoringCameraPolicy) — no §3 reach, see that
 * policy's docblock and MonitoringCamera's class docblock for why. All four
 * routes live in the MAIN v1 group (`routes/api.php`), not the heartbeat's
 * idle-less one: registering or editing a camera is an admin action, not a
 * background beat.
 *
 * Ids are looked up manually (`find($id)` + a 404) rather than through
 * implicit route model binding, mirroring ScreenshotsController — a
 * sibling organization's id must read as "does not exist", not "forbidden",
 * and BelongsToTenant's global scope makes that automatic here too.
 */
class CamerasController extends Controller
{
    private const PROTOCOLS = ['hls', 'mjpeg', 'snapshot', 'rtsp'];

    public function __construct(private readonly AuditLogger $audit) {}

    /** GET /api/v1/monitoring/cameras */
    public function index(): JsonResponse
    {
        $this->authorize('viewAny', MonitoringCamera::class);

        $cameras = MonitoringCamera::query()->visibleTo(request()->user())->orderByDesc('created_at')->get();

        return response()->json([
            'data' => $cameras->map(fn (MonitoringCamera $c) => $this->present($c))->all(),
        ]);
    }

    /** POST /api/v1/monitoring/cameras */
    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', MonitoringCamera::class);

        $protocol = $request->input('protocol');

        $data = $request->validate([
            'label' => ['required', 'string', 'max:255'],
            'protocol' => ['required', 'string', Rule::in(self::PROTOCOLS)],
            'url' => ['required', 'string', 'max:2048', $this->urlRule($protocol)],
            'enabled' => ['sometimes', 'boolean'],
        ]);

        $camera = MonitoringCamera::create([
            'label' => $data['label'],
            'protocol' => $data['protocol'],
            'url' => $data['url'],
            'enabled' => $data['enabled'] ?? true,
            'created_by' => $request->user()->getKey(),
        ]);

        // §2.2: cameras.manage writes records, so who added which camera when
        // is an audited fact (ModuleRegistryTest). The url is left out — it can
        // carry a stream credential; the label and protocol identify it enough.
        $this->audit->log('monitoring.camera.created', $camera, [
            'label' => $camera->label,
            'protocol' => $camera->protocol,
        ]);

        return response()->json(['data' => $this->present($camera)], Response::HTTP_CREATED);
    }

    /** PATCH /api/v1/monitoring/cameras/{id} */
    public function update(Request $request, string $id): JsonResponse
    {
        $camera = MonitoringCamera::query()->visibleTo(request()->user())->find($id);

        if ($camera === null) {
            abort(404);
        }

        $this->authorize('update', $camera);

        $protocol = $request->exists('protocol') ? $request->input('protocol') : $camera->protocol;

        $data = $request->validate([
            'label' => ['sometimes', 'string', 'max:255'],
            'protocol' => ['sometimes', 'string', Rule::in(self::PROTOCOLS)],
            'url' => ['sometimes', 'string', 'max:2048', $this->urlRule($protocol)],
            'enabled' => ['sometimes', 'boolean'],
        ]);

        $camera->fill($data);
        $changed = array_keys($camera->getDirty());
        $camera->save();

        // Switched off while somebody watches: tell its bridge now.
        if (in_array('enabled', $changed, true) && ! $camera->enabled && $camera->site_id !== null) {
            foreach (['sub', 'main'] as $quality) {
                app(CameraRelay::class)->unwant($camera, $quality);
            }
        }

        // Records WHICH fields changed, not their values — the url can carry a
        // credential, and "who changed the url and when" is the audited fact.
        $this->audit->log('monitoring.camera.updated', $camera, ['fields' => $changed]);

        return response()->json(['data' => $this->present($camera->fresh())]);
    }

    /** DELETE /api/v1/monitoring/cameras/{id} */
    public function destroy(string $id): Response
    {
        $camera = MonitoringCamera::query()->visibleTo(request()->user())->find($id);

        if ($camera === null) {
            abort(404);
        }

        $this->authorize('delete', $camera);

        $label = $camera->label;
        $camera->delete();

        // Logged with the label captured before deletion — the row outlives the
        // camera, so "who removed 'Front door' and when" stays legible.
        $this->audit->log('monitoring.camera.deleted', $camera, ['label' => $label]);

        return response()->noContent();
    }

    /**
     * The url must parse and its scheme must match the protocol: `rtsp` only
     * for protocol `rtsp`, `http`/`https` for the other three (`hls`,
     * `mjpeg`, `snapshot` are all fetched by the browser over http(s); only
     * `rtsp` needs its own scheme). Reads `$protocol` from the raw request
     * rather than the validated bag, so it still fires when protocol itself
     * is missing or invalid — that case is reported by protocol's own `Rule::in`.
     */
    private function urlRule(mixed $protocol): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($protocol) {
            if (! is_string($value)) {
                return;
            }

            $allowed = $protocol === 'rtsp' ? ['rtsp'] : ['http', 'https'];
            $scheme = strtolower((string) parse_url($value, PHP_URL_SCHEME));

            if (filter_var($value, FILTER_VALIDATE_URL) === false || ! in_array($scheme, $allowed, true)) {
                $fail('The url must be a valid '.implode('/', $allowed).' URL.');
            }
        };
    }

    /** @return array<string, mixed> */
    private function present(MonitoringCamera $c): array
    {
        return [
            'id' => $c->id,
            'label' => $c->label,
            'protocol' => $c->protocol,
            'url' => $c->url,
            'enabled' => $c->enabled,
            'source' => $c->source, // 'bridge' → watch it; 'url' → play `url`
            'created_at' => $c->created_at?->toIso8601String(),
        ];
    }
}
