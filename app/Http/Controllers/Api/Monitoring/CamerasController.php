<?php

namespace App\Http\Controllers\Api\Monitoring;

use App\Http\Controllers\Api\Controller;
use App\Models\MonitoringCamera;
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

    /** GET /api/v1/monitoring/cameras */
    public function index(): JsonResponse
    {
        $this->authorize('viewAny', MonitoringCamera::class);

        $cameras = MonitoringCamera::query()->orderByDesc('created_at')->get();

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

        return response()->json(['data' => $this->present($camera)], Response::HTTP_CREATED);
    }

    /** PATCH /api/v1/monitoring/cameras/{id} */
    public function update(Request $request, string $id): JsonResponse
    {
        $camera = MonitoringCamera::query()->find($id);

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

        $camera->fill($data)->save();

        return response()->json(['data' => $this->present($camera->fresh())]);
    }

    /** DELETE /api/v1/monitoring/cameras/{id} */
    public function destroy(string $id): Response
    {
        $camera = MonitoringCamera::query()->find($id);

        if ($camera === null) {
            abort(404);
        }

        $this->authorize('delete', $camera);

        $camera->delete();

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
            'created_at' => $c->created_at?->toIso8601String(),
        ];
    }
}
