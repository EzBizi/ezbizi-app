<?php

namespace Tests\Feature\Api;

use App\Models\Mailbox;
use App\Models\MailboxGrant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Email's two tabs are granted separately (Jose, 2026-09-17).
 *
 * "i should be able to grant access by sub tab as well ... so this applies to
 * every tab and it's sub tabs". For Email the split that matters is that the
 * people who READ the mail are not the people who CONNECT the mailboxes:
 * Settings holds provider sign-ins, server addresses, and the per-role inbox
 * grants themselves.
 *
 * ── THE POSITIVE CONTROL IS FIRST, AND THAT IS THE BRIEF'S WARNING ──
 *
 * HR's version of this was refused by a platform-layer sentence — "Clock in is
 * switched off for this organization" — BEFORE any permission was consulted,
 * and a suite of 403 assertions read that as the feature working perfectly.
 * Email has its own version of that trap waiting: a role with no MailboxGrant
 * is refused per-inbox whatever its tab permissions say. So the first test
 * proves somebody CAN get through, and every refusal below is only readable
 * because of it.
 */
class EmailTabPermissionsTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Tab keys are permission rows like any other, and a role cannot be
        // given a key the table does not carry.
        $this->artisan('ezbizi:sync-permissions')->assertSuccessful();
    }

    /**
     * An inbox this role may read, so a refusal below is the TAB refusing.
     */
    private function grantedInbox(array $tenant, string $roleId): Mailbox
    {
        return app(TenantContext::class)->run($tenant['org'], function () use ($tenant, $roleId) {
            $mailbox = Mailbox::create([
                'organization_id' => $tenant['org']->id,
                'scope_type'      => Mailbox::SCOPE_ORGANIZATION,
                'scope_id'        => $tenant['org']->id,
                'address'         => 'info@a-1chimney.com',
                'display_name'    => 'Info',
                'provider'        => 'gmail_oauth',
            ]);

            MailboxGrant::create([
                'organization_id' => $tenant['org']->id,
                'mailbox_id'      => $mailbox->getKey(),
                'role_id'         => $roleId,
            ]);

            return $mailbox;
        });
    }

    /**
     * THE CONTROL. Somebody holding the Inbox tab reaches the mail.
     *
     * Without this every assertion below could be passing on the grant, the
     * reach, or a provider that cannot be reached from a test box — three
     * refusals that look exactly like a permission working.
     */
    public function test_a_role_holding_the_inbox_tab_reaches_its_mail(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $reader = $this->userWithRole($t, 'Dispatcher', ['email.view', 'email.inbox.view']);

        $mailbox = $this->grantedInbox($t, $reader->role_id);

        $this->signedInAs($reader)
            ->getJson("/api/v1/email/mailboxes/{$mailbox->id}/folders")
            ->assertOk();
    }

    /**
     * The same person, without the Settings tab, cannot change a connection.
     *
     * This is the split Jose asked for, from the reading side.
     */
    public function test_the_inbox_tab_alone_cannot_touch_a_connection(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $reader = $this->userWithRole($t, 'Dispatcher', [
            'email.view', 'email.inbox.view', 'email.edit', 'email.create', 'email.delete',
        ]);

        $mailbox = $this->grantedInbox($t, $reader->role_id);

        // `email.edit` and friends are deliberately held: the refusal must come
        // from the missing TAB, not from a missing action. Without those the
        // test would pass against no tab gate at all.
        $this->signedInAs($reader)
            ->patchJson("/api/v1/email/mailboxes/{$mailbox->id}", ['display_name' => 'Renamed'])
            ->assertForbidden();

        $this->signedInAs($reader)
            ->postJson("/api/v1/email/mailboxes/{$mailbox->id}/connect", ['credential' => 'x'])
            ->assertForbidden();

        $this->signedInAs($reader)
            ->getJson('/api/v1/email/workspace')
            ->assertForbidden();
    }

    /**
     * And the mirror: Settings without Inbox manages connections and cannot
     * read a word of anybody's mail.
     */
    public function test_the_settings_tab_alone_cannot_read_the_mail(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $manager = $this->userWithRole($t, 'Office', [
            'email.view', 'email.settings.view', 'email.edit',
        ]);

        $mailbox = $this->grantedInbox($t, $manager->role_id);

        $this->signedInAs($manager)
            ->getJson("/api/v1/email/mailboxes/{$mailbox->id}/messages")
            ->assertForbidden();

        $this->signedInAs($manager)
            ->getJson("/api/v1/email/mailboxes/{$mailbox->id}/folders")
            ->assertForbidden();

        // THE CONTROL FOR THIS ONE: the same person reaches what they DO hold,
        // so the two refusals above are the Inbox tab and not this role being
        // unable to reach anything at all.
        $this->signedInAs($manager)
            ->getJson('/api/v1/email/workspace')
            ->assertOk();
    }

    /**
     * `GET email/mailboxes` serves BOTH tabs and must not be gated to either.
     *
     * Settings lists connections to manage them; the Inbox calls the same
     * endpoint to draw its rail. Gating it to Settings would empty the mail
     * screen for every role that reads mail and does not manage connections —
     * the ordinary case, and the whole reason the tabs were split.
     *
     * The brief names this shape from HR's `hr/onboardings`. It is the one
     * route here with two audiences, and this test is what stops somebody
     * tidying it into the group above it.
     */
    public function test_the_shared_inbox_list_answers_a_reader_with_no_settings_tab(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $reader = $this->userWithRole($t, 'Dispatcher', ['email.view', 'email.inbox.view']);

        $this->grantedInbox($t, $reader->role_id);

        $this->signedInAs($reader)
            ->getJson('/api/v1/email/mailboxes')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    /**
     * An administrator's `/me` carries both tab keys.
     *
     * ── WHY THIS CANNOT BE A CLIENT TEST ─────────────────────────────
     *
     * `email_screen.dart` decides which tabs to draw from
     * `session.me.permissions`. Every client test STATES that list in a
     * fixture, so no client test can discover what the server actually puts in
     * it — and if the answer were "not the tab keys", every administrator would
     * open Email and find a screen with no tabs at all, which is the one
     * audience nobody would suspect of a permissions fault.
     *
     * Two places could drop them, and they fail for different reasons.
     * `heldBy()` gives an administrator `$this->keys()` whole, so the keys have
     * to be IN the registry — that is the declaration in ModuleServiceProvider.
     * Then every key is filtered through
     * `ModuleAvailability::isPermissionAvailable()`, which runs BEFORE any role
     * is consulted and answers about the ORGANIZATION. A tab key it does not
     * recognise is dropped there, for administrators and everybody else alike.
     *
     * Raised by the Human Resources session on 2026-09-17, who measured 121
     * keys for an HR administrator and pointed out that a fixture states its
     * permissions and therefore cannot find this out.
     */
    public function test_an_administrator_is_given_both_email_tabs(): void
    {
        $t = $this->provisionTenant('A1-Group');

        // Through the ROUTE, not through PermissionCatalogue directly: the
        // client is handed whatever `GET /me` answers, and the catalogue is
        // only one of the things between here and there.
        $held = $this->signedInAs($t['admin'])
            ->getJson('/api/v1/me')
            ->assertOk()
            ->json('data.permissions');

        $this->assertContains('email.inbox.view', $held);
        $this->assertContains('email.settings.view', $held);

        // THE CONTROL. `heldBy` returning the world would satisfy the two
        // assertions above while telling us nothing, so pin something it must
        // NOT contain — a key no module declares.
        $this->assertNotContains('email.nonexistent.view', $held);
    }

    /**
     * And an ordinary role that holds one tab is given exactly that one.
     *
     * The mirror of the test above, through the same call the `/me` route
     * makes. Without it, an availability layer that let every declared key
     * through for everybody would pass the administrator test and hand the
     * Inbox to a role that was never granted it.
     */
    public function test_a_role_is_given_only_the_tab_it_was_granted(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $reader = $this->userWithRole($t, 'Dispatcher', ['email.view', 'email.inbox.view']);

        $held = $this->signedInAs($reader)
            ->getJson('/api/v1/me')
            ->assertOk()
            ->json('data.permissions');

        $this->assertContains('email.inbox.view', $held);
        $this->assertNotContains('email.settings.view', $held);
    }

    /**
     * Holding the module and neither tab reaches nothing behind either.
     *
     * `email.view` alone used to open the whole module. It now opens the door
     * and nothing else, which is the half of this change that could silently
     * fail open.
     */
    public function test_the_module_permission_alone_opens_neither_tab(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $user = $this->userWithRole($t, 'Limited', ['email.view', 'email.edit']);

        $mailbox = $this->grantedInbox($t, $user->role_id);

        $this->signedInAs($user)
            ->getJson("/api/v1/email/mailboxes/{$mailbox->id}/messages")
            ->assertForbidden();

        $this->signedInAs($user)
            ->patchJson("/api/v1/email/mailboxes/{$mailbox->id}", ['display_name' => 'Renamed'])
            ->assertForbidden();
    }
}
