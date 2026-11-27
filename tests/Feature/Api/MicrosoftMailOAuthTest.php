<?php

namespace Tests\Feature\Api;

use App\Models\AuditEvent;
use App\Models\Mailbox;
use App\Models\MicrosoftMailSignIn;
use App\Models\Organization;
use App\Support\Email\MicrosoftOAuth;
use App\Support\Email\MicrosoftOAuthSignIns;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Signing in to Microsoft 365 for a shared Outlook inbox — copy of the Gmail
 * flow, with a second callback because a redirect URI is registered at one
 * provider, character for character.
 */
class MicrosoftMailOAuthTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private const CALLBACK = '/api/v1/email/microsoft/callback';

    private const ADDRESS = 'info@a-1chimney.com';

    private const REFRESH = 'refresh-token-1-a-1-chimney-shared-inbox';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.microsoft_mail.client_id'     => 'a-microsoft-client-id',
            'services.microsoft_mail.client_secret' => 'a-microsoft-client-secret',
            'services.microsoft_mail.redirect'      => 'https://app.ezbizi.test'.MicrosoftOAuth::CALLBACK_PATH,
        ]);
    }

    /** @param array{org: Organization} $t */
    private function inbox(array $t, string $address = self::ADDRESS): Mailbox
    {
        return $this->inTenant($t['org'], fn () => Mailbox::create([
            'organization_id' => $t['org']->id,
            'scope_type'      => 'organization',
            'scope_id'        => $t['org']->id,
            'address'         => $address,
            'display_name'    => 'Info',
            'provider'        => 'microsoft_oauth',
        ]));
    }

    private function tokenBody(?string $refresh = self::REFRESH, ?array $scopes = null): array
    {
        $token = [
            'access_token' => 'an-access-token',
            'expires_in'   => 3599,
            'scope'        => implode(' ', $scopes ?? [
                MicrosoftOAuth::SCOPE_READ,
                MicrosoftOAuth::SCOPE_SEND,
                MicrosoftOAuth::SCOPE_OFFLINE,
                MicrosoftOAuth::SCOPE_PROFILE,
            ]),
        ];

        return $refresh === null ? $token : [...$token, 'refresh_token' => $refresh];
    }

    /**
     * CALL THIS ONCE PER TEST. Http::fake() merges; the first matching stub wins.
     */
    private function fakeMicrosoft(
        string $signedInAs = self::ADDRESS,
        ?array $scopes = null,
        ?string $refresh = self::REFRESH,
        int $profileStatus = 200,
    ): void {
        Http::fake([
            'login.microsoftonline.com/common/oauth2/v2.0/token'  => Http::response($this->tokenBody($refresh, $scopes)),
            'login.microsoftonline.com/common/oauth2/v2.0/logout' => Http::response([]),
            'graph.microsoft.com/v1.0/me' => Http::response(
                $profileStatus === 200 ? ['mail' => $signedInAs, 'userPrincipalName' => $signedInAs] : [],
                $profileStatus,
            ),
        ]);
    }

    private function beginSignIn(array $t, Mailbox $mailbox, ?\App\Models\User $as = null): string
    {
        $url = $this->signedInAs($as ?? $t['admin'])
            ->postJson("/api/v1/email/mailboxes/{$mailbox->getKey()}/oauth/start")
            ->assertOk()->json('data.authorization_url');

        return $this->queryOf($url)['state'];
    }

    /** @return array<string, string> */
    private function queryOf(string $url): array
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return $query;
    }

    public function test_starting_a_sign_in_takes_the_same_authority_as_connecting_by_password(): void
    {
        $t       = $this->provisionTenant('A1-Group');
        $mailbox = $this->inbox($t);

        // Holds the Settings TAB and not `email.edit`, deliberately.
        //
        // Since 2026-09-17 these routes sit behind `email.settings.view` as
        // well, and a Reader without it is refused by the tab -- which would
        // pass this assertion while proving nothing about the authority the
        // docblock names. Giving them the tab puts the refusal back on
        // `email.edit`, which is the thing under test. The tab's own refusal
        // is covered by EmailTabPermissionsTest.
        $reader = $this->userWithRole($t, 'Reader', ['email.view', 'email.settings.view']);

        $this->signedInAs($reader)
            ->postJson("/api/v1/email/mailboxes/{$mailbox->getKey()}/oauth/start")
            ->assertForbidden()
            // And it says WHICH refusal, which is the half that cannot rot.
            //
            // Both causes available to this request answer 403: the missing
            // `email.edit`, which is what this test is about, and the
            // `email.settings.view` tab in front of the route. Holding the tab
            // in the fixture above keeps them apart today -- but that is a rule
            // somebody has to remember, and a fixture tidied in a year turns
            // this green assertion into one that measures the wrong thing
            // silently. The sentence cannot be confused: the tab refuses with
            // "permission to open this tab" and MailboxAccess with this.
            //
            // The shape came from the Web Management session, 2026-09-17,
            // who found it in their own WebTenantIsolationTest: where a refusal
            // has more than one possible cause, assert something the other
            // causes cannot produce.
            //
            // ── TWO WAYS TO CHECK THIS ASSERTION EARNS ITS PLACE ──
            //
            // Both were run against this file on 2026-09-17; neither is being
            // described from memory.
            //
            //   1. Take `email.settings.view` off the Reader above. It goes red
            //      HERE, on the message rather than the status, which is the
            //      migration that was passing silently before. Stronger, because
            //      it exercises the real thing -- but it only works where the
            //      tab gate sits on some routes and not others. Web Management
            //      copied it into a module whose gate covers the GET too, where
            //      removing the key reds the positive control first and this
            //      line is never reached. Same manoeuvre, opposite result,
            //      decided by route topology.
            //
            //   2. Swap the expected sentence below for 'open this tab'. Both
            //      refusals in this file go red. Weaker in principle -- it does
            //      not exercise the tab path -- but it proves the two halves
            //      that matter (the assertion really reads the message, and the
            //      message really is MailboxAccess's rather than the
            //      middleware's) and it does not care how the routes are gated.
            //      This is the one to use somewhere new.
            ->assertJsonPath(
                'message',
                fn (string $said) => str_contains($said, 'connect this inbox'),
            );

        $manager = $this->userWithRole($t, 'Manager', ['email.view', 'email.settings.view', 'email.edit']);

        $this->signedInAs($manager)
            ->postJson("/api/v1/email/mailboxes/{$mailbox->getKey()}/oauth/start")
            ->assertOk();

        $this->assertSame(1, MicrosoftMailSignIn::count(),
            'A refused request left a pending sign-in behind.');
    }

    public function test_it_refuses_to_start_a_sign_in_where_ezbizi_has_no_microsoft_registration(): void
    {
        config(['services.microsoft_mail.client_id' => null, 'services.microsoft_mail.client_secret' => null]);

        $t       = $this->provisionTenant('A1-Group');
        $mailbox = $this->inbox($t);

        $body = $this->signedInAs($t['admin'])
            ->postJson("/api/v1/email/mailboxes/{$mailbox->getKey()}/oauth/start")
            ->assertStatus(501)->json();

        $this->assertStringContainsString('EzBizi', $body['message']);
        $this->assertSame(0, MicrosoftMailSignIn::count());
    }

    public function test_the_authorize_url_asks_for_exactly_what_the_flow_needs(): void
    {
        $t       = $this->provisionTenant('A1-Group');
        $mailbox = $this->inbox($t);

        $url = $this->signedInAs($t['admin'])
            ->postJson("/api/v1/email/mailboxes/{$mailbox->getKey()}/oauth/start")
            ->assertOk()->json('data.authorization_url');

        $this->assertStringStartsWith('https://login.microsoftonline.com/common/oauth2/v2.0/authorize?', $url);

        $query = $this->queryOf($url);

        $this->assertSame('consent', $query['prompt'] ?? null,
            'Without a forced consent screen, reconnecting an already-approved account '
            .'returns no refresh token — so the one case that matters, fixing a broken '
            .'mailbox, would silently fail to fix it.');

        $this->assertSame('S256', $query['code_challenge_method'] ?? null);
        $this->assertNotEmpty($query['code_challenge'] ?? '');

        $this->assertEqualsCanonicalizing(
            [
                MicrosoftOAuth::SCOPE_READ,
                MicrosoftOAuth::SCOPE_SEND,
                MicrosoftOAuth::SCOPE_OFFLINE,
                MicrosoftOAuth::SCOPE_PROFILE,
            ],
            explode(' ', $query['scope']),
            'The scopes asked for are not read-and-send plus lasting permission. A wider one '
            .'would let a stolen refresh token delete somebody\'s mail rather than only read it.',
        );

        $this->assertSame(self::ADDRESS, $query['login_hint'] ?? null);

        $row = MicrosoftMailSignIn::sole();

        $this->assertNotEmpty($query['state']);
        $this->assertNotSame($query['state'], $row->state_hash);
        $this->assertSame(hash('sha256', $query['state']), $row->state_hash);
        $this->assertSame($t['org']->id, $row->organization_id);
        $this->assertSame($t['admin']->id, $row->user_id);
        $this->assertSame($mailbox->getKey(), $row->mailbox_id);
        $this->assertTrue($row->expires_at->isFuture());
        $this->assertTrue($row->expires_at->lte(now()->addMinutes(MicrosoftOAuthSignIns::MINUTES)));

        $stored = DB::table('microsoft_mail_sign_ins')->where('id', $row->getKey())->value('code_verifier');

        $this->assertNotSame($row->code_verifier, $stored,
            'The PKCE verifier is stored in the clear. A leaked authorization code plus a '
            .'database dump would then be both halves of the exchange.');
    }

    public function test_the_redirect_uri_is_config_and_cannot_be_moved_by_a_host_header(): void
    {
        $t       = $this->provisionTenant('A1-Group');
        $mailbox = $this->inbox($t);

        $expected = 'https://app.ezbizi.test'.MicrosoftOAuth::CALLBACK_PATH;

        $honest = $this->signedInAs($t['admin'])
            ->postJson("/api/v1/email/mailboxes/{$mailbox->getKey()}/oauth/start")
            ->assertOk()->json('data.authorization_url');

        $this->assertSame($expected, $this->queryOf($honest)['redirect_uri']);

        $spoofed = $this->signedInAs($t['admin'])
            ->withHeaders(['Host' => 'evil.example', 'X-Forwarded-Host' => 'evil.example'])
            ->postJson("/api/v1/email/mailboxes/{$mailbox->getKey()}/oauth/start")
            ->assertOk()->json('data.authorization_url');

        $this->assertSame($expected, $this->queryOf($spoofed)['redirect_uri'],
            'The Host header moved the redirect URI, so an authorization code can be sent to '
            .'a host an attacker chose. That is an open redirector in an OAuth callback.');
    }

    public function test_a_redirect_uri_that_is_not_this_installations_callback_is_refused(): void
    {
        $t       = $this->provisionTenant('A1-Group');
        $mailbox = $this->inbox($t);

        foreach ([
            'somewhere else entirely' => 'https://evil.example/collect',
            'the right host, the wrong path' => 'https://app.ezbizi.test/api/v1/oops',
            'insecure, and not localhost'    => 'http://app.ezbizi.test'.MicrosoftOAuth::CALLBACK_PATH,
            'not a URL at all'               => 'nonsense',
        ] as $why => $configured) {
            config(['services.microsoft_mail.redirect' => $configured]);

            $this->signedInAs($t['admin'])
                ->postJson("/api/v1/email/mailboxes/{$mailbox->getKey()}/oauth/start")
                ->assertStatus(501, "A redirect URI that is {$why} was accepted.");
        }

        $this->assertSame(0, MicrosoftMailSignIn::count());
    }

    public function test_the_callback_path_microsoft_is_told_about_is_a_route_this_api_registers(): void
    {
        $this->assertNotNull(
            Route::getRoutes()->getByName('email.microsoft.callback'),
            'The callback route has gone or been renamed.',
        );

        $registered = '/'.ltrim(Route::getRoutes()->getByName('email.microsoft.callback')->uri(), '/');

        $this->assertSame(MicrosoftOAuth::CALLBACK_PATH, $registered);
        $this->assertSame(
            MicrosoftOAuth::CALLBACK_PATH,
            parse_url((string) config('services.microsoft_mail.redirect'), PHP_URL_PATH),
        );
    }

    public function test_a_matching_sign_in_connects_the_mailbox(): void
    {
        $t       = $this->provisionTenant('A1-Group');
        $mailbox = $this->inbox($t);
        $state   = $this->beginSignIn($t, $mailbox);

        $this->fakeMicrosoft();

        $this->get(self::CALLBACK.'?code=an-auth-code&state='.$state)->assertOk();

        $fresh = $this->inTenant($t['org'], fn () => $mailbox->fresh());

        $this->assertSame(Mailbox::STATUS_CONNECTED, $fresh->status);
        $this->assertSame(self::REFRESH, $fresh->credential());
        $this->assertSame(0, MicrosoftMailSignIn::count());
        $this->assertSame(1, AuditEvent::withoutGlobalScopes()
            ->where('action', 'mailbox.connected')->count());
    }

    public function test_connecting_does_not_persist_message_bodies(): void
    {
        $t       = $this->provisionTenant('A1-Group');
        $mailbox = $this->inbox($t);
        $state   = $this->beginSignIn($t, $mailbox);

        $this->fakeMicrosoft();
        $this->get(self::CALLBACK.'?code=an-auth-code&state='.$state)->assertOk();

        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasTable('mailbox_messages'));
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasTable('email_bodies'));
    }

    public function test_a_state_cannot_be_used_twice(): void
    {
        $t       = $this->provisionTenant('A1-Group');
        $mailbox = $this->inbox($t);
        $state   = $this->beginSignIn($t, $mailbox);

        Http::fake([
            'login.microsoftonline.com/common/oauth2/v2.0/token' => Http::sequence()
                ->push($this->tokenBody())
                ->push($this->tokenBody('a-replayed-attackers-refresh-token')),
            'login.microsoftonline.com/common/oauth2/v2.0/logout' => Http::response([]),
            'graph.microsoft.com/v1.0/me' => Http::response(
                ['mail' => self::ADDRESS, 'userPrincipalName' => self::ADDRESS],
            ),
        ]);

        $this->get(self::CALLBACK.'?code=an-auth-code&state='.$state)->assertOk();
        $this->get(self::CALLBACK.'?code=an-auth-code&state='.$state)->assertStatus(400);

        $this->assertSame(
            self::REFRESH,
            $this->inTenant($t['org'], fn () => $mailbox->fresh())->credential(),
            'A replayed sign-in link replaced the stored credential.',
        );
    }

    public function test_an_unknown_or_expired_state_is_refused(): void
    {
        $t       = $this->provisionTenant('A1-Group');
        $mailbox = $this->inbox($t);

        $this->fakeMicrosoft();

        $this->get(self::CALLBACK.'?code=c&state=a-state-nobody-issued')->assertStatus(400);
        $this->get(self::CALLBACK.'?code=c')->assertStatus(400);

        $state = $this->beginSignIn($t, $mailbox);

        MicrosoftMailSignIn::sole()->forceFill(['expires_at' => now()->subMinute()])->save();

        $this->get(self::CALLBACK.'?code=an-auth-code&state='.$state)->assertStatus(400);

        $this->assertNull($this->inTenant($t['org'], fn () => $mailbox->fresh())->credential());
        $this->assertSame(0, MicrosoftMailSignIn::count());
    }

    public function test_a_state_from_one_organization_cannot_attach_a_credential_in_another(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $beta  = $this->provisionTenant('Beta');

        $theirs = $this->inbox($beta, 'info@beta.test');
        $ours   = $this->inbox($alpha, 'info@alpha.test');

        $state = $this->beginSignIn($alpha, $ours);

        MicrosoftMailSignIn::sole()->forceFill(['mailbox_id' => $theirs->getKey()])->save();

        $this->fakeMicrosoft(signedInAs: 'info@beta.test');

        $this->get(self::CALLBACK.'?code=an-auth-code&state='.$state)->assertStatus(409);

        $this->assertNull(
            $this->inTenant($beta['org'], fn () => $theirs->fresh())->credential(),
            'A sign-in started in one organization attached a credential to another '
            .'organization\'s shared inbox.',
        );

        $this->assertSame(0, AuditEvent::withoutGlobalScopes()
            ->where('action', 'mailbox.connected')->count());
    }

    public function test_somebody_who_may_no_longer_manage_the_inbox_cannot_finish_the_sign_in(): void
    {
        $t       = $this->provisionTenant('A1-Group');
        $mailbox = $this->inbox($t);

        $manager = $this->userWithRole($t, 'Manager', ['email.view', 'email.settings.view', 'email.edit']);
        $state   = $this->beginSignIn($t, $mailbox, $manager);

        $this->inTenant($t['org'], fn () => $manager->forceFill(['is_active' => false])->save());

        $this->fakeMicrosoft();

        $this->get(self::CALLBACK.'?code=an-auth-code&state='.$state)->assertStatus(409);

        $this->assertNull($this->inTenant($t['org'], fn () => $mailbox->fresh())->credential());
    }

    public function test_a_mailbox_removed_while_the_sign_in_was_open_is_not_reconnected(): void
    {
        $t       = $this->provisionTenant('A1-Group');
        $mailbox = $this->inbox($t);
        $state   = $this->beginSignIn($t, $mailbox);

        $id = $mailbox->getKey();

        $this->inTenant($t['org'], fn () => $mailbox->delete());

        $this->fakeMicrosoft();

        $this->get(self::CALLBACK.'?code=an-auth-code&state='.$state)->assertStatus(400);

        $this->assertSame(0, Mailbox::withoutGlobalScopes()->whereKey($id)->count());
    }

    public function test_declining_at_microsoft_connects_nothing_and_burns_the_state(): void
    {
        $t       = $this->provisionTenant('A1-Group');
        $mailbox = $this->inbox($t);
        $state   = $this->beginSignIn($t, $mailbox);

        $this->fakeMicrosoft();

        $this->get(self::CALLBACK.'?error=access_denied&state='.$state)->assertStatus(400);

        $this->assertNull($this->inTenant($t['org'], fn () => $mailbox->fresh())->credential());
        $this->assertSame(0, MicrosoftMailSignIn::count());
        Http::assertNothingSent();
    }

    public function test_a_sign_in_with_the_wrong_microsoft_account_is_refused_and_revoked(): void
    {
        $t       = $this->provisionTenant('A1-Group');
        $mailbox = $this->inbox($t);
        $state   = $this->beginSignIn($t, $mailbox);

        $this->fakeMicrosoft(signedInAs: 'jane.sample.personal@outlook.com');

        $body = $this->get(self::CALLBACK.'?code=an-auth-code&state='.$state)
            ->assertStatus(409)->getContent();

        $this->assertNull(
            $this->inTenant($t['org'], fn () => $mailbox->fresh())->credential(),
            'A personal Outlook account was connected to a shared inbox. Everybody with access '
            .'to that inbox can now read one person\'s private mail.',
        );

        $this->assertStringContainsString('jane.sample.personal@outlook.com', $body);
        $this->assertStringContainsString(self::ADDRESS, $body);

        Http::assertSent(fn ($request) => $request->url() === 'https://login.microsoftonline.com/common/oauth2/v2.0/logout'
            && $request['token'] === self::REFRESH);

        $this->assertSame(0, AuditEvent::withoutGlobalScopes()
            ->where('action', 'mailbox.connected')->count());
    }

    public function test_an_account_microsoft_will_not_identify_is_refused_rather_than_assumed(): void
    {
        $t       = $this->provisionTenant('A1-Group');
        $mailbox = $this->inbox($t);
        $state   = $this->beginSignIn($t, $mailbox);

        $this->fakeMicrosoft(profileStatus: 500);

        $this->get(self::CALLBACK.'?code=an-auth-code&state='.$state)->assertStatus(409);

        $this->assertNull($this->inTenant($t['org'], fn () => $mailbox->fresh())->credential());
    }

    public function test_a_consent_that_does_not_cover_reading_is_refused(): void
    {
        $t       = $this->provisionTenant('A1-Group');
        $mailbox = $this->inbox($t);
        $state   = $this->beginSignIn($t, $mailbox);

        $this->fakeMicrosoft(scopes: [MicrosoftOAuth::SCOPE_SEND, MicrosoftOAuth::SCOPE_OFFLINE]);

        $this->get(self::CALLBACK.'?code=an-auth-code&state='.$state)->assertStatus(409);

        $this->assertNull($this->inTenant($t['org'], fn () => $mailbox->fresh())->credential());
    }

    public function test_a_grant_with_no_lasting_permission_is_refused(): void
    {
        $t       = $this->provisionTenant('A1-Group');
        $mailbox = $this->inbox($t);
        $state   = $this->beginSignIn($t, $mailbox);

        $this->fakeMicrosoft(refresh: null);

        $this->get(self::CALLBACK.'?code=an-auth-code&state='.$state)->assertStatus(502);

        $this->assertNull($this->inTenant($t['org'], fn () => $mailbox->fresh())->credential());
    }

    public function test_the_refresh_token_never_appears_in_a_response_or_in_the_clear(): void
    {
        $t       = $this->provisionTenant('A1-Group');
        $mailbox = $this->inbox($t);
        $state   = $this->beginSignIn($t, $mailbox);

        $this->fakeMicrosoft();

        $page = $this->get(self::CALLBACK.'?code=an-auth-code&state='.$state)->assertOk()->getContent();

        $this->assertStringNotContainsString(self::REFRESH, $page);

        $list = $this->signedInAs($t['admin'])->getJson('/api/v1/email/mailboxes')
            ->assertOk()->getContent();

        $this->assertStringNotContainsString(self::REFRESH, $list);

        $stored = DB::table('mailboxes')->where('id', $mailbox->getKey())->value('credentials');

        $this->assertNotEmpty($stored);
        $this->assertStringNotContainsString(self::REFRESH, (string) $stored);
    }

    public function test_the_audit_entry_records_the_connection_and_never_a_personal_address(): void
    {
        $t       = $this->provisionTenant('A1-Group');
        $mailbox = $this->inbox($t);

        Http::fake([
            'login.microsoftonline.com/common/oauth2/v2.0/token'  => Http::response($this->tokenBody()),
            'login.microsoftonline.com/common/oauth2/v2.0/logout' => Http::response([]),
            'graph.microsoft.com/v1.0/me' => Http::sequence()
                ->push(['mail' => 'jane.sample.personal@outlook.com', 'userPrincipalName' => 'jane.sample.personal@outlook.com'])
                ->push(['mail' => self::ADDRESS, 'userPrincipalName' => self::ADDRESS]),
        ]);

        $state = $this->beginSignIn($t, $mailbox);
        $this->get(self::CALLBACK.'?code=an-auth-code&state='.$state)->assertStatus(409);

        $state = $this->beginSignIn($t, $mailbox);
        $this->get(self::CALLBACK.'?code=an-auth-code&state='.$state)->assertOk();

        $entries = AuditEvent::withoutGlobalScopes()->get();

        $this->assertStringNotContainsString(
            'jane.sample.personal@outlook.com',
            $entries->toJson(),
        );

        $this->assertStringNotContainsString(self::REFRESH, $entries->toJson());

        $connected = $entries->firstWhere('action', 'mailbox.connected');

        $this->assertNotNull($connected);
        $this->assertSame($t['admin']->id, $connected->actor_id);
        $this->assertSame('microsoft_sign_in', $connected->changes['how'] ?? null);
    }

    public function test_the_waiting_screen_is_told_when_the_sign_in_lands(): void
    {
        $t       = $this->provisionTenant('A1-Group');
        $mailbox = $this->inbox($t);

        $started = $this->signedInAs($t['admin'])
            ->postJson("/api/v1/email/mailboxes/{$mailbox->getKey()}/oauth/start")
            ->assertOk()->json('data');

        $flow = $started['flow'];

        $this->assertNotEmpty($flow);

        $status = "/api/v1/email/mailboxes/{$mailbox->getKey()}/oauth/status?flow={$flow}";

        $this->assertSame('waiting', $this->signedInAs($t['admin'])
            ->getJson($status)->assertOk()->json('data.state'));

        $this->fakeMicrosoft();
        $this->get(self::CALLBACK.'?code=an-auth-code&state='
            .$this->queryOf($started['authorization_url'])['state'])->assertOk();

        $this->assertSame('connected', $this->signedInAs($t['admin'])
            ->getJson($status)->assertOk()->json('data.state'));
    }

    public function test_the_waiting_screen_is_told_the_refusal_and_why(): void
    {
        $t       = $this->provisionTenant('A1-Group');
        $mailbox = $this->inbox($t);

        $started = $this->signedInAs($t['admin'])
            ->postJson("/api/v1/email/mailboxes/{$mailbox->getKey()}/oauth/start")
            ->assertOk()->json('data');

        $this->fakeMicrosoft(signedInAs: 'jane.sample.personal@outlook.com');

        $this->get(self::CALLBACK.'?code=an-auth-code&state='
            .$this->queryOf($started['authorization_url'])['state'])->assertStatus(409);

        $answer = $this->signedInAs($t['admin'])->getJson(
            "/api/v1/email/mailboxes/{$mailbox->getKey()}/oauth/status?flow={$started['flow']}"
        )->assertOk()->json('data');

        $this->assertSame('refused', $answer['state']);
        $this->assertStringContainsString('jane.sample.personal@outlook.com', $answer['message']);
        $this->assertStringContainsString(self::ADDRESS, $answer['message']);
    }

    public function test_a_flow_belonging_to_another_inbox_is_not_answered(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $beta  = $this->provisionTenant('Beta');

        $ours   = $this->inbox($alpha, 'info@alpha.test');
        $theirs = $this->inbox($beta, 'info@beta.test');

        $started = $this->signedInAs($alpha['admin'])
            ->postJson("/api/v1/email/mailboxes/{$ours->getKey()}/oauth/start")
            ->assertOk()->json('data');

        $this->fakeMicrosoft(signedInAs: 'jane.sample.personal@outlook.com');

        $this->get(self::CALLBACK.'?code=an-auth-code&state='
            .$this->queryOf($started['authorization_url'])['state'])->assertStatus(409);

        $answer = $this->signedInAs($beta['admin'])->getJson(
            "/api/v1/email/mailboxes/{$theirs->getKey()}/oauth/status?flow={$started['flow']}"
        )->assertOk()->json('data');

        $this->assertSame('waiting', $answer['state']);
        $this->assertNull($answer['message']);
    }

    public function test_polling_the_outcome_takes_the_same_authority_as_starting(): void
    {
        $t       = $this->provisionTenant('A1-Group');
        $mailbox = $this->inbox($t);

        $reader = $this->userWithRole($t, 'Reader', ['email.view', 'email.settings.view']);

        $this->signedInAs($reader)
            ->getJson("/api/v1/email/mailboxes/{$mailbox->getKey()}/oauth/status")
            ->assertForbidden()
            // Names the refusal rather than its status -- see the note on the
            // first of these. A tab refusal says "open this tab".
            ->assertJsonPath(
                'message',
                fn (string $said) => str_contains($said, 'connect this inbox'),
            );

        $this->signedInAs($t['admin'])
            ->getJson("/api/v1/email/mailboxes/{$mailbox->getKey()}/oauth/status")
            ->assertOk();
    }
}
