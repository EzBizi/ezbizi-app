<?php

namespace App\Http\Controllers\Api\Monitoring;

use App\Http\Controllers\Api\Controller;
use App\Models\MonitoredDevice;
use App\Models\MonitoringActivity;
use App\Support\Automations\EventEmitter;
use App\Support\Monitoring\ScreenMonitoringExemption;
use App\Support\Monitoring\SecurityPosture;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * A person's own device reporting its presence (2026-08-28-monitoring-pc-specs).
 *
 * Deliberately not gated by a permission: this is not "view another device",
 * it is a device reporting itself, the way PhonePresence and PhoneTokens are.
 * `routes/api.php` keeps this OUTSIDE the `idle` middleware group — see the
 * comment there for why a timer-driven beat must never slide the idle window.
 */
class HeartbeatController extends Controller
{
    private const PLATFORMS = ['web', 'android', 'ios', 'windows', 'macos', 'linux'];

    private const CAPABILITIES = ['activity', 'specs', 'screenshot', 'control'];

    /**
     * The client's fixed vocabulary for why a capture did not happen. Anything
     * outside it is refused rather than stored: this string is rendered to a
     * human, so an unvalidated one would be a way to put arbitrary text — or
     * something read off the screen — onto the roster. `upload_*` carries the
     * HTTP status the upload was refused with.
     */
    private const CAPTURE_REASON = 'regex:/^(screen_locked|com_unavailable|no_screen|gdi_failed|blit_failed|pixels_unreadable|jpeg_encoder_missing|encode_failed|empty_capture|channel_missing|capture_failed|upload_[0-9]{3})$/';

    public function __construct(
        private readonly EventEmitter $events,
        private readonly TenantContext $tenant,
    ) {}

    public function store(Request $request): Response
    {
        $data = $request->validate([
            'device_key' => ['required', 'string', 'max:64'],
            'platform' => ['required', Rule::in(self::PLATFORMS)],
            'capabilities' => ['required', 'array'],
            'capabilities.*' => [Rule::in(self::CAPABILITIES)],
            'label' => ['nullable', 'string', 'max:120'],
            'current_module' => ['nullable', 'string', 'max:64'],
            'specs' => ['nullable', 'array'],
            'segment' => ['nullable', 'array'],
            'segment.module_key' => ['required_with:segment', 'string', 'max:64'],
            'segment.dwell_seconds' => ['required_with:segment', 'integer', 'min:0'],
            'capture' => ['nullable', 'array'],
            'capture.ok' => ['required_with:capture', 'boolean'],
            'capture.at' => ['required_with:capture', 'date'],
            'capture.reason' => ['nullable', 'string', 'max:40', self::CAPTURE_REASON],
        ]);

        // SECURITY exemption: the Lead Developer's own device advertises no
        // screen-reaching capability, so the Desktop Monitoring picker (which
        // filters to capture-capable devices) never lists it and nothing
        // downstream believes it can be captured or driven. `activity` and
        // `specs` are left untouched, so it still shows online with PC Specs.
        // The client also declines to declare `screenshot` in this case, but
        // stripping here means a tampered client cannot re-assert it.
        if (ScreenMonitoringExemption::isExempt($request->user())) {
            $data['capabilities'] = array_values(array_filter(
                $data['capabilities'],
                fn (string $capability) => ! in_array($capability, ['screenshot', 'control'], true),
            ));
        }

        $keepSpecs = in_array('specs', $data['capabilities'], true);

        // SECURITY exemption, same reasoning as the capability strip above: an
        // exempt device never captures, so it has no capture outcome to report
        // and a tampered client must not be able to write one onto the roster.
        $capture = ScreenMonitoringExemption::isExempt($request->user())
            ? null
            : ($data['capture'] ?? null);

        $device = MonitoredDevice::query()->firstOrNew([
            'organization_id' => $this->tenant->idOrFail(),
            'user_id' => $request->user()->getKey(),
            'device_key' => $data['device_key'],
        ]);
        $isNew = ! $device->exists;

        $device->fill([
            'label' => $data['label'] ?? $device->label,
            'platform' => $data['platform'],
            'capabilities' => $data['capabilities'],
            'user_agent' => substr((string) $request->userAgent(), 0, 400),
            'ip_address' => $request->ip(),
            'last_seen_at' => now(),
            'current_module' => $data['current_module'] ?? $device->current_module,
            'offline_alerted_at' => null, // a beat re-arms the offline alert
        ]);
        if ($keepSpecs && isset($data['specs'])) {
            $specs = $data['specs'];

            // Screen content arrives INSIDE specs because the agent reads it
            // in the same pass — a fact about the agent, not about the data.
            // It is lifted into its own column here so that §9 can call it
            // what it is (activity, not a fact about the hardware), erasure
            // can drop it on its own, and the roster can withhold it by
            // leaving a column out rather than by unsetting keys.
            $screenContext = [];
            foreach (ScreenMonitoringExemption::SCREEN_CONTENT_SPEC_KEYS as $key) {
                if (array_key_exists($key, $specs)) {
                    $screenContext[$key] = $specs[$key];
                    unset($specs[$key]);
                }
            }

            // SECURITY exemption: a window title and a list of browser tabs
            // carry the CONTENT of the screen — a customer name, a subject
            // line, the job somebody has open. The Lead Developer's screen is
            // exempt from monitoring, so none of it is kept, even though the
            // client already declines to send it: a tampered client must not
            // be able to write it. The hardware half of specs is untouched, so
            // an exempt device still shows online with its RAM and its disks.
            if (ScreenMonitoringExemption::isExempt($request->user())) {
                $screenContext = [];
            }

            $device->specs = $specs;

            // Written on every beat that carries specs, so closing a browser
            // clears the tabs rather than leaving yesterday's on the roster.
            $device->screen_context = $screenContext === [] ? null : $screenContext;
        }

        // The device is the only thing that can know why a capture did not
        // happen; a row that is merely MISSING a screenshot cannot tell a
        // broken encoder from a locked screen from a machine that has not
        // reached its first tick. `ok` clears the error rather than writing
        // one, so a device that recovers stops reporting a stale fault.
        if ($capture !== null) {
            $device->capture_last_attempt_at = $capture['at'];
            $device->capture_last_error = $capture['ok'] === true
                ? null
                : ($capture['reason'] ?? 'capture_failed');
        }

        $device->save();

        if (isset($data['segment'])) {
            MonitoringActivity::create([
                'organization_id' => $this->tenant->idOrFail(),
                'device_id' => $device->id,
                'user_id' => $request->user()->getKey(),
                'module_key' => $data['segment']['module_key'],
                'dwell_seconds' => $data['segment']['dwell_seconds'],
                'occurred_at' => now(),
            ]);
        }

        if ($isNew) {
            $this->events->emit('monitoring.new_device_seen', $device, [
                'platform' => $device->platform,
                'label' => $device->label,
            ]);
        }

        $this->raiseSecurityTrigger($device);

        return response()->noContent();
    }

