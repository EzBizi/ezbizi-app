<?php

namespace App\Http\Controllers\Api\Monitoring;

use App\Http\Controllers\Api\Controller;
use App\Models\MonitoredDevice;
use App\Support\Audit\AuditLogger;
use App\Support\Monitoring\ScreenMonitoringExemption;
use App\Support\Settings\SettingsResolver;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

/**
 * Remote Control — a manager driving a monitored machine's mouse and
 * keyboard, riding on top of Live View (2026-09-02-monitoring-live-view):
 * the viewer watches the live feed while sending input, and controlling a
 * device implies watching it, so `control` also refreshes `live-wanted`.
 *
 * ── What is durable here, and what is not ─────────────────────────
 *
 * The input QUEUE writes no table, by construction: a control session is
 * transient — no websocket server exists, so this is necessarily poll-driven,
 * and the queue lives only while somebody is actively driving the device. A
 * dropped viewer's queued events should evaporate, not linger for the next
 * person, which is exactly what a cache TTL buys and a table would not.
 *
 * The one durable fact §2.2 does require is WHO took control of WHICH device
 * WHEN. That is written to `audit_events` (`monitoring.control.started`) once
 * per session — on the first `control` POST, while `control-wanted` is still
 * absent — NOT on the every-few-seconds refreshes that follow, and never per
 * input batch (relaying opaque events is not itself an audited act). Deleting
 * a camera and adding one are audited the same way in CamerasController; see
 * the module's `auditActionPrefixes: ['monitoring']` in ModuleServiceProvider.
 *
 * ── The two cache keys, and why the queue is read-once ─────────────
 *
 * `control-wanted` is `watch`'s sibling: a viewer re-POSTs `control` every
 * few seconds while a control session is open, and the key's own TTL is the
 * "is anyone still driving this" answer — nothing to clean up when a viewer
 * simply lets go. `input` is a QUEUE rather than a single overwriting slot
 * like `live-frame`'s, because order matters here (a mouse-down before a
 * mouse-up) where the last live frame is all a viewer ever needed. The agent
 * clears it on every poll: an event applied once must never be applied
 * again, so "the agent asked for what's pending" and "those events are now
 * gone" are the same act, with a short TTL as the backstop for a poll that
 * never arrives.
 *
 * ── Route grouping mirrors LiveViewController exactly ───────────────
 *
 * `control` and `input` are a person driving another device — real
 * navigation, §3-scoped, gated by `monitoring.control` — so both live in the
 * MAIN idle-bearing `v1` group. `inputPending` is the agent polling in the
 * background, the same shape as `live-wanted`, so it lives in the idle-less
 * group next to it: a background loop must never be what keeps a
 * walked-away session's idle window open. See routes/api.php.
 *
 * ── Why `monitoring.control` and not `monitoring.screenshots.view` ──
 *
 * See MonitoredDevicePolicy::control. Driving a machine is strictly more
 * powerful than watching it, so it is its own grant: a viewer holding
 * `screenshots.view` alone can watch a live view open but gets refused, with
 * a sentence, the moment they try to move the mouse.
 */
class RemoteControlController extends Controller
{
    /**
     * How long a `control` registration stays fresh, and the TTL `control`
     * also stamps onto `live-wanted` when it refreshes it. Matches the
     * design's "15s" exactly — unlike Live View's own `watch`, which is a
     * generous margin (see LiveViewController::WANTED_TTL_SECONDS), this
     * number is a stated requirement rather than a chosen cushion.
     */
    private const CONTROL_WANTED_TTL_SECONDS = 15;

    /**
     * How long a batch of input events sits in the queue before it
     * self-expires. Short on purpose: a dropped viewer session's leftover
     * events should evaporate well before anybody could plausibly still be
     * polling for them, not wait for a cleanup job.
     */
    private const INPUT_QUEUE_TTL_SECONDS = 10;

    /** Events kept in the queue at once — oldest dropped first past this. */
    private const MAX_QUEUE_ITEMS = 200;

    /** Events accepted in a single POST /input batch. */
    private const MAX_EVENTS_PER_BATCH = 100;

    /** Ceiling on the whole batch's encoded size, so nobody parks a megabyte of "opaque" JSON in the cache. */
    private const MAX_BATCH_KILOBYTES = 64;

    /** The SECURITY exemption's refusal sentence — same wording every screen-reaching guard uses. */
    private const EXEMPT_REFUSAL = ScreenMonitoringExemption::REFUSAL;

