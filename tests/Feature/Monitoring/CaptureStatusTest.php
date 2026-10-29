<?php

namespace Tests\Feature\Monitoring;

use App\Models\MonitoredDevice;
use App\Models\PlatformStaff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * A device reporting WHY its last screen capture produced nothing.
 *
 * The gap this closes: a capture that failed used to be silent on every layer
 * — the native side returned an empty buffer for ~20 distinct reasons, the
 * channel flattened all of them into `Success(null)`, and the client logged it
 * only under kDebugMode. So "this device can capture screenshots, but none
 * have landed yet" was equally true of a machine with no JPEG encoder, one
 * whose owner keeps the screen locked, and one that had simply not reached its
 * first five-minute tick, and NO query against this database could tell them
 * apart, because the fact had never been written down anywhere.
 *
 * What is pinned here is therefore not "a column can be written" but the three
 * properties that make the column trustworthy: a success CLEARS a previous
 * fault (so a recovered device stops reporting a stale one), the reason is
 * validated against a fixed vocabulary (so it can never become a way to put
 * arbitrary text — or something read off a screen — onto the roster), and an
 * exempt device's report is refused outright.
 */
class CaptureStatusTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    private function beat($user, array $extra = [])
    {
        return $this->signedInAs($user)->postJson('/api/v1/monitoring/heartbeat', [
            'device_key' => 'dk-1',
            'platform' => 'windows',
            'capabilities' => ['activity', 'screenshot'],
        ] + $extra);
    }

    private function device(): MonitoredDevice
    {
        return MonitoredDevice::withoutGlobalScopes()->firstOrFail();
    }

    public function test_a_reported_failure_is_stored_with_its_reason(): void
    {
        $alpha = $this->provisionTenant('Alpha');

        $this->beat($alpha['admin'], ['capture' => [
            'ok' => false,
            'reason' => 'jpeg_encoder_missing',
            'at' => now()->toIso8601String(),
        ]])->assertNoContent();

        $device = $this->device();
        $this->assertSame('jpeg_encoder_missing', $device->capture_last_error);
        $this->assertNotNull(
            $device->capture_last_attempt_at,
            'The attempt TIME is half the fact: an old failure on a quiet machine and a '
            .'failure thirty seconds ago are different situations.'
        );
    }

    public function test_a_success_clears_a_previous_failure(): void
    {
        $alpha = $this->provisionTenant('Alpha');

        $this->beat($alpha['admin'], ['capture' => [
            'ok' => false, 'reason' => 'screen_locked', 'at' => now()->toIso8601String(),
        ]]);
        // The control: the fault really was recorded, so the null below means
        // it was CLEARED rather than never written.
        $this->assertSame('screen_locked', $this->device()->capture_last_error);

        $this->beat($alpha['admin'], ['capture' => [
            'ok' => true, 'at' => now()->toIso8601String(),
        ]])->assertNoContent();

        $this->assertNull(
            $this->device()->capture_last_error,
            'A device that recovers must stop reporting a stale fault — a lock screen at '
            .'lunchtime should not still be on the roster at four.'
        );
    }

    public function test_a_beat_with_no_capture_block_leaves_the_last_known_answer_alone(): void
    {
        $alpha = $this->provisionTenant('Alpha');

        $this->beat($alpha['admin'], ['capture' => [
            'ok' => false, 'reason' => 'blit_failed', 'at' => now()->toIso8601String(),
        ]]);

        // Beats run every 60s and captures every 5 minutes, so MOST beats carry
        // no capture block at all. If those blanked the column, the roster
        // would show "we don't know" for four minutes out of every five.
        $this->beat($alpha['admin'])->assertNoContent();

        $this->assertSame('blit_failed', $this->device()->capture_last_error);
    }

    public function test_a_reason_outside_the_vocabulary_is_stored_as_capture_failed(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        Log::spy();

        // 2026-10-29: refusing the beat took the device offline (461 beats),
        // so the beat is kept and only the reason is replaced.
        $this->beat($alpha['admin'], ['capture' => [
            'ok' => false,
            'reason' => 'Reading an email from Dr Patel about the biopsy',
            'at' => now()->toIso8601String(),
        ]])->assertNoContent();

        // The point of the vocabulary: this string is rendered to whoever can
        // see the roster, so a free-text reason would be a way to publish
        // whatever a tampered client read off the screen. It is not stored,
        // and not logged either.
        $this->assertSame('capture_failed', MonitoredDevice::withoutGlobalScopes()->value('capture_last_error'));
        Log::shouldHaveReceived('info')->with('monitoring.capture.unknown_reason', ['reason' => '(not a slug)']);
    }

    public function test_an_upload_status_reason_is_accepted(): void
    {
        $alpha = $this->provisionTenant('Alpha');

        $this->beat($alpha['admin'], ['capture' => [
            'ok' => false, 'reason' => 'upload_404', 'at' => now()->toIso8601String(),
        ]])->assertNoContent();

        $this->assertSame('upload_404', $this->device()->capture_last_error);
    }

    public function test_an_exempt_device_cannot_report_a_capture_outcome(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $admin = $alpha['admin'];

        PlatformStaff::create([
            'name' => 'Lead',
            'email' => $admin->email,
            'password' => 'a-sufficiently-long-staff-password',
            'tier' => PlatformStaff::TIER_LEAD,
            'is_active' => true,
        ]);

        // Accepted as a beat — an exempt device still shows online — but the
        // capture report is dropped: it never captures, so a report from one
        // is a tampered client, and the roster must not carry a screen-related
        // fact about a Lead Developer's machine at all.
        $this->beat($admin, ['capture' => [
            'ok' => false, 'reason' => 'screen_locked', 'at' => now()->toIso8601String(),
        ]])->assertNoContent();

        $device = $this->device();
        $this->assertNull($device->capture_last_error);
        $this->assertNull($device->capture_last_attempt_at);
    }

    // ── the read side ────────────────────────────────────────────────

    public function test_capture_status_is_absent_without_the_screenshots_grant(): void
    {
        $alpha = $this->provisionTenant('Alpha');

        $this->beat($alpha['admin'], ['capture' => [
            'ok' => false, 'reason' => 'screen_locked', 'at' => now()->toIso8601String(),
        ]]);

        $viewer = $this->userWithRole($alpha, 'Viewer', ['monitoring.view']);
        $row = $this->signedInAs($viewer)
            ->getJson('/api/v1/monitoring/devices')
            ->assertOk()
            ->json('data.0');

        $this->assertArrayNotHasKey(
            'capture',
            $row,
            'Absent, not present-and-null: the same rule `specs` follows, so a caller '
            .'without the grant cannot learn from the response SHAPE that there is '
            .'something here to be granted.'
        );
    }

    public function test_capture_status_is_returned_with_the_screenshots_grant(): void
    {
        $alpha = $this->provisionTenant('Alpha');

        $this->beat($alpha['admin'], ['capture' => [
            'ok' => false, 'reason' => 'jpeg_encoder_missing', 'at' => now()->toIso8601String(),
        ]]);

        $viewer = $this->userWithRole($alpha, 'Watcher', [
            'monitoring.view', 'monitoring.screenshots.view',
        ]);
        $row = $this->signedInAs($viewer)
            ->getJson('/api/v1/monitoring/devices')
            ->assertOk()
            ->json('data.0');

        $this->assertSame('jpeg_encoder_missing', $row['capture']['last_error']);
        $this->assertNotNull($row['capture']['last_attempt_at']);
    }
}
