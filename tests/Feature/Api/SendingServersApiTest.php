<?php

namespace Tests\Feature\Api;

use App\Models\SendingIdentity;
use App\Support\Mail\MailTransport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\Concerns\GivesAuthenticatorCodes;
use Tests\TestCase;

/**
 * Connecting the mail server a business sends its own mail through.
 *
 * The way back in for reports and automation emails, which have had nowhere to
 * send since Jose's ruling took EzBizi's own transport away from tenant content.
 *
 * ── What these tests are FOR, given none of them can send ────────
 *
 * Nothing here opens a socket. The value of this endpoint is entirely in what it
 * refuses and what it never hands back, so that is what is asserted: who may
 * write, what a reader can see, and that the password is not among it.
 */
class SendingServersApiTest extends TestCase
{
    use GivesAuthenticatorCodes;
    use BuildsTenants;
    use RefreshDatabase;

    private const PATH = '/api/v1/mail/sending-server';

    /** @return array<string,mixed> a complete, valid connection body */
    private function body(array $override = []): array
    {
        return array_merge([
            'from_address' => 'info@a-1chimney.test',
            'from_name' => 'A-1 Chimney',
            'host' => 'smtp.example.test',
            'port' => 587,
            'username' => 'info@a-1chimney.test',
            'password' => 'the-actual-password',
            'encryption' => 'tls',
        ], $override);
    }

    private function scope(array $tenant): array
    {
        return ['scope_type' => 'company', 'scope_id' => $tenant['company']->getKey()];
    }

    public function test_an_administrator_connects_a_mail_server(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $admin = $this->userWithRole($tenant, 'Owner', ['organizations.edit']);

        $this->signedInAs($admin)
            ->putJson(self::PATH.'?'.http_build_query($this->scope($tenant)), $this->body())
            ->assertOk()
            ->assertJsonPath('data.connected_here.host', 'smtp.example.test')
            ->assertJsonPath('data.connected_here.has_password', true);

        $row = $this->inTenant($tenant['org'], fn () => SendingIdentity::query()
            ->where('scope_id', $tenant['company']->getKey())->first());

        $this->assertNotNull($row, 'No identity was created for the scope.');
        $this->assertSame(MailTransport::Smtp->value, $row->transport);
        $this->assertSame('the-actual-password', $row->credentials['password']);
    }

    public function test_the_password_never_comes_back_out(): void
    {
        /*
         * THE test, and the one that would go quietly green if somebody added
         * the field to describe() for a "show the saved password" affordance.
         * Asserted on the whole response rather than on one path, because the
         * failure being guarded against is the value appearing SOMEWHERE — a
         * nested echo of the request, a validation message, a debug key.
         */
        $tenant = $this->provisionTenant('Alpha');
        $admin = $this->userWithRole($tenant, 'Owner', ['organizations.edit']);
        $query = '?'.http_build_query($this->scope($tenant));

        $this->signedInAs($admin)->putJson(self::PATH.$query, $this->body())->assertOk();

        $write = $this->signedInAs($admin)->putJson(self::PATH.$query, $this->body());
        $read = $this->signedInAs($admin)->getJson(self::PATH.$query);

        foreach (['write' => $write, 'read' => $read] as $which => $response) {
            $this->assertStringNotContainsString(
                'the-actual-password',
                $response->getContent(),
                "The saved password appeared in the {$which} response.",
            );
        }

        // And the control: the things that SHOULD come back do, so this test
        // cannot pass by the endpoint returning nothing at all.
        $read->assertJsonPath('data.connected_here.username', 'info@a-1chimney.test')
            ->assertJsonPath('data.connected_here.host', 'smtp.example.test')
            ->assertJsonPath('data.connected_here.has_password', true);
    }

    public function test_the_permission_is_required(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $nobody = $this->userWithRole($tenant, 'Viewer', []);

        $this->signedInAs($nobody)
            ->putJson(self::PATH.'?'.http_build_query($this->scope($tenant)), $this->body())
            ->assertForbidden();

        $this->assertSame(0, $this->inTenant($tenant['org'], fn () => SendingIdentity::query()->count()));
    }

