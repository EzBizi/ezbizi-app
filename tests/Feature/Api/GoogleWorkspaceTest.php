<?php

namespace Tests\Feature\Api;

use App\Models\Company;
use App\Models\GoogleWorkspaceConnection;
use App\Models\Mailbox;
use App\Support\Email\GoogleOAuth;
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
            'gmail.googleapis.com/gmail/v1/users/me/messages*' => Http::response([
                'messages' => [],
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
        $this->assertStringContainsString('admin.directory.user.readonly', $steps[2]['copy']);
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
