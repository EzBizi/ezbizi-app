<?php

namespace Tests\Feature\Dashboard;

use App\Models\Assignment;
use App\Models\Mailbox;
use App\Models\MailboxGrant;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Organizations\ProvisionOrganization;
use App\Support\Dashboard\DashboardPeriod;
use App\Support\Dashboard\Glances\EmailGlance;
use App\Support\Email\CannotReadMailbox;
use App\Support\Email\MailboxReaders;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Api\ScriptedMailboxReader;
use Tests\Feature\Api\ScriptedMailboxReaders;
use Tests\TestCase;

require_once dirname(__DIR__).'/Api/MailboxHealthTest.php';

/**
 * Email dashboard glance (spec § Email — bar of unread per inbox).
 *
 * One bar per readable mailbox in the current view. Unread is the provider's
 * mailbox-level count, not a stored message list. No grant: a sentence, and
 * the reader is not asked.
 */
class EmailGlanceTest extends TestCase
{
    use RefreshDatabase;

    private array $alpha;

    protected function setUp(): void
    {
        parent::setUp();

        ScriptedMailboxReader::reset();

        // Permissions are a PLATFORM table populated by the module registry, not
        // by provisioning an organization — so a test that builds a non-admin
        // role has to make sure the rows exist first.
        $this->artisan('ezbizi:sync-permissions')->assertSuccessful();

        $r = app(ProvisionOrganization::class)(
            organizationName: 'Alpha',
            administrator: ['name' => 'Admin', 'email' => 'a@alpha.test', 'password' => 'a-real-password'],
            companyName: 'Co', franchiseName: 'F1',
        );

        $this->alpha = [
            'org' => $r['organization'], 'admin' => $r['administrator'],
            'company' => $r['company'], 'franchise' => $r['franchise'],
        ];
    }

    private function inTenant(callable $fn): mixed
    {
        return app(TenantContext::class)->run($this->alpha['org'], $fn);
    }

    /**
     * Somebody holding exactly these permissions, assigned to the franchise.
     *
     * @param  string[]  $permissionKeys
     */
    private function userWithPermissions(array $permissionKeys): User
    {
        return $this->inTenant(function () use ($permissionKeys) {
            $role = Role::create([
                'organization_id' => $this->alpha['org']->id,
                'name' => 'Limited', 'is_system' => false,
            ]);

            $role->permissions()->sync(
                Permission::whereIn('key', $permissionKeys)->pluck('id')
            );

            $user = User::create([
                'organization_id' => $this->alpha['org']->id, 'role_id' => $role->id,
                'name' => 'Limited', 'email' => 'limited@alpha.test',
                'password' => 'a-real-password', 'is_active' => true,
            ]);

            Assignment::create([
                'organization_id' => $this->alpha['org']->id,
                'user_id' => $user->id,
                'assigned_franchise_id' => $this->alpha['franchise']->id,
            ]);

            return $user;
        });
    }

    private function withScriptedProvider(): void
    {
        $this->app->bind(MailboxReaders::class, fn () => new ScriptedMailboxReaders);
    }

    /**
     * A franchise inbox this person's role may read (MailboxesApiTest grant shape).
     */
    private function grantedInbox(User $user, string $address = 'info@alpha.test'): Mailbox
    {
        return $this->inTenant(function () use ($user, $address) {
            $mailbox = Mailbox::create([
                'organization_id' => $this->alpha['org']->id,
                'scope_type' => Mailbox::SCOPE_FRANCHISE,
                'scope_id' => $this->alpha['franchise']->id,
                'address' => $address,
                'display_name' => 'Info',
                'provider' => 'gmail_oauth',
            ]);

            MailboxGrant::create([
                'organization_id' => $this->alpha['org']->id,
                'mailbox_id' => $mailbox->getKey(),
                'role_id' => $user->role_id,
            ]);

            return $mailbox;
        });
    }

    public function test_no_inbox_grant_is_a_sentence_and_does_not_hit_the_reader(): void
    {
        $user = $this->userWithPermissions(['email.view']);
        $this->withScriptedProvider();
        $payload = app(EmailGlance::class)->resolve($user, DashboardPeriod::for($user), [])->toArray();
        $this->assertTrue($payload['data']['empty']);
        $this->assertSame('No inbox is assigned to your role.', $payload['data']['sentence']);
        $this->assertSame(0, ScriptedMailboxReader::$unreadAsked);
    }

    public function test_one_bar_per_readable_mailbox(): void
    {
        $user = $this->userWithPermissions(['email.view']);
        $mailbox = $this->grantedInbox($user);
        $this->withScriptedProvider();
        ScriptedMailboxReader::$unread[(string) $mailbox->getKey()] = 4;
        $payload = app(EmailGlance::class)->resolve($user, DashboardPeriod::for($user), [])->toArray();
        $this->assertSame(4, $payload['data']['slices'][0]['total']);
    }

    public function test_an_unreadable_mailbox_is_named_and_omitted_from_slices(): void
    {
        $user = $this->userWithPermissions(['email.view']);
        $ok = $this->grantedInbox($user, 'ok@alpha.test');
        $dead = $this->grantedInbox($user, 'dead@alpha.test');
        $this->withScriptedProvider();
        ScriptedMailboxReader::$unread[(string) $ok->getKey()] = 3;
        ScriptedMailboxReader::$failUnread[(string) $dead->getKey()] = CannotReadMailbox::credentialRejected($dead);

        $payload = app(EmailGlance::class)->resolve($user, DashboardPeriod::for($user), [])->toArray();

        $this->assertFalse($payload['data']['empty']);
        $this->assertCount(1, $payload['data']['slices']);
        $this->assertSame(3, $payload['data']['slices'][0]['total']);
        $this->assertTrue(
            collect($payload['data']['sentences'] ?? [])->contains(
                fn (string $s) => str_contains($s, 'dead@alpha.test'),
            ),
            'The unreadable inbox must be named in a sentence.',
        );
    }

    public function test_unread_counts_ignore_the_dashboard_period(): void
    {
        $user = $this->userWithPermissions(['email.view']);
        $mailbox = $this->grantedInbox($user);
        $this->withScriptedProvider();
        ScriptedMailboxReader::$unread[(string) $mailbox->getKey()] = 4;
        $week = app(EmailGlance::class)->resolve($user, DashboardPeriod::for($user, 'week'), [])->toArray();
        $year = app(EmailGlance::class)->resolve($user, DashboardPeriod::for($user, 'year'), [])->toArray();
        $this->assertSame($week['data']['slices'], $year['data']['slices']);
    }
}