    public function test_reach_is_checked_as_well_as_the_permission(): void
    {
        /*
         * The SECOND gate, and the one a permission-only check would miss. This
         * person HAS organizations.edit — so a test that only covered the
         * permission would pass with this branch of the code deleted. Their
         * assignment reaches one franchise, and they are writing at company
         * level, which is above them.
         *
         * Jose's rule: "a franchise user should not be able to modify parent
         * preferences."
         */
        $tenant = $this->provisionTenant('Alpha');

        $branchManager = $this->userWithRole(
            $tenant,
            'Branch Manager',
            ['organizations.edit'],
            onlyAt: $tenant['franchise'],
        );

        $refused = $this->signedInAs($branchManager)
            ->putJson(self::PATH.'?'.http_build_query($this->scope($tenant)), $this->body())
            ->assertForbidden();

        /*
         * Pinned to the REACH refusal, not just "a 403". This person holds
         * organizations.edit, so if the permission sentence came back it would
         * mean the permission gate fired for a reason this test does not
         * understand — and the reach branch could be deleted with the test
         * still green.
         */
        $this->assertStringNotContainsString(
            'do not have permission',
            $refused->getContent(),
            'Refused for the PERMISSION, but this user has it — the 403 is not the reach gate.',
        );

        // And they CAN write at their own level — without this the test above
        // passes for a build that refuses everybody.
        $this->signedInAs($branchManager)->putJson(
            self::PATH.'?'.http_build_query([
                'scope_type' => 'franchise',
                'scope_id' => $tenant['franchise']->getKey(),
            ]),
            $this->body(),
        )->assertOk();
    }

    public function test_another_organizations_scope_is_not_found_rather_than_refused(): void
    {
        /*
         * Not found rather than forbidden, deliberately: a 403 would confirm
         * that the id names something real, which is an existence oracle over
         * the whole table for anybody who can guess a uuid.
         */
        $mine = $this->provisionTenant('Alpha');
        $theirs = $this->provisionTenant('Beta', 'beta@example.test');
        $admin = $this->userWithRole($mine, 'Owner', ['organizations.edit']);

        $this->signedInAs($admin)->putJson(
            self::PATH.'?'.http_build_query([
                'scope_type' => 'company',
                'scope_id' => $theirs['company']->getKey(),
            ]),
            $this->body(),
        )->assertNotFound();
    }

    public function test_port_25_is_refused(): void
    {
        /*
         * Submission ports only. 25 is server-to-server relay, it is blocked
         * outbound by most hosts anyway, and accepting it here would let the
         * form reach a class of machine the submission ports do not.
         */
        $tenant = $this->provisionTenant('Alpha');
        $admin = $this->userWithRole($tenant, 'Owner', ['organizations.edit']);

        $this->signedInAs($admin)
            ->putJson(self::PATH.'?'.http_build_query($this->scope($tenant)), $this->body(['port' => 25]))
            ->assertStatus(422);
    }

    public function test_disconnecting_needs_a_fresh_authenticator_code(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $admin = $this->userWithRole($tenant, 'Owner', ['organizations.edit']);
        $company = '?'.http_build_query($this->scope($tenant));

        $this->signedInAs($admin)->putJson(self::PATH.$company, $this->body())->assertOk();

        $this->signedInAs($admin)->deleteJson(self::PATH.$company)
            ->assertStatus(422)->assertJsonPath('code', 'no_authenticator');

        $this->authenticatorCode($admin);
        $this->signedInAs($admin)->deleteJson(self::PATH.$company)
            ->assertStatus(422)->assertJsonPath('code', 'authenticator_code_required');
        $this->signedInAs($admin)->deleteJson(self::PATH.$company, ['code' => '000000'])
            ->assertStatus(422)->assertJsonPath('code', 'authenticator_code_invalid');

        $code = $this->authenticatorCode($admin);
        $this->signedInAs($admin)->deleteJson(self::PATH.$company, $code)->assertOk();
    }

    public function test_disconnecting_says_what_the_business_falls_back_to(): void
    {
        /*
         * Removing a branch's server does not stop its mail — it hands it to
         * whatever is above. Said at the moment of removal rather than
         * discovered afterwards, which is the difference between a setting and
         * a surprise.
         */
        $tenant = $this->provisionTenant('Alpha');
        $admin = $this->userWithRole($tenant, 'Owner', ['organizations.edit']);

        $company = '?'.http_build_query($this->scope($tenant));
        $franchise = '?'.http_build_query([
            'scope_type' => 'franchise',
            'scope_id' => $tenant['franchise']->getKey(),
        ]);

        $this->signedInAs($admin)->putJson(self::PATH.$company, $this->body())->assertOk();
        $this->signedInAs($admin)->putJson(self::PATH.$franchise, $this->body([
            'from_address' => 'ct@a-1chimney.test',
            'from_name' => 'A-1 Chimney Connecticut',
        ]))->assertOk();

        $code = $this->authenticatorCode($admin);
        $this->signedInAs($admin)->deleteJson(self::PATH.$franchise, $code)
            ->assertOk()
            ->assertJsonPath('data.connected_here', null)
            // Inherited from the company, not fallen back to EzBizi.
            ->assertJsonPath('data.in_effect.from_address', 'info@a-1chimney.test')
            ->assertJsonPath('data.in_effect.inherited', true);
    }

