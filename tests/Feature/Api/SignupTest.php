<?php

namespace Tests\Feature\Api;

use App\Models\AuditEvent;
use App\Models\Company;
use App\Models\Franchise;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\EmailVerificationLink;
use App\Services\Auth\EmailVerifications;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The front door (plan §3, §13 — TODO #10).
 *
 * Until this endpoint existed there was no way to create an organization at all:
 * every caller of ProvisionOrganization was a test, and the one live tenant was
 * made from a shell.
 *
 * These run REAL requests and use the REAL token that comes back, never
 * Sanctum::actingAs(). Signup happens with no tenant in context and TenantScope
 * now throws in that state rather than failing open, so the only way to know the
 * whole path works is to walk it — reading the code proves nothing about which
 * queries run before SetTenantContext has anything to set.
 */
class SignupTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, string> */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name'           => 'Jose Silva',
            'email'          => 'jose@example.com',
            'password'       => 'a-real-long-password',
            'business_name'  => 'A-1 Chimney',
            'first_location' => 'Connecticut',
        ], $overrides);
    }

    /**
     * An empty device name must not be a 500 — and this endpoint is the one
     * where that mattered most.
     *
     * `input('device_name', 'web')` applies its default only to a MISSING key,
     * and ConvertEmptyStringsToNull turns `""` into null, which reached
     * createToken() and raised a TypeError. On signup that 500 arrived AFTER the
     * tenant transaction had committed: the caller was told the server broke
     * while a real, reachable organization existed, and retrying built a second
     * one. Login carried the same flaw and gets the fix with it.
     */
    public function test_a_blank_device_name_is_not_a_server_error(): void
    {
        // Distinct addresses on purpose: the same one twice is legal here and
        // would make the login below answer choose_organization, which is the
        // right answer to a different question.
        foreach (['', '   ', null] as $i => $deviceName) {
            $this->postJson('/api/v1/auth/signup', $this->payload([
                'email'       => "device{$i}@example.com",
                'device_name' => $deviceName,
            ]))->assertCreated()->assertJsonPath('status', 'authenticated');
        }

        // And the same at the other door, where it was inherited from.
        $this->postJson('/api/v1/auth/login', [
            'email'       => 'device0@example.com',
            'password'    => 'a-real-long-password',
            'device_name' => '',
        ])->assertOk()->assertJsonPath('status', 'authenticated');
    }

    public function test_signup_is_closed_when_the_flag_is_off(): void
    {
        config(['ezbizi.signup.enabled' => false]);

        $this->postJson('/api/v1/auth/signup', $this->payload())
            ->assertForbidden()
            ->assertJsonPath('code', 'signup_closed');

        $this->assertDatabaseCount('organizations', 0);
    }

    // ── the happy path ───────────────────────────────────────────

    public function test_one_request_creates_the_whole_tree(): void
    {
        $this->postJson('/api/v1/auth/signup', $this->payload())->assertCreated();

        $organization = Organization::where('name', 'A-1 Chimney')->firstOrFail();

        // §3 forbids a company without a franchise, which is the entire reason
        // this is one form rather than a wizard somebody can abandon halfway.
        app(TenantContext::class)->run($organization, function () use ($organization) {
            $user = User::where('email', 'jose@example.com')->firstOrFail();

            $this->assertSame('Jose Silva', $user->name);
            $this->assertTrue($user->role->isAdministrator());

            $company = Company::where('organization_id', $organization->id)->firstOrFail();
            $this->assertSame('A-1 Chimney', $company->name);

            $franchise = Franchise::where('company_id', $company->id)->firstOrFail();
            $this->assertSame('Connecticut', $franchise->name);

            // The whole tree, and nothing dangling outside it.
            $this->assertSame(1, Company::count());
            $this->assertSame(1, Franchise::count());
            $this->assertSame(1, User::count());
        });
    }

    public function test_the_business_name_becomes_both_the_organization_and_the_company(): void
    {
        $this->postJson('/api/v1/auth/signup', $this->payload())->assertCreated();

        $organization = Organization::where('name', 'A-1 Chimney')->firstOrFail();

        // Deliberate, not a mapping slip: a business signing up is one company,
        // and asking somebody to distinguish the two before they have seen the
        // product is asking them to learn §3's hierarchy at the front door.
        app(TenantContext::class)->run($organization, function () use ($organization) {
            $this->assertSame(
                $organization->name,
                Company::where('organization_id', $organization->id)->firstOrFail()->name,
            );
        });
    }

    public function test_the_token_it_returns_authenticates_a_real_request(): void
    {
        $token = $this->postJson('/api/v1/auth/signup', $this->payload())
            ->assertCreated()
            ->json('token');

        $this->assertNotEmpty($token);

        // The part a fabricated session would skip: the token has to survive a
        // round trip through the database and satisfy auth:sanctum, `tenant` and
        // `subscription` on the way to an endpoint that reads tenant data.
        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.email', 'jose@example.com')
            ->assertJsonPath('data.organization.name', 'A-1 Chimney')
            // Reach comes from the organization-level assignment, so the
            // franchise created a moment ago is already selectable.
            ->assertJsonPath('data.franchises.0.name', 'Connecticut');
    }

    public function test_the_body_is_exactly_what_a_login_returns(): void
    {
        $signup = $this->postJson('/api/v1/auth/signup', $this->payload())->assertCreated();

        $login = $this->postJson('/api/v1/auth/login', [
            'email' => 'jose@example.com', 'password' => 'a-real-long-password',
        ])->assertOk();

        // The client is going to treat a signup response as a login response, so
        // the two must not be able to drift apart. Keys only — the tokens
        // themselves differ, which is the point of issuing a second one.
        $this->assertSame(
            array_keys($login->json()),
            array_keys($signup->json()),
        );

        $this->assertSame('authenticated', $signup->json('status'));
        $this->assertNull($signup->json('staff_token'));
        $this->assertFalse($signup->json('organization.is_read_only'));
    }

    public function test_the_new_organization_is_on_a_trial_and_is_not_a_demo(): void
    {
        $this->postJson('/api/v1/auth/signup', $this->payload())->assertCreated();

        $organization = Organization::where('name', 'A-1 Chimney')->firstOrFail();

        // TODO #9 — what a demo organization suppresses — is unanswered, so
        // signup must not start minting them.
        $this->assertFalse($organization->is_demo);
        $this->assertNotNull($organization->trial_ends_at);
        $this->assertFalse($organization->isReadOnly());
    }

    public function test_the_franchise_keeps_the_service_default_timezone(): void
    {
        $this->postJson('/api/v1/auth/signup', $this->payload())->assertCreated();

        $organization = Organization::where('name', 'A-1 Chimney')->firstOrFail();

        // The form does not ask. TODO #20 — whether a scheduled job is an instant
        // or a local wall time — is deliberately parked, and offering a timezone
        // picker here would answer it in passing.
        app(TenantContext::class)->run($organization, function () {
            $this->assertSame('America/New_York', Franchise::firstOrFail()->timezone);
        });
    }

    // ── auditing ─────────────────────────────────────────────────

    public function test_the_creation_is_audited_against_the_organization_it_created(): void
    {
        $this->postJson('/api/v1/auth/signup', $this->payload())->assertCreated();

        $organization = Organization::where('name', 'A-1 Chimney')->firstOrFail();

        $event = AuditEvent::where('action', 'organization.provisioned')->firstOrFail();

        // The trap: the request begins with NO tenant, and AuditLogger reads the
        // context to decide whose history an event belongs to. Left unset, the
        // row describing a tenant's creation is missing from that tenant's own
        // history from its first second.
        $this->assertSame($organization->id, $event->organization_id);
        $this->assertSame($organization->id, $event->target_id);
        $this->assertSame('A-1 Chimney', $event->metadata['company']);
        $this->assertSame('Connecticut', $event->metadata['franchise']);

        // Signup ends in a session, so it leaves the same trace a login does.
        $this->assertDatabaseHas('audit_events', [
            'action'          => 'auth.signed_in',
            'organization_id' => $organization->id,
        ]);
    }

    // ── refusals ─────────────────────────────────────────────────

    public static function invalidPayloads(): array
    {
        return [
            'no name'             => [['name' => ''], 'name'],
            'no email'            => [['email' => ''], 'email'],
            'email is not one'    => [['email' => 'not-an-address'], 'email'],
            'no password'         => [['password' => ''], 'password'],
            'password too short'  => [['password' => 'short'], 'password'],
            // §3: both of these become real entities, so neither may be blank.
            'no business name'    => [['business_name' => ''], 'business_name'],
            'blank business name' => [['business_name' => '   '], 'business_name'],
            'no location'         => [['first_location' => ''], 'first_location'],
            'blank location'      => [['first_location' => '   '], 'first_location'],
            // 120, not the column width. Accepting 255 here let the front door
            // create a franchise the Structure screen then refused to rename to
            // its own current value, and made the slug column overflow on a
            // second signup with the same name.
            'name too long'       => [['business_name' => str_repeat('x', 121)], 'business_name'],
            'location too long'   => [['first_location' => str_repeat('x', 121)], 'first_location'],
        ];
    }

    // The attribute, not the `@dataProvider` doc comment: PHPUnit 12 removed
    // doc-comment metadata, and the annotation form is silently ignored — the
    // test then runs once with no arguments and errors on the missing parameter.
    #[DataProvider('invalidPayloads')]
    public function test_bad_input_is_refused_without_creating_anything(array $override, string $field): void
    {
        $this->postJson('/api/v1/auth/signup', $this->payload($override))
            ->assertStatus(422)
            ->assertJsonValidationErrors($field);

        $this->assertSame(0, Organization::count());
        $this->assertSame(0, User::withoutGlobalScopes()->count());
    }

    public function test_the_password_floor_is_the_platform_one(): void
    {
        // There is no organization yet, so there is no settings cascade to read a
        // minimum from — `security.password_min_length` cannot apply at signup by
        // definition, because the scope it is stored against does not exist until
        // this request succeeds. The platform floor is what applies.
        $this->postJson('/api/v1/auth/signup', $this->payload([
            'password' => str_repeat('a', 7),
        ]))->assertStatus(422)->assertJsonValidationErrors('password');

        $this->postJson('/api/v1/auth/signup', $this->payload([
            'password' => str_repeat('a', 8),
        ]))->assertCreated();
    }

    // ── an address that already exists ───────────────────────────

    public function test_a_second_signup_with_the_same_address_makes_a_second_organization(): void
    {
        $this->postJson('/api/v1/auth/signup', $this->payload([
            'business_name' => 'Alpha Chimney',
        ]))->assertCreated();

        $this->postJson('/api/v1/auth/signup', $this->payload([
            'business_name' => 'Bravo Chimney',
        ]))->assertCreated();

        // §4: one address may hold accounts in several organizations — a person
        // working for two clients. AuthApiTest already asserts login copes with
        // it; refusing it here would have broken that from the only door in.
        $this->assertSame(2, Organization::count());

        $users = User::withoutGlobalScopes()->where('email', 'jose@example.com')->get();

        $this->assertCount(2, $users);
        $this->assertCount(2, $users->pluck('organization_id')->unique());

        // And both are reachable afterwards, rather than one being stranded.
        $this->postJson('/api/v1/auth/login', [
            'email' => 'jose@example.com', 'password' => 'a-real-long-password',
        ])->assertOk()->assertJsonPath('status', 'choose_organization')
          ->assertJsonCount(2, 'choices');
    }

    public function test_signup_does_not_reveal_whether_an_address_is_already_registered(): void
    {
        $known = $this->postJson('/api/v1/auth/signup', $this->payload([
            'business_name' => 'Alpha Chimney',
        ]))->assertCreated();

        $again = $this->postJson('/api/v1/auth/signup', $this->payload([
            'business_name' => 'Bravo Chimney',
        ]))->assertCreated();

        $fresh = $this->postJson('/api/v1/auth/signup', $this->payload([
            'email' => 'someone-else@example.com', 'business_name' => 'Charlie Chimney',
        ]))->assertCreated();

        // A `unique:users,email` rule would turn this endpoint into an oracle:
        // type an address, read the status code, learn whether that person has an
        // account. Same status and same keys either way.
        $this->assertSame($known->status(), $again->status());
        $this->assertSame($fresh->status(), $again->status());
        $this->assertSame(array_keys($fresh->json()), array_keys($again->json()));
    }

    // ── email verification (TODO #22) ────────────────────

    public function test_a_new_account_may_read_but_may_not_write_until_it_confirms(): void
    {
        Notification::fake();

        $token = $this->postJson('/api/v1/auth/signup', $this->payload())
            ->assertCreated()->json('token');

        // READS work. This is not incidental: /me is how the client learns who
        // it is and what state it is in, so refusing it would leave a new
        // account staring at a locked door with nothing to explain it.
        $franchiseId = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/me')->assertOk()->json('data.franchises.0.id');

        /*
         * WRITES do not. This replaces a test that pinned the opposite, and the
         * reason it pinned the opposite is worth keeping: nothing on this
         * platform could send mail, so a verification gate would have locked out
         * every account signup created. That was true until SES left the sandbox
         * on 2026-09-14. The tripwire fired on exactly this line when the gate
         * went in, which is what it was for.
         */
        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/customers', [
                'owning_franchise_id' => $franchiseId,
                'name'                => 'First Customer',
            ])
            ->assertForbidden()
            ->assertJsonPath('code', 'email_unverified');

        $organization = Organization::where('name', 'A-1 Chimney')->firstOrFail();

        app(TenantContext::class)->run($organization, function () {
            $this->assertNull(User::firstOrFail()->email_verified_at);
        });

        // And the message that lifts it was actually sent, to the address that
        // signed up rather than to whoever happened to be first in the table.
        Notification::assertSentOnDemand(
            EmailVerificationLink::class,
            fn ($notification, $channels, $notifiable) => $notifiable->routes['mail'] === 'jose@example.com',
        );
    }

    public function test_confirming_the_address_lets_the_account_write(): void
    {
        $token = $this->postJson('/api/v1/auth/signup', $this->payload())
            ->assertCreated()->json('token');

        $franchiseId = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/me')->assertOk()->json('data.franchises.0.id');

        $organization = Organization::where('name', 'A-1 Chimney')->firstOrFail();

        // Mint a link the way signup does, rather than reaching into the table,
        // so this exercises the path a real confirmation takes.
        $verification = app(TenantContext::class)->run(
            $organization,
            fn () => app(EmailVerifications::class)->issue(User::firstOrFail()),
        );

        $this->postJson('/api/v1/auth/verify', ['token' => $verification['token']])
            ->assertOk()
            ->assertJsonPath('message', 'Your address is confirmed.');

        /*
         * A test-harness artifact, not product behaviour. The application is not
         * rebuilt between requests inside one test, so the auth guard still holds
         * the User it resolved for the call above - the copy from before this
         * address was confirmed. Production hands every request a new process and
         * has nothing to forget. Same shape as the controller instance a route
         * keeps for the life of a test file.
         */
        $this->app['auth']->forgetGuards();

        // The same write that was refused a moment ago.
        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/customers', [
                'owning_franchise_id' => $franchiseId,
                'name'                => 'First Customer',
            ])->assertCreated();

        app(TenantContext::class)->run($organization, function () {
            $this->assertNotNull(User::firstOrFail()->email_verified_at);
        });
    }

    public function test_a_confirmation_link_works_once(): void
    {
        $this->postJson('/api/v1/auth/signup', $this->payload())->assertCreated();

        $organization = Organization::where('name', 'A-1 Chimney')->firstOrFail();

        $verification = app(TenantContext::class)->run(
            $organization,
            fn () => app(EmailVerifications::class)->issue(User::firstOrFail()),
        );

        $this->postJson('/api/v1/auth/verify', ['token' => $verification['token']])->assertOk();

        // Spent. A link left in a mailbox is not a way back in later.
        $this->postJson('/api/v1/auth/verify', ['token' => $verification['token']])
            ->assertStatus(422)
            ->assertJsonPath('code', 'verification_token_invalid');

        // And a string that was never issued is refused identically, so the
        // response cannot be used to sort real tokens from invented ones.
        $this->postJson('/api/v1/auth/verify', ['token' => str_repeat('a', 48)])
            ->assertStatus(422)
            ->assertJsonPath('code', 'verification_token_invalid');
    }

    // ── throttling ───────────────────────────────────────────────

    public function test_signups_from_one_address_are_throttled(): void
    {
        // Ten refusals, because the framework's throttle counts every request
        // that reaches the route rather than only the ones that fail — which is
        // the right shape here, since with signup the EXPENSIVE outcome is the
        // success. It is also why the limit is ten rather than login's five: a
        // number tuned to how often a business legitimately signs up would lock
        // somebody out for an hour for mistyping their password twice.
        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/v1/auth/signup', $this->payload(['email' => 'bad']))
                ->assertStatus(422);
        }

        $throttled = $this->postJson('/api/v1/auth/signup', $this->payload())
            ->assertStatus(429);

        // The same body login's hand-rolled throttle returns. The client parses
        // one shape for a 429 and this route is not the exception.
        $throttled->assertJsonPath('code', 'throttled');
        $this->assertIsInt($throttled->json('retry_after'));
        $this->assertNotEmpty($throttled->json('message'));

        // A public endpoint that writes six rows per success cannot be the first
        // one in this application without a limit — and the limit has to bite
        // before the write, not after it.
        $this->assertSame(0, Organization::count());
    }

    public function test_the_throttle_is_keyed_on_the_address_it_came_from(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/v1/auth/signup', $this->payload(['email' => 'bad']))
                ->assertStatus(422);
        }

        $this->postJson('/api/v1/auth/signup', $this->payload())->assertStatus(429);

        // There is no prior account to key on, so it is keyed on IP — which means
        // one exhausted address must not lock out the rest of the internet.
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])
            ->postJson('/api/v1/auth/signup', $this->payload())
            ->assertCreated();
    }
}