    public function __construct(
        private readonly TenantContext $tenant,
        private readonly SettingsResolver $settings,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * POST /api/v1/monitoring/devices/{id}/control — MAIN group.
     *
     * A viewer saying "I am driving this device". Re-POSTed every few
     * seconds for as long as that is true, the same shape as `watch` —
     * except this also refreshes `live-wanted`, because a control session
     * with no picture behind it would be flying blind.
     */
    public function control(Request $request, string $id): Response
    {
        $this->authorize('control', MonitoredDevice::class);

        $device = MonitoredDevice::query()->visibleTo($request->user())->find($id);

        if ($device === null) {
            abort(404);
        }

        // SECURITY exemption: no control session is ever opened over the Lead
        // Developer's machine — refuse before anything is queued or audited.
        if (ScreenMonitoringExemption::isExempt($device->user)) {
            abort(403, self::EXEMPT_REFUSAL);
        }

        // A fresh session, not one of the every-few-seconds refreshes: the
        // control-wanted key is still absent. Audited once here so the trail
        // holds one "took control" row per session, not one per poll.
        $isNewSession = ! Cache::has($this->controlWantedKey($device->id));

        $now = now()->toIso8601String();

        Cache::put($this->controlWantedKey($device->id), $now, self::CONTROL_WANTED_TTL_SECONDS);
        Cache::put($this->liveWantedKey($device->id), $now, self::CONTROL_WANTED_TTL_SECONDS);

        if ($isNewSession) {
            $this->audit->log('monitoring.control.started', $device, [], [
                'device' => $device->label,
                'platform' => $device->platform,
            ]);
        }

        return response()->noContent();
    }

    /**
     * POST /api/v1/monitoring/devices/{id}/input — MAIN group.
     *
     * An ordered batch of opaque input-event objects. The server relays,
     * never interprets: validation stops at "is this an array of at most
     * 100 items within a sane byte cap", never at what any one event
     * contains — that contract (`{type:'mouseMove',x,y}` and the rest) is
     * between the viewer's client and the agent's native side.
     */
    public function input(Request $request, string $id): Response
    {
        $this->authorize('control', MonitoredDevice::class);

        $device = MonitoredDevice::query()->visibleTo($request->user())->find($id);

        if ($device === null) {
            abort(404);
        }

        // SECURITY exemption: no input is ever queued for the Lead Developer's
        // machine, even by a caller that somehow opened a control session.
        if (ScreenMonitoringExemption::isExempt($device->user)) {
            abort(403, self::EXEMPT_REFUSAL);
        }

        $data = $request->validate([
            'events' => ['required', 'array', 'max:'.self::MAX_EVENTS_PER_BATCH, $this->batchSizeRule()],
        ]);

        $key = $this->inputQueueKey($device->id);
        $queue = array_merge(Cache::get($key, []), $data['events']);

        if (count($queue) > self::MAX_QUEUE_ITEMS) {
            $queue = array_slice($queue, -self::MAX_QUEUE_ITEMS);
        }

        Cache::put($key, $queue, self::INPUT_QUEUE_TTL_SECONDS);

        return response()->noContent();
    }

    /**
     * GET /api/v1/monitoring/input-pending — IDLE-LESS group.
     *
     * The agent asking "what should I apply, and is anyone still driving
     * me" in one round trip. Resolves the caller's device the same
     * (org, user, device_key) way `live-wanted` does — an unknown key 404s
     * rather than answering with an empty queue, matching every other
     * agent-facing endpoint in this module. The queue is cleared in the
     * same request that reads it: read-once delivery, so a retried or
     * duplicated poll can never re-apply an event.
     *
     * ── The `indicator` flag rides along ───────────────────────────
     *
     * `indicator` is the org's `monitoring.control_indicator` setting (default
     * false), resolved for this device's own organization — the one the agent
     * has already authenticated as, held in TenantContext. The agent shows its
     * "being controlled" tray notice only while BOTH this and `control_wanted`
     * are true, so a silent org (the default) never changes anything on the
     * machine. Resolved at the ORGANIZATION scope because that is the only
     * scope the setting is offered at.
     *
     * // ponytail: one small indexed SELECT on `settings` per poll. The
     * // resolver caches per REQUEST, and each ~1s poll is a fresh request, so
     * // there is no cross-poll cache to lean on — but this is the same single
     * // lookup DeviceOfflineWorker does per sweep, and it only happens while a
     * // control session is open. A per-org short-TTL cache would be the step
     * // to take if that ever showed up in a profile, not before.
     */
    public function inputPending(Request $request): array
    {
        $data = $request->validate([
            'device_key' => ['required', 'string', 'max:64'],
        ]);

        $device = MonitoredDevice::query()->ownedBy($request->user(), $data['device_key'])->first();

        if ($device === null) {
            abort(404);
        }

        // SECURITY exemption: the agent for an exempt device is never handed
        // input to apply, and never told a driver is present.
        if (ScreenMonitoringExemption::isExempt($device->user)) {
            abort(403, self::EXEMPT_REFUSAL);
        }

        $key = $this->inputQueueKey($device->id);
        $events = Cache::get($key, []);
        Cache::forget($key);

        return [
            'events' => $events,
            'control_wanted' => Cache::has($this->controlWantedKey($device->id)),
            'indicator' => (bool) $this->settings->get(
                'monitoring.control_indicator', $this->tenant->get()
            ),
        ];
    }

    private function controlWantedKey(string $deviceId): string
    {
        return "monitoring:control-wanted:{$deviceId}";
    }

    /**
     * Same key format LiveViewController::wantedKey builds — not a shared
     * call, because that method is private to a sibling controller, but the
     * two MUST agree on the literal string: this is what makes `control`
     * refreshing it and `live-wanted` reading it the same cache entry.
     */
    private function liveWantedKey(string $deviceId): string
    {
        return "monitoring:live-wanted:{$deviceId}";
    }

    private function inputQueueKey(string $deviceId): string
    {
        return "monitoring:input:{$deviceId}";
    }

    private function batchSizeRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) {
            if (! is_array($value)) {
                return;
            }

            $bytes = strlen(json_encode($value) ?: '');

            if ($bytes > self::MAX_BATCH_KILOBYTES * 1024) {
                $fail('The events batch is too large.');
            }
        };
    }
}
