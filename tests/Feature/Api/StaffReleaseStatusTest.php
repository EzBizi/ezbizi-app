<?php

namespace Tests\Feature\Api;

use App\Models\PlatformStaff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PragmaRX\Google2FA\Google2FA;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * The Development tab's Releases screen reads what scripts/lib/release-status.sh
 * appended: one JSON line per stage boundary, folded here into releases.
 */
class StaffReleaseStatusTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    private string $file;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-11T15:00:00Z');
        $this->file = tempnam(sys_get_temp_dir(), 'release-status');
        config(['ezbizi.release_status_file' => $this->file]);
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function line(string $version, string $sha, string $stage, int $percent, string $state, string $message, string $at, ?string $targets = null): string
    {
        return json_encode(array_filter([
            'version' => $version, 'sha' => $sha, 'stage' => $stage, 'percent' => $percent,
            'state' => $state, 'message' => $message, 'at' => $at, 'targets' => $targets,
        ], fn ($v) => $v !== null))."\n";
    }

    private function fixture(): void
    {
        $old = str_repeat('a', 40);
        $failed = str_repeat('b', 40);
        $now = str_repeat('c', 40);

        file_put_contents($this->file, implode('', [
            // An older release that finished.
            $this->line('0.0.0557', $old, 'release', 0, 'running', 'started · targets: web', '2026-11-10T10:00:00Z', 'web'),
            $this->line('0.0.0557', $old, 'precheck', 100, 'done', '✓ precheck finished', '2026-11-10T10:00:00Z'),
            $this->line('0.0.0557', $old, 'server_tests', 100, 'done', '✓ 6700 backend tests passed', '2026-11-10T10:20:00Z'),
            $this->line('0.0.0557', $old, 'web', 100, 'done', '✓ done', '2026-11-10T10:25:00Z'),
            $this->line('0.0.0557', $old, 'release', 100, 'done', '✓ release v0.0.0557', '2026-11-10T10:25:01Z'),
            // One whose deploy died without its stage saying which.
            $this->line('0.0.0558', $failed, 'release', 0, 'running', 'started', '2026-11-11T09:00:00Z', 'web'),
            $this->line('0.0.0558', $failed, 'precheck', 100, 'done', '✓ precheck finished', '2026-11-11T09:00:00Z'),
            $this->line('0.0.0558', $failed, 'server_tests', 100, 'done', '✓ passed', '2026-11-11T09:20:00Z'),
            $this->line('0.0.0558', $failed, 'release', 0, 'failed', '✗ web/API deployment failed', '2026-11-11T09:22:00Z'),
            "not json — a torn line must not take the screen down\n",
            // The one in flight: web + windows, gate at 85%.
            $this->line('0.0.0559', $now, 'release', 0, 'running', 'started', '2026-11-11T14:40:00Z', 'web,windows'),
            $this->line('0.0.0559', $now, 'precheck', 100, 'done', '✓ precheck finished', '2026-11-11T14:40:00Z'),
            $this->line('0.0.0559', $now, 'server_tests', 0, 'running', 'starting the gate', '2026-11-11T14:40:05Z'),
            $this->line('0.0.0559', $now, 'server_tests', 85, 'running', 'phpunit running on the box', '2026-11-11T14:58:00Z'),
        ]));
    }

    public function test_staff_see_the_release_in_flight_and_the_recent_ones(): void
    {
        $this->fixture();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->staffToken())
            ->getJson('/api/v1/staff/release-status')
            ->assertOk();

        $current = $response->json('current');
        $this->assertSame('0.0.0559', $current['version']);
        $this->assertSame('running', $current['state']);
        $this->assertSame(['precheck', 'server_tests', 'web', 'windows'], array_column($current['stages'], 'key'));
        $this->assertSame(['done', 'running', 'pending', 'pending'], array_column($current['stages'], 'state'));
        // (10 + 40 × 0.85) of 75.
        $this->assertSame(58, $current['percent']);
        $this->assertSame('2026-11-11T14:40:00Z', $current['started_at']);
        $this->assertSame(20 * 60, $current['elapsed_seconds']);
        $this->assertSame('phpunit running on the box', $current['message']);

        $recent = $response->json('recent');
        $this->assertSame(['0.0.0558', '0.0.0557'], array_column($recent, 'version'));
        $this->assertSame('failed', $recent[0]['state']);
        $this->assertSame(['done', 'done', 'failed'], array_column($recent[0]['stages'], 'state'));
        $this->assertSame('✗ web/API deployment failed', $recent[0]['stages'][2]['message']);
        $this->assertSame('done', $recent[1]['state']);
        $this->assertSame(100, $recent[1]['percent']);
        $this->assertSame(25 * 60 + 1, $recent[1]['elapsed_seconds']);
    }

    /** Time left = the running stage's share of its median + every stage to come at its median (Jose 11-12). */
    public function test_the_release_in_flight_carries_time_left_from_past_medians(): void
    {
        $this->fixture();

        $current = $this->withHeader('Authorization', 'Bearer '.$this->staffToken())
            ->getJson('/api/v1/staff/release-status')->assertOk()->json('current');

        // Past runs: server tests took 20m twice, web 5m once; Windows never ran.
        $this->assertSame([0, 1200, 300, null], array_column($current['stages'], 'median_seconds'));
        // Server tests at 85% of 20m leave 3m, then web 5m; Windows has no history.
        $this->assertSame(180 + 300, $current['eta_seconds']);
        $this->assertSame(20 * 60, $current['stages'][1]['seconds']); // 14:40:00 → now 15:00
        $this->assertSame(0, $current['stages'][0]['seconds']);
    }

    /** A run that never said it ended, after which a later release finished, is Superseded, not pending forever. */
    public function test_a_run_overtaken_by_a_finished_release_is_superseded(): void
    {
        $stuck = str_repeat('d', 40);
        $later = str_repeat('e', 40);
        file_put_contents($this->file, implode('', [
            $this->line('0.0.0560', $stuck, 'release', 0, 'running', 'started', '2026-11-10T16:08:00Z', 'web'),
            $this->line('0.0.0560', $stuck, 'server_tests', 60, 'running', 'phpunit running on the box', '2026-11-10T16:35:00Z'),
            $this->line('0.0.0560', $later, 'release', 0, 'running', 'started', '2026-11-10T17:09:00Z', 'web'),
            $this->line('0.0.0560', $later, 'release', 100, 'done', '✓ release', '2026-11-10T18:42:00Z'),
        ]));

        $response = $this->withHeader('Authorization', 'Bearer '.$this->staffToken())
            ->getJson('/api/v1/staff/release-status')->assertOk();

        $this->assertSame('done', $response->json('current.state'));
        $this->assertSame('superseded', $response->json('recent.0.state'));
        $this->assertSame($stuck, $response->json('recent.0.sha'));
    }

    /** Staff see the tail of the commit's gate log, with anything credential-like masked. */
    public function test_staff_see_the_tail_of_the_gate_log(): void
    {
        $this->fixture();
        $dir = sys_get_temp_dir().'/gate-runs-'.uniqid();
        mkdir($dir);
        config(['ezbizi.gate_runs_dir' => $dir]);
        $sha = str_repeat('c', 40);
        $body = '';
        for ($i = 1; $i <= 50; $i++) {
            $body .= "line {$i}\n";
        }
        file_put_contents("{$dir}/{$sha}-1-1.log", $body."DB_PASSWORD=hunter2\n...... 5800 / 6833 ( 85%)\n");
        file_put_contents("{$dir}/".str_repeat('a', 40).'-1-1.log', "another commit\n");

        try {
            $log = $this->withHeader('Authorization', 'Bearer '.$this->staffToken())
                ->getJson('/api/v1/staff/release-status')->assertOk()->json('current.log');
        } finally {
            array_map('unlink', glob("{$dir}/*"));
            rmdir($dir);
        }

        $this->assertCount(40, $log);
        $this->assertSame('...... 5800 / 6833 ( 85%)', end($log));
        $this->assertContains('DB_PASSWORD=[hidden]', $log);
        $this->assertNotContains('another commit', $log);
    }

    public function test_no_file_is_no_release_not_an_error(): void
    {
        config(['ezbizi.release_status_file' => $this->file.'-missing']);

        $this->withHeader('Authorization', 'Bearer '.$this->staffToken())
            ->getJson('/api/v1/staff/release-status')
            ->assertOk()
            ->assertExactJson(['current' => null, 'recent' => []]);
    }

    public function test_a_customer_cannot_read_it(): void
    {
        $this->fixture();
        $alpha = $this->provisionTenant('Alpha');

        $this->signedInAs($alpha['admin'])
            ->getJson('/api/v1/staff/release-status')
            ->assertForbidden();
        $this->app['auth']->forgetGuards();

        $this->getJson('/api/v1/staff/release-status')->assertUnauthorized();
    }

    /** Everybody signed in sees the release on its way: version and percent, nothing else (Jose 11-12). */
    public function test_anybody_signed_in_sees_only_the_version_and_percent_of_the_running_release(): void
    {
        $this->fixture();
        $alpha = $this->provisionTenant('Alpha');

        $this->signedInAs($alpha['admin'])->getJson('/api/v1/release/upcoming')
            ->assertOk()
            ->assertExactJson(['data' => ['version' => '0.0.0559', 'percent' => 58]]);

        config(['ezbizi.release_status_file' => $this->file.'-missing']);
        $this->signedInAs($alpha['admin'])->getJson('/api/v1/release/upcoming')
            ->assertOk()->assertExactJson(['data' => null]);
    }

    private function staffToken(): string
    {
        $totp = app(Google2FA::class);
        $secret = $totp->generateSecretKey(32);

        $staff = PlatformStaff::create([
            'name' => 'Jose', 'email' => 'staff@ezbizi.test',
            'password' => 'a-real-staff-password',
            'tier' => PlatformStaff::TIER_DEV, 'is_active' => true,
        ]);
        $staff->forceFill(['totp_secret' => $secret, 'totp_confirmed_at' => now()])->save();

        $token = $this->postJson('/api/v1/staff/auth/login', [
            'email' => 'staff@ezbizi.test', 'password' => 'a-real-staff-password',
            'code' => $totp->getCurrentOtp($secret),
        ])->assertOk()->json('token');

        $this->app['auth']->forgetGuards();

        return $token;
    }
}
