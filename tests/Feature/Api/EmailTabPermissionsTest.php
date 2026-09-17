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
