<?php

namespace Tests\Feature\Api;

use App\Models\GoogleWorkspaceConnection;
use App\Models\GoogleWorkspacePerson;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Email → Google Workspace (Jose, 2026-10-22): people on a Workspace, who they
 * are in EzBizi, and their Gmail signatures set one by one or in bulk.
 *
 * Google is faked; what is proved is what EzBizi sends it and decides.
 */
class GoogleWorkspaceManagementTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private const DOMAIN = 'a-1chimney.com';

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('ezbizi:sync-permissions')->assertSuccessful();

        config([
            'services.google_mail.workspace_client_email' => 'ezbizi@project.iam.gserviceaccount.com',
            'services.google_mail.workspace_private_key'  => $this->testPrivateKey(),
            'services.google_mail.workspace_client_id'    => '1234567890',
        ]);
    }

    private function testPrivateKey(): string
    {
        static $pem;

        if ($pem !== null) {
            return $pem;
        }

        if (getenv('OPENSSL_CONF') === false || getenv('OPENSSL_CONF') === '') {
            $local = getenv('LOCALAPPDATA');
            $cnf   = is_string($local)
                ? $local.'\\Microsoft\\WinGet\\Packages\\PHP.PHP.8.4_Microsoft.Winget.Source_8wekyb3d8bbwe\\extras\\ssl\\openssl.cnf'
                : '';

            if ($cnf !== '' && is_file($cnf)) {
                putenv('OPENSSL_CONF='.$cnf);
            }
        }

        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);

        if ($key === false || ! openssl_pkey_export($key, $pem)) {
            $this->fail('This machine cannot mint an RSA key. See docs/EZBIZI-DEV-ENVIRONMENT.md (OPENSSL_CONF).');
        }

        return $pem;
    }

    private function connect(array $t): GoogleWorkspaceConnection
    {
        return $this->inTenant($t['org'], function () use ($t) {
            $row = new GoogleWorkspaceConnection;
            $row->forceFill([
                'organization_id' => $t['org']->id,
                'scope_type'      => 'organization',
                'scope_id'        => $t['org']->id,
                'domain'          => self::DOMAIN,
                'admin_email'     => 'it-admin@'.self::DOMAIN,
                'status'          => GoogleWorkspaceConnection::STATUS_CONNECTED,
            ])->save();

            return $row;
        });
    }

    /** @param list<string> $refuse addresses Gmail refuses; $settingsAllowed false = scope not on the delegation */
    private function fakeGoogle(array $refuse = [], bool $settingsAllowed = true): void
    {
        Http::fake(function (Request $request) use ($refuse, $settingsAllowed) {
            $url = $request->url();

            if (str_contains($url, 'oauth2.googleapis.com/token')) {
                parse_str($request->body(), $form);
                $claims = json_decode(base64_decode(strtr(explode('.', $form['assertion'] ?? '..')[1], '-_', '+/')), true);

                return ! $settingsAllowed && str_contains($claims['scope'] ?? '', 'gmail.settings.basic')
                    ? Http::response(['error' => 'unauthorized_client'], 401)
                    : Http::response(['access_token' => 'ya29.t', 'expires_in' => 3600]);
            }

            if (str_contains($url, 'admin/directory/v1/users')) {
                return Http::response(['users' => [
                    [
                        'primaryEmail' => 'ana@'.self::DOMAIN, 'isMailboxSetup' => true,
                        'name' => ['fullName' => 'Ana Directory', 'givenName' => 'Ana', 'familyName' => 'Directory'],
                        'organizations' => [['title' => 'Dispatcher', 'primary' => true]],
                        'phones' => [['value' => '555-0100', 'primary' => true]],
                        'aliases' => ['Bookings@'.self::DOMAIN],
                    ],
                    [
                        'primaryEmail' => 'office@nj.'.self::DOMAIN, 'isMailboxSetup' => true,
                        'name' => ['fullName' => 'NJ Office'],
                    ],
                    [
                        'primaryEmail' => 'someone@elsewhere.com', 'isMailboxSetup' => true,
                        'name' => ['fullName' => 'Not ours'],
                    ],
                ]]);
            }

            if (str_contains($url, 'gmail.googleapis.com')) {
                foreach ($refuse as $address) {
                    if (str_contains($url, rawurlencode($address))) {
                        return Http::response(['error' => ['code' => 403]], 403);
                    }
                }

                return Http::response(['sendAsEmail' => 'x', 'signature' => '<b>Old</b>']);
            }

            return Http::response([], 404);
        });
    }

    private function base(array $t, GoogleWorkspaceConnection $c): string
    {
        return "/api/v1/email/workspaces/{$c->id}";
    }

    public function test_the_workspace_is_a_card_that_says_signatures_can_be_set(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $c = $this->connect($t);
        $this->fakeGoogle();

        $this->signedInAs($t['admin'])->getJson('/api/v1/email/workspaces')
            ->assertOk()
            ->assertJsonPath('data.0.id', $c->id)
            ->assertJsonPath('data.0.domain', self::DOMAIN)
            ->assertJsonPath('data.0.signatures_ready', true)
            ->assertJsonPath('data.0.people_count', 2);
    }

    /** Without gmail.settings.basic on the delegation, the card says what to add, and apply refuses. */
    public function test_a_workspace_without_the_settings_scope_says_what_to_add_in_google_admin(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $c = $this->connect($t);
        $this->fakeGoogle(settingsAllowed: false);

        $this->signedInAs($t['admin'])->getJson('/api/v1/email/workspaces')
            ->assertJsonPath('data.0.signatures_ready', false)
            ->assertJsonPath('data.0.signatures_reason', fn ($r) => str_contains($r, 'gmail.settings.basic'));

        $this->signedInAs($t['admin'])
            ->postJson($this->base($t, $c).'/signatures/apply', ['addresses' => ['ana@'.self::DOMAIN], 'html' => 'x'])
            ->assertStatus(422);
    }

    public function test_people_are_the_workspace_users_with_who_they_are_in_ezbizi(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $c = $this->connect($t);
        $this->fakeGoogle();

        // Same address in EzBizi: suggested as that person.
        $this->inTenant($t['org'], fn () => User::query()->whereKey($t['admin']->id)->update(['email' => 'office@nj.'.self::DOMAIN]));

        $people = collect($this->signedInAs($t['admin'])->getJson($this->base($t, $c).'/people')
            ->assertOk()->json('data.people'))->keyBy('address');

        $this->assertSame(['ana@'.self::DOMAIN, 'office@nj.'.self::DOMAIN], $people->keys()->sort()->values()->all(),
            'An address off the connected domain was listed.');
        $this->assertSame('Dispatcher', $people['ana@'.self::DOMAIN]['title']);
        $this->assertSame(['bookings@'.self::DOMAIN], $people['ana@'.self::DOMAIN]['aliases']);
        $this->assertNull($people['ana@'.self::DOMAIN]['user']);
        $this->assertSame('same_email', $people['office@nj.'.self::DOMAIN]['link']);
        $this->assertSame($t['admin']->id, $people['office@nj.'.self::DOMAIN]['user']['id']);

        // Where a signature's values would come from: EzBizi's person first, Google second.
        $this->assertSame(['value' => 'Dispatcher', 'from' => 'google'], $people['ana@'.self::DOMAIN]['fills']['title']);
        $this->assertSame(['value' => 'Ana Directory', 'from' => 'google'], $people['ana@'.self::DOMAIN]['fills']['name']);
        $this->assertSame(['value' => $t['admin']->name, 'from' => 'ezbizi'], $people['office@nj.'.self::DOMAIN]['fills']['name']);
    }

    public function test_an_address_can_be_linked_to_a_person_and_unlinked(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $c = $this->connect($t);
        $this->fakeGoogle();
        $eve = $this->userWithRole($t, 'Office', ['email.view']);

        $this->signedInAs($t['admin'])
            ->putJson($this->base($t, $c).'/people/ana@'.self::DOMAIN.'/link', ['user_id' => $eve->id])
            ->assertOk();

        $ana = collect($this->signedInAs($t['admin'])->getJson($this->base($t, $c).'/people')->json('data.people'))
            ->firstWhere('address', 'ana@'.self::DOMAIN);
        $this->assertSame('linked', $ana['link']);
        $this->assertSame($eve->id, $ana['user']['id']);

        $this->signedInAs($t['admin'])
            ->putJson($this->base($t, $c).'/people/ana@'.self::DOMAIN.'/link', ['user_id' => null])
            ->assertOk();

        $this->assertNull($this->inTenant($t['org'], fn () => GoogleWorkspacePerson::query()->first()->user_id));
    }

    /** Placeholders filled from EzBizi first, the directory second; values escaped. */
    public function test_the_preview_fills_the_template_from_ezbizi_and_escapes_it(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $c = $this->connect($t);
        $this->fakeGoogle();
        $eve = $this->userWithRole($t, 'Office', ['email.view']);
        $this->inTenant($t['org'], fn () => $eve->forceFill(['name' => 'Eve <S>', 'phone' => '555-0199'])->save());

        $this->signedInAs($t['admin'])
            ->putJson($this->base($t, $c).'/people/ana@'.self::DOMAIN.'/link', ['user_id' => $eve->id])->assertOk();

        $this->signedInAs($t['admin'])
            ->postJson($this->base($t, $c).'/signatures/preview', [
                'address' => 'ana@'.self::DOMAIN,
                'html'    => '<p>{{name}} · {{title}} · {{phone}} · {{email}} · {{unknown}}</p>',
            ])
            ->assertOk()
            ->assertJsonPath('data.html', '<p>Eve &lt;S&gt; · Dispatcher · 555-0199 · ana@'.self::DOMAIN.' · {{unknown}}</p>');
    }

    /** Bulk: one PATCH per address, each filled for that person; a refusal is reported, not fatal. */
    public function test_signatures_apply_in_bulk_and_report_each_address(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $c = $this->connect($t);
        $this->fakeGoogle(refuse: ['office@nj.'.self::DOMAIN]);

        $this->signedInAs($t['admin'])
            ->putJson($this->base($t, $c).'/signature-template', ['html' => '<p>{{name}}</p>'])->assertOk();

        $results = collect($this->signedInAs($t['admin'])
            ->postJson($this->base($t, $c).'/signatures/apply', [
                'addresses' => ['ana@'.self::DOMAIN, 'office@nj.'.self::DOMAIN, 'ghost@'.self::DOMAIN],
            ])
            ->assertOk()
            ->assertJsonPath('data.set', 1)
            ->json('data.results'))->keyBy('address');

        $this->assertTrue($results['ana@'.self::DOMAIN]['ok']);
        $this->assertFalse($results['office@nj.'.self::DOMAIN]['ok']);
        $this->assertFalse($results['ghost@'.self::DOMAIN]['ok']);

        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH'
            && str_contains($r->url(), '/users/'.rawurlencode('ana@'.self::DOMAIN).'/settings/sendAs/')
            && $r['signature'] === '<p>Ana Directory</p>');

        $this->assertNotNull($this->inTenant($t['org'], fn () => GoogleWorkspacePerson::query()
            ->where('address', 'ana@'.self::DOMAIN)->first()?->signature_applied_at));
    }

    /** A person's own signature wins over the template. */
    public function test_a_persons_own_signature_is_used_instead_of_the_template(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $c = $this->connect($t);
        $this->fakeGoogle();

        $this->signedInAs($t['admin'])->putJson($this->base($t, $c).'/signature-template', ['html' => 'Template'])->assertOk();
        $this->signedInAs($t['admin'])
            ->putJson($this->base($t, $c).'/people/ana@'.self::DOMAIN.'/signature', ['html' => 'Own {{first_name}}'])->assertOk();

        $this->signedInAs($t['admin'])
            ->postJson($this->base($t, $c).'/signatures/apply', ['addresses' => ['ana@'.self::DOMAIN, 'office@nj.'.self::DOMAIN]])
            ->assertOk()->assertJsonPath('data.set', 2);

        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH' && str_contains($r->url(), rawurlencode('ana@'.self::DOMAIN))
            && $r['signature'] === 'Own Ana');
        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH' && str_contains($r->url(), rawurlencode('office@nj.'.self::DOMAIN))
            && $r['signature'] === 'Template');
    }

    public function test_managing_needs_email_edit_and_an_address_on_the_domain(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $c = $this->connect($t);
        $this->fakeGoogle();
        // The tab without email.edit: may not manage. Settings alone no longer reaches it.
        $viewer = $this->userWithRole($t, 'Viewer', ['email.view', 'email.workspace.view']);
        $settingsOnly = $this->userWithRole($t, 'Settings', ['email.view', 'email.settings.view', 'email.edit']);

        $this->signedInAs($viewer)->getJson('/api/v1/email/workspaces')->assertForbidden();
        $this->signedInAs($settingsOnly)->getJson('/api/v1/email/workspaces')->assertForbidden();

        $this->signedInAs($t['admin'])
            ->putJson($this->base($t, $c).'/people/ana@evil.com/link', ['user_id' => null])
            ->assertNotFound();
    }
}
