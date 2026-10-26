<?php

namespace Tests\Feature\Api;

use App\Models\AuditEvent;
use App\Models\GoogleMailSignIn;
use App\Models\Mailbox;
use App\Models\Organization;
use App\Support\Email\GoogleOAuth;
use App\Support\Email\GoogleOAuthSignIns;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Signing in to Google, which is the act that turns a shared address into a
 * readable mailbox.
 *
 * ── What is faked here, and what that costs ──────────────────────
 *
 * There is no Google account on this installation and there cannot be one in a
 * test, so nothing below proves that Google behaves as documented. What is
 * exercised is every decision this application makes: what it sends, what it
 * stores, what it refuses, and — the part worth the most — what it refuses to
 * store when something is wrong.
 *
 * ── The four that are not about happy paths ──────────────────────
 *
 * THE STATE TESTS, because the callback is unauthenticated by necessity: a
 * browser redirect carries no bearer token, so the state row is the only thing
 * saying who is asking. Replay and cross-tenant are asserted separately because
 * they fail differently.
 *
 * THE MISMATCHED ACCOUNT, because it is the silent data leak in this feature and
 * the one most likely to be skipped: it looks exactly like success. Somebody
 * connects info@, signs in with their personal Gmail, and everybody with the
 * grant can now read that person's private mail.
 *
 * THE HOST HEADER, because the redirect URI is the one value in an OAuth
 * callback that must never come from a request, and Laravel's own URL generator
 * would have taken it from one.
 *
 * THE REFRESH TOKEN, because it is the highest-value secret in the system and
 * two different things have to be true of it: no response contains it, and the
 * database does not hold it in the clear.
 */
class GoogleMailOAuthTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private const CALLBACK = '/api/v1/email/google/callback';

    private const ADDRESS = 'info@a-1chimney.com';

    /** The refresh token every fixture hands back. Distinctive so it is greppable in a response. */
    private const REFRESH = 'refresh-token-1-a-1-chimney-shared-inbox';

    protected function setUp(): void
    {
        parent::setUp();

        // An installation that HAS registered with Google. Without these two,
        // every test below would be asserting the "not built here" refusal
        // rather than the flow — which is a real state, and is its own test.
        config([
            'services.google_mail.client_id'     => 'a-client-id.apps.googleusercontent.com',
            'services.google_mail.client_secret' => 'a-client-secret',
            'services.google_mail.redirect'      => 'https://app.ezbizi.test'.GoogleOAuth::CALLBACK_PATH,
        ]);
    }

    // ── fixtures ─────────────────────────────────────────────────

    /** @param array{org: Organization} $t */
    private function inbox(array $t, string $address = self::ADDRESS): Mailbox
    {
        return $this->inTenant($t['org'], fn () => Mailbox::create([
            'organization_id' => $t['org']->id,
            'scope_type'      => 'organization',
            'scope_id'        => $t['org']->id,
            'address'         => $address,
            'display_name'    => 'Info',
            'provider'        => 'gmail_oauth',
        ]));
    }

    /** What Google's token endpoint answers when the exchange works. */
    private function tokenBody(?string $refresh = self::REFRESH, ?array $scopes = null): array
    {
        $token = ['access_token' => 'an-access-token', 'expires_in' => 3599,
            'scope' => implode(' ', $scopes ?? [GoogleOAuth::SCOPE_MODIFY])];

        return $refresh === null ? $token : [...$token, 'refresh_token' => $refresh];
    }

    /**
     * Google as it behaves when everything is right.
     *
     * Each endpoint is faked by its FULL path rather than by `*.googleapis.com/*`,
     * because three different endpoints on two hosts have to answer differently
     * and a wildcard would quietly let one stand in for another — which is how a
     * test comes to assert that a revoke happened when what actually happened was
     * a token exchange.
     *
     * ── CALL THIS ONCE PER TEST ──────────────────────────────────
     *
     * `Http::fake()` MERGES stubs rather than replacing them, and the first
     * matching stub wins — so a second call with different answers changes
     * nothing at all. That is not a hypothetical: two tests in this file were
     * written that way and one of them failed for exactly this reason, which is
     * the only reason it was noticed. The other would have passed while
     * asserting nothing, because the replay it was supposed to catch was being
     * handed the same token as the first attempt.
     *
     * A test needing Google to answer differently the second time uses
     * `Http::sequence()` inside ONE `Http::fake()` call, as the two below do.
     */
    private function fakeGoogle(
        string $signedInAs = self::ADDRESS,
        ?array $scopes = null,
        ?string $refresh = self::REFRESH,
        int $profileStatus = 200,
    ): void {
        Http::fake([
            'oauth2.googleapis.com/token'  => Http::response($this->tokenBody($refresh, $scopes)),
            'oauth2.googleapis.com/revoke' => Http::response([]),
            'gmail.googleapis.com/gmail/v1/users/me/profile' => Http::response(
                $profileStatus === 200 ? ['emailAddress' => $signedInAs] : [],
                $profileStatus,
            ),
        ]);
    }

    /** Start a sign-in and hand back the raw `state` from the URL Google would receive. */
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

    // ══ 1. STARTING ONE IS AS PRIVILEGED AS TYPING A PASSWORD ════

    /**
     * The easy path must not be the loose one.
     *
     * Pressing a button and typing an IMAP password attach the same kind of key
     * to the same inbox, so they take the same authority — `email.edit` plus §4
     * reach, asked of the one class that answers that question. The control
     * matters as much as the refusal: without somebody succeeding on the same
     * fixture, a 403 could be about the mailbox, the provider or the config.
     */
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

        // Nothing was left behind by the refusal. A pending sign-in written
        // before the permission check would be a row bound to somebody who is
        // not allowed to finish it.
        $this->assertSame(1, GoogleMailSignIn::count(),
            'A refused request left a pending sign-in behind.');
    }

    /**
     * An installation with no Google registration says so, and offers nothing.
     *
     * The same sentence the mailbox catalogue publishes as `unusable_because`,
     * read from the same place — so the screen that hides the button and the
     * endpoint that refuses the press cannot disagree.
     */
    public function test_it_refuses_to_start_a_sign_in_where_ezbizi_has_no_google_registration(): void
    {
        config(['services.google_mail.client_id' => null, 'services.google_mail.client_secret' => null]);

        $t       = $this->provisionTenant('A1-Group');
        $mailbox = $this->inbox($t);

        $body = $this->signedInAs($t['admin'])
            ->postJson("/api/v1/email/mailboxes/{$mailbox->getKey()}/oauth/start")
            ->assertStatus(501)->json();

        $this->assertStringContainsString('EzBizi', $body['message']);
        $this->assertSame(0, GoogleMailSignIn::count());
    }

    /** An IMAP mailbox has no Google sign-in to do, and says so rather than starting one. */
    public function test_it_refuses_a_mailbox_that_is_not_a_google_one(): void
    {
        $t = $this->provisionTenant('A1-Group');

        $mailbox = $this->inTenant($t['org'], fn () => Mailbox::create([
            'organization_id' => $t['org']->id,
            'scope_type'      => 'organization',
            'scope_id'        => $t['org']->id,
            'address'         => 'imap@a-1chimney.com',
            'display_name'    => 'Info',
            'provider'        => 'imap',
        ]));

        $this->signedInAs($t['admin'])
            ->postJson("/api/v1/email/mailboxes/{$mailbox->getKey()}/oauth/start")
            ->assertStatus(422);

        $this->assertSame(0, GoogleMailSignIn::count());
    }

    // ══ 2. WHAT GOOGLE IS ACTUALLY SENT ══════════════════════════

    /**
     * PKCE, offline access, forced consent, the two scopes, and a state that is
     * stored hashed and bound to three things.
     *
     * Every one of these is load bearing and each fails differently if it is
     * dropped, so they are asserted individually rather than as one snapshot of
     * a URL:
     *
     *   no `access_type=offline` — Google returns no refresh token and the inbox
     *   stops working within the hour
     *
     *   no `prompt=consent` — a second connection returns no refresh token, so
     *   RECONNECTING a broken mailbox silently fails to fix it
     *
     *   no PKCE — the authorization code, which travels through an address bar
     *   and this server's access log, becomes sufficient on its own
     *
     *   a wider scope — the credential could delete somebody's mail
     */
    public function test_the_authorize_url_asks_for_exactly_what_the_flow_needs(): void
    {
        $t       = $this->provisionTenant('A1-Group');
        $mailbox = $this->inbox($t);

        $url = $this->signedInAs($t['admin'])
            ->postJson("/api/v1/email/mailboxes/{$mailbox->getKey()}/oauth/start")
            ->assertOk()->json('data.authorization_url');

        $this->assertStringStartsWith('https://accounts.google.com/o/oauth2/v2/auth?', $url);

        $query = $this->queryOf($url);

        $this->assertSame('offline', $query['access_type'] ?? null,
            'Without offline access Google issues no refresh token, and the mailbox stops '
            .'working as soon as the first access token expires.');

        $this->assertSame('consent', $query['prompt'] ?? null,
            'Without a forced consent screen, reconnecting an already-approved account '
            .'returns no refresh token — so the one case that matters, fixing a broken '
            .'mailbox, would silently fail to fix it.');

        $this->assertSame('S256', $query['code_challenge_method'] ?? null);
        $this->assertNotEmpty($query['code_challenge'] ?? '');

        $this->assertEqualsCanonicalizing(
            [GoogleOAuth::SCOPE_MODIFY],
            explode(' ', $query['scope']),
            'Only gmail.modify is asked for: it reads, sends and labels, and Google review '
            .'(2026-10-25) refused readonly and send beside it as more than least privilege.',
        );

        $this->assertSame(self::ADDRESS, $query['login_hint'] ?? null);

        // ── the state, and what it is bound to ───────────────────
        $row = GoogleMailSignIn::sole();

        $this->assertNotEmpty($query['state']);
        $this->assertNotSame($query['state'], $row->state_hash,
            'The state is stored as issued. It travels through a browser address bar, so a '
            .'database dump would then contain values somebody could complete a pending '
            .'sign-in with.');

        $this->assertSame(hash('sha256', $query['state']), $row->state_hash);

        $this->assertSame($t['org']->id, $row->organization_id);
        $this->assertSame($t['admin']->id, $row->user_id);
        $this->assertSame($mailbox->getKey(), $row->mailbox_id);

        $this->assertTrue($row->expires_at->isFuture());
        $this->assertTrue($row->expires_at->lte(now()->addMinutes(GoogleOAuthSignIns::MINUTES)));

        // The PKCE verifier is not the challenge, and is not readable from the
        // row as stored. `code_verifier` is cast encrypted, so the column holds
        // ciphertext even though the attribute reads plainly.
        $stored = DB::table('google_mail_sign_ins')->where('id', $row->getKey())->value('code_verifier');

        $this->assertNotSame($row->code_verifier, $stored,
            'The PKCE verifier is stored in the clear. A leaked authorization code plus a '
            .'database dump would then be both halves of the exchange.');
    }

    /**
     * THE REDIRECT URI CANNOT BE MOVED BY A REQUEST.
     *
     * ═══════════════════════════════════════════════════════════════
     *  This is the test that stops the obvious refactor. Building the
     *  redirect URI with `route()` is the idiomatic Laravel answer and
     *  it takes the origin from the CURRENT REQUEST when there is one —
     *  which means the Host header, which is a request parameter,
     *  moves where Google sends the authorization code.
     *
     *  An open redirector in an OAuth callback is how tokens leak, so
     *  the URI comes from config and the path is a literal. This
     *  proves it: the same request with a hostile Host header produces
     *  the same redirect_uri.
     * ═══════════════════════════════════════════════════════════════
     */
    public function test_the_redirect_uri_is_config_and_cannot_be_moved_by_a_host_header(): void
    {
        $t       = $this->provisionTenant('A1-Group');
        $mailbox = $this->inbox($t);

        $expected = 'https://app.ezbizi.test'.GoogleOAuth::CALLBACK_PATH;

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

    /**
     * A redirect URI that does not point at this application's own callback is
     * refused rather than sent.
     *
     * "Allow-listed" has to be something the code does. This is it: the value
     * comes from config, and config being the source says who may change it —
     * not whether what they typed points at this server.
     */
    public function test_a_redirect_uri_that_is_not_this_installations_callback_is_refused(): void
    {
        $t       = $this->provisionTenant('A1-Group');
        $mailbox = $this->inbox($t);

        foreach ([
            'somewhere else entirely' => 'https://evil.example/collect',
            'the right host, the wrong path' => 'https://app.ezbizi.test/api/v1/oops',
            'insecure, and not localhost'    => 'http://app.ezbizi.test'.GoogleOAuth::CALLBACK_PATH,
            'not a URL at all'               => 'nonsense',
        ] as $why => $configured) {
            config(['services.google_mail.redirect' => $configured]);

            $this->signedInAs($t['admin'])
                ->postJson("/api/v1/email/mailboxes/{$mailbox->getKey()}/oauth/start")
                ->assertStatus(501, "A redirect URI that is {$why} was accepted.");
        }

        $this->assertSame(0, GoogleMailSignIn::count(),
            'A refused sign-in left a row behind that nothing will ever finish.');
    }

    /**
     * The path this application registers is the path Google is told about.
     *
     * `GoogleOAuth::CALLBACK_PATH` is a literal on purpose — see its own note,
     * where `route()` is rejected because it reads the request's host. The cost
     * of a literal is that it can drift from the route, and this is what closes
     * that: renaming the route without changing the constant fails here rather
     * than at Google, in production, with a message about a mismatched URI.
     */
    public function test_the_callback_path_google_is_told_about_is_a_route_this_api_registers(): void
    {
        $this->assertNotNull(
            Route::getRoutes()->getByName('email.google.callback'),
            'The callback route has gone or been renamed.',
        );

        $registered = '/'.ltrim(Route::getRoutes()->getByName('email.google.callback')->uri(), '/');

        $this->assertSame(GoogleOAuth::CALLBACK_PATH, $registered);

        $this->assertSame(
            GoogleOAuth::CALLBACK_PATH,
            parse_url((string) config('services.google_mail.redirect'), PHP_URL_PATH),
        );
    }

    // ══ 3. FINISHING ONE ═════════════════════════════════════════

    /** The whole point: an address becomes a mailbox somebody can open. */
    public function test_a_matching_sign_in_connects_the_mailbox(): void
    {
        $t       = $this->provisionTenant('A1-Group');
        $mailbox = $this->inbox($t);
        $state   = $this->beginSignIn($t, $mailbox);

        $this->fakeGoogle();

        $this->get(self::CALLBACK.'?code=an-auth-code&state='.$state)->assertOk();

        $fresh = $this->inTenant($t['org'], fn () => $mailbox->fresh());

        $this->assertSame(Mailbox::STATUS_CONNECTED, $fresh->status);
        $this->assertSame(self::REFRESH, $fresh->credential(),
            'The refresh token was not stored, or the access token was stored instead — which '
            .'produces a mailbox that works until lunchtime and then reports a revoked '
            .'sign-in.');

        // Burnt. The row is gone, so the link in the browser history is spent.
        $this->assertSame(0, GoogleMailSignIn::count());

        $this->assertSame(1, AuditEvent::withoutGlobalScopes()
            ->where('action', 'mailbox.connected')->count());
    }

    /**
     * A state can be spent once, and a replay changes nothing.
     *
     * The second attempt hands over a DIFFERENT refresh token, so the assertion
     * is not merely that the response was a refusal — it is that the credential
     * on the mailbox is still the first one. A replay that refused politely and
     * overwrote the credential anyway would pass a weaker test.
     */
    public function test_a_state_cannot_be_used_twice(): void
    {
        $t       = $this->provisionTenant('A1-Group');
        $mailbox = $this->inbox($t);
        $state   = $this->beginSignIn($t, $mailbox);

        /*
         * The second exchange would hand back a DIFFERENT refresh token, so the
         * assertion below is about the credential rather than about the status
         * code. A replay that refused politely and overwrote the credential
         * anyway passes a weaker test than this one.
         *
         * One `Http::fake` with a sequence, because a second `Http::fake` would
         * merge behind the first and change nothing — see `fakeGoogle`.
         */
        Http::fake([
            'oauth2.googleapis.com/token' => Http::sequence()
                ->push($this->tokenBody())
                ->push($this->tokenBody('a-replayed-attackers-refresh-token')),
            'oauth2.googleapis.com/revoke' => Http::response([]),
            'gmail.googleapis.com/gmail/v1/users/me/profile' => Http::response(
                ['emailAddress' => self::ADDRESS],
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

    /** A state nobody issued, and one that has aged out, answer the same way. */
    public function test_an_unknown_or_expired_state_is_refused(): void
    {
        $t       = $this->provisionTenant('A1-Group');
        $mailbox = $this->inbox($t);

        $this->fakeGoogle();

        $this->get(self::CALLBACK.'?code=c&state=a-state-nobody-issued')->assertStatus(400);
        $this->get(self::CALLBACK.'?code=c')->assertStatus(400);

        $state = $this->beginSignIn($t, $mailbox);

        GoogleMailSignIn::sole()->forceFill(['expires_at' => now()->subMinute()])->save();

        $this->get(self::CALLBACK.'?code=an-auth-code&state='.$state)->assertStatus(400);

        $this->assertNull($this->inTenant($t['org'], fn () => $mailbox->fresh())->credential());

        // Expired, and gone rather than left to be tried again.
        $this->assertSame(0, GoogleMailSignIn::count());
    }

    /**
     * A STATE MINTED IN ONE ORGANIZATION CANNOT REACH ANOTHER'S INBOX.
     *
     * ═══════════════════════════════════════════════════════════════
     *  The row is what names the organization, and everything else is
     *  resolved INSIDE it. That ordering is the containment, and it is
     *  invisible in the happy path — every ordinary sign-in has a row
     *  and a mailbox in the same tenant, so a version of this code
     *  that resolved the mailbox unscoped would pass every other test
     *  in this file.
     *
     *  So the row is tampered with directly: organization A's state,
     *  pointed at organization B's mailbox.
     *
     *  ── WHAT MUTATION FOUND, AND WHY THIS PARAGRAPH CHANGED ──
     *
     *  This docblock used to end "drop the scope and this connects B's
     *  inbox". That was written from the design and it is NOT TRUE:
     *  deleting the tenant scope from the mailbox lookup leaves this
     *  test GREEN. There are two locks here, not one, and either alone
     *  refuses:
     *
     *    1. the tenant scope on the lookup — B's mailbox does not
     *       exist inside A, so it resolves to nothing;
     *    2. the reach re-check on the person — A's administrator has
     *       no authority over anything in B, and §4 authority never
     *       crosses organizations.
     *
     *  They are genuinely redundant for this attack, which is the
     *  right shape for a boundary this severe rather than a
     *  duplication to tidy away. What that costs is precision: this
     *  test asserts the OUTCOME — no credential crosses — and goes red
     *  only when BOTH are removed. Verified by doing exactly that.
     * ═══════════════════════════════════════════════════════════════
     */
    public function test_a_state_from_one_organization_cannot_attach_a_credential_in_another(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $beta  = $this->provisionTenant('Beta');

        $theirs = $this->inbox($beta, 'info@beta.test');
        $ours   = $this->inbox($alpha, 'info@alpha.test');

        $state = $this->beginSignIn($alpha, $ours);

        // The attack, expressed as the state this flow would have to be tricked
        // into holding: Alpha's sign-in, aimed at Beta's inbox.
        GoogleMailSignIn::sole()->forceFill(['mailbox_id' => $theirs->getKey()])->save();

        $this->fakeGoogle(signedInAs: 'info@beta.test');

        $this->get(self::CALLBACK.'?code=an-auth-code&state='.$state)->assertStatus(409);

        $this->assertNull(
            $this->inTenant($beta['org'], fn () => $theirs->fresh())->credential(),
            'A sign-in started in one organization attached a credential to another '
            .'organization\'s shared inbox.',
        );

        $this->assertSame(0, AuditEvent::withoutGlobalScopes()
            ->where('action', 'mailbox.connected')->count());
    }

    /**
     * Being allowed to start it is not being allowed to finish it.
     *
     * Ten minutes is long enough for somebody to be deactivated, and the request
     * that finishes the flow carries no session for that to be noticed by
     * itself. So the question is asked again.
     */
    public function test_somebody_who_may_no_longer_manage_the_inbox_cannot_finish_the_sign_in(): void
    {
        $t       = $this->provisionTenant('A1-Group');
        $mailbox = $this->inbox($t);

        $manager = $this->userWithRole($t, 'Manager', ['email.view', 'email.settings.view', 'email.edit']);
        $state   = $this->beginSignIn($t, $mailbox, $manager);

        $this->inTenant($t['org'], fn () => $manager->forceFill(['is_active' => false])->save());

        $this->fakeGoogle();

        $this->get(self::CALLBACK.'?code=an-auth-code&state='.$state)->assertStatus(409);

        $this->assertNull($this->inTenant($t['org'], fn () => $mailbox->fresh())->credential());
    }

    /** An inbox somebody disconnected mid-flow is not resurrected by the callback. */
    public function test_a_mailbox_removed_while_the_sign_in_was_open_is_not_reconnected(): void
    {
        $t       = $this->provisionTenant('A1-Group');
        $mailbox = $this->inbox($t);
        $state   = $this->beginSignIn($t, $mailbox);

        $id = $mailbox->getKey();

        $this->inTenant($t['org'], fn () => $mailbox->delete());

        $this->fakeGoogle();

        // The cascade takes the pending sign-in with the mailbox, so this is the
        // same refusal as an unknown state — which is the right answer, and is
        // why the assertion is on the mailbox rather than on the status alone.
        $this->get(self::CALLBACK.'?code=an-auth-code&state='.$state)->assertStatus(400);

        $this->assertSame(0, Mailbox::withoutGlobalScopes()->whereKey($id)->count());
    }

    /** Pressing Cancel at Google refuses, and spends the state on the way. */
    public function test_declining_at_google_connects_nothing_and_burns_the_state(): void
    {
        $t       = $this->provisionTenant('A1-Group');
        $mailbox = $this->inbox($t);
        $state   = $this->beginSignIn($t, $mailbox);

        $this->fakeGoogle();

        $this->get(self::CALLBACK.'?error=access_denied&state='.$state)->assertStatus(400);

        $this->assertNull($this->inTenant($t['org'], fn () => $mailbox->fresh())->credential());

        $this->assertSame(0, GoogleMailSignIn::count(),
            'A declined sign-in left its state alive, so the link can be tried again — which '
            .'is the one property single use exists to remove.');

        // Nothing was exchanged. A callback that posted a token request for a
        // flow Google had already refused would be spending a code that does
        // not exist.
        Http::assertNothingSent();
    }

    // ══ 4. THE SIGNED-IN ACCOUNT HAS TO BE THIS MAILBOX ══════════

    /**
     * THE SILENT DATA LEAK THIS FLOW EXISTS TO PREVENT.
     *
     * Somebody sets up info@a-1chimney.com, is sent to Google, and signs in with
     * the account their browser was already signed in to — their own. Nothing
     * about that looks like a failure: consent is given, a real refresh token
     * comes back, and the mailbox would read "connected". Everybody granted that
     * inbox could then read that person's private mail.
     *
     * So three things are asserted, and the third is the one that would be
     * skipped: nothing is stored, the sentence names both addresses so somebody
     * can act on it, and the grant is handed BACK to Google rather than merely
     * dropped — this application held lasting permission to a private mailbox
     * for a moment and must not be the reason it stays alive.
     */
    public function test_a_sign_in_with_the_wrong_google_account_is_refused_and_revoked(): void
    {
        $t       = $this->provisionTenant('A1-Group');
        $mailbox = $this->inbox($t);
        $state   = $this->beginSignIn($t, $mailbox);

        $this->fakeGoogle(signedInAs: 'jenny.smith.personal@gmail.com');

        $body = $this->get(self::CALLBACK.'?code=an-auth-code&state='.$state)
            ->assertStatus(409)->getContent();

        $this->assertNull(
            $this->inTenant($t['org'], fn () => $mailbox->fresh())->credential(),
            'A personal Gmail account was connected to a shared inbox. Everybody with access '
            .'to that inbox can now read one person\'s private mail.',
        );

        $this->assertStringContainsString('jenny.smith.personal@gmail.com', $body);
        $this->assertStringContainsString(self::ADDRESS, $body);

        Http::assertSent(fn ($request) => $request->url() === 'https://oauth2.googleapis.com/revoke'
            && $request['token'] === self::REFRESH);

        $this->assertSame(0, AuditEvent::withoutGlobalScopes()
            ->where('action', 'mailbox.connected')->count());
    }

    /**
     * "We could not check" is not "it matched".
     *
     * The identity check is one HTTP call, and the tempting shape is to treat a
     * failed call as inconclusive and carry on. That turns the guard above off
     * on exactly the day Google's profile endpoint is having a bad afternoon,
     * silently, with no way to tell afterwards which connections were checked.
     */
    public function test_an_account_google_will_not_identify_is_refused_rather_than_assumed(): void
    {
        $t       = $this->provisionTenant('A1-Group');
        $mailbox = $this->inbox($t);
        $state   = $this->beginSignIn($t, $mailbox);

        $this->fakeGoogle(profileStatus: 500);

        $this->get(self::CALLBACK.'?code=an-auth-code&state='.$state)->assertStatus(409);

        $this->assertNull($this->inTenant($t['org'], fn () => $mailbox->fresh())->credential());
    }

    /** The right account, with a tick box cleared, is not a connection worth storing. */
    public function test_a_consent_that_does_not_cover_reading_is_refused(): void
    {
        $t       = $this->provisionTenant('A1-Group');
        $mailbox = $this->inbox($t);
        $state   = $this->beginSignIn($t, $mailbox);

        $this->fakeGoogle(scopes: [GoogleOAuth::SCOPE_SEND]);

        $this->get(self::CALLBACK.'?code=an-auth-code&state='.$state)->assertStatus(409);

        $this->assertNull(
            $this->inTenant($t['org'], fn () => $mailbox->fresh())->credential(),
            'A credential that cannot read the mailbox was stored, so the row would read '
            .'"connected" and fail the first time anybody opened it.',
        );
    }

    /**
     * An hour-long permission is not a connection.
     *
     * Google issues a refresh token on first consent and, afterwards, only when
     * asked to consent again — which this flow always does. So an absent refresh
     * token is an anomaly, and storing the access token instead would produce a
     * mailbox that worked until lunchtime and then reported that its sign-in had
     * been revoked: a true sentence about the wrong cause.
     */
    public function test_a_grant_with_no_lasting_permission_is_refused(): void
    {
        $t       = $this->provisionTenant('A1-Group');
        $mailbox = $this->inbox($t);
        $state   = $this->beginSignIn($t, $mailbox);

        $this->fakeGoogle(refresh: null);

        $this->get(self::CALLBACK.'?code=an-auth-code&state='.$state)->assertStatus(502);

        $this->assertNull($this->inTenant($t['org'], fn () => $mailbox->fresh())->credential());
    }

    // ══ 5. THE CREDENTIAL GOES IN AND NEVER COMES OUT ════════════

    /**
     * Two different things have to be true of the refresh token, and they are
     * attacked from different sides.
     *
     * It is the highest-value secret in the system: it unlocks every message the
     * mailbox has ever received and the ability to send as it. So no response
     * may contain it — not the callback page, not the mailbox list, not the row
     * the connect flow refreshes — and the database may not hold it in the
     * clear, because a dump is not a credential dump.
     */
    public function test_the_refresh_token_never_appears_in_a_response_or_in_the_clear(): void
    {
        $t       = $this->provisionTenant('A1-Group');
        $mailbox = $this->inbox($t);
        $state   = $this->beginSignIn($t, $mailbox);

        $this->fakeGoogle();

        $page = $this->get(self::CALLBACK.'?code=an-auth-code&state='.$state)->assertOk()->getContent();

        $this->assertStringNotContainsString(self::REFRESH, $page,
            'The page a browser lands on contains the refresh token.');

        $list = $this->signedInAs($t['admin'])->getJson('/api/v1/email/mailboxes')
            ->assertOk()->getContent();

        $this->assertStringNotContainsString(self::REFRESH, $list,
            'The mailbox list publishes the refresh token.');

        $stored = DB::table('mailboxes')->where('id', $mailbox->getKey())->value('credentials');

        $this->assertNotEmpty($stored);
        $this->assertStringNotContainsString(self::REFRESH, (string) $stored,
            'The refresh token is in the database in the clear, so a database dump is a '
            .'credential dump.');
    }

    /**
     * The audit trail records the connection and not the account behind it.
     *
     * On the success path the Google account IS the mailbox address and already
     * public. On the mismatch path it is a private individual's own address —
     * and writing that into a table colleagues can read would turn the refusal
     * that protected somebody's privacy into the thing that published it.
     */
    public function test_the_audit_entry_records_the_connection_and_never_a_personal_address(): void
    {
        $t       = $this->provisionTenant('A1-Group');
        $mailbox = $this->inbox($t);

        // One refused sign-in with somebody's personal account, then a real one
        // — in a single `Http::fake` with the profile as a sequence, because a
        // second call would merge behind the first. See `fakeGoogle`.
        Http::fake([
            'oauth2.googleapis.com/token'  => Http::response($this->tokenBody()),
            'oauth2.googleapis.com/revoke' => Http::response([]),
            'gmail.googleapis.com/gmail/v1/users/me/profile' => Http::sequence()
                ->push(['emailAddress' => 'jenny.smith.personal@gmail.com'])
                ->push(['emailAddress' => self::ADDRESS]),
        ]);

        $state = $this->beginSignIn($t, $mailbox);
        $this->get(self::CALLBACK.'?code=an-auth-code&state='.$state)->assertStatus(409);

        $state = $this->beginSignIn($t, $mailbox);
        $this->get(self::CALLBACK.'?code=an-auth-code&state='.$state)->assertOk();

        $entries = AuditEvent::withoutGlobalScopes()->get();

        $this->assertStringNotContainsString(
            'jenny.smith.personal@gmail.com',
            $entries->toJson(),
            'A refused sign-in wrote somebody\'s personal Google address into the audit log.',
        );

        $this->assertStringNotContainsString(self::REFRESH, $entries->toJson());

        $connected = $entries->firstWhere('action', 'mailbox.connected');

        $this->assertNotNull($connected);
        $this->assertSame($t['admin']->id, $connected->actor_id,
            'The connection was not attributed to the person who signed in.');
    }

    // ══ 6. THE SCREEN THAT CANNOT SEE THE CALLBACK ═══════════════

    /**
     * The waiting screen is told what happened, because it cannot look.
     *
     * The consent window stops belonging to the client the moment it navigates
     * to Google, and a browser will not let an opener read across origins — so
     * the only party that saw the token arrive is this server. Three states, and
     * the middle one is what stops the screen deciding for itself: while nothing
     * has landed the answer is "still going", not "no" and not an error.
     */
    public function test_the_waiting_screen_is_told_when_the_sign_in_lands(): void
    {
        $t       = $this->provisionTenant('A1-Group');
        $mailbox = $this->inbox($t);

        $started = $this->signedInAs($t['admin'])
            ->postJson("/api/v1/email/mailboxes/{$mailbox->getKey()}/oauth/start")
            ->assertOk()->json('data');

        $flow = $started['flow'];

        $this->assertNotEmpty($flow, 'No flow was handed back, so a second attempt could not '
            .'be told apart from one somebody abandoned ten minutes ago.');

        $status = "/api/v1/email/mailboxes/{$mailbox->getKey()}/oauth/status?flow={$flow}";

        $this->assertSame('waiting', $this->signedInAs($t['admin'])
            ->getJson($status)->assertOk()->json('data.state'));

        $this->fakeGoogle();
        $this->get(self::CALLBACK.'?code=an-auth-code&state='
            .$this->queryOf($started['authorization_url'])['state'])->assertOk();

        $this->assertSame('connected', $this->signedInAs($t['admin'])
            ->getJson($status)->assertOk()->json('data.state'));
    }

    /**
     * A refusal reaches the screen VERBATIM, and it is the one that matters most.
     *
     * The mismatched-account sentence names the account somebody signed in with
     * and the address it does not match, and that pair is the entire content of
     * the message. A screen told only "it failed" would leave somebody pressing
     * the same button with the same browser signed in to the same wrong account.
     */
    public function test_the_waiting_screen_is_told_the_refusal_and_why(): void
    {
        $t       = $this->provisionTenant('A1-Group');
        $mailbox = $this->inbox($t);

        $started = $this->signedInAs($t['admin'])
            ->postJson("/api/v1/email/mailboxes/{$mailbox->getKey()}/oauth/start")
            ->assertOk()->json('data');

        $this->fakeGoogle(signedInAs: 'jenny.smith.personal@gmail.com');

        $this->get(self::CALLBACK.'?code=an-auth-code&state='
            .$this->queryOf($started['authorization_url'])['state'])->assertStatus(409);

        $answer = $this->signedInAs($t['admin'])->getJson(
            "/api/v1/email/mailboxes/{$mailbox->getKey()}/oauth/status?flow={$started['flow']}"
        )->assertOk()->json('data');

        $this->assertSame('refused', $answer['state']);
        $this->assertStringContainsString('jenny.smith.personal@gmail.com', $answer['message']);
        $this->assertStringContainsString(self::ADDRESS, $answer['message']);
    }

    /**
     * A flow id is not a key into somebody else's outcomes.
     *
     * The refusal sentence names the Google account that was signed in with — a
     * private individual's own address on the case that matters — so "how did
     * that sign-in end" must not be answerable by anybody holding an id. The
     * outcome carries the mailbox it belongs to and this asks about a different
     * one, in a different organization, and gets "still going" rather than a
     * refusal or a 404: an answer that distinguished "not yours" from "no such
     * flow" would itself be the disclosure.
     */
    public function test_a_flow_belonging_to_another_inbox_is_not_answered(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $beta  = $this->provisionTenant('Beta');

        $ours   = $this->inbox($alpha, 'info@alpha.test');
        $theirs = $this->inbox($beta, 'info@beta.test');

        $started = $this->signedInAs($alpha['admin'])
            ->postJson("/api/v1/email/mailboxes/{$ours->getKey()}/oauth/start")
            ->assertOk()->json('data');

        $this->fakeGoogle(signedInAs: 'jenny.smith.personal@gmail.com');

        $this->get(self::CALLBACK.'?code=an-auth-code&state='
            .$this->queryOf($started['authorization_url'])['state'])->assertStatus(409);

        $answer = $this->signedInAs($beta['admin'])->getJson(
            "/api/v1/email/mailboxes/{$theirs->getKey()}/oauth/status?flow={$started['flow']}"
        )->assertOk()->json('data');

        $this->assertSame('waiting', $answer['state'],
            'Another organization\'s sign-in outcome was handed over, and its sentence names '
            .'the personal Google account somebody signed in with.');

        $this->assertNull($answer['message']);
    }

    /**
     * Polling takes the same authority as starting, asked every time.
     *
     * A polling endpoint that checked authority once at the beginning is one
     * that keeps answering for somebody whose access was withdrawn while they
     * watched — and this one answers with a sentence naming a Google account.
     */
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
