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
    /** Retired 2026-11-03 (CASA cost): starting a personal Google sign-in is refused. */
    public function test_starting_a_google_sign_in_is_retired(): void
    {
        $t       = $this->provisionTenant('A1-Group');
        $mailbox = $this->inbox($t);

        $this->signedInAs($t['admin'])
            ->postJson("/api/v1/email/mailboxes/{$mailbox->getKey()}/oauth/start")
            ->assertStatus(410)
            ->assertJsonPath('message', \App\Support\Email\GmailMailboxReader::RETIRED);
        Http::assertNothingSent();
    }

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

    // ══ 4. THE SIGNED-IN ACCOUNT HAS TO BE THIS MAILBOX ══════════

    // ══ 5. THE CREDENTIAL GOES IN AND NEVER COMES OUT ════════════

    // ══ 6. THE SCREEN THAT CANNOT SEE THE CALLBACK ═══════════════

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
