<?php

namespace App\Support\Monitoring;

use App\Models\CameraSite;
use App\Models\MonitoringCamera;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Which of a site's cameras somebody is watching right now, and the signed
 * tokens the relay checks (camera bridge, phase 1).
 *
 * The wanted set lives in the cache, one entry per site, with a version that
 * moves whenever the set does — the bridge's long-poll waits on the version.
 * Relay paths are one segment, `cam-<camera_id>-<quality>`: go2rtc's RTMP
 * publish mangles a token in a multi-segment path (Task 1 spike).
 */
class CameraRelay
{
    public function version(CameraSite $site): int
    {
        return $this->state($site->id)['version'];
    }

    /**
     * The cameras to stream now, lapsed wants dropped.
     *
     * @return list<array{camera_id:string, quality:string}>
     */
    public function wanted(CameraSite $site): array
    {
        $out = [];
        foreach ($this->state($site->id)['items'] as $cameraId => $qualities) {
            foreach ($qualities as $quality => $until) {
                if ($until > now()->getTimestamp()) {
                    $out[] = ['camera_id' => (string) $cameraId, 'quality' => $quality];
                }
            }
        }

        return $out;
    }

    /** Somebody is watching: keep this camera wanted for want_ttl_seconds more. */
    public function want(MonitoringCamera $camera, string $quality): void
    {
        $this->change($camera->site_id, function (array &$items) use ($camera, $quality): bool {
            $new = ($items[$camera->id][$quality] ?? 0) <= now()->getTimestamp();
            $items[$camera->id][$quality] = now()->getTimestamp() + (int) config('camera_bridge.want_ttl_seconds', 60);

            return $new; // a renewal alone does not wake the bridge
        });
    }

    public function unwant(MonitoringCamera $camera, string $quality): void
    {
        $this->change($camera->site_id, function (array &$items) use ($camera, $quality): bool {
            if (! isset($items[$camera->id][$quality])) {
                return false;
            }
            unset($items[$camera->id][$quality]);
            if ($items[$camera->id] === []) {
                unset($items[$camera->id]);
            }

            return true;
        });
    }

    /** Forget everything this site was asked to stream (revoke, delete). */
    public function forget(CameraSite $site): void
    {
        $this->change($site->id, function (array &$items): bool {
            $had = $items !== [];
            $items = [];

            return $had;
        });
    }

    public static function path(string $cameraId, string $quality): string
    {
        return "cam-{$cameraId}-{$quality}";
    }

    /** @return array{url:string, token:string, expires_at:string} */
    public function mintPublish(MonitoringCamera $camera, string $quality): array
    {
        $path = self::path($camera->id, $quality);
        $expires = now()->getTimestamp() + (int) config('camera_bridge.publish_ttl_seconds', 600);
        $token = $this->sign('publish', $path, $expires);
        $base = rtrim((string) config('camera_bridge.publish_url'), '/');

        return [
            'url' => "{$base}/{$path}?user=bridge&pass={$token}",
            'token' => $token,
            'expires_at' => date(DATE_ATOM, $expires),
        ];
    }

    /** @return array{url:string, token:string, expires_at:string} */
    public function mintPlay(MonitoringCamera $camera, User $viewer, string $quality): array
    {
        // ponytail: the viewer is not in the token; the watch audit names them.
        $path = self::path($camera->id, $quality);
        $expires = now()->getTimestamp() + (int) config('camera_bridge.play_ttl_seconds', 300);
        $token = $this->sign('read', $path, $expires);
        $base = rtrim((string) config('camera_bridge.play_url'), '/');

        return [
            'url' => "{$base}/api/stream.mjpeg?src={$path}_jpg&token={$token}",
            'token' => $token,
            'expires_at' => date(DATE_ATOM, $expires),
        ];
    }

    /**
     * Have the box's go2rtc read this path from the relay and offer it as
     * MJPEG (`<path>_jpg`). Idempotent; go2rtc only pulls while somebody
     * watches. A failure is logged, not thrown: the viewer then sees no
     * frames, and the desk sees why.
     */
    public function ensureBoxStream(string $path, string $quality): void
    {
        $api = rtrim((string) config('camera_bridge.box_go2rtc'), '/');
        $secret = (string) config('camera_bridge.relay_secret');
        [$w, $h] = $quality === 'main' ? [1280, 720] : [640, 360];
        try {
            foreach ([
                $path => "rtsp://box:{$secret}@127.0.0.1:8554/{$path}",
                "{$path}_jpg" => "ffmpeg:{$path}#video=mjpeg#width={$w}#height={$h}",
            ] as $name => $src) {
                Http::timeout(2)->put("{$api}/api/streams?".http_build_query(['name' => $name, 'src' => $src]))->throw();
            }
        } catch (\Throwable $e) {
            // Never the message: Guzzle's ends with the full URI, secret included.
            Log::warning('camera relay: box go2rtc refused a stream', ['path' => $path, 'error' => $e::class]);
        }
    }

    /** The relay's auth hook asks this: is $token good for $action on $path now? */
    public function verify(string $token, string $action, string $path): bool
    {
        $parts = explode('.', $token, 2);
        if (count($parts) !== 2 || ! ctype_digit($parts[0]) || (int) $parts[0] < now()->getTimestamp()) {
            return false;
        }

        return hash_equals($this->sign($action, $path, (int) $parts[0]), $token);
    }

    private function sign(string $action, string $path, int $expires): string
    {
        // URL-safe: it rides in an RTMP query string.
        return $expires.'.'.hash_hmac('sha256', "{$action}|{$path}|{$expires}", (string) (config('camera_bridge.relay_secret') ?: config('app.key')));
    }

    /** @return array{version:int, items:array<string, array<string, int>>} */
    private function state(string $siteId): array
    {
        return Cache::get($this->key($siteId), ['version' => 0, 'items' => []]);
    }

    private function change(?string $siteId, callable $mutate): void
    {
        if ($siteId === null) {
            return;
        }
        // ponytail: one cache lock per site; fine at a handful of viewers per site.
        Cache::lock($this->key($siteId).':lock', 5)->block(3, function () use ($siteId, $mutate) {
            $state = $this->state($siteId);
            if ($mutate($state['items'])) {
                $state['version']++;
            }
            Cache::put($this->key($siteId), $state, now()->addDay());
        });
    }

    private function key(string $siteId): string
    {
        return "camera-wanted:{$siteId}";
    }
}