    public function test_a_scope_with_nothing_connected_reports_the_platform_rather_than_an_error(): void
    {
        /*
         * The empty state, which is every scope on every box today. A screen
         * that cannot render it is a screen nobody can reach the form through —
         * and the endpoint must distinguish "nothing here" from "nothing
         * anywhere", because those are different sentences.
         */
        $tenant = $this->provisionTenant('Alpha');
        $admin = $this->userWithRole($tenant, 'Owner', ['organizations.edit']);

        $this->signedInAs($admin)
            ->getJson(self::PATH.'?'.http_build_query($this->scope($tenant)))
            ->assertOk()
            ->assertJsonPath('data.connected_here', null)
            ->assertJsonPath('data.in_effect.transport', MailTransport::Platform->value)
            ->assertJsonPath('data.in_effect.source_name', null)
            ->assertJsonPath('data.in_effect.inherited', false);
    }

    public function test_never_tried_is_derived_from_a_recorded_attempt(): void
    {
        /*
         * THE test that can tell the column exists. `never_tried` reads
         * last_attempted_at, and a MISSING column reads as null too — so
         * asserting only the true case passes against a build where the
         * migration never ran. The second half is what distinguishes them: it
         * records an attempt and requires the answer to CHANGE.
         *
         * UI/UX drew "Saved, never tried" and named it the trap in this screen:
         * it is true of every row today, it goes false when the first send
         * happens in ANOTHER module, and nothing here would have to change for
         * the sentence to start lying. Deriving it means it stops being true on
         * its own rather than when somebody remembers.
         */
        $tenant = $this->provisionTenant('Alpha');
        $admin = $this->userWithRole($tenant, 'Owner', ['organizations.edit']);
        $query = '?'.http_build_query($this->scope($tenant));

        $this->signedInAs($admin)->putJson(self::PATH.$query, $this->body())->assertOk();

        $this->signedInAs($admin)->getJson(self::PATH.$query)
            ->assertOk()
            ->assertJsonPath('data.connected_here.never_tried', true);

        $this->inTenant($tenant['org'], function () use ($tenant) {
            SendingIdentity::query()
                ->where('scope_id', $tenant['company']->getKey())
                ->first()
                ->noteAttempt();
        });

        // Goes false, and this is the assertion a missing column cannot satisfy.
        $this->signedInAs($admin)->getJson(self::PATH.$query)
            ->assertOk()
            ->assertJsonPath('data.connected_here.never_tried', false);
    }

    public function test_an_attempt_is_recorded_whatever_the_outcome(): void
    {
        /*
         * noteAttempt() takes no outcome on purpose. An attempt recorded only on
         * SUCCESS would leave a row that has failed ten times still reporting
         * that nobody has tried it — which is the precise sentence the column
         * exists to stop being a lie, reintroduced by the obvious convenience.
         *
         * Pinned here because the sending path does not exist yet: whoever
         * builds it will be looking for where to call this, and this says what
         * calling it must NOT be conditional on.
         */
        $tenant = $this->provisionTenant('Alpha');

        $this->inTenant($tenant['org'], function () use ($tenant) {
            $row = new SendingIdentity([
                'organization_id' => $tenant['org']->id,
                'scope_type' => SendingIdentity::SCOPE_COMPANY,
                'scope_id' => $tenant['company']->getKey(),
                'from_address' => 'info@a-1chimney.test',
                'from_name' => 'A-1 Chimney',
            ]);

            $row->connectServer([
                'host' => 'smtp.example.test', 'port' => 587,
                'username' => 'u', 'password' => 'p', 'encryption' => 'tls',
            ]);
            $row->save();

            $this->assertTrue($row->neverTried(), 'A freshly saved server was not "never tried".');

            $row->noteAttempt();

            $this->assertFalse(
                $row->fresh()->neverTried(),
                'noteAttempt() did not persist, so a tried server still reports untried.',
            );
        });
    }
}
