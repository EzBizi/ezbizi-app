<?php

namespace Tests\Feature\Integrations;

use App\Models\Automation;
use App\Models\AutomationVersion;
use App\Models\GoogleWorkspaceConnection;
use App\Models\PhoneConnection;
use App\Models\PhoneNumber;
use App\Models\SocialConnection;
use App\Models\SocialPost;
use App\Models\SocialPostTarget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsTenants;
use Tests\Concerns\GivesAuthenticatorCodes;
use Tests\TestCase;

/**
 * Jose, 2026-10-21: "disconnecting an integration should require an
 * authenticator code, and also if possible list the amount of services -
 * automations - etc that will stop working".
 */
class DisconnectRequiresAuthenticatorTest extends TestCase
{
    use BuildsTenants;
    use GivesAuthenticatorCodes;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake(['*' => Http::response([], 200)]);
    }

    // ── the code ──────────────────────────────────────────────────

    public function test_a_social_disconnect_without_an_authenticator_is_refused_with_the_way_out(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $row = $this->social($t, 'workiz');

        $this->signedInAs($t['admin'])
            ->deleteJson('/api/v1/social/connections/'.$row->id)
            ->assertStatus(422)
            ->assertJsonPath('code', 'no_authenticator')
            ->assertJsonPath('message', fn (string $m) => str_contains($m, 'Set up an authenticator'));

        $this->assertTrue($this->exists(SocialConnection::class, $t, $row->id));
    }

    public function test_a_social_disconnect_without_a_code_or_with_a_wrong_one_is_refused(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $row = $this->social($t, 'airtable');
        $this->authenticatorCode($t['admin']);

        $this->signedInAs($t['admin'])
            ->deleteJson('/api/v1/social/connections/'.$row->id)
            ->assertStatus(422)->assertJsonPath('code', 'authenticator_code_required');

        $this->signedInAs($t['admin'])
            ->deleteJson('/api/v1/social/connections/'.$row->id, ['code' => 'ABCD-EFGH'])
            ->assertStatus(422)->assertJsonPath('code', 'authenticator_code_required');

        $this->signedInAs($t['admin'])
            ->deleteJson('/api/v1/social/connections/'.$row->id, ['code' => $this->wrongCode()])
            ->assertStatus(422)->assertJsonPath('code', 'authenticator_code_invalid');

        $this->assertTrue($this->exists(SocialConnection::class, $t, $row->id));
    }

    public function test_a_valid_code_disconnects_every_social_provider(): void
    {
        $t = $this->provisionTenant('A1-Group');

        foreach (['airtable', 'ringcentral', 'workiz', 'thumbtack', 'mailchimp', 'bluesky', 'google_ads', 'google_business_profile'] as $i => $provider) {
            $row = $this->social($t, $provider, 'r-'.$i);

            $code = $this->authenticatorCode($t['admin']);
            $this->signedInAs($t['admin'])
                ->deleteJson('/api/v1/social/connections/'.$row->id, $code)
                ->assertOk();

            $this->assertFalse($this->exists(SocialConnection::class, $t, $row->id), $provider);
        }
    }

    public function test_disconnecting_a_whole_provider_needs_the_code(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $this->social($t, 'thumbtack');
        $this->authenticatorCode($t['admin']);

        $this->signedInAs($t['admin'])
            ->deleteJson('/api/v1/social/providers/thumbtack/connections')
            ->assertStatus(422)->assertJsonPath('code', 'authenticator_code_required');

        $code = $this->authenticatorCode($t['admin']);
        $this->signedInAs($t['admin'])
            ->deleteJson('/api/v1/social/providers/thumbtack/connections', $code)
            ->assertOk()->assertJsonPath('data.disconnected', 1);
    }

    public function test_a_phone_connection_needs_the_code(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $phone = $this->phone($t);
        $this->authenticatorCode($t['admin']);

        $this->signedInAs($t['admin'])
            ->deleteJson('/api/v1/phone/connections/'.$phone->id)
            ->assertStatus(422)->assertJsonPath('code', 'authenticator_code_required');
        $this->assertTrue($this->exists(PhoneConnection::class, $t, $phone->id));

        $code = $this->authenticatorCode($t['admin']);
        $this->signedInAs($t['admin'])
            ->deleteJson('/api/v1/phone/connections/'.$phone->id, $code)
            ->assertOk();
        $this->assertFalse($this->exists(PhoneConnection::class, $t, $phone->id));
    }

    public function test_the_google_workspace_needs_the_code(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $ws = $this->workspace($t);
        $scope = ['scope_type' => 'organization', 'scope_id' => $t['org']->id];
        $this->authenticatorCode($t['admin']);

        $this->signedInAs($t['admin'])
            ->deleteJson('/api/v1/email/workspace', $scope)
            ->assertStatus(422)->assertJsonPath('code', 'authenticator_code_required');
        $this->assertTrue($this->exists(GoogleWorkspaceConnection::class, $t, $ws->id));

        $code = $this->authenticatorCode($t['admin']);
        $this->signedInAs($t['admin'])
            ->deleteJson('/api/v1/email/workspace', $scope + $code)
            ->assertOk();
        $this->assertFalse($this->exists(GoogleWorkspaceConnection::class, $t, $ws->id));
    }

    // ── what stops working ────────────────────────────────────────

    public function test_dependents_count_automations_and_scheduled_posts_on_the_connection(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $workiz = $this->social($t, 'workiz');

        $this->automation($t, 'Push to Workiz', 'job.completed', [['type' => 'workiz.job_update']]);
        $this->automation($t, 'Names it', 'lead.received', [['type' => 'notify', 'connection_id' => $workiz->id]]);
        $this->automation($t, 'Unrelated', 'lead.received', [['type' => 'notify']]);
        $this->automation($t, 'Switched off', 'job.completed', [['type' => 'workiz.job_update']], active: false);

        $this->scheduledPost($t, $workiz, SocialPostTarget::STATUS_SCHEDULED);
        $this->scheduledPost($t, $workiz, SocialPostTarget::STATUS_QUEUED);
        $this->scheduledPost($t, $workiz, SocialPostTarget::STATUS_SENT);

        $rows = $this->signedInAs($t['admin'])
            ->getJson('/api/v1/integrations/social/'.$workiz->id.'/dependents')
            ->assertOk()
            ->json('data.dependents');

        $by = collect($rows)->keyBy('key');
        $this->assertSame(2, $by['automations']['count']);
        $this->assertSame(['Names it', 'Push to Workiz'], $by['automations']['names']);
        $this->assertSame(2, $by['scheduled_posts']['count']);
        $this->assertSame('2 scheduled posts will not publish', $by['scheduled_posts']['label']);
    }

    public function test_a_second_account_of_the_provider_keeps_provider_wide_rules_off_the_list(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $one = $this->social($t, 'workiz', 'w-1');
        $this->social($t, 'workiz', 'w-2');
        $this->automation($t, 'Push to Workiz', 'job.completed', [['type' => 'workiz.job_update']]);

        $rows = $this->signedInAs($t['admin'])
            ->getJson('/api/v1/integrations/social/'.$one->id.'/dependents')
            ->assertOk()->json('data.dependents');

        $this->assertSame([], $rows);
    }

    public function test_dependents_names_are_hidden_without_automations_view_but_counted(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $row = $this->social($t, 'mailchimp');
        $this->automation($t, 'Add to list', 'lead.received', [['type' => 'add_to_audience']]);
        $viewer = $this->userWithRole($t, 'Marketer', ['marketing.view', 'marketing.edit']);

        $rows = $this->signedInAs($viewer)
            ->getJson('/api/v1/integrations/social/'.$row->id.'/dependents')
            ->assertOk()->json('data.dependents');

        $this->assertSame(1, $rows[0]['count']);
        $this->assertSame([], $rows[0]['names']);
    }

    public function test_phone_dependents_count_its_numbers(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $phone = $this->phone($t);
        $this->inTenant($t['org'], fn () => PhoneNumber::factory()->count(2)->create([
            'organization_id' => $t['org']->id, 'connection_id' => $phone->id,
        ]));
        $this->automation($t, 'Missed call text', 'phone.call.missed', [['type' => 'sms.send']]);

        $by = collect($this->signedInAs($t['admin'])
            ->getJson('/api/v1/integrations/phone/'.$phone->id.'/dependents')
            ->assertOk()->json('data.dependents'))->keyBy('key');

        $this->assertSame(2, $by['phone_numbers']['count']);
        $this->assertSame(1, $by['automations']['count']);
    }

    public function test_workspace_dependents_are_addressed_by_scope(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $this->workspace($t);
        $this->automation($t, 'Inbox alert', 'email.received', [['type' => 'notify']]);

        $by = collect($this->signedInAs($t['admin'])
            ->getJson('/api/v1/integrations/workspace/organization:'.$t['org']->id.'/dependents')
            ->assertOk()->json('data.dependents'))->keyBy('key');

        $this->assertSame(1, $by['automations']['count']);
    }

    public function test_another_organizations_connection_is_a_404_and_its_rules_are_not_counted(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $beta = $this->provisionTenant('Beta');
        $mine = $this->social($alpha, 'workiz');
        $theirs = $this->social($beta, 'workiz', 'beta-1');
        $this->automation($beta, 'Beta pushes', 'job.completed', [['type' => 'workiz.job_update', 'connection_id' => $mine->id]]);

        $this->signedInAs($alpha['admin'])
            ->getJson('/api/v1/integrations/social/'.$theirs->id.'/dependents')
            ->assertNotFound();

        $this->signedInAs($alpha['admin'])
            ->getJson('/api/v1/integrations/social/'.$mine->id.'/dependents')
            ->assertOk()->assertJsonPath('data.dependents', []);

        $code = $this->authenticatorCode($alpha['admin']);
        $this->signedInAs($alpha['admin'])
            ->deleteJson('/api/v1/social/connections/'.$theirs->id, $code)
            ->assertNotFound();
    }

    // ── helpers ───────────────────────────────────────────────────

    private function wrongCode(): string
    {
        return '000001';
    }

    private function exists(string $model, array $t, string $id): bool
    {
        return $this->inTenant($t['org'], fn () => $model::query()->whereKey($id)->exists());
    }

    private function social(array $t, string $provider, string $remote = 'r-1'): SocialConnection
    {
        return $this->inTenant($t['org'], function () use ($t, $provider, $remote) {
            $row = new SocialConnection;
            $row->forceFill([
                'organization_id' => $t['org']->id,
                'scope_type' => 'organization',
                'scope_id' => $t['org']->id,
                'provider' => $provider,
                'remote_id' => $remote,
                'display_name' => ucfirst($provider).' '.$remote,
            ]);
            $row->connect(['access_token' => 'token-'.$remote]);
            $row->save();

            return $row;
        });
    }

    private function phone(array $t): PhoneConnection
    {
        return $this->inTenant($t['org'], fn () => PhoneConnection::factory()->create([
            'organization_id' => $t['org']->id,
            'scope_type' => PhoneConnection::SCOPE_ORGANIZATION,
            'scope_id' => $t['org']->id,
        ]));
    }

    private function workspace(array $t): GoogleWorkspaceConnection
    {
        return $this->inTenant($t['org'], function () use ($t) {
            $row = new GoogleWorkspaceConnection;
            $row->forceFill([
                'organization_id' => $t['org']->id,
                'scope_type' => 'organization',
                'scope_id' => $t['org']->id,
                'domain' => 'a-1chimney.test',
                'admin_email' => 'admin@a-1chimney.test',
                'status' => GoogleWorkspaceConnection::STATUS_CONNECTED,
            ])->save();

            return $row;
        });
    }

    private function automation(array $t, string $name, string $trigger, array $actions, bool $active = true): Automation
    {
        return $this->inTenant($t['org'], function () use ($t, $name, $trigger, $actions, $active) {
            $rule = Automation::create([
                'organization_id' => $t['org']->id,
                'name' => $name,
                'trigger_key' => $trigger,
                'is_active' => $active,
            ]);
            $version = AutomationVersion::create([
                'organization_id' => $t['org']->id,
                'automation_id' => $rule->id,
                'version' => 1,
                'conditions' => null,
                'actions' => $actions,
                'created_at' => now(),
            ]);
            $rule->forceFill(['active_version_id' => $version->id])->save();

            return $rule;
        });
    }

    private function scheduledPost(array $t, SocialConnection $on, string $status): void
    {
        $this->inTenant($t['org'], function () use ($t, $on, $status) {
            $post = SocialPost::create([
                'body' => 'Autumn sweeps.',
                'created_by' => $t['admin']->id,
                'scheduled_for' => now()->addDay(),
            ]);
            SocialPostTarget::create([
                'social_post_id' => $post->id,
                'social_connection_id' => $on->id,
                'status' => $status,
            ]);
        });
    }
}
