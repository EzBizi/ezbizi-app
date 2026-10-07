<?php

namespace Tests\Feature\Api;

use App\Models\Notification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Communication ▸ Alerts. Jose, 2026-10-07: "I'd like to be able to send a
 * notificiations to specific users, like an alert system ... a pop up on the
 * screen, that they can dismiss".
 */
class AlertsTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private function alertsFor(array $tenant, User $user): array
    {
        return $this->inTenant($tenant['org'], fn () => Notification::query()
            ->where('user_id', $user->id)->where('source', 'alert')
            ->get(['title', 'body'])->map(fn ($n) => [$n->title, $n->body])->all());
    }

    public function test_an_alert_reaches_exactly_the_people_picked(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $sender = $this->userWithRole($tenant, 'Sender', ['phone.alerts.view']);
        $eli = $this->userWithRole($tenant, 'Eli');
        $bystander = $this->userWithRole($tenant, 'Bystander');

        $this->signedInAs($sender)
            ->postJson('/api/v1/alerts', ['user_ids' => [$eli->id], 'message' => 'Jose Will Remember This...'])
            ->assertCreated()
            ->assertJsonPath('data.told', 1);

        $this->assertSame([['Jose Will Remember This...', "From {$sender->name}"]], $this->alertsFor($tenant, $eli));
        // The control: somebody not picked hears nothing, so this cannot pass
        // by alerting the whole organization.
        $this->assertSame([], $this->alertsFor($tenant, $bystander));
    }

    public function test_sending_needs_the_alerts_tab_permission(): void
    {
        $tenant = $this->provisionTenant('Beta');
        $noTab = $this->userWithRole($tenant, 'Phones only', ['phone.view']);
        $eli = $this->userWithRole($tenant, 'Eli');

        $this->signedInAs($noTab)
            ->postJson('/api/v1/alerts', ['user_ids' => [$eli->id], 'message' => 'Hi'])
            ->assertForbidden();
        $this->signedInAs($noTab)->getJson('/api/v1/alerts/recipients')->assertForbidden();

        $this->assertSame([], $this->alertsFor($tenant, $eli));
    }

    public function test_one_person_from_another_organization_refuses_the_whole_send(): void
    {
        $alpha = $this->provisionTenant('Gamma');
        $other = $this->provisionTenant('Delta', 'owner@delta.test');
        $sender = $this->userWithRole($alpha, 'Sender', ['phone.alerts.view']);
        $eli = $this->userWithRole($alpha, 'Eli');

        $this->signedInAs($sender)
            ->postJson('/api/v1/alerts', ['user_ids' => [$eli->id, $other['admin']->id], 'message' => 'Hi'])
            ->assertUnprocessable();

        // All or nothing: "sent" must mean sent to everybody picked.
        $this->assertSame([], $this->alertsFor($alpha, $eli));
        $this->assertSame([], $this->alertsFor($other, $other['admin']));
    }

    public function test_the_sender_sees_one_alert_and_who_has_dismissed_it(): void
    {
        $tenant = $this->provisionTenant('Eta');
        $sender = $this->userWithRole($tenant, 'Sender', ['phone.alerts.view']);
        $eli = $this->userWithRole($tenant, 'Eli');
        $ana = $this->userWithRole($tenant, 'Ana');

        $this->signedInAs($sender)
            ->postJson('/api/v1/alerts', ['user_ids' => [$eli->id, $ana->id], 'message' => 'Fire drill at 3'])
            ->assertCreated();

        // Eli dismisses; Ana has not.
        $row = $this->inTenant($tenant['org'], fn () => Notification::where('user_id', $eli->id)->where('source', 'alert')->firstOrFail());
        $this->signedInAs($eli)->postJson("/api/v1/notifications/{$row->id}/read")->assertOk();

        $sent = $this->signedInAs($sender)->getJson('/api/v1/alerts')->assertOk()->json('data');

        $this->assertCount(1, $sent, 'Two recipients, one alert.');
        $this->assertSame('Fire drill at 3', $sent[0]['message']);
        $dismissed = collect($sent[0]['recipients'])->mapWithKeys(fn ($r) => [$r['id'] => $r['dismissed_at'] !== null]);
        $this->assertCount(2, $dismissed);
        $this->assertTrue($dismissed[$eli->id]);
        $this->assertFalse($dismissed[$ana->id]);
    }

    public function test_an_open_alert_rides_the_bell_poll_even_behind_twenty_newer_notifications(): void
    {
        $tenant = $this->provisionTenant('Theta');
        $sender = $this->userWithRole($tenant, 'Sender', ['phone.alerts.view']);
        $eli = $this->userWithRole($tenant, 'Eli');

        $this->signedInAs($sender)
            ->postJson('/api/v1/alerts', ['user_ids' => [$eli->id], 'message' => 'Jose Will Remember This...'])
            ->assertCreated();

        // Twenty-five ordinary notifications arrive after it, pushing it off
        // the newest-20 page the bell asks for.
        $this->inTenant($tenant['org'], function () use ($tenant, $eli): void {
            for ($i = 0; $i < 25; $i++) {
                Notification::create([
                    'organization_id' => $tenant['org']->id, 'user_id' => $eli->id,
                    'source' => 'system', 'title' => "Later {$i}", 'created_at' => now()->addMinutes($i + 1),
                ]);
            }
        });

        $poll = $this->signedInAs($eli)->getJson('/api/v1/notifications?limit=20')->assertOk();

        $this->assertNotContains('Jose Will Remember This...', collect($poll->json('data'))->pluck('title'),
            'Precondition: the alert is off the page, which is the case being guarded.');
        $alerts = $poll->json('meta.alerts');
        $this->assertCount(1, $alerts);
        $this->assertSame('Jose Will Remember This...', $alerts[0]['title']);
        $this->assertSame($sender->name, $alerts[0]['sender']);

        // Dismissed, it stops riding the poll.
        $this->signedInAs($eli)->postJson("/api/v1/notifications/{$alerts[0]['id']}/read")->assertOk();
        $this->assertSame([], $this->signedInAs($eli)->getJson('/api/v1/notifications?limit=20')->json('meta.alerts'));
    }

    public function test_the_picker_lists_this_organizations_active_people_only(): void
    {
        $tenant = $this->provisionTenant('Epsilon');
        $other = $this->provisionTenant('Zeta', 'owner@zeta.test');
        $sender = $this->userWithRole($tenant, 'Sender', ['phone.alerts.view']);

        $names = collect($this->signedInAs($sender)->getJson('/api/v1/alerts/recipients')
            ->assertOk()->json('data'))->pluck('id');

        $this->assertContains($sender->id, $names);
        $this->assertNotContains($other['admin']->id, $names);
    }
}
