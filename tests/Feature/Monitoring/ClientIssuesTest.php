<?php

namespace Tests\Feature\Monitoring;

use App\Console\Commands\CheckClientIssueSpikes;
use App\Models\ClientIssue;
use App\Models\PlatformStaff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PragmaRX\Google2FA\Google2FA;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * The apps reporting their own errors, crashes, freezes and failed calls
 * (Jose, 2026-10-15: "We should have the app log crashes and issues as well").
 */
class ClientIssuesTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    private function report(array $issues, array $headers = [])
    {
        return $this->withHeaders($headers + [
            'X-EzBizi-Client-Platform' => 'windows',
            'X-EzBizi-Client-Version' => '0.0.0425',
        ])->postJson('/api/v1/client-issues', ['issues' => $issues]);
    }

    public function test_a_signed_out_app_can_report_and_the_build_comes_from_the_headers(): void
    {
        // A crash on the sign-in screen is still a crash.
        $this->report([[
            'kind' => 'dart_error',
            'message' => 'Null check operator used on a null value',
            'detail' => '#0 LoginScreen.build (package:ezbizi_client/screens/login_screen.dart:120:5)',
        ]])->assertNoContent();

        $row = ClientIssue::query()->sole();
        $this->assertNull($row->user_id);
        $this->assertSame('windows', $row->platform);
        $this->assertSame('0.0.0425', $row->app_version);
        $this->assertSame('dart_error', $row->kind);
    }

    public function test_a_signed_in_report_names_the_person_organization_and_module(): void
    {
        $alpha = $this->provisionTenant('Alpha');

        $this->signedInAs($alpha['admin']);
        $this->report([[
            'kind' => 'freeze',
            'message' => 'UI thread did not answer for 4200 ms',
            'module' => 'scheduling',
        ]])->assertNoContent();

        $row = ClientIssue::query()->sole();
        $this->assertSame($alpha['admin']->getKey(), $row->user_id);
        $this->assertSame($alpha['org']->getKey(), $row->organization_id);
        $this->assertSame('scheduling', $row->module);
    }

    public function test_tokens_addresses_numbers_and_query_strings_are_never_stored(): void
    {
        $this->report([[
            'kind' => 'api_failure',
            'message' => 'GET /customers?search=jane failed for jane.doe@example.com, call +1 (571) 549-9969, '
                .'Authorization: Bearer 1|abcdEFGHijklMNOPqrstUVWXyz0123456789abcd',
        ]])->assertNoContent();

        $message = ClientIssue::query()->sole()->message;
        $this->assertStringNotContainsString('jane.doe@example.com', $message);
        $this->assertStringNotContainsString('549-9969', $message);
        $this->assertStringNotContainsString('search=jane', $message);
        $this->assertStringNotContainsString('abcdEFGHijkl', $message);
        $this->assertStringContainsString('GET /customers', $message, 'What failed where is kept.');
    }

    public function test_the_same_problem_folds_into_one_row_per_version(): void
    {
        $issue = [
            'kind' => 'dart_error',
            'message' => 'RangeError (index): Invalid value: 7',
            'detail' => '#0 JobsList.build (package:ezbizi_client/screens/jobs.dart:88:3)',
        ];

        $this->report([$issue]);
        // Only the number differs: the same problem.
        $this->report([['message' => 'RangeError (index): Invalid value: 9'] + $issue]);
        $this->report([$issue], ['X-EzBizi-Client-Version' => '0.0.0426']);

        $this->assertSame(2, ClientIssue::query()->count(), 'One row per version.');
        $this->assertSame(2, ClientIssue::query()->where('app_version', '0.0.0425')->sole()->count);
    }

    public function test_a_broken_loop_cannot_flood_the_table(): void
    {
        for ($batch = 0; $batch < 8; $batch++) {
            $issues = [];
            for ($i = 0; $i < 20; $i++) {
                $issues[] = [
                    'kind' => 'dart_error',
                    'message' => "loop error {$batch}-{$i}",
                    'detail' => "#0 frame{$batch}x{$i} (package:x/y.dart:1:1)",
                ];
            }
            // Still 204 past the limit: reporting never fails the app.
            $this->report($issues)->assertNoContent();
        }

        $this->assertLessThanOrEqual(120, ClientIssue::query()->count());
    }

    public function test_an_unknown_kind_is_refused(): void
    {
        $this->report([['kind' => 'gossip', 'message' => 'x']])->assertStatus(422);
    }

    public function test_staff_see_problems_grouped_and_customers_cannot(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $issue = [
            'kind' => 'dart_error',
            'message' => 'Bad state: No element',
            'detail' => '#0 Iterable.first (dart:core)',
            'module' => 'jobs',
        ];
        $this->report([$issue]);
        $this->report([$issue], ['X-EzBizi-Client-Version' => '0.0.0426']);

        $this->signedInAs($alpha['admin'])
            ->getJson('/api/v1/staff/health/client-issues')
            ->assertForbidden();
        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', 'Bearer '.$this->staffToken())
            ->getJson('/api/v1/staff/health/client-issues')
            ->assertOk()
            ->assertJsonCount(1, 'problems')
            ->assertJsonPath('problems.0.total', 2)
            ->assertJsonPath('problems.0.modules.0', 'jobs');
    }

    public function test_a_version_that_suddenly_reports_far_more_is_a_spike(): void
    {
        $make = fn (string $version, int $count, $at) => ClientIssue::create([
            'kind' => 'freeze', 'fingerprint' => sha1($version.$at), 'message' => 'freeze',
            'platform' => 'windows', 'app_version' => $version, 'count' => $count,
            'first_seen_at' => $at, 'last_seen_at' => $at,
        ]);

        // 0.0.0425: about 2 an hour yesterday, 40 in the last hour.
        $make('0.0.0425', 48, now()->subHours(12));
        $make('0.0.0425', 40, now()->subMinutes(20));
        // 0.0.0424: steady at about 30 an hour, 30 again now: busy, not a spike.
        $make('0.0.0424', 720, now()->subHours(12));
        $make('0.0.0424', 30, now()->subMinutes(20));

        $spikes = CheckClientIssueSpikes::spikes();

        $this->assertCount(1, $spikes);
        $this->assertSame('0.0.0425', $spikes[0]['version']);
    }

    private function staffToken(): string
    {
        $totp = app(Google2FA::class);
        $secret = $totp->generateSecretKey(32);

        $staff = PlatformStaff::create([
            'name' => 'Jose', 'email' => 'staff@ezbizi.test',
            'password' => 'a-real-staff-password',
            'tier' => PlatformStaff::TIER_LEAD, 'is_active' => true,
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
