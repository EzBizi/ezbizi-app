<?php

namespace App\Http\Controllers\Api\Monitoring;

use App\Http\Controllers\Api\Controller;
use App\Models\MonitoredDevice;
use App\Models\MonitoringActivity;
use App\Support\Automations\EventEmitter;
use App\Support\Monitoring\ScreenMonitoringExemption;
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
            'device_key'          => ['required', 'string', 'max:64'],
            'platform'            => ['required', Rule::in(self::PLATFORMS)],
            'capabilities'        => ['required', 'array'],
            'capabilities.*'      => [Rule::in(self::CAPABILITIES)],
            'label'               => ['nullable', 'string', 'max:120'],
            'current_module'      => ['nullable', 'string', 'max:64'],
            'specs'               => ['nullable', 'array'],
            'segment'             => ['nullable', 'array'],
            'segment.module_key'  => ['required_with:segment', 'string', 'max:64'],
            'segment.dwell_seconds' => ['required_with:segment', 'integer', 'min:0'],
            'capture'             => ['nullable', 'array'],
            'capture.ok'          => ['required_with:capture', 'boolean'],
            'capture.at'          => ['required_with:capture', 'date'],
            'capture.reason'      => ['nullable', 'string', 'max:40', self::CAPTURE_REASON],
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
            'user_id'         => $request->user()->getKey(),
            'device_key'      => $data['device_key'],
        ]);
        $isNew = ! $device->exists;

        $device->fill([
            'label'          => $data['label'] ?? $device->label,
            'platform'       => $data['platform'],
            'capabilities'   => $data['capabilities'],
            'user_agent'     => substr((string) $request->userAgent(), 0, 400),
            'ip_address'     => $request->ip(),
            'last_seen_at'   => now(),
            'current_module' => $data['current_module'] ?? $device->current_module,
            'offline_alerted_at' => null, // a beat re-arms the offline alert
        ]);
        if ($keepSpecs && isset($data['specs'])) {
            $specs = $data['specs'];

            // SECURITY exemption, third application of the same rule:
            // `active_window` is the TITLE of whatever is in front, which
            // carries the content of the screen (a customer name, a subject
            // line, a document) rather than anything about the hardware. The
            // Lead Developer's screen is exempt from monitoring, so the title
            // is stripped here even though the client already declines to
            // send it — a tampered client must not be able to write one.
            //
            // Everything else in `specs` is about the MACHINE and stays: an
            // exempt device still shows its RAM, its disks and whether it is
            // online, which is exactly the line the exemption draws.
            if (ScreenMonitoringExemption::isExempt($request->user())) {
                unset($specs['active_window']);
            }

            $device->specs = $specs;
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
                'device_id'       => $device->id,
                'user_id'         => $request->user()->getKey(),
                'module_key'      => $data['segment']['module_key'],
                'dwell_seconds'   => $data['segment']['dwell_seconds'],
                'occurred_at'     => now(),
            ]);
        }

        if ($isNew) {
            $this->events->emit('monitoring.new_device_seen', $device, [
                'platform' => $device->platform,
                'label'    => $device->label,
            ]);
        }

        return response()->noContent();
    }
}
