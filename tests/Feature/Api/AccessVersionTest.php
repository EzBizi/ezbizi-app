<?php

namespace Tests\Feature\Api;

use App\Models\Mailbox;
use App\Models\MailboxGrant;
use App\Models\Role;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * /me's access_version moves when what a person may reach moves, so an open
 * app redraws on its own (Jose, 2026-11-10: an inbox granted to Lucas showed
 * only after he reloaded).
 */
class AccessVersionTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('ezbizi:sync-permissions')->assertSuccessful();
    }

    private function version($user): string
    {
        $value = $this->signedInAs($user)->getJson('/api/v1/me')
            ->assertOk()->json('data.access_version');
        $this->assertIsString($value);

        return $value;
    }

    private function grantInbox(array $t, $user, string $address): void
    {
        app(TenantContext::class)->run($t['org'], function () use ($t, $user, $address): void {
            $mailbox = Mailbox::create([
                'organization_id' => $t['org']->id,
                'scope_type' => Mailbox::SCOPE_ORGANIZATION,
                'scope_id' => $t['org']->id,
                'address' => $address,
                'display_name' => 'Office',
                'provider' => 'gmail_oauth',
            ]);
            MailboxGrant::create([
                'organization_id' => $t['org']->id,
                'mailbox_id' => $mailbox->getKey(),
                'user_id' => $user->id,
            ]);
        });
    }

    /**
     * A SECOND inbox: the first already gave the email keys, so only the
     * grant itself can move the value.
     */
    public function test_an_inbox_granted_by_name_moves_only_that_persons_version(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $lucas = $this->userWithRole($t, 'Dispatcher', ['jobs.view']);
        $colleague = $this->userWithRole($t, 'Tech', ['jobs.view']);
        $this->grantInbox($t, $lucas, 'info@nj.example.com');

        $before = $this->version($lucas);
        $colleagueBefore = $this->version($colleague);
        $this->assertSame($before, $this->version($lucas), 'stable when nothing changed');

        $this->grantInbox($t, $lucas, 'office@nj.example.com');

        $this->assertNotSame($before, $this->version($lucas));
        $this->assertSame($colleagueBefore, $this->version($colleague));
    }

    public function test_changing_the_roles_permissions_moves_the_version(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $user = $this->userWithRole($t, 'Dispatcher', ['jobs.view']);

        $before = $this->version($user);

        app(TenantContext::class)->run($t['org'], fn () => Role::query()
            ->findOrFail($user->role_id)
            ->syncPermissionKeys(['jobs.view', 'customers.view']));

        $this->assertNotSame($before, $this->version($user->fresh()));
    }
}
