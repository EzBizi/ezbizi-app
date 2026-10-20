<?php

namespace App\Http\Controllers\Api\Monitoring;

use App\Http\Controllers\Api\Controller;
use App\Models\CameraSite;
use App\Models\MonitoringCamera;
use App\Support\Monitoring\CameraRelay;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;

/**
 * The bridge PC's side (AuthenticateCameraBridge in front): it checks in, waits
 * for cameras to stream, and sends a still per camera every 10 minutes.
 * Everything is limited to the key's own site.
 */
class CameraBridgeController extends Controller
{
    public function __construct(private readonly CameraRelay $relay) {}

    public function checkin(Request $request): Response
    {
        $site = $this->site($request);
        $data = $request->validate([
            'engine_version' => ['nullable', 'string', 'max:32'],
            'app_version' => ['nullable', 'string', 'max:32'],
            'reason' => ['nullable', 'string', 'max:255'],
            'found' => ['nullable', 'array', 'max:64'],
            'found.*.key' => ['required', 'string', 'max:64'],
            'found.*.label' => ['nullable', 'string', 'max:120'],
            'found.*.kind' => ['required', 'in:recorder,camera'],
            'found.*.address' => ['nullable', 'string', 'max:64'],
            'found.*.channels' => ['nullable', 'array', 'max:64'],
            'found.*.channels.*.channel' => ['required', 'string', 'max:64'],
            'found.*.channels.*.label' => ['nullable', 'string', 'max:120'],
            'cameras' => ['nullable', 'array', 'max:128'],
            'cameras.*.camera_id' => ['required', 'string'],
            'cameras.*.status' => ['required', 'in:ok,unreachable,auth_failed'],
            'cameras.*.reason' => ['nullable', 'string', 'max:255'],
        ]);

        $site->forceFill([
            'last_checkin_at' => now(),
            'status' => collect($data)->only(['engine_version', 'app_version', 'reason', 'found'])->all(),
        ])->save();

        $mine = $site->cameras()->pluck('id')->flip();
        foreach ($data['cameras'] ?? [] as $report) {
            if (isset($mine[$report['camera_id']])) { // never another site's camera
                MonitoringCamera::query()->whereKey($report['camera_id'])->update([
                    'bridge_status' => $report['status'],
                    'bridge_reason' => $report['reason'] ?? null,
                ]);
            }
        }

        return response()->noContent();
    }

    /**
     * Long-poll: answers as soon as the wanted set moves past `?since=`, or
     * after long_poll_seconds with the same shape.
     *
     * ponytail: a sleep loop holds one PHP-FPM worker per bridge; move to a
     * push channel if bridges ever outnumber spare workers.
     */
    public function work(Request $request): array
    {
        $site = $this->site($request);
        $since = (int) $request->query('since', -1);
        $until = microtime(true) + (int) config('camera_bridge.long_poll_seconds', 25);

        while ($this->relay->version($site) === $since && microtime(true) < $until) {
            usleep(500_000);
        }

        $cameras = $site->cameras()->where('enabled', true)->get()->keyBy('id');
        $wanted = [];
        foreach ($this->relay->wanted($site) as $want) {
            $camera = $cameras->get($want['camera_id']);
            if ($camera !== null) {
                $wanted[] = [
                    'camera_id' => $camera->id,
                    'channel' => $camera->channel,
                    'quality' => $want['quality'],
                    'publish' => $this->relay->mintPublish($camera, $want['quality']),
                ];
            }
        }

        return ['version' => $this->relay->version($site), 'wanted' => $wanted];
    }

    /** One low-res still per camera, overwritten in place. */
    public function still(Request $request): Response
    {
        $site = $this->site($request);
        $data = $request->validate([
            'camera_id' => ['required', 'string'],
            'image' => ['required', 'file', 'mimes:jpg,jpeg', 'max:300'],
        ]);

        $camera = $site->cameras()->whereKey($data['camera_id'])->firstOrFail();
        Storage::disk('local')->putFileAs('camera-stills', $data['image'], "{$camera->id}.jpg");

        return response()->noContent();
    }

    private function site(Request $request): CameraSite
    {
        return $request->attributes->get('camera_site');
    }
}
