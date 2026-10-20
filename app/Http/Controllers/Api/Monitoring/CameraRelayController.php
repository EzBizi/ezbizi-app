<?php

namespace App\Http\Controllers\Api\Monitoring;

use App\Http\Controllers\Api\Controller;
use App\Models\MonitoringCamera;
use App\Support\Audit\AuditLogger;
use App\Support\Monitoring\CameraRelay;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

/**
 * Watching a bridge camera, and the relay's two hooks (camera bridge, phase 1).
 *
 * `watch` is a person's call (main v1 group). `auth` and `viewers` are the
 * box's MediaMTX calling back with no user — they carry the shared
 * `X-Relay-Secret` instead, and answer only about the one path they name.
 */
class CameraRelayController extends Controller
{
    private const PATH = '/^cam-([0-9a-f-]{36})-(sub|main)$/';

    public function __construct(private readonly CameraRelay $relay, private readonly AuditLogger $audit) {}

    /** POST /monitoring/cameras/{id}/watch — renew it every ~30 s while watching. */
    public function watch(Request $request, string $id): JsonResponse
    {
        $this->authorize('viewAny', MonitoringCamera::class);
        $quality = $request->validate(['quality' => ['sometimes', 'in:sub,main']])['quality'] ?? 'sub';

        $camera = MonitoringCamera::query()->visibleTo($request->user())
            ->where('source', 'bridge')->with('site')->findOrFail($id);

        if (! $camera->site->online()) {
            return response()->json([
                'code' => 'bridge_offline',
                'message' => 'The camera computer at this site is offline.',
                'last_checkin_at' => $camera->site->last_checkin_at?->toIso8601String(),
            ], 409);
        }

        $this->relay->want($camera, $quality);
        $this->relay->ensureBoxStream(CameraRelay::path($camera->id, $quality), $quality);
        $this->audit->log('monitoring.camera.watched', $camera, [], ['quality' => $quality]);

        return response()->json(['play' => $this->relay->mintPlay($camera, $request->user(), $quality)]);
    }

    /** POST /camera-relay/auth — MediaMTX's authHTTPAddress. */
    public function auth(Request $request): Response
    {
        $this->guard($request);

        // The box's own go2rtc reading the relay: the shared secret, loopback only.
        if ($request->input('user') === 'box') {
            $ok = $request->input('action') === 'read'
                && in_array($request->input('ip'), ['127.0.0.1', '::1'], true)
                && hash_equals((string) config('camera_bridge.relay_secret'), (string) $request->input('password'));

            return response()->noContent($ok ? 200 : 401);
        }

        $ok = $this->relay->verify(
            (string) ($request->input('password') ?: $request->input('token')),
            (string) $request->input('action'),
            (string) $request->input('path'),
        );

        return response()->noContent($ok ? 200 : 401);
    }

    /**
     * GET /camera-relay/play-check — nginx auth_request in front of the box's
     * go2rtc: is the viewer's token good for the MJPEG it asks for?
     */
    public function playCheck(Request $request): Response
    {
        $this->guard($request);
        parse_str((string) parse_url((string) $request->header('X-Original-URI'), PHP_URL_QUERY), $q);
        $src = (string) ($q['src'] ?? '');
        $ok = str_ends_with($src, '_jpg')
            && $this->relay->verify((string) ($q['token'] ?? ''), 'read', substr($src, 0, -4));

        return response()->noContent($ok ? 204 : 401);
    }

    /** POST /camera-relay/viewers {path, viewers} — the last one out unwants it after a grace. */
    public function viewers(Request $request): Response
    {
        $this->guard($request);
        $data = $request->validate(['path' => ['required', 'string', 'regex:'.self::PATH], 'viewers' => ['required', 'integer', 'min:0']]);
        preg_match(self::PATH, $data['path'], $m);

        Cache::put("camera-viewers:{$data['path']}", $data['viewers'], now()->addMinutes(5));
        if ($data['viewers'] === 0) {
            [$cameraId, $quality, $path] = [$m[1], $m[2], $data['path']];
            dispatch(function () use ($cameraId, $quality, $path) {
                if ((int) Cache::get("camera-viewers:{$path}", 0) > 0) {
                    return; // somebody came back inside the grace
                }
                $camera = app(TenantContext::class)->runWithoutTenant(fn () => MonitoringCamera::query()->find($cameraId));
                if ($camera !== null) {
                    app(CameraRelay::class)->unwant($camera, $quality);
                }
            })->delay(now()->addSeconds(3));
        }

        return response()->noContent();
    }

    private function guard(Request $request): void
    {
        $secret = (string) config('camera_bridge.relay_secret');
        $given = (string) ($request->header('X-Relay-Secret') ?: $request->query('secret'));
        abort_if($secret === '' || ! hash_equals($secret, $given), 401);
    }
}
