<?php

namespace App\Support\Monitoring;

use App\Models\CameraSite;
use App\Models\MonitoringCamera;
use Illuminate\Support\Facades\Cache;

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
                if ($until > time()) {
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
            $new = ($items[$camera->id][$quality] ?? 0) <= time();
            $items[$camera->id][$quality] = time() + (int) config('camera_bridge.want_ttl_seconds', 60);

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

    public static function path(string $cameraId, string $quality): string
    {
        return "cam-{$cameraId}-{$quality}";
    }

    /** @return array{url:string, token:string, expires_at:string} */
    public function mintPublish(MonitoringCamera $camera, string $quality): array
    {
        $path = self::path($camera->id, $quality);
        $expires = time() + (int) config('camera_bridge.publish_ttl_seconds', 600);
        $token = $this->sign('publish', $path, $expires);
        $base = rtrim((string) config('camera_bridge.publish_url'), '/');

        return [
            'url' => "{$base}/{$path}?user=bridge&pass={$token}",
            'token' => $token,
            'expires_at' => date(DATE_ATOM, $expires),
        ];
    }

    /** The relay's auth hook asks this: is $token good for $action on $path now? */
    public function verify(string $token, string $action, string $path): bool
    {
        $parts = explode('.', $token, 2);
        if (count($parts) !== 2 || ! ctype_digit($parts[0]) || (int) $parts[0] < time()) {
            return false;
        }

        return hash_equals($this->sign($action, $path, (int) $parts[0]), $token);
    }

    private function sign(string $action, string $path, int $expires): string
    {
        // URL-safe: it rides in an RTMP query string.
        return $expires.'.'.hash_hmac('sha256', "{$action}|{$path}|{$expires}", (string) config('app.key'));
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
