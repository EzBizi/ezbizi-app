<?php

namespace Tests\Feature\Monitoring;

use App\Models\AuditEvent;
use App\Models\Company;
use App\Models\Franchise;
use App\Models\MonitoredDevice;
use App\Models\PlatformStaff;
use App\Models\PlatformStaffLink;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PragmaRX\Google2FA\Google2FA;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Remote file access (2026-10-01-monitoring-files) — a manager browsing and
 * downloading a monitored machine's files, a round trip over the Remote
 * Control poll channel: the manager queues a request, the agent pulls it
 * read-once, answers into a per-request slot, and the manager collects it.
 *
 * Mirrors RemoteControlTest's shape — the same beat/deviceFor helpers, the
 * same §3, gating and exemption cases — one grant away: `monitoring.files.view`
 * rather than `monitoring.control`.
 */
class FilesTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    private const REFUSAL = 'This device belongs to a Lead Developer and is exempt from screen monitoring.';

    /** Files is locked behind an authenticator code; the lock itself is
     * covered by the unlock tests below, so the rest open it directly. */
    private function unlockFiles($user): void
    {
        Cache::put('monitoring-files-unlock:'.$user->getKey(), true, 900);
    }

    private function beat($user, string $deviceKey = 'dk-1'): void
    {
        $this->signedInAs($user)->postJson('/api/v1/monitoring/heartbeat', [
            'device_key' => $deviceKey, 'platform' => 'windows', 'capabilities' => ['screenshot'],
        ])->assertNoContent();
    }

    private function deviceFor($user, string $deviceKey = 'dk-1'): MonitoredDevice
    {
        return MonitoredDevice::withoutGlobalScopes()
            ->where('user_id', $user->getKey())
            ->where('device_key', $deviceKey)
            ->firstOrFail();
    }

    private function leadStaff(string $email): PlatformStaff
    {
        return PlatformStaff::create([
            'name' => 'Dev '.$email,
            'email' => $email,
            'password' => 'a-sufficiently-long-staff-password',
            'tier' => PlatformStaff::TIER_LEAD,
            'is_active' => true,
        ]);
    }

    // ── the whole round trip: request → agent pulls → agent answers → manager collects ──

    public function test_a_listing_request_travels_to_the_agent_and_its_answer_comes_back(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $this->beat($alpha['admin']);
        $device = $this->deviceFor($alpha['admin']);
        $manager = $this->userWithRole($alpha, 'Files', ['monitoring.files.view']);
        $this->unlockFiles($manager);

        // Manager asks for a listing.
        $requestId = $this->signedInAs($manager)
            ->postJson('/api/v1/monitoring/devices/'.$device->id.'/files', [
                'op' => 'list', 'path' => 'C:\\Users\\toula',
            ])
            ->assertOk()
            ->json('data.request_id');

        $this->assertNotEmpty($requestId);

        // Before the agent answers, the manager's poll says pending — not an
        // error, not an empty listing that reads as "the folder is empty".
        $this->signedInAs($manager)
            ->getJson('/api/v1/monitoring/devices/'.$device->id.'/files/'.$requestId)
            ->assertOk()
            ->assertJson(['data' => ['status' => 'pending']]);

        // Agent pulls what it should do.
        $pulled = $this->signedInAs($alpha['admin'])
            ->getJson('/api/v1/monitoring/file-requests?device_key=dk-1')
            ->assertOk()
            ->json('requests');

        $this->assertCount(1, $pulled);
        $this->assertSame('list', $pulled[0]['op']);
        $this->assertSame('C:\\Users\\toula', $pulled[0]['path']);
        $this->assertSame($requestId, $pulled[0]['id']);

        // Agent answers.
        $this->signedInAs($alpha['admin'])
            ->postJson('/api/v1/monitoring/file-requests/'.$requestId, [
                'device_key' => 'dk-1',
                'status' => 'ready',
                'op' => 'list',
                'path' => 'C:\\Users\\toula',
                'entries' => [
                    ['name' => 'Documents', 'is_dir' => true, 'size_bytes' => null, 'modified_at' => '2026-09-30T10:00:00Z'],
                    ['name' => 'invoice.pdf', 'is_dir' => false, 'size_bytes' => 20480, 'modified_at' => '2026-09-29T08:00:00Z'],
                ],
            ])
            ->assertNoContent();

        // Manager collects the answer.
        $this->signedInAs($manager)
            ->getJson('/api/v1/monitoring/devices/'.$device->id.'/files/'.$requestId)
            ->assertOk()
            ->assertJson(['data' => [
                'status' => 'ready',
                'op' => 'list',
                'path' => 'C:\\Users\\toula',
                'entries' => [
                    ['name' => 'Documents', 'is_dir' => true],
                    ['name' => 'invoice.pdf', 'is_dir' => false, 'size_bytes' => 20480],
                ],
            ]]);
    }

    public function test_a_download_answer_carries_the_bytes_back(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $this->beat($alpha['admin']);
        $device = $this->deviceFor($alpha['admin']);
        $manager = $this->userWithRole($alpha, 'Files', ['monitoring.files.view']);
        $this->unlockFiles($manager);

        $requestId = $this->signedInAs($manager)
            ->postJson('/api/v1/monitoring/devices/'.$device->id.'/files', [
                'op' => 'download', 'path' => 'C:\\Users\\toula\\invoice.pdf',
            ])
            ->assertOk()->json('data.request_id');

        $bytes = base64_encode('the file body');

        $this->signedInAs($alpha['admin'])
            ->postJson('/api/v1/monitoring/file-requests/'.$requestId, [
                'device_key' => 'dk-1',
                'status' => 'ready',
                'op' => 'download',
                'name' => 'invoice.pdf',
                'size' => 13,
                'content_base64' => $bytes,
            ])
            ->assertNoContent();

        $this->signedInAs($manager)
            ->getJson('/api/v1/monitoring/devices/'.$device->id.'/files/'.$requestId)
            ->assertOk()
            ->assertJson(['data' => [
                'status' => 'ready', 'op' => 'download', 'name' => 'invoice.pdf', 'content_base64' => $bytes,
            ]]);
    }

    public function test_a_drive_with_entries_windows_will_not_stat_still_lists(): void
    {
        // Measured 2026-10-12: every drive root holds entries Windows will not
        // let anyone stat — pagefile.sys, hiberfil.sys, System Volume
        // Information. Dart's statSync does not throw for them; it returns
        // type notFound, size -1, modified 1970-01-01. size_bytes was min:0, so
        // the WHOLE listing 422'd and every drive opened to "looks offline".
        // The agent's real wire shape for C:\ on a shipped build:
        $alpha = $this->provisionTenant('Alpha');
        $this->beat($alpha['admin']);
        $device = $this->deviceFor($alpha['admin']);
        $manager = $this->userWithRole($alpha, 'Files', ['monitoring.files.view']);
        $this->unlockFiles($manager);

        $requestId = $this->signedInAs($manager)
            ->postJson('/api/v1/monitoring/devices/'.$device->id.'/files', ['op' => 'list', 'path' => 'C:\\'])
            ->json('data.request_id');

        $this->signedInAs($alpha['admin'])
            ->postJson('/api/v1/monitoring/file-requests/'.$requestId, [
                'device_key' => 'dk-1', 'status' => 'ready', 'op' => 'list', 'path' => 'C:\\',
                'entries' => [
                    ['name' => 'Users', 'is_dir' => true, 'size_bytes' => null, 'modified_at' => '2026-10-01T12:00:00.000Z'],
                    ['name' => 'pagefile.sys', 'is_dir' => false, 'size_bytes' => -1, 'modified_at' => '1970-01-01T00:00:00.000Z'],
                ],
            ])
            ->assertNoContent();

        // The unreadable entry is still listed, with no size and no date —
        // never a "-1 bytes" or a 1970 timestamp the agent never measured.
        $this->signedInAs($manager)
            ->getJson('/api/v1/monitoring/devices/'.$device->id.'/files/'.$requestId)
            ->assertJsonPath('data.status', 'ready')
            ->assertJsonPath('data.entries.0.size_bytes', null)
            ->assertJsonPath('data.entries.0.modified_at', '2026-10-01T12:00:00.000Z')
            ->assertJsonPath('data.entries.1.name', 'pagefile.sys')
            ->assertJsonPath('data.entries.1.size_bytes', null)
            ->assertJsonPath('data.entries.1.modified_at', null);
    }

    public function test_a_zero_byte_file_downloads_rather_than_timing_out(): void
    {
        // An empty file is base64 '' on the wire, which ConvertEmptyStringsToNull
        // turns into null — the same trap as the roots path. It must arrive as
        // an empty download, not a 422 the viewer reads as "offline".
        $alpha = $this->provisionTenant('Alpha');
        $this->beat($alpha['admin']);
        $device = $this->deviceFor($alpha['admin']);
        $manager = $this->userWithRole($alpha, 'Files', ['monitoring.files.view']);
        $this->unlockFiles($manager);

        $requestId = $this->signedInAs($manager)
            ->postJson('/api/v1/monitoring/devices/'.$device->id.'/files', [
                'op' => 'download', 'path' => 'C:\\empty.txt',
            ])
            ->json('data.request_id');

        $this->signedInAs($alpha['admin'])
            ->postJson('/api/v1/monitoring/file-requests/'.$requestId, [
                'device_key' => 'dk-1', 'status' => 'ready', 'op' => 'download',
                'name' => 'empty.txt', 'size' => 0, 'content_base64' => '',
            ])
            ->assertNoContent();

        $this->signedInAs($manager)
            ->getJson('/api/v1/monitoring/devices/'.$device->id.'/files/'.$requestId)
            ->assertJsonPath('data.status', 'ready')
            ->assertJsonPath('data.name', 'empty.txt');
    }

    public function test_the_agent_can_report_an_error_the_manager_then_sees(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $this->beat($alpha['admin']);
        $device = $this->deviceFor($alpha['admin']);
        $manager = $this->userWithRole($alpha, 'Files', ['monitoring.files.view']);
        $this->unlockFiles($manager);

        $requestId = $this->signedInAs($manager)
            ->postJson('/api/v1/monitoring/devices/'.$device->id.'/files', [
                'op' => 'list', 'path' => 'C:\\Windows\\System32\\config',
            ])
            ->assertOk()->json('data.request_id');

        $this->signedInAs($alpha['admin'])
            ->postJson('/api/v1/monitoring/file-requests/'.$requestId, [
                'device_key' => 'dk-1', 'status' => 'error', 'error' => 'access_denied',
                'path' => 'C:\\Windows\\System32\\config',
            ])
            ->assertNoContent();

        $this->signedInAs($manager)
            ->getJson('/api/v1/monitoring/devices/'.$device->id.'/files/'.$requestId)
            ->assertOk()
            ->assertJson(['data' => ['status' => 'error', 'error' => 'access_denied']]);
    }

    public function test_the_roots_request_sends_an_empty_path_and_is_accepted(): void
    {
        // THE BUG the first real machines hit. The Files screen asks for "This
        // PC" by POSTing path:'' — the agent reads an empty path as "the
        // drives". Laravel's `required` rejects an empty string, so the roots
        // request 422'd before it ever reached the agent, and the viewer showed
        // "This could not be read" on every machine regardless of build. The
        // listing tests all used a non-empty path and never caught it.
        $alpha = $this->provisionTenant('Alpha');
        $this->beat($alpha['admin']);
        $device = $this->deviceFor($alpha['admin']);
        $manager = $this->userWithRole($alpha, 'Files', ['monitoring.files.view']);
        $this->unlockFiles($manager);

        $requestId = $this->signedInAs($manager)
            ->postJson('/api/v1/monitoring/devices/'.$device->id.'/files', [
                'op' => 'list', 'path' => '',
            ])
            ->assertOk()
            ->json('data.request_id');

        $this->assertNotEmpty($requestId);

        // And the empty path travels to the agent as-is, so its reader lists
        // the drives rather than a named folder.
        $pulled = $this->signedInAs($alpha['admin'])
            ->getJson('/api/v1/monitoring/file-requests?device_key=dk-1')
            ->assertOk()
            ->json('requests');

        $this->assertCount(1, $pulled);
        $this->assertSame('', $pulled[0]['path']);

        // THE SECOND HALF of the same bug, found 2026-10-12 on Hyanne's machine:
        // the agent echoes path:'' back in its ANSWER, and answer() validated it
        // as `sometimes|string` — ConvertEmptyStringsToNull turns '' into null,
        // `string` rejects null, the answer 422'd, the slot never filled, and the
        // viewer timed out to "This computer looks offline (last seen 0 min ago)"
        // on a machine that was polling every 1.5s. This is the agent's real
        // wire shape for the drive list.
        $this->signedInAs($alpha['admin'])
            ->postJson('/api/v1/monitoring/file-requests/'.$requestId, [
                'device_key' => 'dk-1',
                'status' => 'ready',
                'op' => 'list',
                'path' => '',
                'entries' => [
                    ['name' => 'C:\\', 'is_dir' => true, 'size_bytes' => null, 'modified_at' => null],
                ],
            ])
            ->assertNoContent();

        $this->signedInAs($manager)
            ->getJson('/api/v1/monitoring/devices/'.$device->id.'/files/'.$requestId)
            ->assertOk()
            ->assertJsonPath('data.status', 'ready')
            ->assertJsonPath('data.entries.0.name', 'C:\\');
    }

    public function test_an_error_answer_for_the_roots_is_accepted_too(): void
    {
        // The agent's error branch also echoes path:'' — a roots read that
        // failed must still reach the manager as its slug, not time out as
        // "offline".
        $alpha = $this->provisionTenant('Alpha');
        $this->beat($alpha['admin']);
        $device = $this->deviceFor($alpha['admin']);
        $manager = $this->userWithRole($alpha, 'Files', ['monitoring.files.view']);
        $this->unlockFiles($manager);

        $requestId = $this->signedInAs($manager)
            ->postJson('/api/v1/monitoring/devices/'.$device->id.'/files', ['op' => 'list', 'path' => ''])
            ->json('data.request_id');

        $this->signedInAs($alpha['admin'])
            ->postJson('/api/v1/monitoring/file-requests/'.$requestId, [
                'device_key' => 'dk-1', 'status' => 'error', 'op' => 'list',
                'error' => 'unreadable', 'path' => '',
            ])
            ->assertNoContent();

        $this->signedInAs($manager)
            ->getJson('/api/v1/monitoring/devices/'.$device->id.'/files/'.$requestId)
            ->assertJsonPath('data.status', 'error')
            ->assertJsonPath('data.error', 'unreadable');
    }

    // ── read-once pull ───────────────────────────────────────────────

    public function test_the_agent_pulls_a_request_only_once(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $this->beat($alpha['admin']);
        $device = $this->deviceFor($alpha['admin']);
        $manager = $this->userWithRole($alpha, 'Files', ['monitoring.files.view']);
        $this->unlockFiles($manager);

        $this->signedInAs($manager)
            ->postJson('/api/v1/monitoring/devices/'.$device->id.'/files', ['op' => 'list', 'path' => 'C:\\'])
            ->assertOk();

        $first = $this->signedInAs($alpha['admin'])
            ->getJson('/api/v1/monitoring/file-requests?device_key=dk-1')
            ->assertOk()->json('requests');
        $this->assertCount(1, $first);

        // A retried or duplicated poll must not run the same operation twice.
        $this->signedInAs($alpha['admin'])
            ->getJson('/api/v1/monitoring/file-requests?device_key=dk-1')
            ->assertOk()
            ->assertJson(['requests' => []]);
    }

    // ── §2.2 audit: one row per path opened, listing vs download distinguished ──

    public function test_a_big_download_comes_in_pieces_and_is_audited_once(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $this->beat($alpha['admin']);
        $device = $this->deviceFor($alpha['admin']);
        $manager = $this->userWithRole($alpha, 'Files', ['monitoring.files.view']);
        $this->unlockFiles($manager);
        $url = '/api/v1/monitoring/devices/'.$device->id.'/files';

        foreach ([0, 4194304, 8388608] as $offset) {
            $this->signedInAs($manager)
                ->postJson($url, ['op' => 'download', 'path' => 'C:\v.mp4', 'offset' => $offset])
                ->assertOk();
        }
        // Starting mid-file on another path is still recorded.
        $this->signedInAs($manager)
            ->postJson($url, ['op' => 'download', 'path' => 'C:\other.mp4', 'offset' => 4194304])
            ->assertOk();

        $queued = $this->signedInAs($alpha['admin'])
            ->getJson('/api/v1/monitoring/file-requests?device_key=dk-1')
            ->assertOk()->json('requests');
        $this->assertSame([0, 4194304, 8388608, 4194304], array_column($queued, 'offset'));

        $paths = AuditEvent::withoutGlobalScopes()
            ->where('action', 'monitoring.files.downloaded')
            ->get()->map(fn ($r) => $r->metadata['path'])->all();
        $this->assertSame(['C:\v.mp4', 'C:\other.mp4'], $paths);

        $requestId = $this->signedInAs($manager)
            ->postJson($url, ['op' => 'download', 'path' => 'C:\v.mp4', 'offset' => 4194304])
            ->json('data.request_id');
        $this->signedInAs($alpha['admin'])
            ->postJson('/api/v1/monitoring/file-requests/'.$requestId, [
                'device_key' => 'dk-1', 'status' => 'ready', 'op' => 'download',
                'name' => 'v.mp4', 'size' => 9000000, 'offset' => 4194304,
                'content_base64' => base64_encode('piece'),
            ])->assertNoContent();
        $this->signedInAs($manager)
            ->getJson($url.'/'.$requestId)
            ->assertOk()
            ->assertJson(['data' => ['status' => 'ready', 'size' => 9000000, 'offset' => 4194304]]);
    }

    public function test_each_request_writes_an_audit_row_naming_the_path(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $this->beat($alpha['admin']);
        $device = $this->deviceFor($alpha['admin']);
        $manager = $this->userWithRole($alpha, 'Files', ['monitoring.files.view']);
        $this->unlockFiles($manager);

        $this->signedInAs($manager)
            ->postJson('/api/v1/monitoring/devices/'.$device->id.'/files', ['op' => 'list', 'path' => 'C:\\Users'])
            ->assertOk();
        $this->signedInAs($manager)
            ->postJson('/api/v1/monitoring/devices/'.$device->id.'/files', ['op' => 'download', 'path' => 'C:\\Users\\secret.txt'])
            ->assertOk();

        $rows = AuditEvent::withoutGlobalScopes()
            ->where('organization_id', $alpha['org']->id)
            ->whereIn('action', ['monitoring.files.listed', 'monitoring.files.downloaded'])
            ->get();

        $this->assertCount(2, $rows,
            'Reading another person\'s files is audited per path, not once per session.');

        $listed = $rows->firstWhere('action', 'monitoring.files.listed');
        $downloaded = $rows->firstWhere('action', 'monitoring.files.downloaded');

        $this->assertSame('C:\\Users', $listed->metadata['path'] ?? null);
        $this->assertSame('C:\\Users\\secret.txt', $downloaded->metadata['path'] ?? null);
        $this->assertSame($manager->getKey(), $listed->actor_id);
        $this->assertSame($device->id, $listed->target_id);
    }

    // ── gating: monitoring.files.view is its own grant ───────────────

    public function test_the_roots_answer_carries_quick_links_and_older_agents_send_none(): void
    {
        // Explorer's Quick access (Jose, 2026-10-12): the agent names the
        // signed-in user's own Desktop, Downloads... with the drive list.
        $alpha = $this->provisionTenant('Alpha');
        $this->beat($alpha['admin']);
        $device = $this->deviceFor($alpha['admin']);
        $manager = $this->userWithRole($alpha, 'Files', ['monitoring.files.view']);
        $this->unlockFiles($manager);

        $ask = fn () => $this->signedInAs($manager)
            ->postJson('/api/v1/monitoring/devices/'.$device->id.'/files', ['op' => 'list', 'path' => ''])
            ->json('data.request_id');
        $answer = fn (string $rid, array $extra) => $this->signedInAs($alpha['admin'])
            ->postJson('/api/v1/monitoring/file-requests/'.$rid, [
                'device_key' => 'dk-1', 'status' => 'ready', 'op' => 'list', 'path' => '',
                'entries' => [['name' => 'C:\\', 'is_dir' => true]],
            ] + $extra)
            ->assertNoContent();
        $collect = fn (string $rid) => $this->signedInAs($manager)
            ->getJson('/api/v1/monitoring/devices/'.$device->id.'/files/'.$rid);

        $rid = $ask();
        $answer($rid, ['quick_links' => [
            ['label' => 'Desktop', 'path' => 'C:\\Users\\hyanne\\OneDrive\\Desktop'],
            ['label' => 'Downloads', 'path' => 'C:\\Users\\hyanne\\Downloads'],
        ]]);
        $collect($rid)
            ->assertJsonPath('data.quick_links.0.label', 'Desktop')
            ->assertJsonPath('data.quick_links.0.path', 'C:\\Users\\hyanne\\OneDrive\\Desktop')
            ->assertJsonPath('data.quick_links.1.label', 'Downloads');

        // A shipped agent that predates quick links: an empty list, not a 422.
        $rid = $ask();
        $answer($rid, []);
        $collect($rid)->assertJsonPath('data.status', 'ready')->assertJsonPath('data.quick_links', []);
    }

    // ── the authenticator lock (Jose, 2026-10-12) ─────────────────────

    private function withAuthenticator(array $tenant, $user): string
    {
        $secret = app(Google2FA::class)->generateSecretKey(32);
        $this->inTenant($tenant['org'], fn () => $user->forceFill([
            'totp_secret' => $secret, 'totp_confirmed_at' => now(),
        ])->save());

        return $secret;
    }

    public function test_files_stay_locked_until_a_current_authenticator_code_opens_them(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $this->beat($alpha['admin']);
        $device = $this->deviceFor($alpha['admin']);
        $manager = $this->userWithRole($alpha, 'Files', ['monitoring.files.view']);
        $secret = $this->withAuthenticator($alpha, $manager);

        // Locked: the screen gets a code it can turn into the prompt.
        $this->signedInAs($manager)
            ->postJson('/api/v1/monitoring/devices/'.$device->id.'/files', ['op' => 'list', 'path' => ''])
            ->assertForbidden()
            ->assertJsonPath('code', 'files_locked');

        // Nothing was queued for the agent while locked.
        $this->signedInAs($alpha['admin'])
            ->getJson('/api/v1/monitoring/file-requests?device_key=dk-1')
            ->assertJsonCount(0, 'requests');

        // A wrong code does not open it.
        $this->signedInAs($manager)
            ->postJson('/api/v1/monitoring/files/unlock', ['code' => '000000'])
            ->assertStatus(422);

        $this->signedInAs($manager)
            ->postJson('/api/v1/monitoring/files/unlock', [
                'code' => app(Google2FA::class)->getCurrentOtp($secret),
            ])
            ->assertOk()
            ->assertJsonStructure(['data' => ['unlocked_until']]);

        $this->signedInAs($manager)
            ->postJson('/api/v1/monitoring/devices/'.$device->id.'/files', ['op' => 'list', 'path' => ''])
            ->assertOk();

        $this->assertTrue(
            AuditEvent::query()->where('action', 'monitoring.files.unlocked')->exists(),
            'Each unlock is recorded, alongside every listing and download.',
        );
    }

    public function test_the_unlock_slides_with_use_and_lapses_after_fifteen_quiet_minutes(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $this->beat($alpha['admin']);
        $device = $this->deviceFor($alpha['admin']);
        $manager = $this->userWithRole($alpha, 'Files', ['monitoring.files.view']);
        $secret = $this->withAuthenticator($alpha, $manager);

        $this->signedInAs($manager)
            ->postJson('/api/v1/monitoring/files/unlock', ['code' => app(Google2FA::class)->getCurrentOtp($secret)])
            ->assertOk();

        $list = fn () => $this->signedInAs($manager)
            ->postJson('/api/v1/monitoring/devices/'.$device->id.'/files', ['op' => 'list', 'path' => '']);

        // In use at 10 and 20 minutes: still open, because each request
        // restarts the 15.
        $this->travel(10)->minutes();
        $list()->assertOk();
        $this->travel(10)->minutes();
        $list()->assertOk();

        // 16 quiet minutes: locked again.
        $this->travel(16)->minutes();
        $list()->assertForbidden()->assertJsonPath('code', 'files_locked');
    }

    public function test_lock_now_ends_the_unlock_on_the_server_and_requests_report_the_expiry(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $this->beat($alpha['admin']);
        $device = $this->deviceFor($alpha['admin']);
        $manager = $this->userWithRole($alpha, 'Files', ['monitoring.files.view']);
        $this->unlockFiles($manager);

        // Each request reports the server's sliding expiry, 15 minutes out.
        $until = $this->signedInAs($manager)
            ->postJson('/api/v1/monitoring/devices/'.$device->id.'/files', ['op' => 'list', 'path' => ''])
            ->assertOk()
            ->json('data.unlocked_until');
        $this->assertEqualsWithDelta(
            now()->addMinutes(15)->getTimestamp(),
            Carbon::parse($until)->getTimestamp(),
            5,
        );

        // "Lock now" locks for real, not just on the screen.
        $this->signedInAs($manager)
            ->deleteJson('/api/v1/monitoring/files/unlock')
            ->assertNoContent();

        $this->signedInAs($manager)
            ->postJson('/api/v1/monitoring/devices/'.$device->id.'/files', ['op' => 'list', 'path' => ''])
            ->assertForbidden()
            ->assertJsonPath('code', 'files_locked');
    }

    public function test_a_manager_who_signs_in_through_a_staff_account_unlocks_with_that_authenticator(): void
    {
        // Jose and Bar, 2026-10-13: "it says i need to set it up, when it's
        // already set up". Their sign-in factor lives on the linked
        // PlatformStaff account, not on the tenant user — TwoFactorPolicy::
        // subjectFor() is what sign-in asks, and unlock() must ask the same.
        $alpha = $this->provisionTenant('Alpha');
        $this->beat($alpha['admin']);
        $device = $this->deviceFor($alpha['admin']);
        $manager = $this->userWithRole($alpha, 'Files', ['monitoring.files.view']);

        $staff = $this->leadStaff('manager-staff@ezbizi.test');
        $secret = app(Google2FA::class)->generateSecretKey(32);
        $staff->forceFill(['totp_secret' => $secret, 'totp_confirmed_at' => now()])->save();
        PlatformStaffLink::create(['staff_id' => $staff->getKey(), 'user_id' => $manager->getKey()]);

        // The tenant user itself has no authenticator — exactly their case.
        $this->assertFalse($manager->fresh()->hasTotp());

        $this->signedInAs($manager)
            ->postJson('/api/v1/monitoring/files/unlock', [
                'code' => app(Google2FA::class)->getCurrentOtp($secret),
            ])
            ->assertOk();

        $this->signedInAs($manager)
            ->postJson('/api/v1/monitoring/devices/'.$device->id.'/files', ['op' => 'list', 'path' => ''])
            ->assertOk();
    }

    public function test_only_a_six_digit_code_unlocks_so_no_recovery_code_is_spent(): void
    {
        // TotpEnrolment::verify() consumes a recovery code for anything that is
        // not six digits. A recovery code is for getting back into an account;
        // browsing a desk must never burn one.
        $alpha = $this->provisionTenant('Alpha');
        $manager = $this->userWithRole($alpha, 'Files', ['monitoring.files.view']);
        $this->withAuthenticator($alpha, $manager);

        $this->signedInAs($manager)
            ->postJson('/api/v1/monitoring/files/unlock', ['code' => 'ABCD-1234'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('code');
    }

    public function test_unlocking_without_an_authenticator_says_to_set_one_up(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $manager = $this->userWithRole($alpha, 'Files', ['monitoring.files.view']);
        $this->inTenant($alpha['org'], fn () => $manager->forceFill([
            'totp_secret' => null, 'totp_confirmed_at' => null,
        ])->save());

        $this->signedInAs($manager)
            ->postJson('/api/v1/monitoring/files/unlock', ['code' => '123456'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'no_authenticator');
    }

    public function test_unlocking_needs_the_files_permission(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $other = $this->userWithRole($alpha, 'Driver', ['monitoring.control']);
        $secret = $this->withAuthenticator($alpha, $other);

        $this->signedInAs($other)
            ->postJson('/api/v1/monitoring/files/unlock', ['code' => app(Google2FA::class)->getCurrentOtp($secret)])
            ->assertForbidden();
    }

    public function test_files_without_the_permission_is_refused_with_a_sentence(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $this->beat($alpha['admin']);
        $device = $this->deviceFor($alpha['admin']);
        // Holds control AND screenshots, but not files — proves files.view is
        // neither of them: seeing files is not driving the mouse nor watching
        // the screen.
        $other = $this->userWithRole($alpha, 'Driver', ['monitoring.control', 'monitoring.screenshots.view']);

        $this->signedInAs($other)
            ->postJson('/api/v1/monitoring/devices/'.$device->id.'/files', ['op' => 'list', 'path' => 'C:\\'])
            ->assertForbidden()
            ->assertJsonStructure(['message']);

        // And collecting an answer is gated too, not only asking.
        $this->signedInAs($other)
            ->getJson('/api/v1/monitoring/devices/'.$device->id.'/files/whatever')
            ->assertForbidden();

        // Control: the grant opens it.
        $manager = $this->userWithRole($alpha, 'Files', ['monitoring.files.view']);
        $this->unlockFiles($manager);
        $this->signedInAs($manager)
            ->postJson('/api/v1/monitoring/devices/'.$device->id.'/files', ['op' => 'list', 'path' => 'C:\\'])
            ->assertOk();
    }

    // ── §3 reach ─────────────────────────────────────────────────────

    public function test_a_sibling_orgs_manager_gets_404_requesting_files_on_another_devices(): void
    {
        $alpha = $this->provisionTenant('Alpha');

        $elsewhere = $this->inTenant($alpha['org'], function () use ($alpha) {
            $company = Company::create(['organization_id' => $alpha['org']->id, 'name' => 'Other Co']);

            return Franchise::create([
                'organization_id' => $alpha['org']->id,
                'company_id' => $company->id,
                'name' => 'Other F1',
            ]);
        });

        $theirs = $this->userWithRole($alpha, 'Distant', [], onlyAt: $elsewhere);
        $this->beat($theirs, 'dk-distant');
        $theirDevice = $this->deviceFor($theirs, 'dk-distant');

        $manager = $this->userWithRole(
            $alpha, 'Branch Manager', ['monitoring.files.view'], onlyAt: $alpha['franchise'],
        );
        $this->unlockFiles($manager);

        $this->signedInAs($manager)
            ->postJson('/api/v1/monitoring/devices/'.$theirDevice->id.'/files', ['op' => 'list', 'path' => 'C:\\'])
            ->assertNotFound();

        // No request was queued for a device the manager cannot reach.
        $this->signedInAs($theirs)
            ->getJson('/api/v1/monitoring/file-requests?device_key=dk-distant')
            ->assertOk()
            ->assertJson(['requests' => []]);
    }

    // ── the SECURITY exemption holds at every door ───────────────────

    public function test_the_lead_developers_device_refuses_files_at_every_door(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $this->leadStaff(strtolower($alpha['admin']->email));
        $this->beat($alpha['admin']);
        $device = $this->deviceFor($alpha['admin']);
        $manager = $this->userWithRole($alpha, 'Files', ['monitoring.files.view']);
        $this->unlockFiles($manager);

        // The manager cannot open a request over the exempt machine.
        $this->signedInAs($manager)
            ->postJson('/api/v1/monitoring/devices/'.$device->id.'/files', ['op' => 'list', 'path' => 'C:\\'])
            ->assertForbidden()
            ->assertJson(['message' => self::REFUSAL]);

        // And the exempt machine's own agent is never handed a request to run.
        $this->signedInAs($alpha['admin'])
            ->getJson('/api/v1/monitoring/file-requests?device_key=dk-1')
            ->assertForbidden()
            ->assertJson(['message' => self::REFUSAL]);
    }

    // ── the inline download ceiling ──────────────────────────────────

    public function test_a_download_over_the_inline_cap_comes_back_as_too_large(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $this->beat($alpha['admin']);
        $device = $this->deviceFor($alpha['admin']);
        $manager = $this->userWithRole($alpha, 'Files', ['monitoring.files.view']);
        $this->unlockFiles($manager);

        $requestId = $this->signedInAs($manager)
            ->postJson('/api/v1/monitoring/devices/'.$device->id.'/files', ['op' => 'download', 'path' => 'C:\\big.iso'])
            ->assertOk()->json('data.request_id');

        // 7 MB of base64 — past the ~6.99 MB encoded ceiling (base64 of the
        // 5 MB raw cap). The server retires it to too_large rather than parking
        // it in the cache, even though the agent should have said so itself.
        $tooBig = str_repeat('A', 7 * 1024 * 1024);

        $this->signedInAs($alpha['admin'])
            ->postJson('/api/v1/monitoring/file-requests/'.$requestId, [
                'device_key' => 'dk-1', 'status' => 'ready', 'op' => 'download',
                'name' => 'big.iso', 'size' => 7 * 1024 * 1024, 'content_base64' => $tooBig,
            ])
            ->assertNoContent();

        $this->signedInAs($manager)
            ->getJson('/api/v1/monitoring/devices/'.$device->id.'/files/'.$requestId)
            ->assertOk()
            ->assertJson(['data' => ['status' => 'error', 'op' => 'download', 'error' => 'too_large']]);
    }
}
