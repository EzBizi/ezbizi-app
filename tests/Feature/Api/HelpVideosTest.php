<?php

namespace Tests\Feature\Api;

use App\Models\AuditEvent;
use App\Models\PlatformHelpVideo;
use App\Models\PlatformStaff;
use App\Services\Organizations\ProvisionOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/** UI queue 81: each tab's how-to video, and the walkthroughs a person has seen. */
class HelpVideosTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(ProvisionOrganization::class)(
            organizationName: 'Alpha',
            administrator: ['name' => 'Admin', 'email' => 'a@alpha.test', 'password' => 'a-real-password'],
            companyName: 'Co', franchiseName: 'F1',
        );
    }

    private function asTenant(): static
    {
        $token = $this->postJson('/api/v1/auth/login', [
            'email' => 'a@alpha.test', 'password' => 'a-real-password',
        ])->assertOk()->json('token');

        $this->app['auth']->forgetGuards();

        return $this->withHeader('Authorization', "Bearer {$token}");
    }

    private function asStaff(): static
    {
        $staff = PlatformStaff::query()->where('email', 'lead@ezbizi.test')->first();
        if ($staff === null) {
            $staff = PlatformStaff::create([
                'name' => 'Platform Staff', 'email' => 'lead@ezbizi.test',
                'password' => 'a-sufficiently-long-password',
                'tier' => PlatformStaff::TIER_LEAD, 'is_active' => true,
            ]);
            $staff->totp_secret = app(Google2FA::class)->generateSecretKey(32);
            $staff->totp_confirmed_at = now();
            $staff->save();
        }

        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($staff);

        return $this;
    }

    public function test_a_video_staff_save_reaches_everybody_keyed_by_its_tab(): void
    {
        $this->asTenant()->getJson('/api/v1/help/videos')->assertOk()->assertExactJson(['data' => []]);

        $this->asStaff()->putJson('/api/v1/staff/help-videos/scheduling', [
            'url' => 'https://ezbizi.com/help/scheduling-v1.mp4',
            'title' => 'How Scheduling works',
            'duration_seconds' => 160,
            'chapters' => [['at' => 42.7, 'label' => 'Booking a job'], ['at' => 0, 'label' => 'The week']],
        ])->assertOk();

        $this->asTenant()->getJson('/api/v1/help/videos')
            ->assertOk()
            ->assertJsonPath('data.scheduling.url', 'https://ezbizi.com/help/scheduling-v1.mp4')
            ->assertJsonPath('data.scheduling.duration_seconds', 160)
            // In time order, whatever order they were typed in.
            ->assertJsonPath('data.scheduling.chapters.0.label', 'The week')
            ->assertJsonPath('data.scheduling.chapters.1.at', 42);

        $this->assertSame(1, AuditEvent::query()->where('action', 'staff.help_video_changed')->count());
    }

    public function test_staff_see_every_module_and_which_have_none(): void
    {
        PlatformHelpVideo::create(['module_key' => 'scheduling', 'url' => 'https://ezbizi.com/a.mp4']);

        $rows = collect($this->asStaff()->getJson('/api/v1/staff/help-videos')->assertOk()->json('data'))
            ->keyBy('module_key');

        $this->assertSame('https://ezbizi.com/a.mp4', $rows['scheduling']['video']['url']);
        $this->assertNull($rows['tasks']['video']);
    }

    public function test_a_plain_http_link_or_an_unknown_tab_is_refused(): void
    {
        $this->asStaff()->putJson('/api/v1/staff/help-videos/scheduling', ['url' => 'http://ezbizi.com/a.mp4'])
            ->assertStatus(422)->assertJsonValidationErrors('url');

        $this->asStaff()->putJson('/api/v1/staff/help-videos/not_a_module', ['url' => 'https://ezbizi.com/a.mp4'])
            ->assertStatus(422)->assertJsonValidationErrors('module');
    }

    public function test_removing_a_video_takes_the_button_away(): void
    {
        PlatformHelpVideo::create(['module_key' => 'scheduling', 'url' => 'https://ezbizi.com/a.mp4']);

        $this->asStaff()->deleteJson('/api/v1/staff/help-videos/scheduling')->assertNoContent();

        $this->assertSame(0, PlatformHelpVideo::query()->count());
    }

    public function test_a_tenant_cannot_set_a_video(): void
    {
        $this->asTenant()->putJson('/api/v1/staff/help-videos/scheduling', ['url' => 'https://ezbizi.com/a.mp4'])
            ->assertForbidden();

        $this->assertSame(0, PlatformHelpVideo::query()->count());
    }

    public function test_walkthroughs_seen_keep_module_versions_and_drop_the_rest(): void
    {
        $response = $this->asTenant()->patchJson('/api/v1/me/preferences', [
            'preferences' => ['help.tours_seen' => [
                'tasks' => 1, 'scheduling' => 3, 'Bad Key' => 2, 'leads' => 'two', 'jobs' => -1,
            ]],
        ])->assertOk();

        // The key has a dot in it, so it is read by hand rather than by path.
        $this->assertSame(['tasks' => 1, 'scheduling' => 3], $response->json('data')['help.tours_seen']);
    }
}
