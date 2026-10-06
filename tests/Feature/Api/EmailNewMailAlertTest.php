<?php

namespace Tests\Feature\Api;

use App\Models\Automation;
use App\Models\AutomationVersion;
use App\Models\Mailbox;
use App\Models\Role;
use App\Support\Automations\MailboxAlerts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * "Notify about new emails", switched per inbox from Email settings.
 *
 * Jose, 2026-10-06: "if possible build also a link on email settings, so it
 * can be changed from there as well like syncing with the automation".
 *
 * These drive Automations' real MailboxAlerts, not a stand-in: the whole
 * promise is that the switch and the rule are ONE edit, and a mock would only
 * prove the endpoint calls something.
 */
class EmailNewMailAlertTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('ezbizi:sync-permissions')->assertSuccessful();
    }

    private function inbox(array $t, string $address): Mailbox
    {
        return $this->inTenant($t['org'], fn () => Mailbox::create([
            'organization_id' => $t['org']->id,
            'scope_type'      => Mailbox::SCOPE_ORGANIZATION,
            'scope_id'        => $t['org']->id,
            'address'         => $address,
            'display_name'    => 'Inbox',
            'provider'        => 'gmail_workspace',
        ]));
    }

    /** The one active new-lead notify rule MailboxAlerts copies who is told from. */
    private function newLeadRule(array $t): void
    {
        $this->inTenant($t['org'], function () use ($t) {
            $role = Role::query()->firstOrFail();
            $rule = Automation::create([
                'organization_id' => $t['org']->id, 'name' => 'New lead', 'trigger_key' => 'lead.received',
                'is_active' => true, 'created_by' => $t['admin']->id,
            ]);
            $version = AutomationVersion::create([
                'organization_id' => $t['org']->id, 'automation_id' => $rule->id, 'version' => 1,
                'conditions' => ['all' => []],
                'actions' => [['type' => 'notify', 'message' => 'New lead', 'role_ids' => [$role->id]]],
                'created_by' => $t['admin']->id,
            ]);
            $rule->forceFill(['active_version_id' => $version->id])->save();
        });
    }

    private function alerts(array $t, $as = null): array
    {
        return $this->signedInAs($as ?? $t['admin'])
            ->getJson('/api/v1/email/mailboxes/new-mail-alerts')->assertOk()->json('data');
    }

    public function test_switching_an_inbox_on_and_off_edits_the_rule(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $this->newLeadRule($t);
        $info = $this->inbox($t, 'info@a-1chimney.com');

        $this->signedInAs($t['admin'])
            ->putJson("/api/v1/email/mailboxes/{$info->id}/new-mail-alert", ['on' => true])
            ->assertOk()
            ->assertJsonPath('data.addresses', ['info@a-1chimney.com']);

        // The rule really exists and really carries the address and the bulk filter.
        $conditions = $this->inTenant($t['org'], fn () => Automation::query()
            ->where('trigger_key', 'email.received')->firstOrFail()->activeVersion->conditions);
        // MySQL's JSON column returns keys sorted, so compare each clause key-order-free.
        $this->assertTrue(collect($conditions['all'])->contains(
            fn (array $c) => $c == ['field' => 'is_bulk', 'op' => 'eq', 'value' => false],
        ), 'The rule does not skip bulk mail: '.json_encode($conditions));

        $this->signedInAs($t['admin'])
            ->putJson("/api/v1/email/mailboxes/{$info->id}/new-mail-alert", ['on' => false])
            ->assertOk()
            ->assertJsonPath('data.addresses', []);
    }

    /**
     * ONE edit, two places: a change made on the Automations side shows on
     * this screen, because both read the same rule and nothing is copied.
     */
    public function test_a_change_made_in_automations_shows_here(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $this->newLeadRule($t);
        $info = $this->inbox($t, 'info@a-1chimney.com');
        $this->inbox($t, 'office@a-1chimney.com');

        $this->signedInAs($t['admin'])
            ->putJson("/api/v1/email/mailboxes/{$info->id}/new-mail-alert", ['on' => true])->assertOk();

        $this->inTenant($t['org'], fn () => app(MailboxAlerts::class)->set('office@a-1chimney.com', true, $t['admin']));

        $this->assertEqualsCanonicalizing(
            ['info@a-1chimney.com', 'office@a-1chimney.com'],
            $this->alerts($t)['addresses'],
        );
    }

    /** It edits an automation, so it takes the automation permission too. */
    public function test_switching_needs_permission_to_change_automations(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $this->newLeadRule($t);
        $info = $this->inbox($t, 'info@a-1chimney.com');

        $manager = $this->userWithRole($t, 'Email manager', ['email.view', 'email.settings.view', 'email.edit']);

        $this->assertFalse($this->alerts($t, $manager)['may_change']);

        $this->signedInAs($manager)
            ->putJson("/api/v1/email/mailboxes/{$info->id}/new-mail-alert", ['on' => true])
            ->assertForbidden();

        $this->assertSame([], $this->alerts($t)['addresses']);
    }

    /** And the authority to manage the inbox: automations alone is not enough. */
    public function test_switching_needs_authority_over_the_inbox(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $this->newLeadRule($t);
        $info = $this->inbox($t, 'info@a-1chimney.com');

        $automator = $this->userWithRole($t, 'Automator', ['email.view', 'email.settings.view', 'automations.view', 'automations.edit']);

        $this->signedInAs($automator)
            ->putJson("/api/v1/email/mailboxes/{$info->id}/new-mail-alert", ['on' => true])
            ->assertForbidden();
    }

    /** MailboxAlerts' own sentence reaches the screen on a 422. */
    public function test_a_change_the_rule_cannot_take_is_refused_in_its_words(): void
    {
        $t = $this->provisionTenant('A1-Group');
        // No new-lead rule: nobody to copy who is told from.
        $info = $this->inbox($t, 'info@a-1chimney.com');

        $this->signedInAs($t['admin'])
            ->putJson("/api/v1/email/mailboxes/{$info->id}/new-mail-alert", ['on' => true])
            ->assertStatus(422)
            ->assertJsonPath('message', fn (string $m) => str_contains($m, 'new-lead notification'));
    }
}