    /**
     * Raise `monitoring.security_needs_attention` when a machine's defences
     * go from fine to not fine — once per fault, not once per beat.
     *
     * ── Why this is an EDGE and not a level ──────────────────────────
     *
     * A device beats every 60 seconds. An automation that fired whenever the
     * antivirus was off would send an email every minute for as long as it
     * stayed off, which is not an alert — it is a way of teaching somebody to
     * filter the alerts. So `security_alerted_at` marks a fault as already
     * reported and the trigger stays quiet until the machine says it is fixed.
     *
     * ── Why the marker is a column, having first tried it without ────
     *
     * The first version compared the incoming security block against the one
     * already in `specs`, on the reasoning that the previous beat IS the
     * record. SecurityTriggerTest proved that wrong before it shipped: a beat
     * carrying specs but NO security block overwrites `specs` and destroys the
     * memory, so the next real report looked like a fresh fault and fired a
     * second time for something nobody had touched. The migration carries the
     * longer version of why storing the old block instead would have traded
     * that bug for a roster showing a frozen reading.
     *
     * ── What counts as fixed ─────────────────────────────────────────
     *
     * A beat that REPORTS security and names no unhealthy area clears the
     * marker. A beat that reports nothing at all — an older agent, a Mac, a
     * Windows Server with no Security Center — leaves it standing, because
     * silence is not a report of health and treating it as one is exactly the
     * bug above.
     */
    private function raiseSecurityTrigger(MonitoredDevice $device): void
    {
        if (! SecurityPosture::reported($device->specs)) {
            return;
        }

        $unhealthy = SecurityPosture::unhealthyAreas($device->specs);

        if ($unhealthy === []) {
            // Reported, and nothing wrong: re-arm, so a fault that comes back
            // is news again rather than being swallowed by the first one.
            if ($device->security_alerted_at !== null) {
                $device->forceFill(['security_alerted_at' => null])->save();
            }

            return;
        }

        if ($device->security_alerted_at !== null) {
            return;
        }

        $device->forceFill(['security_alerted_at' => now()])->save();

        // Each area named individually as well as listed, so an automation can
        // act on "the firewall specifically" rather than parsing a sentence —
        // the condition field picker offers the fields an event actually
        // carries, so a key absent here is a filter nobody can build. A
        // healthy area says "ok" rather than being absent, because an absent
        // key cannot be filtered on and reads as "no answer".
        $this->events->emit('monitoring.security_needs_attention', $device, [
            'areas' => implode(', ', array_keys($unhealthy)),
            'antivirus' => $unhealthy['antivirus'] ?? 'ok',
            'firewall' => $unhealthy['firewall'] ?? 'ok',
            'updates' => $unhealthy['updates'] ?? 'ok',
            'platform' => $device->platform,
            'label' => $device->label,
        ]);
    }
}
