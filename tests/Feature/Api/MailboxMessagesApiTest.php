<?php

namespace Tests\Feature\Api;

use App\Models\AuditEvent;
use App\Models\AutomationEvent;
use App\Models\Mailbox;
use App\Models\MailboxGrant;
use App\Models\MessageDealt;
use App\Models\MessageSeen;
use App\Models\Role;
use App\Models\User;
use App\Support\Email\Arrivals;
use App\Support\Email\Block;
use App\Support\Email\BlockKind;
use App\Support\Email\BodySanitiser;
use App\Support\Email\CannotChangeMail;
use App\Support\Email\CannotReadMailbox;
use App\Support\Email\EmailPicture;
use App\Support\Email\MailboxReader;
use App\Support\Email\MailboxReaders;
use App\Support\Email\MessageDetail;
use App\Support\Email\MessagePage;
use App\Support\Email\MessageSummary;
use App\Support\Email\ReplyComposer;
use App\Support\Settings\SettingsResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use ReflectionMethod;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Reading a shared inbox: the three gates, the refusal, and "seen by".
 *
 * ── Why there is a fake reader in this file ──────────────────────
 *
 * Neither real reader can open a mailbox on this installation — one needs a
 * Google OAuth client that nobody has registered, the other an IMAP library PHP
 * removed from core in the version this runs on. That is the honest state and
 * {@see test_an_unconfigured_mailbox_refuses_rather_than_showing_an_empty_inbox}
 * asserts it directly, against the REAL reader.
 *
 * Everything else here is about what happens AROUND the provider — who may look,
 * what "seen by" says, whether a reply carries the individual — and those need a
 * mailbox that answers. So there is a fake, and it is deliberately dumb: it
 * returns what it was told to and records what it was asked.
 *
 * The fake is not standing in for the refusal. The refusal has its own test,
 * against the real class, and it is the one that would catch a reader quietly
 * learning to return an empty page.
 */
class MailboxMessagesApiTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        FakeMailboxReader::$replies   = [];
        FakeMailboxReader::$sent      = [];
        FakeMailboxReader::$trashed   = [];
        FakeMailboxReader::$downloads = [];
        FakeMailboxReader::$page      = null;
        FakeMailboxReader::$detail    = null;
        FakeMailboxReader::$markedAtSource  = [];
        FakeMailboxReader::$openedThread = null;
        FakeMailboxReader::$unmarkedAtSource = [];
        FakeMailboxReader::$canMarkAtSource = true;
        FakeMailboxReader::$sourceRefuses   = false;
        FakeMailboxReader::$modified = [];
        FakeMailboxReader::$listedCursors = [];
        FakeMailboxReader::$pages = null;
    }

    /** @param array{org: \App\Models\Organization} $tenant */
    private function inbox(
        array $tenant,
        string $address = 'info@a-1chimney.com',
        string $scopeType = 'organization',
        ?string $scopeId = null,
    ): Mailbox {
        return $this->inTenant($tenant['org'], function () use ($tenant, $address, $scopeType, $scopeId) {
            $mailbox = Mailbox::create([
                'organization_id' => $tenant['org']->id,
                'scope_type'      => $scopeType,
                'scope_id'        => $scopeId ?? $tenant['org']->id,
                'address'         => $address,
                'display_name'    => 'Info',
                'provider'        => 'gmail_oauth',
            ]);

            $mailbox->connect('a-refresh-token');
            $mailbox->save();

            return $mailbox;
        });
    }

    private function grant(array $tenant, Mailbox $mailbox, string $roleName): void
    {
        $this->inTenant($tenant['org'], function () use ($tenant, $mailbox, $roleName) {
            MailboxGrant::create([
                'organization_id' => $tenant['org']->id,
                'mailbox_id'      => $mailbox->getKey(),
                'role_id'         => Role::where('name', $roleName)->firstOrFail()->getKey(),
            ]);
        });
    }

    /** Swap both readers for one that answers. */
    private function withFakeProvider(): void
    {
        $this->app->bind(MailboxReaders::class, fn () => new FakeMailboxReaders);
    }

    private function summary(string $id = 'm-1', string $subject = 'Quote for a sweep'): MessageSummary
    {
        return new MessageSummary(
            id: $id,
            threadId: 't-1',
            subject: $subject,
            fromName: 'Bob Smith',
            fromAddress: 'bob@example.com',
            to: ['info@a-1chimney.com'],
            sentAt: Carbon::parse('2026-08-11 09:00:00'),
            snippet: 'Could you quote for two flues',
        );
    }

    // ══ 1. NOT CONNECTED IS NOT AN EMPTY INBOX ═══════════════════

    /**
     * The decision this whole module rests on, asserted against the REAL reader.
     *
     * An empty list and a mailbox that cannot be opened look identical on
     * screen, and only one of them is a reason to ring somebody. So the
     * unconfigured path throws, the controller answers with the exception's own
     * status, and there is no `messages` key in the body at all — a client
     * cannot mistake this for a page with nothing on it.
     */
    public function test_an_unconfigured_mailbox_refuses_rather_than_showing_an_empty_inbox(): void
    {
        $t = $this->provisionTenant('A1-Group');

        $mailbox = $this->inTenant($t['org'], fn () => Mailbox::create([
            'organization_id' => $t['org']->id,
            'scope_type'      => 'organization',
            'scope_id'        => $t['org']->id,
            'address'         => 'info@a-1chimney.com',
            'display_name'    => 'Info',
            'provider'        => 'gmail_oauth',
        ]));

        $response = $this->signedInAs($t['admin'])
            ->getJson("/api/v1/email/mailboxes/{$mailbox->id}/messages");

        // NOT 200. Nothing in the 2xx range may be returned for a mailbox that
        // could not be opened.
        $this->assertGreaterThanOrEqual(400, $response->status());

        $body = $response->json();

        $this->assertArrayNotHasKey('data', $body);
        $this->assertStringContainsString('nothing to show', $body['message']);

        // R8: the sentence says whose problem it is and what happens meanwhile.
        $this->assertStringContainsString('at EzBizi\'s end', $body['message']);
        $this->assertTrue($body['at_our_end']);
    }

    /**
     * The same for a connected-but-unregistered Gmail mailbox, and for IMAP.
     *
     * Two providers, one property. Without this, a reader that returned an empty
     * page for one of them would be caught by nothing.
     */
    public function test_neither_provider_returns_an_empty_page_when_it_cannot_look(): void
    {
        $t = $this->provisionTenant('A1-Group');

        foreach (['gmail_oauth', 'imap'] as $provider) {
            $mailbox = $this->inTenant($t['org'], function () use ($t, $provider) {
                $mailbox = Mailbox::create([
                    'organization_id' => $t['org']->id,
                    'scope_type'      => 'organization',
                    'scope_id'        => $t['org']->id,
                    'address'         => "{$provider}@a-1chimney.com",
                    'display_name'    => 'Info',
                    'provider'        => $provider,
                    'mail_server'     => ['imap_host' => '93.184.216.34', 'imap_port' => 993],
                ]);

                $mailbox->connect('a-credential');
                $mailbox->save();

                return $mailbox;
            });

            $response = $this->signedInAs($t['admin'])
                ->getJson("/api/v1/email/mailboxes/{$mailbox->id}/messages");

            $this->assertGreaterThanOrEqual(400, $response->status(), "{$provider} answered 2xx");
            $this->assertArrayNotHasKey('data', $response->json(), "{$provider} returned a page");
        }
    }

    // ══ 2. GRANT AND REACH ARE TWO QUESTIONS ═════════════════════

    /**
     * THE CASE THE WHOLE DESIGN TURNS ON.
     *
     * Jenny is a Branch Manager assigned to Connecticut only. Her ROLE has been
     * granted the company-wide inbox — a perfectly reasonable thing for an
     * organization to do. She still may not open it, because the inbox is above
     * where she stands.
     *
     * The control is the second half: the same person, the same role, the same
     * grant, on an inbox at her own franchise — and she gets in. So the refusal
     * is demonstrably about REACH and not about the grant or the permission.
     */
    public function test_a_franchise_user_granted_a_company_inbox_still_cannot_open_it(): void
    {
        $this->withFakeProvider();

        $t = $this->provisionTenant('A1-Group');

        $company   = $this->inbox($t, 'info@a-1chimney.com', 'company', $t['company']->id);
        $ownBranch = $this->inbox($t, 'connecticut@a-1chimney.com', 'franchise', $t['franchise']->id);

        $jenny = $this->userWithRole($t, 'Branch Manager', ['email.view', 'email.inbox.view', 'email.settings.view'], onlyAt: $t['franchise']);

        // Her role is granted BOTH inboxes.
        $this->grant($t, $company, 'Branch Manager');
        $this->grant($t, $ownBranch, 'Branch Manager');

        FakeMailboxReader::$page = new MessagePage([$this->summary()]);

        $refused = $this->signedInAs($jenny)
            ->getJson("/api/v1/email/mailboxes/{$company->id}/messages")
            ->assertForbidden();

        $message = $refused->json('message');

        // The sentence is about WHERE, not about the grant — the remedy is an
        // assignment, and sending her to ask for access she already has would
        // baffle everybody involved.
        $this->assertStringContainsString('assigned', $message);
        $this->assertStringNotContainsString('has not been given', $message);

        // THE CONTROL.
        $this->signedInAs($jenny)
            ->getJson("/api/v1/email/mailboxes/{$ownBranch->id}/messages")
            ->assertOk()
            ->assertJsonPath('data.messages.0.subject', 'Quote for a sweep');
    }

    /**
     * The switcher is a lens. An administrator who can open EzBizi's inbox
     * must not open it while looking at A-1 Chimney — listing it on Inbox
     * was the visible half of the same leak.
     */
    public function test_a_company_inbox_cannot_be_opened_from_a_sibling_company(): void
    {
        $this->withFakeProvider();

        $t = $this->provisionTenant('A1-Group');
        $premier = $this->inTenant($t['org'], fn () => \App\Models\Company::create([
            'organization_id' => $t['org']->id,
            'name'            => 'Premier Garage Repair',
        ]));

        $inbox = $this->inbox($t, 'development@ezbizi.com', 'company', $t['company']->id);
        $this->lookingAt($t['admin'], 'company', $premier->id);

        FakeMailboxReader::$page = new MessagePage([$this->summary()]);

        $this->signedInAs($t['admin']->fresh())
            ->getJson("/api/v1/email/mailboxes/{$inbox->id}/messages")
            ->assertForbidden()
            ->assertJsonPath(
                'message',
                fn (string $m) => str_contains($m, 'development@ezbizi.com')
                    && str_contains($m, 'Premier Garage Repair')
                    && str_contains($m, 'organization'),
            );
    }

    private function lookingAt(\App\Models\User $user, string $type, string $id): void
    {
        $this->inTenant($user->organization, function () use ($user, $type, $id) {
            $user->forceFill([
                'preferences' => array_merge($user->preferences ?? [], [
                    'navigation.scope' => $type.':'.$id,
                ]),
            ])->save();
        });
    }

    /**
     * The other half: reach without a grant.
     *
     * Somebody standing exactly where the inbox is, holding the module's view
     * permission, and not given this inbox. The refusal names the roles editor,
     * because that is where the fix is.
     */
    public function test_reach_and_the_tab_are_not_enough_without_a_grant(): void
    {
        $this->withFakeProvider();

        $t = $this->provisionTenant('A1-Group');

        $info    = $this->inbox($t, 'info@a-1chimney.com');
        $payroll = $this->inbox($t, 'payroll@a-1chimney.com');

        $clerk = $this->userWithRole($t, 'Clerk', ['email.view', 'email.inbox.view', 'email.settings.view']);

        $this->grant($t, $info, 'Clerk');

        FakeMailboxReader::$page = new MessagePage([$this->summary()]);

        $message = $this->signedInAs($clerk)
            ->getJson("/api/v1/email/mailboxes/{$payroll->id}/messages")
            ->assertForbidden()
            ->json('message');

        $this->assertStringContainsString('payroll@a-1chimney.com', $message);
        $this->assertStringContainsString('has not been given', $message);
        $this->assertStringContainsString('roles', $message);

        // R8: never an internal identifier.
        $this->assertStringNotContainsString('email.view', $message);
        $this->assertStringNotContainsString('MailboxGrant', $message);

        // THE CONTROL — the inbox they WERE given opens.
        $this->signedInAs($clerk)
            ->getJson("/api/v1/email/mailboxes/{$info->id}/messages")
            ->assertOk();
    }

    /**
     * The Administrator holds every inbox without holding a row.
     *
     * Exactly as `Role::hasPermission` answers true for every key for that same
     * role, and — the reason this is asserted here rather than argued about —
     * exactly as the ROLES EDITOR already reports it:
     * App\Support\Access\InboxAccess::heldBy expands the Administrator to the
     * whole list. The two halves of one feature have to agree, or the screen
     * shows the Administrator holding info@ and opening it refuses them.
     *
     * The owner's requirement is untouched: it is about withholding inboxes from
     * roles the organization creates, and every one of those is still refused
     * without a grant — which the test above this one proves.
     */
    public function test_an_administrator_holds_every_inbox_without_holding_a_grant(): void
    {
        $this->withFakeProvider();

        $t = $this->provisionTenant('A1-Group');

        $mailbox = $this->inbox($t);

        FakeMailboxReader::$page = new MessagePage([$this->summary()]);

        $this->signedInAs($t['admin'])
            ->getJson("/api/v1/email/mailboxes/{$mailbox->id}/messages")
            ->assertOk();

        $row = $this->signedInAs($t['admin'])->getJson('/api/v1/email/mailboxes')
            ->assertOk()->json('data.0');

        $this->assertTrue($row['may_read']);
        $this->assertTrue($row['may_manage']);

        // And it owns NO row — nothing writes grants for the Administrator, so a
        // reader that consulted the table would report an administrator who can
        // open no mail at all.
        $this->inTenant($t['org'], fn () => $this->assertSame(0, MailboxGrant::count()));
    }

    /**
     * The Administrator's waiver covers the GRANT and not §4 REACH.
     *
     * Two different things, and it would be easy to collapse them into one
     * `isAdministrator()` short circuit at the top of the refusal. They must not
     * be: a grant is about what a ROLE is trusted with, and reach is about where
     * a PERSON stands — which comes from their assignment and has nothing to do
     * with their role.
     *
     * So this builds the case that tells them apart, and it is a real one rather
     * than a contrived shape: somebody holding the organization's Administrator
     * ROLE whose assignment is a single franchise. A company's inbox is still
     * not theirs to open, exactly as a company's settings are not theirs to
     * change.
     *
     * The control is the organization's actual administrator, who stands above
     * everything and gets in — so the refusal is about the assignment rather
     * than about the module being broken.
     */
    /**
     * Superseded 2026-10-16. Jose, asked "Should Administrators assigned to
     * one company stay limited to that company?": "No". An Administrator now
     * reaches the whole organization whatever their assignment, so the branch
     * administrator below opens the company inbox.
     */
    public function test_an_administrator_assigned_to_one_branch_reaches_the_company_inbox(): void
    {
        $this->withFakeProvider();

        $t = $this->provisionTenant('A1-Group');

        $companyInbox = $this->inbox($t, 'info@a-1chimney.com', 'company', $t['company']->id);

        // The Administrator ROLE, and an assignment covering one branch only.
        $branchAdmin = $this->inTenant($t['org'], function () use ($t) {
            $user = User::create([
                'organization_id' => $t['org']->id,
                'role_id'         => Role::where('is_administrator', true)->firstOrFail()->getKey(),
                'name'            => 'Connecticut Administrator',
                'email'           => 'ct-admin@a1.test',
                'password'        => 'a-real-password',
                'is_active'       => true,
            ]);

            \App\Models\Assignment::create([
                'organization_id'      => $t['org']->id,
                'user_id'              => $user->getKey(),
                'assigned_franchise_id' => $t['franchise']->getKey(),
            ]);

            return $user;
        });

        FakeMailboxReader::$page = new MessagePage([$this->summary()]);

        $this->signedInAs($branchAdmin)
            ->getJson("/api/v1/email/mailboxes/{$companyInbox->id}/messages")
            ->assertOk();

        // THE CONTROL — the organization-wide administrator gets in, so this is
        // an assignment rule and not a broken module.
        $this->signedInAs($t['admin'])
            ->getJson("/api/v1/email/mailboxes/{$companyInbox->id}/messages")
            ->assertOk();
    }

    public function test_without_the_module_permission_nothing_opens(): void
    {
        $this->withFakeProvider();

        $t = $this->provisionTenant('A1-Group');

        $mailbox = $this->inbox($t);

        $viewer = $this->userWithRole($t, 'Viewer', ['customers.view']);

        // Even WITH a grant, which is the point: a grant is not a permission.
        $this->grant($t, $mailbox, 'Viewer');

        FakeMailboxReader::$page = new MessagePage([$this->summary()]);

        $this->signedInAs($viewer)
            ->getJson("/api/v1/email/mailboxes/{$mailbox->id}/messages")
            ->assertForbidden()
            ->assertJsonPath('message', fn (string $m) => str_contains($m, 'do not have permission'));
    }

    public function test_a_thread_is_one_list_row_with_a_count(): void
    {
        $this->withFakeProvider();

        $t = $this->provisionTenant('A1-Group');
        $mailbox = $this->inbox($t);
        $clerk = $this->userWithRole($t, 'Clerk', ['email.view', 'email.inbox.view', 'email.settings.view']);
        $this->grant($t, $mailbox, 'Clerk');

        FakeMailboxReader::$page = new MessagePage([
            new MessageSummary(
                id: 'm-5',
                threadId: 't-wa',
                subject: 'Your WhatsApp message template state has been changed',
                fromName: 'WhatsApp Business Team',
                fromAddress: 'notification@facebookmail.com',
                to: ['info@a-1chimney.com'],
                sentAt: Carbon::parse('2026-08-21 10:31:00'),
                snippet: 'Please see your template current state information.',
                messageCount: 5,
            ),
        ]);

        $row = $this->signedInAs($clerk)
            ->getJson("/api/v1/email/mailboxes/{$mailbox->id}/messages")
            ->assertOk()
            ->json('data.messages.0');

        $this->assertSame(5, $row['message_count']);
        $this->assertSame('t-wa', $row['thread_id']);
        $this->assertSame('WhatsApp Business Team', $row['from']['name']);
    }

    public function test_deleting_a_message_is_refused_in_a_sentence(): void
    {
        $this->withFakeProvider();

        $t = $this->provisionTenant('A1-Group');
        $mailbox = $this->inbox($t);
        $clerk = $this->userWithRole($t, 'Clerk', ['email.view', 'email.inbox.view', 'email.settings.view']);
        $this->grant($t, $mailbox, 'Clerk');

        $this->signedInAs($clerk)
            ->deleteJson("/api/v1/email/mailboxes/{$mailbox->id}/messages/m-1")
            ->assertOk()
            ->assertJsonPath('data.id', 'm-1');

        $this->assertSame(['m-1'], FakeMailboxReader::$trashed);
    }

    public function test_bulk_trash_bins_every_ticked_id(): void
    {
        $this->withFakeProvider();

        $t = $this->provisionTenant('A1-Group');
        $mailbox = $this->inbox($t);
        $clerk = $this->userWithRole($t, 'Clerk', ['email.view', 'email.inbox.view', 'email.settings.view']);
        $this->grant($t, $mailbox, 'Clerk');

        $this->signedInAs($clerk)
            ->postJson("/api/v1/email/mailboxes/{$mailbox->id}/messages/bulk", [
                'action' => 'trash',
                'ids'    => ['m-1', 'm-2'],
            ])
            ->assertOk()
            ->assertJsonPath('data.ids', ['m-1', 'm-2']);

        $this->assertSame(['m-1', 'm-2'], FakeMailboxReader::$trashed);
    }

    // ══ 3. SEEN, SHARED OR PRIVATE ═══════════════════════════════

    public function test_opening_a_message_records_who_looked_and_the_list_says_so(): void
    {
        $this->withFakeProvider();

        $t = $this->provisionTenant('A1-Group');

        $mailbox = $this->inbox($t);

        $jenny = $this->userWithRole($t, 'Front desk', ['email.view', 'email.inbox.view', 'email.settings.view'], email: 'jenny@a1.test');
        $sam   = $this->userWithRole($t, 'Dispatcher', ['email.view', 'email.inbox.view', 'email.settings.view'], email: 'sam@a1.test');

        $this->grant($t, $mailbox, 'Front desk');
        $this->grant($t, $mailbox, 'Dispatcher');

        FakeMailboxReader::$page = new MessagePage([$this->summary()]);

        $this->signedInAs($jenny)
            ->getJson("/api/v1/email/mailboxes/{$mailbox->id}/messages/m-1")
            ->assertOk();

        $this->inTenant($t['org'], fn () => $this->assertSame(1, MessageSeen::count()));

        // Sam sees that somebody has picked it up — by NAME, because "has
        // anybody dealt with this" is answered by who and not by how many.
        $row = $this->signedInAs($sam)->getJson("/api/v1/email/mailboxes/{$mailbox->id}/messages")
            ->assertOk()->json('data.messages.0');

        $this->assertSame(['Front desk'], $row['seen_by']);

        // And Jenny sees herself as "You" rather than working out that Front
        // desk is her.
        $mine = $this->signedInAs($jenny)->getJson("/api/v1/email/mailboxes/{$mailbox->id}/messages")
            ->assertOk()->json('data.messages.0');

        $this->assertSame(['You'], $mine['seen_by']);
    }

    /**
     * The cascading setting, and what makes it worth being a setting.
     *
     * The ROWS are identical under both readings — nothing is deleted when the
     * organization changes its mind — so switching back shows the marks again.
     * That is asserted here rather than described, because "we keep the data" is
     * exactly the kind of claim that quietly stops being true.
     */
    public function test_a_private_read_state_hides_colleagues_marks_and_keeps_them(): void
    {
        $this->withFakeProvider();

        $t = $this->provisionTenant('A1-Group');

        $mailbox = $this->inbox($t);

        $jenny = $this->userWithRole($t, 'Front desk', ['email.view', 'email.inbox.view', 'email.settings.view'], email: 'jenny@a1.test');
        $sam   = $this->userWithRole($t, 'Dispatcher', ['email.view', 'email.inbox.view', 'email.settings.view'], email: 'sam@a1.test');

        $this->grant($t, $mailbox, 'Front desk');
        $this->grant($t, $mailbox, 'Dispatcher');

        FakeMailboxReader::$page = new MessagePage([$this->summary()]);

        $this->signedInAs($jenny)
            ->getJson("/api/v1/email/mailboxes/{$mailbox->id}/messages/m-1")->assertOk();

        // Shared is the default, and the control for everything below.
        $this->assertSame(['Front desk'], $this->signedInAs($sam)
            ->getJson("/api/v1/email/mailboxes/{$mailbox->id}/messages")
            ->assertOk()->json('data.messages.0.seen_by'));

        $this->inTenant($t['org'], fn () => app(SettingsResolver::class)
            ->set('email.read_state', $t['org'], 'private'));

        $body = $this->signedInAs($sam)->getJson("/api/v1/email/mailboxes/{$mailbox->id}/messages")
            ->assertOk()->json('data.messages.0');

        $this->assertSame([], $body['seen_by']);

        // Jenny still sees her own — private means private to each person, not
        // switched off.
        $this->assertSame(['You'], $this->signedInAs($jenny)
            ->getJson("/api/v1/email/mailboxes/{$mailbox->id}/messages")
            ->assertOk()->json('data.messages.0.seen_by'));

        // The row was never deleted, so switching back restores the view.
        $this->inTenant($t['org'], function () use ($t) {
            $this->assertSame(1, MessageSeen::count());

            app(SettingsResolver::class)->set('email.read_state', $t['org'], 'shared');
        });

        $this->assertSame(['Front desk'], $this->signedInAs($sam)
            ->getJson("/api/v1/email/mailboxes/{$mailbox->id}/messages")
            ->assertOk()->json('data.messages.0.seen_by'));
    }

    public function test_marking_the_same_message_twice_writes_one_row(): void
    {
        $this->withFakeProvider();

        $t = $this->provisionTenant('A1-Group');

        $mailbox = $this->inbox($t);
        $clerk   = $this->userWithRole($t, 'Clerk', ['email.view', 'email.inbox.view', 'email.settings.view']);

        $this->grant($t, $mailbox, 'Clerk');

        foreach (range(1, 3) as $_) {
            $this->signedInAs($clerk)
                ->postJson("/api/v1/email/mailboxes/{$mailbox->id}/messages/m-1/seen")
                ->assertOk();
        }

        $this->inTenant($t['org'], fn () => $this->assertSame(1, MessageSeen::count()));
    }

    /** A seen row holds an opaque id and nothing about the message. */
    public function test_a_seen_row_holds_no_part_of_the_message(): void
    {
        $this->withFakeProvider();

        $t = $this->provisionTenant('A1-Group');

        $mailbox = $this->inbox($t);
        $clerk   = $this->userWithRole($t, 'Clerk', ['email.view', 'email.inbox.view', 'email.settings.view']);

        $this->grant($t, $mailbox, 'Clerk');

        FakeMailboxReader::$page = new MessagePage([$this->summary()]);

        $this->signedInAs($clerk)
            ->getJson("/api/v1/email/mailboxes/{$mailbox->id}/messages/m-1")->assertOk();

        $row = \Illuminate\Support\Facades\DB::table('message_seen')->first();

        $this->assertSame(
            ['id', 'organization_id', 'mailbox_id', 'provider_message_id', 'user_id', 'seen_at'],
            array_keys((array) $row),
        );

        $this->assertSame('m-1', $row->provider_message_id);
    }

    // ══ 4. THE REPLY GOES OUT AS THE INBOX ═══════════════════════

    /**
     * There is no way to put the individual on the message.
     *
     * Asserted structurally rather than by inspecting output, because the
     * guarantee is structural: neither {@see MailboxReader::reply} nor
     * {@see ReplyComposer::compose} takes a User, so no controller edit can
     * start passing one by accident. A test that only checked the composed
     * headers would go green again the moment somebody added the parameter and
     * used it somewhere else.
     */
    public function test_no_reply_path_can_be_handed_the_person_who_typed_it(): void
    {
        foreach ([
            [MailboxReader::class, 'reply'],
            [MailboxReader::class, 'send'],
            [ReplyComposer::class, 'compose'],
            [ReplyComposer::class, 'composeForGmail'],
            [ReplyComposer::class, 'composeNew'],
            [ReplyComposer::class, 'composeNewForGmail'],
        ] as [$class, $method]) {
            foreach ((new ReflectionMethod($class, $method))->getParameters() as $parameter) {
                $this->assertNotSame(
                    User::class,
                    (string) $parameter->getType(),
                    "{$class}::{$method} can be handed the person who typed the reply. "
                    .'"info replies as info" — the From line comes from the mailbox record, '
                    .'and the way that is guaranteed is that there is no parameter for a person.',
                );
            }
        }
    }

    /** The From line is the mailbox, and the words are the sender's. */
    public function test_the_composed_reply_goes_out_as_the_inbox(): void
    {
        $t = $this->provisionTenant('A1-Group');

        $mailbox = $this->inbox($t);

        $raw = (new ReplyComposer)->compose(
            $mailbox,
            $this->summary(),
            ['message-id' => '<abc@example.com>'],
            "Yes, Tuesday works.\n\nThanks",
        );

        $this->assertStringContainsString('From: Info <info@a-1chimney.com>', $raw);
        $this->assertStringContainsString('To: bob@example.com', $raw);
        $this->assertStringContainsString('Subject: Re: Quote for a sweep', $raw);
        $this->assertStringContainsString('In-Reply-To: <abc@example.com>', $raw);

        /*
         * Nothing about the person who typed it — no Reply-To, no Sender, no
         * second From.
         *
         * Matched as a LINE rather than as a substring, and that is not
         * pedantry: `In-Reply-To:` contains the characters `Reply-To:`, so a
         * substring check here fails against correct output and would be
         * "fixed" by dropping the threading header, which is the one thing that
         * makes the answer land under the question in the customer's mail
         * program.
         */
        [$headers] = explode("



", $raw, 2);

        $this->assertDoesNotMatchRegularExpression('/^Reply-To:/mi', $headers);
        $this->assertDoesNotMatchRegularExpression('/^Sender:/mi', $headers);
        $this->assertSame(1, preg_match_all('/^From:/mi', $headers));
        $this->assertStringNotContainsString('a1group@example.test', $raw);

        // The control: the body really is in there, so the assertions above are
        // not passing against an empty string.
        $this->assertStringContainsString('Yes, Tuesday works.', $raw);
    }

    /**
     * Header injection, from the one field an attacker controls.
     *
     * The subject comes from the message being replied to — from whoever sent
     * it. A newline in it would end the Subject header and everything after
     * would become new headers, so `Bcc:` would silently copy the business's
     * reply to whoever chose it.
     */
    public function test_a_subject_carrying_a_header_injection_cannot_add_a_header(): void
    {
        $t = $this->provisionTenant('A1-Group');

        $mailbox = $this->inbox($t);

        $raw = (new ReplyComposer)->compose(
            $mailbox,
            $this->summary(subject: "Invoice\r\nBcc: everyone@evil.example.net\r\nX-Injected: yes"),
            [],
            'Thanks for your message.',
        );

        [$headers] = explode("\r\n\r\n", $raw, 2);

        /*
         * No new HEADER — matched as a LINE, because the words themselves are
         * expected to survive and a substring check would fail against correct
         * output.
         *
         * They survive on the Subject line, mashed together by the scrub, and
         * that is the deliberate outcome rather than a near miss: an obviously
         * odd subject is better than a silently truncated one, and the customer
         * receiving the reply sees exactly what was in their own subject.
         */
        $this->assertDoesNotMatchRegularExpression('/^Bcc:/mi', $headers);
        $this->assertDoesNotMatchRegularExpression('/^X-Injected:/mi', $headers);

        // Exactly one Subject line — the injection did not split it in two.
        $this->assertSame(1, preg_match_all('/^Subject:/mi', $headers));

        // The control: the text really was there to be injected, so the two
        // assertions above are about scrubbing rather than about an empty value.
        $this->assertStringContainsString('everyone@evil.example.net', $headers);
    }

    /** A newline in the body is a newline. It is not a header. */
    public function test_a_body_containing_a_header_line_stays_body(): void
    {
        $t = $this->provisionTenant('A1-Group');

        $raw = (new ReplyComposer)->compose(
            $this->inbox($t),
            $this->summary(),
            [],
            "Hello\nBcc: everyone@evil.example.net\nRegards",
        );

        [$headers, $body] = explode("\r\n\r\n", $raw, 2);

        $this->assertStringNotContainsString('Bcc', $headers);
        $this->assertStringContainsString('Bcc: everyone@evil.example.net', $body);
    }

    public function test_replying_is_audited_with_who_typed_it_and_without_the_words(): void
    {
        $this->withFakeProvider();

        $t = $this->provisionTenant('A1-Group');

        $mailbox = $this->inbox($t);
        $jenny   = $this->userWithRole($t, 'Front desk', ['email.view', 'email.inbox.view', 'email.settings.view'], email: 'jenny@a1.test');

        $this->grant($t, $mailbox, 'Front desk');

        $reply = 'Yes, we can do Tuesday. Our price is 180.';

        $this->signedInAs($jenny)
            ->postJson("/api/v1/email/mailboxes/{$mailbox->id}/messages/m-1/reply", ['body' => $reply])
            ->assertOk()
            ->assertJsonPath('message', fn (string $m) => str_contains($m, 'info@a-1chimney.com'));

        $event = AuditEvent::where('action', 'mailbox.replied')->firstOrFail();

        // WHO typed it is recorded — that is where the internal question belongs.
        $this->assertStringContainsString('jenny@a1.test', $event->actor_label);

        // The WORDS are not. An audit log holding the text of every reply is a
        // second copy of the correspondence, which is the store decision 2
        // forbids arriving through a side door.
        $this->assertStringNotContainsString('180', json_encode($event->changes));
        $this->assertSame(strlen($reply), $event->changes['characters']);
    }

    /**
     * Every feature wires into automations.
     *
     * `email.replied` is the event this module raises when a PERSON acts: it is
     * emitted from the controller, in the request that sent the reply. Its
     * sibling, "an email arrived", is raised by nobody in a request at all —
     * `ezbizi:watch-mailboxes` finds it on a timer — and is covered by
     * MailboxWatchTest. This one stays here because the thing being asserted is
     * that sending recorded WHO typed it, which only a request can answer.
     */
    public function test_sending_a_reply_raises_the_automation_event(): void
    {
        $this->withFakeProvider();

        $t = $this->provisionTenant('A1-Group');

        $mailbox = $this->inbox($t);
        $jenny   = $this->userWithRole($t, 'Front desk', ['email.view', 'email.inbox.view', 'email.settings.view'], email: 'jenny@a1.test');

        $this->grant($t, $mailbox, 'Front desk');

        $this->signedInAs($jenny)
            ->postJson("/api/v1/email/mailboxes/{$mailbox->id}/messages/m-1/reply", ['body' => 'On our way.'])
            ->assertOk();

        // Read inside the tenant: `automation_events` is tenant-owned, and its
        // global scope refuses an unscoped read rather than quietly returning
        // every organization's events.
        $this->inTenant($t['org'], function () {
            $event = AutomationEvent::where('event_key', 'email.replied')->firstOrFail();

            $this->assertSame('info@a-1chimney.com', $event->payload['address']);
            $this->assertStringContainsString('jenny@a1.test', $event->actor_label);
        });
    }

    public function test_somebody_who_cannot_open_an_inbox_cannot_reply_from_it(): void
    {
        $this->withFakeProvider();

        $t = $this->provisionTenant('A1-Group');

        $mailbox = $this->inbox($t);
        $clerk   = $this->userWithRole($t, 'Clerk', ['email.view', 'email.inbox.view', 'email.settings.view']);

        $this->signedInAs($clerk)
            ->postJson("/api/v1/email/mailboxes/{$mailbox->id}/messages/m-1/reply", ['body' => 'Hello'])
            ->assertForbidden();

        $this->assertSame([], FakeMailboxReader::$replies);
    }

    // ══ 4. DEALT WITH — the inbox's fact, not a person's ═════════

    /**
     * THE DISTINCTION THE WHOLE FEATURE RESTS ON.
     *
     * ═══════════════════════════════════════════════════════════════
     *  Marking a message handled must NOT record that anybody read
     *  it. "Seen by Jenny" is a claim about a person, and a rule —
     *  or a colleague clicking Dealt with from the list without
     *  opening anything — has not read it.
     *
     *  If these two ever merge, the failure is quiet and expensive:
     *  a colleague sees a name against a message, believes it was
     *  looked at, and skips a customer who was never answered.
     * ═══════════════════════════════════════════════════════════════
     */
    public function test_marking_dealt_does_not_claim_anybody_read_it(): void
    {
        $this->withFakeProvider();

        $t       = $this->provisionTenant('A1-Group');
        $mailbox = $this->inbox($t);

        $this->signedInAs($t['admin'])
            ->postJson("/api/v1/email/mailboxes/{$mailbox->id}/messages/m-1/dealt")
            ->assertOk()
            ->assertJsonPath('data.dealt.by', $t['admin']->name);

        $this->inTenant($t['org'], function () use ($mailbox) {
            $this->assertSame(1, MessageDealt::where('mailbox_id', $mailbox->getKey())->count());

            // The control, and the point: no read mark was invented.
            $this->assertSame(
                0,
                MessageSeen::where('mailbox_id', $mailbox->getKey())->count(),
                'Marking a message handled recorded that somebody READ it. '
                .'"Seen by" is a statement about a person and this was not one.',
            );
        });
    }

    /** One row for the inbox, however many people press it. */
    public function test_marking_dealt_twice_is_one_fact(): void
    {
        $this->withFakeProvider();

        $t       = $this->provisionTenant('A1-Group');
        $mailbox = $this->inbox($t);
        $jenny   = $this->userWithRole($t, 'Front desk', ['email.view', 'email.inbox.view', 'email.settings.view'], email: 'jenny@a1.test');

        $this->grant($t, $mailbox, 'Front desk');

        $this->signedInAs($t['admin'])
            ->postJson("/api/v1/email/mailboxes/{$mailbox->id}/messages/m-1/dealt")->assertOk();
        $this->signedInAs($jenny)
            ->postJson("/api/v1/email/mailboxes/{$mailbox->id}/messages/m-1/dealt")->assertOk();

        $this->inTenant($t['org'], function () use ($mailbox) {
            $this->assertSame(1, MessageDealt::where('mailbox_id', $mailbox->getKey())->count());
        });
    }

    /**
     * ANYBODY who can read the inbox may undo it — unlike "seen".
     *
     * `unsee` takes off only the caller's own mark, because that is their fact.
     * This is the inbox's fact: when the customer turns out not to have been
     * answered, whoever notices must be able to say so without tracking down
     * whoever marked it.
     */
    public function test_somebody_else_can_undo_a_dealt_mark(): void
    {
        $this->withFakeProvider();

        $t       = $this->provisionTenant('A1-Group');
        $mailbox = $this->inbox($t);
        $jenny   = $this->userWithRole($t, 'Front desk', ['email.view', 'email.inbox.view', 'email.settings.view'], email: 'jenny@a1.test');

        $this->grant($t, $mailbox, 'Front desk');

        $this->signedInAs($t['admin'])
            ->postJson("/api/v1/email/mailboxes/{$mailbox->id}/messages/m-1/dealt")->assertOk();

        $this->signedInAs($jenny)
            ->deleteJson("/api/v1/email/mailboxes/{$mailbox->id}/messages/m-1/dealt")
            ->assertOk()
            ->assertJsonPath('data.dealt', null);

        $this->inTenant($t['org'], function () use ($mailbox) {
            $this->assertSame(0, MessageDealt::where('mailbox_id', $mailbox->getKey())->count());
        });
    }

    public function test_somebody_who_cannot_open_an_inbox_cannot_mark_it_dealt(): void
    {
        $this->withFakeProvider();

        $t       = $this->provisionTenant('A1-Group');
        $mailbox = $this->inbox($t);
        $clerk   = $this->userWithRole($t, 'Clerk', ['email.view', 'email.inbox.view', 'email.settings.view']);

        $this->signedInAs($clerk)
            ->postJson("/api/v1/email/mailboxes/{$mailbox->id}/messages/m-1/dealt")
            ->assertForbidden();

        $this->inTenant($t['org'], function () use ($mailbox) {
            $this->assertSame(0, MessageDealt::where('mailbox_id', $mailbox->getKey())->count());
        });
    }

    /** The list carries it, so a busy inbox shows what still needs answering. */
    public function test_the_list_says_which_messages_are_handled(): void
    {
        $this->withFakeProvider();

        $t       = $this->provisionTenant('A1-Group');
        $mailbox = $this->inbox($t);

        FakeMailboxReader::$page = new MessagePage([$this->summary(), $this->summary('m-2', 'Second')], null);

        $this->signedInAs($t['admin'])
            ->postJson("/api/v1/email/mailboxes/{$mailbox->id}/messages/m-1/dealt")->assertOk();

        FakeMailboxReader::$page = new MessagePage([$this->summary(), $this->summary('m-2', 'Second')], null);

        $body = $this->signedInAs($t['admin'])
            ->getJson("/api/v1/email/mailboxes/{$mailbox->id}/messages")
            ->assertOk()->json('data');

        $this->assertArrayHasKey('m-1', $body['dealt']);

        // The control: the OTHER message is absent rather than the map being
        // full of everything or empty of everything.
        $this->assertArrayNotHasKey('m-2', $body['dealt']);
    }

    /**
     * Item 2: the two settings are one intent.
     *
     * An inbox told to mirror its read state to Gmail is one where "handled
     * here" should mean "handled there" — marking it twice, in two
     * applications, is what that switch exists to stop.
     */
    public function test_marking_dealt_also_marks_read_at_the_service_when_switched_on(): void
    {
        $this->withFakeProvider();

        $t       = $this->provisionTenant('A1-Group');
        $mailbox = $this->inbox($t);

        $this->inTenant($t['org'], fn () => $mailbox->update(['mark_read_at_source' => true]));

        $this->signedInAs($t['admin'])
            ->postJson("/api/v1/email/mailboxes/{$mailbox->id}/messages/m-1/dealt")
            ->assertOk()
            ->assertJsonPath('data.at_source', true);

        $this->assertSame(['m-1'], FakeMailboxReader::$markedAtSource);
    }

    /** And leaves it alone when somebody switched the mailbox off. */
    public function test_marking_dealt_leaves_the_service_alone_when_switched_off(): void
    {
        $this->withFakeProvider();

        $t       = $this->provisionTenant('A1-Group');
        $mailbox = $this->inbox($t);
        $this->inTenant($t['org'], fn () => $mailbox->update(['mark_read_at_source' => false]));

        $this->signedInAs($t['admin'])
            ->postJson("/api/v1/email/mailboxes/{$mailbox->id}/messages/m-1/dealt")
            ->assertOk()
            ->assertJsonPath('data.at_source', null);

        $this->assertSame([], FakeMailboxReader::$markedAtSource);
    }

    // ══ 4a. MARKING READ AT THE MAIL SERVICE ═════════════════════

    /**
     * Switched OFF, opening never reaches the mail service.
     *
     * The default became ON on 2026-10-06 (Jose: "Sync email read/unread with
     * gmail"), but the switch stays, and this is what it is FOR: read state at
     * a provider is ONE GLOBAL FLAG while `message_seen` is per person, so a
     * shared inbox may need it off -- Jenny opening a customer's mail would
     * otherwise mark it read for Bob looking at Gmail directly.
     */
    public function test_opening_a_message_does_not_touch_the_mail_service_when_switched_off(): void
    {
        $this->withFakeProvider();

        $t       = $this->provisionTenant('A1-Group');
        $mailbox = $this->inbox($t);
        $this->inTenant($t['org'], fn () => $mailbox->update(['mark_read_at_source' => false]));

        FakeMailboxReader::$detail = new MessageDetail(
            summary: $this->summary(),
            blocks: (new BodySanitiser)->sanitise('<p>A promotion.</p>'),
        );

        $this->signedInAs($t['admin'])
            ->getJson("/api/v1/email/mailboxes/{$mailbox->id}/messages/m-1")
            ->assertOk()
            ->assertJsonPath('data.seen_by', fn ($seen) => $seen !== null);

        $this->assertSame(
            [],
            FakeMailboxReader::$markedAtSource,
            'A mailbox switched OFF reached out to the mail service.',
        );
    }

    /**
     * Switched on, OPENING a message marks it read at the service.
     *
     * Opening, not the explicit "mark as read" button — that is the act
     * somebody described wanting: click a promotion here, and have it stop
     * being bold in Gmail. Wiring only the button would have shipped a setting
     * that does nothing in the case it was asked for.
     */
    public function test_opening_a_message_marks_it_read_at_the_service_when_switched_on(): void
    {
        $this->withFakeProvider();

        $t       = $this->provisionTenant('A1-Group');
        $mailbox = $this->inbox($t);

        $this->inTenant($t['org'], fn () => $mailbox->update(['mark_read_at_source' => true]));

        FakeMailboxReader::$detail = new MessageDetail(
            summary: $this->summary(),
            blocks: (new BodySanitiser)->sanitise('<p>A promotion.</p>'),
        );

        $this->signedInAs($t['admin'])
            ->getJson("/api/v1/email/mailboxes/{$mailbox->id}/messages/m-1")
            ->assertOk();

        $this->assertSame(['m-1'], FakeMailboxReader::$markedAtSource);
    }

    /**
     * The mark runs AFTER the response, when the request's tenant has been
     * cleared. Production, 2026-10-09: "No tenant in context, refusing to read
     * App\Models\GoogleWorkspaceConnection unscoped" on every Workspace open.
     */
    public function test_the_deferred_mark_runs_inside_the_mailboxs_tenant(): void
    {
        $this->withFakeProvider();

        $t       = $this->provisionTenant('A1-Group');
        $mailbox = $this->inbox($t);

        $this->inTenant($t['org'], fn () => $mailbox->update(['mark_read_at_source' => true]));

        FakeMailboxReader::$tenantWhenAsked = null;
        FakeMailboxReader::$detail = new MessageDetail(
            summary: $this->summary(),
            blocks: (new BodySanitiser)->sanitise('<p>Hello.</p>'),
        );

        $this->signedInAs($t['admin'])
            ->getJson("/api/v1/email/mailboxes/{$mailbox->id}/messages/m-1")
            ->assertOk();

        $this->assertSame($t['org']->id, FakeMailboxReader::$tenantWhenAsked);
    }

    // ══ Design 137: one inbox across inboxes ═══════════════════════

    private function sameMail(string $id): MessageSummary
    {
        return new MessageSummary(
            id: $id, threadId: 't-'.$id, subject: 'Quote', fromName: 'Bob Smith', fromAddress: 'bob@example.com',
            to: ['admin@a-1chimney.com', 'info@a-1chimney.com'], sentAt: Carbon::parse('2026-10-22 09:00:00'),
            snippet: 'Two flues', unreadAtProvider: true, rfcId: '<one@example.com>', labels: ['INBOX', 'STARRED'],
        );
    }

    /** The same mail at admin@ and info@ is one row naming both (Jose: it showed twice). */
    public function test_the_inbox_merges_the_same_mail_across_inboxes_and_names_the_customer(): void
    {
        $this->withFakeProvider();
        $t = $this->provisionTenant('A1-Group');
        $this->inbox($t, 'info@a-1chimney.com');
        $this->inbox($t, 'admin@a-1chimney.com');
        $this->inTenant($t['org'], fn () => \App\Models\Customer::factory()->create([
            'owning_franchise_id' => $t['franchise']->id, 'name' => 'Bob Smith', 'email' => 'bob@example.com',
        ]));

        FakeMailboxReader::$page = new MessagePage([$this->sameMail('m-1')]);

        $rows = $this->signedInAs($t['admin'])->getJson('/api/v1/email/inbox')->assertOk()->json('data');

        $this->assertCount(1, $rows, 'The same mail showed once per inbox.');
        $this->assertEqualsCanonicalizing(['admin@a-1chimney.com', 'info@a-1chimney.com'], array_column($rows[0]['inboxes'], 'address'));
        $this->assertTrue($rows[0]['starred']);
        $this->assertSame('people', $rows[0]['category']);
        $this->assertSame('customer', $rows[0]['customer']['type']);
    }

    /** Handled travels on inbox rows and the open message; unread per inbox comes from the cache. */
    public function test_inbox_rows_and_detail_carry_dealt_and_inboxes_carry_unread(): void
    {
        $this->withFakeProvider();
        $t    = $this->provisionTenant('A1-Group');
        $info = $this->inbox($t);
        FakeMailboxReader::$page   = new MessagePage([$this->sameMail('m-1')]);
        FakeMailboxReader::$detail = new MessageDetail(summary: $this->summary('m-1'), blocks: []);

        $this->signedInAs($t['admin'])->postJson("/api/v1/email/mailboxes/{$info->id}/messages/m-1/dealt")->assertOk();

        $this->signedInAs($t['admin'])->getJson('/api/v1/email/inbox')
            ->assertJsonPath('data.0.dealt.by', $t['admin']->name)
            ->assertJsonPath('data.0.inboxes.0.dealt.by', $t['admin']->name);
        $this->signedInAs($t['admin'])->getJson("/api/v1/email/mailboxes/{$info->id}/messages/m-1")
            ->assertJsonPath('data.dealt.by', $t['admin']->name);

        \Illuminate\Support\Facades\Cache::forever('dashboard.email.unread.'.$info->id, ['n' => 7, 'at' => now()->getTimestamp()]);
        $this->signedInAs($t['admin'])->getJson('/api/v1/email/mailboxes')->assertJsonPath('data.0.unread', 7);
    }

    /** A tab reads on through Gmail's pages until it has a page's worth (Jose: inboxes looked empty). */
    public function test_a_tab_reads_further_pages_until_it_has_mail(): void
    {
        $this->withFakeProvider();
        $t = $this->provisionTenant('A1-Group');
        $this->inbox($t);

        $mail = fn (string $id, bool $bulk) => new MessageSummary(
            id: $id, threadId: 't-'.$id, subject: $id, fromName: 'X', fromAddress: 'x@example.com',
            to: [], sentAt: Carbon::parse('2026-10-22 09:00:00'), snippet: '', isBulk: $bulk,
        );
        FakeMailboxReader::$pages = [
            new MessagePage([$mail('n1', true), $mail('p1', false)], 'p1'),
            new MessagePage([$mail('n2', true), $mail('p2', false)], 'p2'),
            new MessagePage([$mail('p3', false)]),
        ];

        $rows = $this->signedInAs($t['admin'])->getJson('/api/v1/email/inbox?category=primary')->assertOk()->json('data');

        $this->assertSame(['p1', 'p2', 'p3'], collect($rows)->pluck('subject')->sort()->values()->all());
        $this->assertSame([null, 'p1', 'p2'], FakeMailboxReader::$listedCursors);

        // Without a tab, one page as before.
        FakeMailboxReader::$listedCursors = [];
        $this->signedInAs($t['admin'])->getJson('/api/v1/email/inbox')->assertOk();
        $this->assertSame([null], FakeMailboxReader::$listedCursors);
    }

    public function test_inbox_actions_star_and_refuse_an_inbox_the_person_cannot_open(): void
    {
        $this->withFakeProvider();
        $t    = $this->provisionTenant('A1-Group');
        $info = $this->inbox($t, 'info@a-1chimney.com');

        $results = $this->signedInAs($t['admin'])->postJson('/api/v1/email/inbox/actions', [
            'action' => 'star',
            'items'  => [['mailbox_id' => $info->id, 'message_id' => 'm-1'], ['mailbox_id' => 'not-mine', 'message_id' => 'm-2']],
        ])->assertOk()->json('data.results');

        $this->assertTrue($results[0]['ok']);
        $this->assertFalse($results[1]['ok']);
        $this->assertSame([['info@a-1chimney.com m-1', ['STARRED'], []]], FakeMailboxReader::$modified);
    }

    /** Snooze archives at Gmail, lists under Snoozed, and the minute job puts it back. */
    public function test_snooze_puts_mail_away_and_wakes_it_when_due(): void
    {
        $this->withFakeProvider();
        $t    = $this->provisionTenant('A1-Group');
        $info = $this->inbox($t, 'info@a-1chimney.com');
        FakeMailboxReader::$page = new MessagePage([$this->summary('m-1')]);

        $this->signedInAs($t['admin'])->postJson('/api/v1/email/inbox/actions', [
            'action' => 'snooze', 'until' => now()->addHour()->toIso8601String(),
            'items'  => [['mailbox_id' => $info->id, 'message_id' => 'm-1']],
        ])->assertOk()->assertJsonPath('data.results.0.ok', true);

        $this->assertSame([['info@a-1chimney.com m-1', [], ['INBOX']]], FakeMailboxReader::$modified);
        $this->signedInAs($t['admin'])->getJson('/api/v1/email/inbox?folder=snoozed')
            ->assertOk()->assertJsonPath('data.0.subject', 'Quote for a sweep')->assertJsonPath('meta.counts.snoozed', 1);

        $this->travel(2)->hours();
        $this->artisan('ezbizi:wake-snoozed-mail')->assertSuccessful();

        $this->assertSame(['info@a-1chimney.com m-1', ['INBOX'], []], FakeMailboxReader::$modified[1]);
        $this->assertSame(0, $this->inTenant($t['org'], fn () => \App\Models\EmailSnooze::query()->count()));
    }

    /** Forward: the note, then the original as text, sent from the inbox. */
    public function test_forward_sends_the_original_under_the_note(): void
    {
        $this->withFakeProvider();
        $t    = $this->provisionTenant('A1-Group');
        $info = $this->inbox($t);
        FakeMailboxReader::$detail = new MessageDetail(
            summary: $this->summary(),
            blocks: (new BodySanitiser)->sanitise('<p>Please quote two flues.</p>'),
        );

        $this->signedInAs($t['admin'])
            ->postJson("/api/v1/email/mailboxes/{$info->id}/messages/m-1/forward", ['to' => ['tech@a-1chimney.com'], 'body' => 'Can you take this?'])
            ->assertOk();

        $sent = FakeMailboxReader::$sent[0];
        $this->assertSame(['tech@a-1chimney.com'], $sent['to']);
        $this->assertSame('Fwd: Quote for a sweep', $sent['subject']);
        $this->assertStringStartsWith('Can you take this?', $sent['body']);
        $this->assertStringContainsString('Please quote two flues.', $sent['body']);
        $this->assertStringContainsString('From: Bob Smith <bob@example.com>', $sent['body']);
    }

    /** Copies on a provider without them are refused in words, not silently dropped. */
    public function test_reply_with_copies_is_refused_where_the_provider_has_none(): void
    {
        $this->withFakeProvider();
        $t    = $this->provisionTenant('A1-Group');
        $info = $this->inbox($t);

        $this->signedInAs($t['admin'])
            ->postJson("/api/v1/email/mailboxes/{$info->id}/messages/m-1/reply", ['body' => 'Hi', 'all' => true])
            ->assertStatus(422);
        $this->assertSame([], FakeMailboxReader::$replies);
    }

    public function test_the_mark_as_read_button_also_reaches_the_service(): void
    {
        $this->withFakeProvider();

        $t       = $this->provisionTenant('A1-Group');
        $mailbox = $this->inbox($t);

        $this->inTenant($t['org'], fn () => $mailbox->update(['mark_read_at_source' => true]));

        $this->signedInAs($t['admin'])
            ->postJson("/api/v1/email/mailboxes/{$mailbox->id}/messages/m-1/seen")
            ->assertOk()
            ->assertJsonPath('data.at_source', true);

        $this->assertSame(['m-1'], FakeMailboxReader::$markedAtSource);
    }

    /**
     * THE LOCAL MARK SURVIVES A PROVIDER THAT REFUSES.
     *
     * The local mark is what the person asked for and it is already written.
     * Losing it because a secondary effect failed would be the request undoing
     * itself over something nobody requested — so the outcome is REPORTED
     * rather than thrown, and the degradation is to exactly how every mailbox
     * behaved before this existed.
     */
    public function test_a_refusal_at_the_service_does_not_lose_the_local_mark(): void
    {
        $this->withFakeProvider();

        $t       = $this->provisionTenant('A1-Group');
        $mailbox = $this->inbox($t);

        $this->inTenant($t['org'], fn () => $mailbox->update(['mark_read_at_source' => true]));

        FakeMailboxReader::$sourceRefuses = true;

        $this->signedInAs($t['admin'])
            ->postJson("/api/v1/email/mailboxes/{$mailbox->id}/messages/m-1/seen")
            ->assertOk()
            // Said, not swallowed: false is "tried and refused", which is what
            // stops a switch that is quietly not working from looking like one
            // that is.
            ->assertJsonPath('data.at_source', false)
            ->assertJsonPath('data.seen_by', fn ($seen) => $seen !== []);

        // And the local row is really there, not merely echoed back.
        $this->inTenant($t['org'], function () use ($mailbox) {
            $this->assertSame(1, MessageSeen::where('mailbox_id', $mailbox->getKey())->count());
        });
    }

    /** Null, not false: not asked for is not the same as tried and refused. */
    public function test_at_source_is_null_when_the_mailbox_is_switched_off(): void
    {
        $this->withFakeProvider();

        $t       = $this->provisionTenant('A1-Group');
        $mailbox = $this->inbox($t);
        $this->inTenant($t['org'], fn () => $mailbox->update(['mark_read_at_source' => false]));

        $this->signedInAs($t['admin'])
            ->postJson("/api/v1/email/mailboxes/{$mailbox->id}/messages/m-1/seen")
            ->assertOk()
            ->assertJsonPath('data.at_source', null);
    }

    /**
     * THE DEFAULT, and the case the bug lived in: a mailbox nobody has touched
     * now carries reading across to the mail service.
     *
     * With the old opt-in default the list drew its bold from Gmail's flag
     * while opening only changed EzBizi's record, so every opened message went
     * back to unread on the next refresh. Every production mailbox was there.
     */
    public function test_a_mailbox_nobody_touched_syncs_reading_by_default(): void
    {
        $this->withFakeProvider();

        $t       = $this->provisionTenant('A1-Group');
        $mailbox = $this->inbox($t);

        FakeMailboxReader::$detail = new MessageDetail(
            summary: $this->summary(),
            blocks: (new BodySanitiser)->sanitise('<p>A quote request.</p>'),
        );

        $this->signedInAs($t['admin'])
            ->getJson("/api/v1/email/mailboxes/{$mailbox->id}/messages/m-1")
            ->assertOk();

        $this->assertSame(['m-1'], FakeMailboxReader::$markedAtSource);
    }

    /**
     * Jose: "Add mark as unread to emails". The button existed; it changed
     * only EzBizi's record, so the message went straight back to read.
     */
    public function test_marking_unread_reaches_the_mail_service(): void
    {
        $this->withFakeProvider();

        $t       = $this->provisionTenant('A1-Group');
        $mailbox = $this->inbox($t);

        $this->signedInAs($t['admin'])
            ->deleteJson("/api/v1/email/mailboxes/{$mailbox->id}/messages/m-1/seen")
            ->assertOk()
            ->assertJsonPath('data.at_source', true);

        $this->assertSame(['m-1'], FakeMailboxReader::$unmarkedAtSource);
        $this->assertSame([], FakeMailboxReader::$markedAtSource, 'Unread must not mark it read.');
    }

    /** The ticked-row buttons are the same two actions, carried the same way. */
    public function test_bulk_read_and_unread_reach_the_mail_service(): void
    {
        $this->withFakeProvider();

        $t       = $this->provisionTenant('A1-Group');
        $mailbox = $this->inbox($t);

        $this->signedInAs($t['admin'])
            ->postJson("/api/v1/email/mailboxes/{$mailbox->id}/messages/bulk", [
                'action' => 'unseen', 'ids' => ['m-1', 'm-2'],
            ])->assertOk();

        $this->signedInAs($t['admin'])
            ->postJson("/api/v1/email/mailboxes/{$mailbox->id}/messages/bulk", [
                'action' => 'seen', 'ids' => ['m-3'],
            ])->assertOk();

        $this->assertSame(['m-1', 'm-2'], FakeMailboxReader::$unmarkedAtSource);
        $this->assertSame(['m-3'], FakeMailboxReader::$markedAtSource);
    }

    /**
     * THE BOUNCE: a change at the service clears the cached list.
     *
     * The listing is cached briefly and only trash used to clear it, so after
     * marking a message read or unread the next list came from the cache --
     * still drawing the service's OLD flag. Without this, syncing would work
     * and still look broken for the length of the cache.
     */
    public function test_a_read_state_change_clears_the_cached_list(): void
    {
        $this->withFakeProvider();

        $t       = $this->provisionTenant('A1-Group');
        $mailbox = $this->inbox($t);

        FakeMailboxReader::$page = new MessagePage([$this->summary()]);
        $list = fn () => $this->signedInAs($t['admin'])
            ->getJson("/api/v1/email/mailboxes/{$mailbox->id}/messages")
            ->assertOk()->json('data.from_cache');

        $list();
        $this->assertTrue($list(), 'Control: the second list should come from the cache.');

        $this->signedInAs($t['admin'])
            ->deleteJson("/api/v1/email/mailboxes/{$mailbox->id}/messages/m-1/seen")
            ->assertOk();

        $this->assertFalse($list(), 'The list still came from the cache after a read-state change.');
    }

    /**
     * A connection that cannot write read state is not asked. Null, not false:
     * Outlook and IMAP are read-only grants by design, and calling them would
     * be a refused request on every open of every such inbox.
     */
    public function test_a_read_only_connection_is_not_asked(): void
    {
        $this->withFakeProvider();
        FakeMailboxReader::$canMarkAtSource = false;

        $t       = $this->provisionTenant('A1-Group');
        $mailbox = $this->inbox($t);

        $this->signedInAs($t['admin'])
            ->postJson("/api/v1/email/mailboxes/{$mailbox->id}/messages/m-1/seen")
            ->assertOk()
            ->assertJsonPath('data.at_source', null);

        $this->assertSame([], FakeMailboxReader::$markedAtSource);
    }

    /**
     * The list row's thread reaches the reader -- the wire the one-fetch open
     * depends on. A controller that dropped the query parameter would leave
     * GmailOpenInOneFetchTest green and every real open at two fetches.
     */
    public function test_opening_passes_the_rows_thread_to_the_reader(): void
    {
        $this->withFakeProvider();

        $t       = $this->provisionTenant('A1-Group');
        $mailbox = $this->inbox($t);

        $this->signedInAs($t['admin'])
            ->getJson("/api/v1/email/mailboxes/{$mailbox->id}/messages/m-1?thread=t-9")
            ->assertOk();

        $this->assertSame('t-9', FakeMailboxReader::$openedThread);

        $this->signedInAs($t['admin'])
            ->getJson("/api/v1/email/mailboxes/{$mailbox->id}/messages/m-1")
            ->assertOk();

        $this->assertNull(FakeMailboxReader::$openedThread, 'No hint must reach the reader as no hint.');
    }

    // ══ 2b. AN INBOX GIVEN TO A PERSON (2026-10-06) ══════════════

    /*
     * Jose: "I also want to be able to assign emails to specific people,
     * instead of just roles -- maybe done via the email settings tab".
     */

    private const INBOX_TAB = ['email.view', 'email.inbox.view'];

    /**
     * THE FEATURE, with its control in the same test: two people on the same
     * role, the inbox given to ONE of them. If person grants did nothing, the
     * first assertion fails; if they leaked to the whole role, the second does.
     */
    public function test_a_person_given_an_inbox_opens_it_and_a_colleague_on_the_same_role_does_not(): void
    {
        $this->withFakeProvider();
        $t = $this->provisionTenant('A1-Group');
        $mailbox = $this->inbox($t);
        FakeMailboxReader::$page = new MessagePage([$this->summary()]);

        $ana = $this->userWithRole($t, 'Dispatcher', self::INBOX_TAB, email: 'ana@a1.test');
        $bob = $this->userWithRole($t, 'Dispatcher too', self::INBOX_TAB, email: 'bob@a1.test');

        // ON ANA'S ROLE, so the only difference between them is the grant.
        $this->inTenant($t['org'], fn () => $bob->forceFill(['role_id' => $ana->role_id])->save());

        $this->signedInAs($t['admin'])
            ->postJson("/api/v1/email/mailboxes/{$mailbox->id}/people", ['user_id' => $ana->id])
            ->assertOk()
            ->assertJsonPath('data.people.0.id', $ana->id);

        $this->signedInAs($ana)->getJson("/api/v1/email/mailboxes/{$mailbox->id}/messages")->assertOk();
        $this->signedInAs($bob)->getJson("/api/v1/email/mailboxes/{$mailbox->id}/messages")->assertForbidden();
    }

    /**
     * A grant is a key to one door, not a way round the others: a person whose
     * role lacks the Inbox tab is not let in by a person grant. Written straight
     * to the table, because the endpoint refuses to create it at all.
     */
    public function test_a_person_grant_does_not_get_round_the_inbox_tab(): void
    {
        $this->withFakeProvider();
        $t = $this->provisionTenant('A1-Group');
        $mailbox = $this->inbox($t);
        FakeMailboxReader::$page = new MessagePage([$this->summary()]);

        $carl = $this->userWithRole($t, 'Driver', ['email.view'], email: 'carl@a1.test');
        $this->inTenant($t['org'], fn () => \App\Models\MailboxGrant::create([
            'organization_id' => $t['org']->id, 'mailbox_id' => $mailbox->id, 'user_id' => $carl->id,
        ]));

        $this->signedInAs($carl)->getJson("/api/v1/email/mailboxes/{$mailbox->id}/messages")->assertForbidden();

        // And the endpoint says why rather than writing a grant that does nothing.
        $this->inTenant($t['org'], fn () => \App\Models\MailboxGrant::query()->delete());
        $this->signedInAs($t['admin'])
            ->postJson("/api/v1/email/mailboxes/{$mailbox->id}/people", ['user_id' => $carl->id])
            ->assertStatus(422)
            ->assertJsonPath('message', fn (string $m) => str_contains($m, 'Email Inbox tab'));
    }

    /** Reach still applies: a branch user cannot be given a company inbox above them. */
    public function test_a_person_out_of_reach_is_refused_when_given(): void
    {
        $this->withFakeProvider();
        $t = $this->provisionTenant('A1-Group');
        $company = $this->inbox($t, 'info@a-1chimney.com', 'company', $t['company']->id);
        $jenny = $this->userWithRole($t, 'Branch Manager', self::INBOX_TAB, onlyAt: $t['franchise']);

        $this->signedInAs($t['admin'])
            ->postJson("/api/v1/email/mailboxes/{$company->id}/people", ['user_id' => $jenny->id])
            ->assertStatus(422)
            ->assertJsonPath('message', fn (string $m) => str_contains($m, 'not assigned to'));

        $this->assertSame([], $this->inTenant($t['org'], fn () => \App\Models\MailboxGrant::query()->pluck('id')->all()));
    }

    /**
     * The picker offers exactly the people who could open it once added -- not
     * the Administrator, not someone without the Inbox tab, not someone already
     * on it -- and lists the roles that hold it alongside.
     */
    public function test_the_people_list_offers_only_those_who_could_open_it(): void
    {
        $this->withFakeProvider();
        $t = $this->provisionTenant('A1-Group');
        $mailbox = $this->inbox($t);

        $ana = $this->userWithRole($t, 'Dispatcher', self::INBOX_TAB, email: 'ana@a1.test');
        $dee = $this->userWithRole($t, 'Office', self::INBOX_TAB, email: 'dee@a1.test');
        $carl = $this->userWithRole($t, 'Driver', ['email.view'], email: 'carl@a1.test');
        $this->grant($t, $mailbox, 'Office');

        $this->signedInAs($t['admin'])
            ->postJson("/api/v1/email/mailboxes/{$mailbox->id}/people", ['user_id' => $ana->id])->assertOk();

        $data = $this->signedInAs($t['admin'])
            ->getJson("/api/v1/email/mailboxes/{$mailbox->id}/people")
            ->assertOk()->json('data');

        $candidates = array_column($data['candidates'], 'id');

        $this->assertSame([$ana->id], array_column($data['people'], 'id'));
        $this->assertContains('Office', array_column($data['roles'], 'name'));
        $this->assertContains($dee->id, $candidates);
        $this->assertNotContains($ana->id, $candidates, 'Someone already on it was offered again.');
        $this->assertNotContains($carl->id, $candidates, 'Someone without the Inbox tab was offered.');
        $this->assertNotContains($t['admin']->id, $candidates, 'The Administrator was offered.');
    }

    public function test_taking_an_inbox_away_closes_it(): void
    {
        $this->withFakeProvider();
        $t = $this->provisionTenant('A1-Group');
        $mailbox = $this->inbox($t);
        FakeMailboxReader::$page = new MessagePage([$this->summary()]);
        $ana = $this->userWithRole($t, 'Dispatcher', self::INBOX_TAB, email: 'ana@a1.test');

        $this->signedInAs($t['admin'])
            ->postJson("/api/v1/email/mailboxes/{$mailbox->id}/people", ['user_id' => $ana->id])->assertOk();
        $this->signedInAs($ana)->getJson("/api/v1/email/mailboxes/{$mailbox->id}/messages")->assertOk();

        $this->signedInAs($t['admin'])
            ->deleteJson("/api/v1/email/mailboxes/{$mailbox->id}/people/{$ana->id}")
            ->assertOk()
            ->assertJsonPath('data.people', []);

        $this->signedInAs($ana)->getJson("/api/v1/email/mailboxes/{$mailbox->id}/messages")->assertForbidden();
    }

    /** Choosing who reads an inbox is managing it: the Settings tab alone is not enough. */
    public function test_choosing_people_needs_the_authority_to_manage_the_inbox(): void
    {
        $this->withFakeProvider();
        $t = $this->provisionTenant('A1-Group');
        $mailbox = $this->inbox($t);
        $ana = $this->userWithRole($t, 'Dispatcher', self::INBOX_TAB, email: 'ana@a1.test');
        $viewer = $this->userWithRole($t, 'Viewer', ['email.view', 'email.settings.view'], email: 'v@a1.test');

        $this->signedInAs($viewer)
            ->postJson("/api/v1/email/mailboxes/{$mailbox->id}/people", ['user_id' => $ana->id])
            ->assertForbidden();

        $this->assertSame([], $this->inTenant($t['org'], fn () => \App\Models\MailboxGrant::query()->pluck('id')->all()));
    }

    /**
     * THE TWO-WRITERS HAZARD. The roles editor rewrites a role's inboxes from a
     * list; if it ever touched person rows, saving a role would silently strip
     * people of inboxes they were given in Email settings.
     */
    public function test_saving_a_roles_inboxes_leaves_person_grants_alone(): void
    {
        $this->withFakeProvider();
        $t = $this->provisionTenant('A1-Group');
        $mailbox = $this->inbox($t);
        $ana = $this->userWithRole($t, 'Dispatcher', self::INBOX_TAB, email: 'ana@a1.test');

        $this->signedInAs($t['admin'])
            ->postJson("/api/v1/email/mailboxes/{$mailbox->id}/people", ['user_id' => $ana->id])->assertOk();

        // Ana's own role saved with NO inboxes, then with this one, then without.
        $this->inTenant($t['org'], function () use ($ana, $mailbox) {
            $inboxes = app(\App\Support\Access\InboxAccess::class);
            $inboxes->sync($ana->role, []);
            $inboxes->sync($ana->role, [$mailbox->id]);
            $inboxes->sync($ana->role, []);
        });

        $this->assertTrue(
            $this->inTenant($t['org'], fn () => \App\Models\MailboxGrant::query()
                ->where('mailbox_id', $mailbox->id)->where('user_id', $ana->id)->exists()),
            'Saving a role removed a person grant.',
        );
    }

    public function test_a_person_from_another_organization_cannot_be_given_it(): void
    {
        $this->withFakeProvider();
        $t = $this->provisionTenant('A1-Group');
        $other = $this->provisionTenant('Premier Garage');
        $mailbox = $this->inbox($t);
        $stranger = $this->userWithRole($other, 'Dispatcher', self::INBOX_TAB, email: 'x@other.test');

        $this->signedInAs($t['admin'])
            ->postJson("/api/v1/email/mailboxes/{$mailbox->id}/people", ['user_id' => $stranger->id])
            ->assertStatus(422);
    }

    // ══ 4b. STARTING A CONVERSATION ══════════════════════════════

    public function test_a_composed_message_reaches_the_provider_whole(): void
    {
        $this->withFakeProvider();

        $t = $this->provisionTenant('A1-Group');

        $mailbox = $this->inbox($t);
        $jenny   = $this->userWithRole($t, 'Front desk', ['email.view', 'email.inbox.view', 'email.settings.view'], email: 'jenny@a1.test');

        $this->grant($t, $mailbox, 'Front desk');

        $this->signedInAs($jenny)
            ->postJson("/api/v1/email/mailboxes/{$mailbox->id}/messages", [
                'to'      => ['bob@example.com', 'jan@example.com'],
                'subject' => 'Your annual sweep',
                'body'    => 'Morning both',
            ])
            ->assertOk()
            ->assertJsonPath('message', fn (string $m) => str_contains($m, 'info@a-1chimney.com'));

        $this->assertCount(1, FakeMailboxReader::$sent);

        // Both recipients, not just the first. A To field that quietly
        // delivered to one address would look like success on both ends.
        $this->assertSame(['bob@example.com', 'jan@example.com'], FakeMailboxReader::$sent[0]['to']);
        $this->assertSame('Your annual sweep', FakeMailboxReader::$sent[0]['subject']);
        $this->assertSame('Morning both', FakeMailboxReader::$sent[0]['body']);
    }

    public function test_composing_is_audited_without_the_words(): void
    {
        $this->withFakeProvider();

        $t = $this->provisionTenant('A1-Group');

        $mailbox = $this->inbox($t);
        $jenny   = $this->userWithRole($t, 'Front desk', ['email.view', 'email.inbox.view', 'email.settings.view'], email: 'jenny@a1.test');

        $this->grant($t, $mailbox, 'Front desk');

        $body = 'We can do Tuesday at 180.';

        $this->signedInAs($jenny)
            ->postJson("/api/v1/email/mailboxes/{$mailbox->id}/messages", [
                'to'      => ['bob@example.com'],
                'subject' => 'Quote',
                'body'    => $body,
            ])
            ->assertOk();

        $event = AuditEvent::where('action', 'mailbox.sent')->firstOrFail();

        $this->assertStringContainsString('jenny@a1.test', $event->actor_label);
        $this->assertStringNotContainsString('180', json_encode($event->changes));
        $this->assertSame(strlen($body), $event->changes['characters']);
        $this->assertSame(1, $event->changes['recipients']);
    }

    /**
     * The recipient ADDRESSES stay out of the automation payload.
     *
     * Rules are tenant-authored and read this table, so carrying the addresses
     * here would quietly make every rule author a reader of the mailing list.
     * The count is what a rule can act on without that.
     */
    public function test_composing_raises_the_automation_event_without_the_addresses(): void
    {
        $this->withFakeProvider();

        $t = $this->provisionTenant('A1-Group');

        $mailbox = $this->inbox($t);
        $jenny   = $this->userWithRole($t, 'Front desk', ['email.view', 'email.inbox.view', 'email.settings.view'], email: 'jenny@a1.test');

        $this->grant($t, $mailbox, 'Front desk');

        $this->signedInAs($jenny)
            ->postJson("/api/v1/email/mailboxes/{$mailbox->id}/messages", [
                'to'      => ['bob@example.com'],
                'subject' => 'Quote',
                'body'    => 'On our way.',
            ])
            ->assertOk();

        $this->inTenant($t['org'], function () {
            $event = AutomationEvent::where('event_key', 'email.sent')->firstOrFail();

            $this->assertSame('info@a-1chimney.com', $event->payload['address']);
            $this->assertSame(1, $event->payload['recipients']);
            $this->assertStringNotContainsString('bob@example.com', json_encode($event->payload));
            $this->assertStringContainsString('jenny@a1.test', $event->actor_label);
        });
    }

    public function test_somebody_who_cannot_open_an_inbox_cannot_send_from_it(): void
    {
        $this->withFakeProvider();

        $t = $this->provisionTenant('A1-Group');

        $mailbox = $this->inbox($t);
        $clerk   = $this->userWithRole($t, 'Clerk', ['email.view', 'email.inbox.view', 'email.settings.view']);

        $this->signedInAs($clerk)
            ->postJson("/api/v1/email/mailboxes/{$mailbox->id}/messages", [
                'to'      => ['bob@example.com'],
                'subject' => 'Hello',
                'body'    => 'Hello',
            ])
            ->assertForbidden();

        $this->assertSame([], FakeMailboxReader::$sent);
    }

    public function test_a_recipient_that_is_not_an_address_is_refused_before_anything_is_sent(): void
    {
        $this->withFakeProvider();

        $t = $this->provisionTenant('A1-Group');

        $mailbox = $this->inbox($t);
        $jenny   = $this->userWithRole($t, 'Front desk', ['email.view', 'email.inbox.view', 'email.settings.view'], email: 'jenny@a1.test');

        $this->grant($t, $mailbox, 'Front desk');

        $this->signedInAs($jenny)
            ->postJson("/api/v1/email/mailboxes/{$mailbox->id}/messages", [
                'to'      => ["bob@example.com\r\nBcc: everyone@competitor.example"],
                'subject' => 'Quote',
                'body'    => 'Hello',
            ])
            ->assertStatus(422);

        // Nothing left the building. The composer would have neutralised it
        // anyway — this is the outer of the two, and both are wanted.
        $this->assertSame([], FakeMailboxReader::$sent);
    }

    public function test_a_file_reaches_the_provider_as_name_type_and_bytes(): void
    {
        $this->withFakeProvider();

        $t = $this->provisionTenant('A1-Group');

        $mailbox = $this->inbox($t);
        $jenny   = $this->userWithRole($t, 'Front desk', ['email.view', 'email.inbox.view', 'email.settings.view'], email: 'jenny@a1.test');

        $this->grant($t, $mailbox, 'Front desk');

        $this->signedInAs($jenny)
            ->post("/api/v1/email/mailboxes/{$mailbox->id}/messages", [
                'to'          => ['bob@example.com'],
                'subject'     => 'Your report',
                'body'        => 'Attached.',
                // createWithContent, not create: `create()` fakes the SIZE and
                // writes an empty file, so the controller reads zero bytes and
                // the test proves nothing about what reaches the provider.
                'attachments' => [
                    UploadedFile::fake()->createWithContent('sweep.pdf', str_repeat('P', 4096)),
                ],
            ])
            ->assertOk();

        $sent = FakeMailboxReader::$sent[0];

        $this->assertCount(1, $sent['attachments']);
        $this->assertSame('sweep.pdf', $sent['attachments'][0]['name']);

        // The BYTES, not a path or a handle — nothing was written down for the
        // reader to go back and fetch.
        $this->assertSame(str_repeat('P', 4096), $sent['attachments'][0]['bytes']);
    }

    /**
     * Per-file validation cannot express "these, added up".
     *
     * Five files of 2MB are each individually valid and together unsendable, so
     * the total is checked separately and refused with a sentence about the
     * total rather than about one file.
     */
    public function test_files_that_are_too_large_together_are_refused(): void
    {
        $this->withFakeProvider();

        $t = $this->provisionTenant('A1-Group');

        $mailbox = $this->inbox($t);
        $jenny   = $this->userWithRole($t, 'Front desk', ['email.view', 'email.inbox.view', 'email.settings.view'], email: 'jenny@a1.test');

        $this->grant($t, $mailbox, 'Front desk');

        $this->signedInAs($jenny)
            ->post("/api/v1/email/mailboxes/{$mailbox->id}/messages", [
                'to'          => ['bob@example.com'],
                'subject'     => 'Everything',
                'body'        => 'All of it.',
                'attachments' => [
                    UploadedFile::fake()->createWithContent('a.pdf', str_repeat('a', 900 * 1024)),
                    UploadedFile::fake()->createWithContent('b.pdf', str_repeat('b', 900 * 1024)),
                    UploadedFile::fake()->createWithContent('c.pdf', str_repeat('c', 900 * 1024)),
                ],
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', fn (string $m) => str_contains($m, '2 MB'));

        // Nothing was sent — not a truncated message with two of the three.
        $this->assertSame([], FakeMailboxReader::$sent);
    }

    public function test_a_message_with_no_recipient_is_refused(): void
    {
        $this->withFakeProvider();

        $t = $this->provisionTenant('A1-Group');

        $mailbox = $this->inbox($t);
        $jenny   = $this->userWithRole($t, 'Front desk', ['email.view', 'email.inbox.view', 'email.settings.view'], email: 'jenny@a1.test');

        $this->grant($t, $mailbox, 'Front desk');

        $this->signedInAs($jenny)
            ->postJson("/api/v1/email/mailboxes/{$mailbox->id}/messages", [
                'to'      => [],
                'subject' => 'Quote',
                'body'    => 'Hello',
            ])
            ->assertStatus(422);

        $this->assertSame([], FakeMailboxReader::$sent);
    }

    // ══ 5. THE BODY IS BLOCKS ════════════════════════════════════

    /**
     * No route in this application returns email HTML.
     *
     * The fake reader is handed a genuinely hostile body and made to run it
     * through the real sanitiser, so this covers the whole path from provider to
     * wire rather than only the sanitiser in isolation.
     */
    public function test_opening_a_message_answers_blocks_and_never_markup(): void
    {
        $this->withFakeProvider();

        $t = $this->provisionTenant('A1-Group');

        $mailbox = $this->inbox($t);
        $clerk   = $this->userWithRole($t, 'Clerk', ['email.view', 'email.inbox.view', 'email.settings.view']);

        $this->grant($t, $mailbox, 'Clerk');

        FakeMailboxReader::$detail = new MessageDetail(
            summary: $this->summary(),
            blocks: (new BodySanitiser)->sanitise(
                '<p>Can you quote?</p><script>alert(1)</script>'
                .'<a href="javascript:alert(2)">here</a>'
                .'<img src="http://169.254.169.254/latest/meta-data/">'
            ),
            imagesBlocked: true,
        );

        $body = $this->signedInAs($clerk)
            ->getJson("/api/v1/email/mailboxes/{$mailbox->id}/messages/m-1")
            ->assertOk()->json('data');

        // The control — the message really is there.
        $this->assertStringContainsString('Can you quote?', json_encode($body['blocks']));

        $wire = json_encode($body);

        $this->assertStringNotContainsString('<script', $wire);
        $this->assertStringNotContainsString('alert', $wire);
        $this->assertStringNotContainsString('169.254.169.254', $wire);

        // And the reader is told why the pictures are missing, in words.
        $this->assertTrue($body['images_blocked']);
        $this->assertStringContainsString('could not be shown', $body['images_blocked_reason']);

        $kinds = array_column($body['blocks'], 'kind');

        $this->assertContains(BlockKind::BlockedImage->value, $kinds);

        // The address a reply goes out as is the inbox, stated on the
        // payload so the client does not have to remember which mailbox
        // it selected.
        $this->assertSame('info@a-1chimney.com', $body['reply']['as']['address']);
        $this->assertSame('Info', $body['reply']['as']['name']);
        $this->assertSame('Re: Quote for a sweep', $body['reply']['subject']);
    }

    public function test_html_mail_keeps_its_layout_and_drops_script(): void
    {
        $this->withFakeProvider();

        $t = $this->provisionTenant('A1-Group');
        $mailbox = $this->inbox($t);
        $clerk = $this->userWithRole($t, 'Clerk', ['email.view', 'email.inbox.view', 'email.settings.view']);
        $this->grant($t, $mailbox, 'Clerk');

        FakeMailboxReader::$detail = new MessageDetail(
            summary: $this->summary(),
            blocks: (new BodySanitiser)->sanitise('<p>Hello</p>'),
            sourceHtml: '<p>Hello</p><script>alert(1)</script>'
                .'<table width="600"><tr><td>Cell</td></tr></table>',
        );

        $body = $this->signedInAs($clerk)
            ->getJson("/api/v1/email/mailboxes/{$mailbox->id}/messages/m-1")
            ->assertOk()->json('data');

        $html = $body['html'] ?? '';

        $this->assertStringContainsString('<table', $html);
        $this->assertStringContainsString('Cell', $html);
        $this->assertStringContainsString('<!DOCTYPE html>', $html);
        $this->assertStringContainsString('max-width:100%', $html);
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('alert', $html);
        $this->assertArrayNotHasKey('source_html', $body);
    }

    public function test_a_remote_picture_is_sealed_so_the_senders_url_is_not_on_the_wire(): void
    {
        $this->withFakeProvider();

        $t = $this->provisionTenant('A1-Group');
        $mailbox = $this->inbox($t);
        $clerk = $this->userWithRole($t, 'Clerk', ['email.view', 'email.inbox.view', 'email.settings.view']);
        $this->grant($t, $mailbox, 'Clerk');

        FakeMailboxReader::$detail = new MessageDetail(
            summary: $this->summary(),
            blocks: (new BodySanitiser)->sanitise(
                '<p>Hi</p><img src="https://cdn.example.net/logo.png" alt="Logo">'
            ),
        );

        $body = $this->signedInAs($clerk)
            ->getJson("/api/v1/email/mailboxes/{$mailbox->id}/messages/m-1")
            ->assertOk()->json('data');

        $this->assertSame('image', $body['blocks'][1]['kind']);
        $this->assertNotEmpty($body['blocks'][1]['src']);
        $this->assertStringNotContainsString('cdn.example.net', json_encode($body));
        $this->assertFalse($body['images_blocked']);
    }

    public function test_a_picture_is_fetched_through_this_origin(): void
    {
        $this->withFakeProvider();

        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', true);
        Http::fake([
            'http://93.184.216.34/*' => Http::response($png, 200, ['Content-Type' => 'image/png']),
        ]);

        $t = $this->provisionTenant('A1-Group');
        $mailbox = $this->inbox($t);
        $clerk = $this->userWithRole($t, 'Clerk', ['email.view', 'email.inbox.view', 'email.settings.view']);
        $this->grant($t, $mailbox, 'Clerk');

        $token = Crypt::encryptString('http://93.184.216.34/logo.png');

        $this->signedInAs($clerk)
            ->get("/api/v1/email/mailboxes/{$mailbox->id}/messages/m-1/picture?src=".urlencode($token))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_a_picture_pointed_at_the_metadata_endpoint_is_not_fetched(): void
    {
        $this->withFakeProvider();

        $t = $this->provisionTenant('A1-Group');
        $mailbox = $this->inbox($t);
        $clerk = $this->userWithRole($t, 'Clerk', ['email.view', 'email.inbox.view', 'email.settings.view']);
        $this->grant($t, $mailbox, 'Clerk');

        $token = Crypt::encryptString('http://169.254.169.254/latest/meta-data/');

        $this->signedInAs($clerk)
            ->get("/api/v1/email/mailboxes/{$mailbox->id}/messages/m-1/picture?src=".urlencode($token))
            ->assertNotFound();
    }

    public function test_an_attachment_is_streamed_and_not_stored(): void
    {
        $this->withFakeProvider();

        $t = $this->provisionTenant('A1-Group');
        $mailbox = $this->inbox($t);
        $clerk = $this->userWithRole($t, 'Clerk', ['email.view', 'email.inbox.view', 'email.settings.view']);
        $this->grant($t, $mailbox, 'Clerk');

        $token = Crypt::encryptString("m-1\natt-1");

        $this->signedInAs($clerk)
            ->get("/api/v1/email/mailboxes/{$mailbox->id}/messages/m-1/file?src=".urlencode($token).'&name=invoice.pdf')
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png')
            ->assertHeader('Content-Disposition', 'attachment; filename="invoice.pdf"')
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        $this->assertSame(
            [['message' => 'm-1', 'attachment' => 'att-1']],
            FakeMailboxReader::$downloads,
        );
    }

    public function test_a_forged_attachment_token_is_not_fetched(): void
    {
        $this->withFakeProvider();

        $t = $this->provisionTenant('A1-Group');
        $mailbox = $this->inbox($t);
        $clerk = $this->userWithRole($t, 'Clerk', ['email.view', 'email.inbox.view', 'email.settings.view']);
        $this->grant($t, $mailbox, 'Clerk');

        $this->signedInAs($clerk)
            ->get("/api/v1/email/mailboxes/{$mailbox->id}/messages/m-1/file?src=not-a-token")
            ->assertNotFound();

        $this->assertSame([], FakeMailboxReader::$downloads);
    }

    public function test_a_filename_cannot_carry_a_path_or_a_quote(): void
    {
        $this->withFakeProvider();

        $t = $this->provisionTenant('A1-Group');
        $mailbox = $this->inbox($t);
        $clerk = $this->userWithRole($t, 'Clerk', ['email.view', 'email.inbox.view', 'email.settings.view']);
        $this->grant($t, $mailbox, 'Clerk');

        $token = Crypt::encryptString("m-1\natt-1");

        $response = $this->signedInAs($clerk)
            ->get("/api/v1/email/mailboxes/{$mailbox->id}/messages/m-1/file?src=".urlencode($token).'&name='.urlencode('../quote".pdf'))
            ->assertOk();

        $this->assertSame(
            'attachment; filename="..quote.pdf"',
            $response->headers->get('Content-Disposition'),
        );
    }

    // ══ 6. THE CACHE IS A CACHE ══════════════════════════════════

    /**
     * Headers may be cached; a search never is.
     *
     * Somebody searching a mailbox has usually just failed to find something,
     * which is the worst possible moment to be handed a minute-old answer.
     */
    public function test_the_listing_is_cached_briefly_and_a_search_is_not(): void
    {
        $this->withFakeProvider();

        $t = $this->provisionTenant('A1-Group');

        $mailbox = $this->inbox($t);
        $clerk   = $this->userWithRole($t, 'Clerk', ['email.view', 'email.inbox.view', 'email.settings.view']);

        $this->grant($t, $mailbox, 'Clerk');

        FakeMailboxReader::$page = new MessagePage([$this->summary()]);

        $first = $this->signedInAs($clerk)->getJson("/api/v1/email/mailboxes/{$mailbox->id}/messages")
            ->assertOk()->json('data');

        $this->assertFalse($first['from_cache']);

        $second = $this->signedInAs($clerk)->getJson("/api/v1/email/mailboxes/{$mailbox->id}/messages")
            ->assertOk()->json('data');

        // Said out loud, so a screen can be honest about it and so somebody
        // debugging a stale list does not have to add logging.
        $this->assertTrue($second['from_cache']);

        $searched = $this->signedInAs($clerk)
            ->getJson("/api/v1/email/mailboxes/{$mailbox->id}/messages?search=invoice")
            ->assertOk()->json('data');

        $this->assertFalse($searched['from_cache']);
        $this->assertSame(['invoice'], FakeMailboxReader::$searches);
    }

    /** No message is stored, by anything, on any of these paths. */
    public function test_reading_a_mailbox_writes_no_message_anywhere(): void
    {
        $this->withFakeProvider();

        $t = $this->provisionTenant('A1-Group');

        $mailbox = $this->inbox($t);
        $clerk   = $this->userWithRole($t, 'Clerk', ['email.view', 'email.inbox.view', 'email.settings.view']);

        $this->grant($t, $mailbox, 'Clerk');

        FakeMailboxReader::$page   = new MessagePage([$this->summary()]);
        FakeMailboxReader::$detail = new MessageDetail(
            summary: $this->summary(),
            blocks: [Block::of(BlockKind::Paragraph, 'The quote is 180 pounds.')],
        );

        $this->signedInAs($clerk)->getJson("/api/v1/email/mailboxes/{$mailbox->id}/messages")->assertOk();
        $this->signedInAs($clerk)->getJson("/api/v1/email/mailboxes/{$mailbox->id}/messages/m-1")->assertOk();

        /*
         * Every table in the schema is searched for the message's own words,
         * rather than only the tables this module owns — the failure worth
         * catching is a body arriving somewhere nobody expected, and a check
         * that only looked at `message_seen` would not find it.
         */
        foreach (\Illuminate\Support\Facades\DB::select('SHOW TABLES') as $row) {
            $table = array_values((array) $row)[0];

            $dump = json_encode(\Illuminate\Support\Facades\DB::table($table)->limit(200)->get());

            $this->assertStringNotContainsString('180 pounds', $dump, "a message body reached {$table}");
            $this->assertStringNotContainsString('Quote for a sweep', $dump, "a subject reached {$table}");
        }

        // The control: the ONE thing that is stored, so this is not passing
        // because nothing happened.
        $this->inTenant($t['org'], fn () => $this->assertSame(1, MessageSeen::count()));
    }
}

/**
 * A reader that answers, so the tests around the provider can run.
 *
 * Static state rather than a constructor, because the controller resolves its
 * reader through the container and a test needs to set the answer before the
 * request is made. It is reset in setUp, which is what stops one test's page
 * leaking into the next.
 */
class FakeMailboxReader implements \App\Support\Email\LabelsThreads, MailboxReader
{
    /** @var list<array{0: string, 1: list<string>, 2: list<string>}> */
    public static array $modified = [];

    public function modifyThread(Mailbox $mailbox, string $messageId, array $add, array $remove): void
    {
        self::$modified[] = [$mailbox->address.' '.$messageId, $add, $remove];
    }

    public static ?string $tenantWhenAsked = null;

    public static ?MessagePage $page = null;

    public static ?MessageDetail $detail = null;

    /** @var array<int, array{message: string, body: string}> */
    public static array $replies = [];

    /** @var string[] */
    public static array $searches = [];

    /** @var list<?string> cursors list() was asked for */
    public static array $listedCursors = [];

    /** When set, list() answers page N by cursor (null, 'p1', 'p2', ...). */
    public static ?array $pages = null;

    public function list(Mailbox $mailbox, ?string $cursor = null, int $limit = 25, string $label = 'INBOX'): MessagePage
    {
        self::$listedCursors[] = $cursor;

        if (self::$pages !== null) {
            return self::$pages[$cursor === null ? 0 : (int) substr($cursor, 1)] ?? new MessagePage([]);
        }

        return self::$page ?? new MessagePage([]);
    }

    public function search(Mailbox $mailbox, string $query, ?string $cursor = null, int $limit = 25, string $label = 'INBOX'): MessagePage
    {
        self::$searches[] = $query;

        return self::$page ?? new MessagePage([]);
    }

    /** The thread hint the controller passed on the last open. */
    public static ?string $openedThread = null;

    public function open(Mailbox $mailbox, string $messageId, ?string $threadId = null): MessageDetail
    {
        self::$openedThread = $threadId;

        return self::$detail ?? new MessageDetail(
            summary: new MessageSummary(
                id: $messageId, threadId: null, subject: 'Quote for a sweep',
                fromName: 'Bob', fromAddress: 'bob@example.com', to: [],
                sentAt: null, snippet: '',
            ),
            blocks: [],
        );
    }

    public function reply(Mailbox $mailbox, string $messageId, string $body): void
    {
        self::$replies[] = ['message' => $messageId, 'body' => $body];
    }

    /** @var array<int, array{to: list<string>, subject: string, body: string}> */
    public static array $sent = [];

    /** @var list<string> */
    public static array $folders = ['INBOX', 'SENT'];

    public function availableFolders(Mailbox $mailbox): array
    {
        return self::$folders;
    }

    public static bool $canMarkAtSource = true;

    /** @var list<string> */
    public static array $markedAtSource = [];

    /** @var string[] Messages marked UNREAD at the mail service. */
    public static array $unmarkedAtSource = [];

    /** Set to make the provider refuse, so the controller's fallback is exercised. */
    public static bool $sourceRefuses = false;

    public function canMarkReadAtSource(Mailbox $mailbox): bool
    {
        // The real Workspace reader reads a tenant-scoped connection here.
        self::$tenantWhenAsked = app(\App\Support\Tenancy\TenantContext::class)->id();

        return self::$canMarkAtSource;
    }

    public function markReadAtSource(Mailbox $mailbox, string $messageId): void
    {
        if (self::$sourceRefuses) {
            throw new CannotChangeMail('The mail service said no.');
        }

        self::$markedAtSource[] = $messageId;
    }

    public function markUnreadAtSource(Mailbox $mailbox, string $messageId): void
    {
        if (self::$sourceRefuses) {
            throw new CannotChangeMail('The mail service said no.');
        }

        self::$unmarkedAtSource[] = $messageId;
    }

    public function send(Mailbox $mailbox, array $to, string $subject, string $body, array $attachments = []): void
    {
        self::$sent[] = [
            'to' => $to, 'subject' => $subject, 'body' => $body, 'attachments' => $attachments,
        ];
    }

    /** @var string[] */
    public static array $trashed = [];

    public function trash(Mailbox $mailbox, string $messageId): void
    {
        self::$trashed[] = $messageId;
    }

    /** @var array<int, array{message: string, attachment: string}> */
    public static array $downloads = [];

    public function downloadAttachment(Mailbox $mailbox, string $messageId, string $attachmentId): EmailPicture
    {
        self::$downloads[] = ['message' => $messageId, 'attachment' => $attachmentId];

        return new EmailPicture('file-bytes', 'image/png');
    }

    /**
     * Nothing in this file watches, so these two answer the emptiest truthful
     * thing rather than a scripted one. MailboxWatchTest has a reader that
     * actually models arrivals; a second scripted one here would be a fixture
     * two tests could disagree about.
     */
    public function currentMark(Mailbox $mailbox): string
    {
        return 'mark-0';
    }

    public function arrivalsSince(Mailbox $mailbox, string $mark, int $limit): Arrivals
    {
        return new Arrivals([], $mark);
    }

    public function unreadCount(Mailbox $mailbox): int
    {
        return 0;
    }

    public function isConfigured(Mailbox $mailbox): bool
    {
        return true;
    }

    public function unavailableBecause(Mailbox $mailbox): ?string
    {
        return null;
    }

    /** A scripted reader stands in for a provider that IS ready. */
    public function notReadyBecause(): ?string
    {
        return null;
    }
}

/**
 * Routes every mailbox to the fake, whatever provider it names.
 *
 * `readerFor` and not `for`: the latter is `final`, because it also wraps the
 * reader in App\Support\Email\RecordingMailboxReader. Overriding it would have
 * substituted away the health recording as well as the mail service, and these
 * tests would then have been proving the controller's behaviour against a
 * reader arrangement that never runs in production.
 */
class FakeMailboxReaders extends MailboxReaders
{
    protected function readerFor(Mailbox $mailbox): MailboxReader
    {
        return new FakeMailboxReader;
    }
}
