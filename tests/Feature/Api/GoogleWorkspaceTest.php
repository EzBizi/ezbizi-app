<?php

namespace Tests\Feature\Api;

use App\Models\Company;
use App\Models\GoogleWorkspaceConnection;
use App\Models\Mailbox;
use App\Support\Email\GoogleOAuth;
use App\Support\Email\GoogleWorkspace;
use App\Support\Email\MailboxProvider;
use App\Support\Email\MailboxReaders;
use App\Support\Email\RecordingMailboxReader;
use App\Support\Email\WorkspaceMailboxReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Google Workspace as a third way to open a shared inbox.
 *
 * Domain-wide delegation: a Workspace admin proves they run the domain by
 * signing in, then EzBizi impersonates listed addresses with a service account.
 * Mail is still live; nothing is stored. Personal mailboxes are not imported —
 * an administrator picks shared addresses from the Directory.
 *
 * Google itself is faked. What is proved is every decision this application
 * makes: what it publishes, what it stores, what it refuses, and that tenant B
 * cannot claim tenant A's domain.
 */
class GoogleWorkspaceTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private const DOMAIN = 'a-1chimney.com';

    private const ADMIN = 'it-admin@a-1chimney.com';

    private const INFO = 'info@a-1chimney.com';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.google_mail.client_id'     => 'a-client-id.apps.googleusercontent.com',
            'services.google_mail.client_secret' => 'a-client-secret',
            'services.google_mail.redirect'      => 'https://app.ezbizi.test'.GoogleOAuth::CALLBACK_PATH,
            'app.url'                            => 'https://app.ezbizi.test',
        ]);
    }

    private function withServiceAccount(): void
    {
        config([
            'services.google_mail.workspace_client_email' => 'ezbizi@project.iam.gserviceaccount.com',
            // Catalogue `usable` only asks whether a key is present. Signing
            // tests call {@see withSigningKey} — minting a keypair here made
            // every Workspace test depend on OPENSSL_CONF, which Windows PHP
            // does not set (docs/EZBIZI-DEV-ENVIRONMENT.md).
            'services.google_mail.workspace_private_key'  => "-----BEGIN PRIVATE KEY-----\ntest\n-----END PRIVATE KEY-----",
            'services.google_mail.workspace_client_id'    => '1234567890',
        ]);
    }

    private function withSigningKey(): void
    {
        $this->withServiceAccount();

        config(['services.google_mail.workspace_private_key' => $this->testPrivateKey()]);
    }

    private function testPrivateKey(): string
    {
        static $pem;

        if ($pem !== null) {
            return $pem;
        }

        /*
         * Windows PHP ships openssl.cnf and does not point OPENSSL_CONF at it,
         * so openssl_pkey_new() fails for any key. The path is the one
         * docs/EZBIZI-DEV-ENVIRONMENT.md records. Linux already finds a config,
         * so this is a no-op there.
         */
        if (getenv('OPENSSL_CONF') === false || getenv('OPENSSL_CONF') === '') {
            $local = getenv('LOCALAPPDATA');
            $cnf   = is_string($local)
                ? $local.'\\Microsoft\\WinGet\\Packages\\PHP.PHP.8.4_Microsoft.Winget.Source_8wekyb3d8bbwe\\extras\\ssl\\openssl.cnf'
                : '';

            if ($cnf !== '' && is_file($cnf)) {
                putenv('OPENSSL_CONF='.$cnf);
            }
        }

        $key = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        if ($key === false || ! openssl_pkey_export($key, $pem) || ! is_string($pem) || $pem === '') {
            $this->fail(
                'This machine cannot mint an RSA key for the Workspace tests. '
                .'See docs/EZBIZI-DEV-ENVIRONMENT.md (OPENSSL_CONF).'
            );
        }

        return $pem;
    }

    /** @param array{org: \App\Models\Organization} $t */
    private function connectWorkspace(array $t, string $domain = self::DOMAIN, string $admin = self::ADMIN): GoogleWorkspaceConnection
    {
        return $this->inTenant($t['org'], function () use ($t, $domain, $admin) {
            $row = new GoogleWorkspaceConnection;
            $row->forceFill([
                'organization_id' => $t['org']->id,
                'scope_type'      => 'organization',
                'scope_id'        => $t['org']->id,
                'domain'          => $domain,
                'admin_email'     => $admin,
                'status'          => GoogleWorkspaceConnection::STATUS_CONNECTED,
            ])->save();

            return $row;
        });
    }

    private function fakeDirectory(string $primary = self::INFO, string $alias = 'jobs@a-1chimney.com'): void
    {
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'ya29.directory']),
            'admin.googleapis.com/admin/directory/v1/users*' => Http::response([
                'users' => [[
                    'primaryEmail'  => $primary,
                    'name'          => ['fullName' => 'Info'],
                    'isMailboxSetup' => true,
                    'aliases'       => [$alias],
                ]],
            ]),
        ]);
    }

    // ── catalogue ────────────────────────────────────────────────

    public function test_the_provider_list_includes_google_workspace(): void
    {
        $t = $this->provisionTenant('A1-Group');

        $byValue = collect(
            $this->signedInAs($t['admin'])->getJson('/api/v1/email/mailboxes')
                ->assertOk()->json('meta.providers')
        )->keyBy('value');

        $this->assertTrue($byValue->has('gmail_workspace'),
            'Google Workspace was not published as a provider, so the connect form cannot offer it.');

        $workspace = $byValue['gmail_workspace'];

        $this->assertFalse($workspace['needs_host']);
        $this->assertSame('directory', $workspace['connects_by']);
        $this->assertSame('sign_in', $byValue['gmail_oauth']['connects_by']);
        $this->assertSame('sign_in', $byValue['microsoft_oauth']['connects_by']);
        $this->assertSame('password', $byValue['imap']['connects_by']);
        $this->assertStringContainsString('Workspace', $workspace['label']);
        $this->assertStringContainsString('shared', strtolower((string) $workspace['caution']));
        $this->assertFalse($workspace['usable']);
        $this->assertStringContainsString('EzBizi', $workspace['unusable_because']);
    }

    public function test_workspace_is_usable_once_the_service_account_exists(): void
    {
        $this->withServiceAccount();
        $t = $this->provisionTenant('A1-Group');

        $workspace = collect(
            $this->signedInAs($t['admin'])->getJson('/api/v1/email/mailboxes')
                ->assertOk()->json('meta.providers')
        )->firstWhere('value', 'gmail_workspace');

        $this->assertTrue($workspace['usable']);
        $this->assertNull($workspace['unusable_because']);
    }

    public function test_a_workspace_reader_is_wrapped_like_the_other_two(): void
    {
        $this->withServiceAccount();
        $t = $this->provisionTenant('A1-Group');

        $this->inTenant($t['org'], function () use ($t) {
            $mailbox = Mailbox::create([
                'organization_id' => $t['org']->id,
                'scope_type'      => 'organization',
                'scope_id'        => $t['org']->id,
                'address'         => self::INFO,
                'display_name'    => 'Info',
                'provider'        => 'gmail_workspace',
            ]);

            $reader = app(MailboxReaders::class)->for($mailbox);

            $this->assertInstanceOf(RecordingMailboxReader::class, $reader);
            $this->assertInstanceOf(WorkspaceMailboxReader::class, $reader->inner);
        });
    }

    public function test_oauth_start_on_a_workspace_mailbox_is_the_wrong_door(): void
    {
        $this->withServiceAccount();
        $t = $this->provisionTenant('A1-Group');
        $this->connectWorkspace($t);

        $mailbox = $this->inTenant($t['org'], fn () => Mailbox::create([
            'organization_id' => $t['org']->id,
            'scope_type'      => 'organization',
            'scope_id'        => $t['org']->id,
            'address'         => self::INFO,
            'display_name'    => 'Info',
            'provider'        => MailboxProvider::GmailWorkspace->value,
        ]));

        $message = $this->signedInAs($t['admin'])
            ->postJson("/api/v1/email/mailboxes/{$mailbox->getKey()}/oauth/start")
            ->assertStatus(422)
            ->json('message');

        $this->assertStringContainsString(self::INFO, $message);
        $this->assertStringContainsString('Google', $message);
    }

    // ── bind ─────────────────────────────────────────────────────

    public function test_a_franchise_user_cannot_connect_the_company_domain(): void
    {
        $this->withServiceAccount();
        $t = $this->provisionTenant('A1-Group');
        $branch = $this->userWithRole($t, 'Branch', ['email.view', 'email.edit'], onlyAt: $t['franchise']);

        $this->signedInAs($branch)
            ->postJson('/api/v1/email/workspace/oauth/start', ['domain' => self::DOMAIN])
            ->assertForbidden();
    }

    public function test_a_personal_gmail_domain_is_refused(): void
    {
        $this->withServiceAccount();
        $t = $this->provisionTenant('A1-Group');

        $message = $this->signedInAs($t['admin'])
            ->postJson('/api/v1/email/workspace/oauth/start', ['domain' => 'gmail.com'])
            ->assertStatus(422)
            ->json('message');

        $this->assertStringContainsString('Workspace', $message);
        $this->assertStringNotContainsString('::', $message);
    }

    public function test_another_organization_cannot_claim_a_connected_domain(): void
    {
        $this->withServiceAccount();
        $a = $this->provisionTenant('A1-Group');
        $b = $this->provisionTenant('Other-Co');
        $this->connectWorkspace($a);

        $message = $this->signedInAs($b['admin'])
            ->postJson('/api/v1/email/workspace/oauth/start', ['domain' => self::DOMAIN])
            ->assertStatus(409)
            ->json('message');

        $this->assertStringContainsString(self::DOMAIN, $message);
        $this->assertStringContainsString('another', $message);
    }

    public function test_a_workspace_admin_sign_in_binds_the_domain_and_stores_no_secret(): void
    {
        $this->withSigningKey();
        $t = $this->provisionTenant('A1-Group');

        Http::fake([
            'oauth2.googleapis.com/token' => Http::sequence()
                ->push(['access_token' => 'ya29.identity'])
                ->push(['access_token' => 'ya29.directory']),
            'openidconnect.googleapis.com/v1/userinfo' => Http::response([
                'email' => self::ADMIN,
                'hd'    => self::DOMAIN,
            ]),
            'admin.googleapis.com/admin/directory/v1/users*' => Http::response([
                'users' => [[
                    'primaryEmail'   => self::INFO,
                    'name'           => ['fullName' => 'Info'],
                    'isMailboxSetup' => true,
                ]],
            ]),
        ]);

        $url = $this->signedInAs($t['admin'])
            ->postJson('/api/v1/email/workspace/oauth/start', ['domain' => self::DOMAIN])
            ->assertOk()->json('data.authorization_url');

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        $this->assertSame('openid email', $query['scope']);
        $this->assertSame(self::DOMAIN, $query['hd']);
        $this->assertArrayNotHasKey('access_type', $query);

        $this->get('/api/v1/email/google/callback?'.http_build_query([
            'state' => $query['state'],
            'code'  => 'an-authorization-code',
        ]))->assertOk()->assertSee(self::DOMAIN, false);

        $connection = $this->inTenant($t['org'], fn () => GoogleWorkspaceConnection::query()->first());

        $this->assertNotNull($connection);
        $this->assertSame(self::DOMAIN, $connection->domain);
        $this->assertSame(self::ADMIN, $connection->admin_email);
        $this->assertSame(GoogleWorkspaceConnection::STATUS_CONNECTED, $connection->status);
        $this->assertArrayNotHasKey('credentials', $connection->toArray());
        $this->assertArrayNotHasKey('private_key', $connection->toArray());
    }

    public function test_a_consumer_gmail_account_cannot_bind_a_workspace(): void
    {
        $this->withServiceAccount();
        $t = $this->provisionTenant('A1-Group');

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'ya29.identity']),
            'openidconnect.googleapis.com/v1/userinfo' => Http::response([
                'email' => 'person@gmail.com',
            ]),
        ]);

        $url = $this->signedInAs($t['admin'])
            ->postJson('/api/v1/email/workspace/oauth/start', ['domain' => self::DOMAIN])
            ->assertOk()->json('data.authorization_url');

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        $html = $this->get('/api/v1/email/google/callback?'.http_build_query([
            'state' => $query['state'],
            'code'  => 'an-authorization-code',
        ]))->assertStatus(422)->getContent();

        $this->assertStringContainsString('personal Gmail', $html);
        $this->assertTrue($this->inTenant($t['org'], fn () => GoogleWorkspaceConnection::query()->doesntExist()));
    }

    // ── directory and attach ─────────────────────────────────────

    public function test_the_directory_lists_users_and_aliases_not_groups(): void
    {
        $this->withSigningKey();
        $t = $this->provisionTenant('A1-Group');
        $this->connectWorkspace($t);
        $this->fakeDirectory();

        $listed = $this->signedInAs($t['admin'])
            ->getJson('/api/v1/email/workspace/directory')
            ->assertOk()->json('data');

        $addresses = array_column($listed, 'address');

        $this->assertContains(self::INFO, $addresses);
        $this->assertContains('jobs@a-1chimney.com', $addresses);
        $this->assertNotContains('everyone@a-1chimney.com', $addresses);
    }

    /**
     * Google adds `user@domain.test-google-a.com` as a non-editable alias
     * for every account. They are not inboxes. Listing them doubles the
     * picker and invites somebody to connect a fake address.
     */
    public function test_the_directory_omits_google_test_domain_aliases(): void
    {
        $this->withSigningKey();
        $t = $this->provisionTenant('A1-Group');
        $this->connectWorkspace($t);

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'ya29.directory']),
            'admin.googleapis.com/admin/directory/v1/users*' => Http::response([
                'users' => [[
                    'primaryEmail'  => self::INFO,
                    'name'          => ['fullName' => 'Info'],
                    'isMailboxSetup' => true,
                    'aliases'       => ['jobs@a-1chimney.com'],
                    'nonEditableAliases' => [
                        'info@a-1chimney.com.test-google-a.com',
                        'jobs@a-1chimney.com.test-google-a.com',
                    ],
                ]],
            ]),
        ]);

        $addresses = $this->signedInAs($t['admin'])
            ->getJson('/api/v1/email/workspace/directory')
            ->assertOk()
            ->json('data.*.address');

        $this->assertSame([self::INFO, 'jobs@a-1chimney.com'], $addresses);
    }

    /**
     * A Workspace answers `domain=` and `customer=` differently, the way Google does.
     *
     * `domain=a-1chimney.com` returns only users whose PRIMARY address is on
     * exactly that domain; `customer=my_customer` returns every user in the
     * account. Measured on the real A-1 Workspace on 2026-10-06: 1 subdomain
     * address by domain, 39 across 35 subdomains by customer.
     *
     * Every other fake in this file answers any query identically, which is
     * why none of them could tell the two queries apart -- a test of this fix
     * against one of those would pass against the broken code too.
     */
    private function fakeWorkspaceWithSubdomains(): void
    {
        $user = fn (string $primary, string $name) => [
            'primaryEmail'   => $primary,
            'name'           => ['fullName' => $name],
            'isMailboxSetup' => true,
        ];

        Http::fake(function ($request) use ($user) {
            if (str_contains($request->url(), 'oauth2.googleapis.com/token')) {
                return Http::response(['access_token' => 'ya29.directory']);
            }

            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            $onPrimaryDomain = [$user(self::INFO, 'Info')];

            if (($query['customer'] ?? null) !== 'my_customer') {
                return Http::response(['users' => $onPrimaryDomain]);
            }

            return Http::response(['users' => [
                ...$onPrimaryDomain,
                $user('office@nj.a-1chimney.com', 'NJ Office'),
                $user('office@atl.a-1chimney.com', 'Atlanta Office'),
                // Held by the same account but NOT this domain or a subdomain.
                $user('hello@premiergarage.com', 'Premier Garage'),
                // The dot is the boundary: not a subdomain.
                $user('office@evil-a-1chimney.com', 'Lookalike'),
            ]]);
        });
    }

    /**
     * Jose, via Bar: "Subdomain emails not showing on workspace add email like
     * office@nj.a-1chimney.com".
     */
    public function test_the_directory_offers_branch_subdomain_addresses(): void
    {
        $this->withSigningKey();
        $t = $this->provisionTenant('A1-Group');
        $this->connectWorkspace($t);
        $this->fakeWorkspaceWithSubdomains();

        $addresses = $this->signedInAs($t['admin'])
            ->getJson('/api/v1/email/workspace/directory')
            ->assertOk()
            ->json('data.*.address');

        $this->assertContains('office@nj.a-1chimney.com', $addresses);
        $this->assertContains('office@atl.a-1chimney.com', $addresses);
        $this->assertContains(self::INFO, $addresses);
    }

    /**
     * Asking by customer returns every domain in the account. Only the
     * connected domain and its subdomains may come back out.
     */
    public function test_the_directory_admits_no_other_domain_the_account_holds(): void
    {
        $this->withSigningKey();
        $t = $this->provisionTenant('A1-Group');
        $this->connectWorkspace($t);
        $this->fakeWorkspaceWithSubdomains();

        $addresses = $this->signedInAs($t['admin'])
            ->getJson('/api/v1/email/workspace/directory')
            ->assertOk()
            ->json('data.*.address');

        $this->assertNotContains('hello@premiergarage.com', $addresses);
        $this->assertNotContains('office@evil-a-1chimney.com', $addresses);
    }

    /**
     * Listed is not enough: the subdomain address must also be OPENABLE, which
     * is a second host check in `unavailableBecause`. Fixing only the directory
     * would offer an address that then refuses to connect.
     */
    public function test_a_subdomain_address_can_be_picked_and_opened(): void
    {
        $this->withSigningKey();
        $t = $this->provisionTenant('A1-Group');
        $this->connectWorkspace($t);
        $this->fakeWorkspaceWithSubdomains();

        $row = $this->signedInAs($t['admin'])->postJson('/api/v1/email/mailboxes', [
            'scope_type'   => 'organization',
            'scope_id'     => $t['org']->id,
            'address'      => 'office@nj.a-1chimney.com',
            'display_name' => 'NJ Office',
            'provider'     => MailboxProvider::GmailWorkspace->value,
        ])->assertCreated()->json('data');

        $this->assertSame('connected', $row['status']);

        $refusal = $this->inTenant($t['org'], fn () => app(GoogleWorkspace::class)
            ->unavailableBecause(Mailbox::query()->firstOrFail()));

        $this->assertNull($refusal, 'A branch subdomain address was listed and then refused to open.');
    }

    /**
     * Jose 11-12: connecting files the inbox itself. office@nj goes where its
     * sibling at the same domain is filed; office@atl, with no sibling, stays
     * where the person is standing. The connect form is offered the same.
     */
    public function test_an_inbox_is_filed_where_its_domain_siblings_are(): void
    {
        $this->withSigningKey();
        $t = $this->provisionTenant('A1-Group');
        $this->connectWorkspace($t);
        $this->fakeWorkspaceWithSubdomains();

        $this->inTenant($t['org'], fn () => Mailbox::create([
            'organization_id' => $t['org']->id,
            'scope_type'      => 'franchise',
            'scope_id'        => $t['franchise']->id,
            'address'         => 'sales@nj.a-1chimney.com',
            'display_name'    => 'NJ Sales',
            'provider'        => MailboxProvider::Imap->value,
        ]));

        $this->signedInAs($t['admin'])->getJson('/api/v1/email/mailboxes/suggest-scope?address=office@nj.a-1chimney.com')
            ->assertOk()->assertJsonPath('data', ['scope_type' => 'franchise', 'scope_id' => $t['franchise']->id]);
        $this->signedInAs($t['admin'])->getJson('/api/v1/email/mailboxes/suggest-scope?address=office@atl.a-1chimney.com')
            ->assertOk()->assertJsonPath('data', null);

        $this->batch($t, ['office@nj.a-1chimney.com', 'office@atl.a-1chimney.com'])->assertCreated();

        $filed = $this->inTenant($t['org'], fn () => Mailbox::query()->pluck('scope_type', 'address')->all());
        $this->assertSame('franchise', $filed['office@nj.a-1chimney.com']);
        $this->assertSame('organization', $filed['office@atl.a-1chimney.com']);
    }

    /** How many times the directory itself was read. */
    private function directoryReads(): int
    {
        return Http::recorded(fn ($request) => str_contains($request->url(), 'admin/directory/v1/users'))->count();
    }

    /** @param list<string> $addresses */
    private function batch(array $t, array $addresses, ?\App\Models\User $as = null): \Illuminate\Testing\TestResponse
    {
        return $this->signedInAs($as ?? $t['admin'])->postJson('/api/v1/email/mailboxes/workspace-batch', [
            'scope_type' => 'organization',
            'scope_id'   => $t['org']->id,
            'addresses'  => array_map(fn ($a) => ['address' => $a, 'display_name' => strtok($a, '@')], $addresses),
        ]);
    }

    /**
     * Jose: "add ability to add emails by bulk as well, when selecting
     * workspace". Several listed addresses, one request, all connected -- and
     * the directory read ONCE, not once per address.
     */
    public function test_several_workspace_addresses_are_added_in_one_go(): void
    {
        $this->withSigningKey();
        $t = $this->provisionTenant('A1-Group');
        $this->connectWorkspace($t);
        $this->fakeWorkspaceWithSubdomains();

        $data = $this->batch($t, ['office@nj.a-1chimney.com', 'office@atl.a-1chimney.com', self::INFO])
            ->assertCreated()
            ->json('data');

        $this->assertSame(
            ['office@nj.a-1chimney.com', 'office@atl.a-1chimney.com', self::INFO],
            array_column($data['added'], 'address'),
        );
        $this->assertSame([], $data['skipped']);
        $this->assertSame(['connected'], array_values(array_unique(array_column($data['added'], 'status'))));
        $this->assertSame(1, $this->directoryReads(), 'The directory was read once per address.');
    }

    /**
     * Partial success is reported, not hidden: one address already there and
     * one not on the directory are skipped WITH a reason, and the rest land.
     */
    public function test_a_batch_skips_what_it_cannot_add_and_says_why(): void
    {
        $this->withSigningKey();
        $t = $this->provisionTenant('A1-Group');
        $this->connectWorkspace($t);
        $this->fakeWorkspaceWithSubdomains();

        $this->batch($t, [self::INFO])->assertCreated();

        $data = $this->batch($t, [self::INFO, 'hello@premiergarage.com', 'office@nj.a-1chimney.com', 'office@nj.a-1chimney.com'])
            ->assertCreated()
            ->json('data');

        $this->assertSame(['office@nj.a-1chimney.com'], array_column($data['added'], 'address'), 'Added twice, or not at all.');

        $skipped = array_column($data['skipped'], 'reason', 'address');
        $this->assertStringContainsString('already been added', $skipped[self::INFO]);
        $this->assertStringContainsString('directory', $skipped['hello@premiergarage.com']);
    }

    /** Nothing addable is a refusal, with the reasons -- not an empty success. */
    public function test_a_batch_that_adds_nothing_is_refused(): void
    {
        $this->withSigningKey();
        $t = $this->provisionTenant('A1-Group');
        $this->connectWorkspace($t);
        $this->fakeWorkspaceWithSubdomains();

        $this->batch($t, ['hello@premiergarage.com'])
            ->assertStatus(422)
            ->assertJsonPath('data.added', []);
    }

    /** Same authority as adding one. */
    public function test_a_batch_needs_permission_to_add_an_inbox(): void
    {
        $this->withSigningKey();
        $t = $this->provisionTenant('A1-Group');
        $this->connectWorkspace($t);
        $this->fakeWorkspaceWithSubdomains();

        $viewer = $this->userWithRole($t, 'Viewer', ['email.view', 'email.settings.view']);

        $this->batch($t, [self::INFO], $viewer)->assertForbidden();
        $this->assertTrue($this->inTenant($t['org'], fn () => Mailbox::query()->doesntExist()));
    }

    public function test_picking_a_listed_address_connects_it_without_a_mailbox_secret(): void
    {
        $this->withSigningKey();
        $t = $this->provisionTenant('A1-Group');
        $this->connectWorkspace($t);
        $this->fakeDirectory();

        $row = $this->signedInAs($t['admin'])->postJson('/api/v1/email/mailboxes', [
            'scope_type'   => 'organization',
            'scope_id'     => $t['org']->id,
            'address'      => self::INFO,
            'display_name' => 'Info',
            'provider'     => MailboxProvider::GmailWorkspace->value,
        ])->assertCreated()->json('data');

        $this->assertSame('connected', $row['status']);
        $this->assertArrayNotHasKey('credentials', $row);
        $this->assertSame(MailboxProvider::GmailWorkspace->value, $row['provider']);

        $mailbox = $this->inTenant($t['org'], fn () => Mailbox::query()->first());

        $this->assertNotNull($mailbox);
        $this->assertFalse($mailbox->hasCredential());
        $this->assertSame(Mailbox::STATUS_CONNECTED, $mailbox->status);
        $this->assertNull($mailbox->getAttributes()['credentials'] ?? null);
    }

    public function test_an_address_not_on_the_directory_is_refused_and_not_stored(): void
    {
        $this->withSigningKey();
        $t = $this->provisionTenant('A1-Group');
        $this->connectWorkspace($t);
        $this->fakeDirectory();

        $message = $this->signedInAs($t['admin'])->postJson('/api/v1/email/mailboxes', [
            'scope_type'   => 'organization',
            'scope_id'     => $t['org']->id,
            'address'      => 'payroll@a-1chimney.com',
            'display_name' => 'Payroll',
            'provider'     => MailboxProvider::GmailWorkspace->value,
        ])->assertStatus(422)->json('message');

        $this->assertStringContainsString('payroll@a-1chimney.com', $message);
        $this->assertStringContainsString('directory', $message);
        $this->assertTrue($this->inTenant($t['org'], fn () => Mailbox::query()->doesntExist()));
    }

    // ── reader ───────────────────────────────────────────────────

    public function test_opening_a_workspace_mailbox_impersonates_that_address(): void
    {
        $this->withSigningKey();
        $t = $this->provisionTenant('A1-Group');
        $this->connectWorkspace($t);

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'ya29.mail']),
            'gmail.googleapis.com/gmail/v1/users/me/threads*' => Http::response([
                'threads' => [],
                'resultSizeEstimate' => 0,
            ]),
        ]);

        $mailbox = $this->inTenant($t['org'], function () use ($t) {
            $mailbox = Mailbox::create([
                'organization_id' => $t['org']->id,
                'scope_type'      => 'organization',
                'scope_id'        => $t['org']->id,
                'address'         => self::INFO,
                'display_name'    => 'Info',
                'provider'        => MailboxProvider::GmailWorkspace->value,
            ]);
            $mailbox->markConnectedWithoutSecret();
            $mailbox->save();

            return $mailbox;
        });

        $this->inTenant($t['org'], fn () => app(MailboxReaders::class)->for($mailbox)->list($mailbox));

        Http::assertSent(function ($request) {
            if ($request->url() !== 'https://oauth2.googleapis.com/token') {
                return false;
            }

            $this->assertSame('urn:ietf:params:oauth:grant-type:jwt-bearer', $request['grant_type']);

            $parts = explode('.', (string) $request['assertion']);
            $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);

            $this->assertSame(self::INFO, $payload['sub']);
            $this->assertStringContainsString(GoogleOAuth::SCOPE_READ, $payload['scope']);
            $this->assertStringContainsString(GoogleOAuth::SCOPE_SEND, $payload['scope']);

            return true;
        });
    }

    /** A connected Workspace inbox, and the requests made to Google kept for counting. */
    private function workspaceInboxForSpeed(array $t): Mailbox
    {
        return $this->inTenant($t['org'], function () use ($t) {
            $mailbox = Mailbox::create([
                'organization_id' => $t['org']->id,
                'scope_type'      => 'organization',
                'scope_id'        => $t['org']->id,
                'address'         => self::INFO,
                'display_name'    => 'Info',
                'provider'        => MailboxProvider::GmailWorkspace->value,
            ]);
            $mailbox->markConnectedWithoutSecret();
            $mailbox->save();

            return $mailbox;
        });
    }

    private function tokenMints(): int
    {
        return Http::recorded(fn ($request) => $request->url() === 'https://oauth2.googleapis.com/token')->count();
    }

    /**
     * ONE token for many reads.
     *
     * Measured on production 2026-10-06: 84 token requests for 42 opens, at
     * ~150 ms each -- every Gmail call minted a fresh Workspace token, and an
     * open makes two calls.
     */
    public function test_a_workspace_token_is_reused_across_reads(): void
    {
        $this->withSigningKey();
        $t = $this->provisionTenant('A1-Group');
        $this->connectWorkspace($t);

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'ya29.mail', 'expires_in' => 3599]),
            'gmail.googleapis.com/*' => Http::response(['threads' => [], 'resultSizeEstimate' => 0]),
        ]);

        $mailbox = $this->workspaceInboxForSpeed($t);
        $reader = fn () => app(MailboxReaders::class)->for($mailbox);

        $this->inTenant($t['org'], function () use ($reader, $mailbox) {
            $reader()->list($mailbox);
            $reader()->list($mailbox);
            $reader()->list($mailbox);
        });

        $this->assertSame(1, $this->tokenMints());
    }

    /**
     * A token Gmail refuses is dropped and a fresh one minted, once.
     *
     * The other half of caching: without it a revoked delegation would keep
     * failing for most of an hour, because the 401 retry was handed the same
     * cached token.
     */
    public function test_a_refused_workspace_token_is_replaced_not_reused(): void
    {
        $this->withSigningKey();
        $t = $this->provisionTenant('A1-Group');
        $this->connectWorkspace($t);

        $gmailCalls = 0;
        Http::fake(function ($request) use (&$gmailCalls) {
            if (str_contains($request->url(), 'oauth2.googleapis.com/token')) {
                return Http::response(['access_token' => 'ya29.mail', 'expires_in' => 3599]);
            }

            // The first Gmail call refuses the token; every later one accepts.
            return ++$gmailCalls === 1
                ? Http::response(['error' => ['code' => 401]], 401)
                : Http::response(['threads' => [], 'resultSizeEstimate' => 0]);
        });

        $mailbox = $this->workspaceInboxForSpeed($t);

        $this->inTenant($t['org'], fn () => app(MailboxReaders::class)->for($mailbox)->list($mailbox));

        $this->assertSame(2, $this->tokenMints(), 'The refused token was reused instead of replaced.');
    }

    /**
     * The health probe still asks Google every time.
     *
     * That is why the cache sits on the mail path and not inside
     * `impersonate()`: a cached token would let "can EzBizi still open these
     * mailboxes?" say yes for most of an hour after an administrator revoked
     * the delegation.
     */
    public function test_the_delegation_probe_is_never_answered_from_the_cache(): void
    {
        $this->withSigningKey();
        $t = $this->provisionTenant('A1-Group');
        $this->connectWorkspace($t);

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'ya29.mail', 'expires_in' => 3599]),
            'gmail.googleapis.com/*' => Http::response(['threads' => [], 'resultSizeEstimate' => 0]),
        ]);

        $mailbox = $this->workspaceInboxForSpeed($t);
        $this->inTenant($t['org'], fn () => app(MailboxReaders::class)->for($mailbox)->list($mailbox));
        $this->assertSame(1, $this->tokenMints());

        $probe = new \ReflectionMethod(GoogleWorkspace::class, 'canImpersonate');
        $probe->setAccessible(true);

        // TWICE. Once only proves the probe does not borrow the MAIL cache; a
        // cache of its own would still pass a single call and lie on the
        // second -- which is the one that matters, after access was revoked.
        $probe->invoke(app(GoogleWorkspace::class), self::INFO, [GoogleOAuth::SCOPE_READ]);
        $probe->invoke(app(GoogleWorkspace::class), self::INFO, [GoogleOAuth::SCOPE_READ]);

        $this->assertSame(3, $this->tokenMints(), 'The probe was answered from a cache.');
    }

    public function test_a_company_workspace_is_hidden_from_a_sibling_company(): void
    {
        $this->withServiceAccount();
        $t = $this->provisionTenant('A1-Group');
        $premier = $this->inTenant($t['org'], fn () => Company::create([
            'organization_id' => $t['org']->id,
            'name'            => 'Premier Garage Repair',
        ]));

        $this->inTenant($t['org'], function () use ($t) {
            $row = new GoogleWorkspaceConnection;
            $row->forceFill([
                'organization_id' => $t['org']->id,
                'scope_type'      => 'company',
                'scope_id'        => $t['company']->id,
                'domain'          => self::DOMAIN,
                'admin_email'     => self::ADMIN,
                'status'          => GoogleWorkspaceConnection::STATUS_PENDING,
                'status_reason'   => 'waiting',
            ])->save();
        });

        $this->lookingAt($t['admin'], 'company', $premier->id);

        $body = $this->signedInAs($t['admin']->fresh())
            ->getJson('/api/v1/email/workspace')
            ->assertOk()->json('data');

        $this->assertTrue($body['can_connect']);
        $this->assertSame([], $body['connections']);
        $this->assertSame('company', $body['view']['type']);
        $this->assertSame($premier->id, $body['view']['id']);
    }

    public function test_the_organization_view_lists_a_company_workspace(): void
    {
        $this->withServiceAccount();
        $t = $this->provisionTenant('A1-Group');

        $this->inTenant($t['org'], function () use ($t) {
            $row = new GoogleWorkspaceConnection;
            $row->forceFill([
                'organization_id' => $t['org']->id,
                'scope_type'      => 'company',
                'scope_id'        => $t['company']->id,
                'domain'          => self::DOMAIN,
                'admin_email'     => self::ADMIN,
                'status'          => GoogleWorkspaceConnection::STATUS_CONNECTED,
            ])->save();
        });

        $body = $this->signedInAs($t['admin'])
            ->getJson('/api/v1/email/workspace')
            ->assertOk()->json('data');

        $this->assertTrue($body['can_connect']);
        $this->assertCount(1, $body['connections']);
        $this->assertSame(self::DOMAIN, $body['connections'][0]['domain']);
        $this->assertSame('company', $body['connections'][0]['scope_type']);
        $this->assertFalse($body['connections'][0]['is_current_view']);
    }

    public function test_pending_workspace_publishes_a_step_by_step_google_admin_guide(): void
    {
        $this->withServiceAccount();
        Http::fake(['*' => Http::response(['error' => 'unauthorized_client'], 401)]);
        $t = $this->provisionTenant('A1-Group');
        $this->connectWorkspace($t);
        $this->inTenant($t['org'], function () {
            GoogleWorkspaceConnection::query()->update([
                'status'        => GoogleWorkspaceConnection::STATUS_PENDING,
                'status_reason' => 'waiting',
            ]);
        });

        $body = $this->signedInAs($t['admin'])
            ->getJson('/api/v1/email/workspace')
            ->assertOk()->json('data');

        $steps = $body['connections'][0]['setup_steps'];

        $this->assertCount(4, $steps);
        $this->assertSame('https://admin.google.com/ac/owl/domainwidedelegation', $steps[0]['url']);
        $this->assertSame('1234567890', $steps[1]['copy']);
        $this->assertStringContainsString('gmail.readonly', $steps[2]['copy']);
        $this->assertStringContainsString('gmail.send', $steps[2]['copy']);
        $this->assertStringContainsString('gmail.modify', $steps[2]['copy']);
        $this->assertStringContainsString('admin.directory.user.readonly', $steps[2]['copy']);
        $this->assertSame('pending', $body['connections'][0]['health']['status']);
        $this->assertNull($body['marketplace_listing_url']);
    }

    public function test_a_marketplace_listing_is_the_first_install_step(): void
    {
        $this->withServiceAccount();
        config([
            'services.google_mail.marketplace_listing_url' =>
                'https://workspace.google.com/marketplace/app/ezbizi/247590132639',
        ]);
        Http::fake(['*' => Http::response(['error' => 'unauthorized_client'], 401)]);
        $t = $this->provisionTenant('A1-Group');
        $this->connectWorkspace($t);
        $this->inTenant($t['org'], function () {
            GoogleWorkspaceConnection::query()->update([
                'status'        => GoogleWorkspaceConnection::STATUS_PENDING,
                'status_reason' => 'waiting',
            ]);
        });

        $body = $this->signedInAs($t['admin'])
            ->getJson('/api/v1/email/workspace')
            ->assertOk()->json('data');

        $this->assertSame(
            'https://workspace.google.com/marketplace/app/ezbizi/247590132639',
            $body['marketplace_listing_url'],
        );
        $steps = $body['connections'][0]['setup_steps'];
        $this->assertSame('Install EzBizi in Google', $steps[0]['title']);
        $this->assertSame(
            'https://workspace.google.com/marketplace/app/ezbizi/247590132639',
            $steps[0]['url'],
        );
        $this->assertStringContainsString('gmail.modify', json_encode($steps));
    }

    public function test_connected_workspace_names_a_missing_modify_scope(): void
    {
        $this->withSigningKey();
        Http::fake(function ($request) {
            if (! str_contains($request->url(), 'oauth2.googleapis.com/token')) {
                return Http::response(['error' => 'unexpected'], 500);
            }

            $jwt = (string) $request['assertion'];
            $parts = explode('.', $jwt);
            $json = base64_decode(strtr($parts[1] ?? '', '-_', '+/'), true);
            $payload = is_string($json) ? json_decode($json, true) : [];
            $scope = is_array($payload) ? (string) ($payload['scope'] ?? '') : '';

            if (str_contains($scope, 'gmail.modify')) {
                return Http::response(['error' => 'unauthorized_client'], 401);
            }

            return Http::response(['access_token' => 'ya29.x'], 200);
        });

        $t = $this->provisionTenant('A1-Group');
        $this->connectWorkspace($t);

        $body = $this->signedInAs($t['admin'])
            ->getJson('/api/v1/email/workspace')
            ->assertOk()->json('data.connections.0');

        $health = $body['health'];
        $this->assertSame('needs_attention', $health['status']);
        $this->assertTrue($health['probed']);
        $this->assertStringContainsString('gmail.modify', $health['summary']);
        $this->assertNotEmpty($body['setup_steps']);

        $byId = collect($health['scopes'])->keyBy('id');
        $this->assertTrue($byId['gmail.readonly']['ok']);
        $this->assertTrue($byId['gmail.send']['ok']);
        $this->assertFalse($byId['gmail.modify']['ok']);
        $this->assertTrue($byId['directory']['ok']);
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
}
