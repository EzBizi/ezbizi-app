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

    // Questions (Jose 11-18): ask with context and choices, answer, read back.
    public function test_a_question_is_answered_once_by_the_person_asked_and_read_by_the_asker(): void
    {
        $tenant = $this->provisionTenant('Gamma');
        $jose = $this->userWithRole($tenant, 'Asker', ['phone.alerts.view']);
        $bar = $this->userWithRole($tenant, 'Bar');
        $other = $this->userWithRole($tenant, 'Other');

        $id = $this->signedInAs($jose)->postJson('/api/v1/alerts', [
            'user_ids' => [$bar->id], 'kind' => 'question', 'message' => 'Which job source for the PPC jobs?',
            'context' => 'EverDoors / A1 Garage Doors PPC', 'choices' => ['(a) new PPC source', '(b) existing source'],
            'allow_text' => true,
        ])->assertCreated()->json('data.id');

        // The pop-up carries the question.
        $this->signedInAs($bar)->getJson('/api/v1/notifications')->assertOk()
            ->assertJsonFragment(['alert_id' => $id, 'choices' => ['(a) new PPC source', '(b) existing source']]);
        $this->signedInAs($bar)->getJson('/api/v1/alerts/questions')
            ->assertJsonPath('meta.unanswered', 1)->assertJsonPath('data.0.context', 'EverDoors / A1 Garage Doors PPC');

        // Only the person asked may answer; a choice must be one offered.
        $this->signedInAs($other)->postJson("/api/v1/alerts/{$id}/reply", ['choice' => '(a) new PPC source'])->assertNotFound();
        $this->signedInAs($bar)->postJson("/api/v1/alerts/{$id}/reply", ['choice' => '(c) made up'])->assertUnprocessable();

        $this->signedInAs($bar)->postJson("/api/v1/alerts/{$id}/reply", ['choice' => '(b) existing source', 'text' => 'Use Google PPC'])
            ->assertCreated();
        $this->signedInAs($bar)->postJson("/api/v1/alerts/{$id}/reply", ['choice' => '(a) new PPC source'])->assertStatus(409);

        $this->signedInAs($bar)->getJson('/api/v1/alerts/questions')->assertJsonPath('meta.unanswered', 0);
        $this->signedInAs($jose)->getJson("/api/v1/alerts/{$id}/replies")->assertOk()
            ->assertJsonPath('data.replies.0.name', $bar->name)
            ->assertJsonPath('data.replies.0.choice', '(b) existing source')
            ->assertJsonPath('data.replies.0.text', 'Use Google PPC');
        $this->signedInAs($other)->getJson("/api/v1/alerts/{$id}/replies")->assertNotFound();

        // The asker's bell says who answered what; answering closed Bar's pop-up.
        $this->assertContains("{$bar->name} answered: (b) existing source — Use Google PPC",
            $this->inTenant($tenant['org'], fn () => Notification::query()->where('user_id', $jose->id)->pluck('title')->all()));
        $this->assertSame(0, $this->inTenant($tenant['org'], fn () => Notification::query()
            ->where('user_id', $bar->id)->where('source', 'alert')->whereNull('read_at')->count()));
    }

    public function test_a_question_with_no_way_to_answer_is_refused(): void
    {
        $tenant = $this->provisionTenant('Delta');
        $jose = $this->userWithRole($tenant, 'Asker', ['phone.alerts.view']);
        $bar = $this->userWithRole($tenant, 'Bar');

        $this->signedInAs($jose)->postJson('/api/v1/alerts', ['user_ids' => [$bar->id], 'kind' => 'question', 'message' => '?'])
            ->assertUnprocessable();
    }
}
