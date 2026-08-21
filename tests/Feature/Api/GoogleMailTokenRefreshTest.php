<?php

namespace Tests\Feature\Api;

use App\Models\Mailbox;
use App\Support\Email\CannotReadMailbox;
use App\Support\Email\GmailMailboxReader;
use App\Support\Email\MailboxFault;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Keeping a shared inbox open when nobody is standing at a browser.
 *
 * ═══════════════════════════════════════════════════════════════════
 *  THE SIGN-IN HAPPENS ONCE. EVERY READ AFTERWARDS IS UNATTENDED.
 *
 *  Somebody consents on a Tuesday and the mailbox has to keep opening
 *  for months without them. That is the refresh token being spent for
 *  an access token by App\Support\Email\GmailMailboxReader, with no
 *  browser, no consent screen and nobody to ask — and it is the part
 *  of an OAuth integration that gets tested least and breaks most.
 *
 *  Three behaviours have to hold together, and each one is wrong on
 *  its own:
 *
 *    An access token is REUSED, or a page of twenty-five messages
 *    means twenty-six round trips to Google — and Google rate limits
 *    token minting separately, so the cost arrives as a quota failure
 *    that reads like a broken mailbox.
 *
 *    An expired one is ORDINARY. A stale cached token has to cost one
 *    extra round trip, not a mailbox marked broken and an automation
 *    telling a manager their inbox has stopped working.
 *
 *    A genuinely dead sign-in still SURFACES, as the fault this module
 *    already has for it, rather than being retried for ever.
 * ═══════════════════════════════════════════════════════════════════
 *
 * No Google account exists here, so what is asserted is the reader's own
 * behaviour against the response shapes Google documents: how many times it
 * asks, which token it presents, and what it concludes.
 */
class GoogleMailTokenRefreshTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private const TOKEN_ENDPOINT = 'https://oauth2.googleapis.com/token';

    private const LISTING = 'gmail.googleapis.com/gmail/v1/users/me/threads*';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.google_mail.client_id'     => 'a-client-id.apps.googleusercontent.com',
            'services.google_mail.client_secret' => 'a-client-secret',
        ]);
    }

    /** @param array{org: \App\Models\Organization} $t */
    private function inbox(array $t): Mailbox
    {
        return $this->inTenant($t['org'], function () use ($t) {
            $mailbox = Mailbox::create([
                'organization_id' => $t['org']->id,
                'scope_type'      => 'organization',
                'scope_id'        => $t['org']->id,
                'address'         => 'info@a-1chimney.com',
                'display_name'    => 'Info',
                'provider'        => 'gmail_oauth',
            ]);

            $mailbox->connect('a-refresh-token');
            $mailbox->save();

            return $mailbox;
        });
    }

    /** How many times the token endpoint was asked for a fresh access token. */
    private function tokenRequests(): int
    {
        return Http::recorded(fn ($request) => $request->url() === self::TOKEN_ENDPOINT)->count();
    }

    // ══ 1. REUSE ═════════════════════════════════════════════════

    /**
     * The access token survives between reads, and is not sitting in the cache
     * as a bearer token anybody who can read that store may spend.
     *
     * Both halves matter. Without reuse this is a round trip per call; without
     * the encryption, caching a credential would have been the objection the
     * previous implementation refused to cache for in the first place.
     */
    public function test_an_access_token_is_reused_and_is_not_cached_in_the_clear(): void
    {
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(
                ['access_token' => 'an-access-token', 'expires_in' => 3599],
            ),
            self::LISTING => Http::response(['threads' => [], 'resultSizeEstimate' => 0]),
        ]);

        $t       = $this->provisionTenant('A1-Group');
        $mailbox = $this->inbox($t);

        $this->inTenant($t['org'], function () use ($mailbox) {
            app(GmailMailboxReader::class)->list($mailbox);
            app(GmailMailboxReader::class)->list($mailbox);
        });

        $this->assertSame(1, $this->tokenRequests(),
            'Every read mints a new access token. A page of twenty-five messages is then '
            .'twenty-six round trips to Google, and Google rate limits token minting '
            .'separately — so the cost arrives as a quota failure that looks like a broken '
            .'mailbox.');

        $cached = Cache::get('email.google.access-token.'.$mailbox->getKey());

        $this->assertNotEmpty($cached, 'Nothing was cached, so the assertion above is '
            .'passing for a reason other than the one it names.');

        $this->assertStringNotContainsString('an-access-token', (string) $cached,
            'The access token is in the cache store in the clear — an hour of access to '
            .'somebody\'s mail, readable by anything that can read the cache.');
    }

    /**
     * A token whose life Google does not state is not cached at all.
     *
     * The alternative is assuming an hour, and an assumed lifetime that is too
     * long produces expired tokens on every call until the entry ages out —
     * which is exactly the failure caching is here to avoid, arriving as a
     * mailbox that mysteriously works for a while after each reconnect.
     */
    public function test_a_token_with_no_stated_lifetime_is_not_cached(): void
    {
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'an-access-token']),
            self::LISTING => Http::response(['threads' => [], 'resultSizeEstimate' => 0]),
        ]);

        $t       = $this->provisionTenant('A1-Group');
        $mailbox = $this->inbox($t);

        $this->inTenant($t['org'], function () use ($mailbox) {
            app(GmailMailboxReader::class)->list($mailbox);
            app(GmailMailboxReader::class)->list($mailbox);
        });

        $this->assertSame(2, $this->tokenRequests());
        $this->assertNull(Cache::get('email.google.access-token.'.$mailbox->getKey()));
    }

    // ══ 2. AN EXPIRED TOKEN IS ORDINARY ══════════════════════════

    /**
     * A STALE ACCESS TOKEN IS A RETRY, NOT A DIAGNOSIS.
     *
     * ═══════════════════════════════════════════════════════════════
     *  Google's clock and ours will disagree, and a cached token will
     *  occasionally be presented a few seconds after it stopped being
     *  good. Reading that 401 as "somebody withdrew EzBizi's access"
     *  would mark a perfectly healthy mailbox broken, raise
     *  `email.mailbox_broken` to whoever asked to be told, and send an
     *  administrator to reconnect an inbox that needed nothing —
     *  intermittently, which is the worst kind of wrong to be told.
     *
     *  So the first 401 discards the cached token and mints a fresh
     *  one with nobody present. Delete the retry and this test fails
     *  with a CannotReadMailbox instead of a page.
     * ═══════════════════════════════════════════════════════════════
     */
    public function test_an_expired_access_token_is_refreshed_without_anybody_present(): void
    {
        Http::fake([
            'oauth2.googleapis.com/token' => Http::sequence()
                ->push(['access_token' => 'the-stale-one', 'expires_in' => 3599])
                ->push(['access_token' => 'the-fresh-one', 'expires_in' => 3599]),

            self::LISTING => Http::sequence()
                ->push([], 401)
                ->push(['threads' => [], 'resultSizeEstimate' => 0]),
        ]);

        $t       = $this->provisionTenant('A1-Group');
        $mailbox = $this->inbox($t);

        $page = $this->inTenant($t['org'], fn () => app(GmailMailboxReader::class)->list($mailbox));

        $this->assertSame([], $page->messages);

        $this->assertSame(2, $this->tokenRequests(),
            'The stale token was not replaced, so the read either failed or reused it.');

        Http::assertSent(fn ($request) => str_contains($request->url(), '/users/me/threads')
            && $request->hasHeader('Authorization', 'Bearer the-fresh-one'));

        // The mailbox is not broken. This is the whole point: an expired access
        // token is bookkeeping, and it must not reach the status screen.
        $fresh = $this->inTenant($t['org'], fn () => $mailbox->fresh());

        $this->assertSame(Mailbox::STATUS_CONNECTED, $fresh->status);
        $this->assertNull($fresh->status_reason);
    }

    /** The stale entry is thrown away rather than presented again on the next read. */
    public function test_a_rejected_access_token_is_dropped_from_the_cache(): void
    {
        Http::fake([
            'oauth2.googleapis.com/token' => Http::sequence()
                ->push(['access_token' => 'the-stale-one', 'expires_in' => 3599])
                ->push(['access_token' => 'the-fresh-one', 'expires_in' => 3599]),

            self::LISTING => Http::sequence()
                ->push([], 401)
                ->push(['threads' => [], 'resultSizeEstimate' => 0]),
        ]);

        $t       = $this->provisionTenant('A1-Group');
        $mailbox = $this->inbox($t);

        $this->inTenant($t['org'], fn () => app(GmailMailboxReader::class)->list($mailbox));

        $cached = Cache::get('email.google.access-token.'.$mailbox->getKey());

        $this->assertNotEmpty($cached);
        $this->assertSame(
            'the-fresh-one',
            \Illuminate\Support\Facades\Crypt::decryptString((string) $cached),
            'The rejected token was left in the cache, so the next read starts by presenting '
            .'a token Google has already refused.',
        );
    }

    // ══ 3. A DEAD SIGN-IN STILL SURFACES ═════════════════════════

    /**
     * A withdrawn grant is the fault this module already models, and it arrives
     * through the retry rather than being hidden by it.
     *
     * `invalid_grant` at the token endpoint is Google's answer to a refresh
     * token that has been withdrawn, has aged out, or was invalidated by a
     * password change — and it will not say which, which is why
     * MailboxFault::SignInNoLongerValid names all three.
     */
    public function test_a_revoked_grant_becomes_the_existing_sign_in_fault(): void
    {
        Http::fake([
            'oauth2.googleapis.com/token' => Http::sequence()
                ->push(['access_token' => 'the-stale-one', 'expires_in' => 3599])
                ->push(['error' => 'invalid_grant',
                    'error_description' => 'Token has been expired or revoked.'], 400),

            self::LISTING => Http::response([], 401),
        ]);

        $t       = $this->provisionTenant('A1-Group');
        $mailbox = $this->inbox($t);

        try {
            $this->inTenant($t['org'], fn () => app(GmailMailboxReader::class)->list($mailbox));

            $this->fail('A withdrawn sign-in returned a page.');
        } catch (CannotReadMailbox $e) {
            $this->assertSame(MailboxFault::SignInNoLongerValid, $e->fault);
        }

        $this->assertSame(2, $this->tokenRequests());
    }

    /**
     * EXACTLY ONE RETRY, and then the existing diagnosis.
     *
     * A loop would turn a revoked mailbox into a request that hammers Google's
     * token endpoint until something times out — and the quota that follows
     * would then be reported as a rate limit, which is a second wrong answer
     * sitting on top of the first.
     *
     * The second 401 is the honest one: that token is seconds old, so the grant
     * behind it really has gone.
     */
    public function test_a_second_refusal_is_not_retried_again(): void
    {
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(
                ['access_token' => 'an-access-token', 'expires_in' => 3599],
            ),
            self::LISTING => Http::response([], 401),
        ]);

        $t       = $this->provisionTenant('A1-Group');
        $mailbox = $this->inbox($t);

        try {
            $this->inTenant($t['org'], fn () => app(GmailMailboxReader::class)->list($mailbox));

            $this->fail('A mailbox whose every call is refused returned a page.');
        } catch (CannotReadMailbox $e) {
            $this->assertSame(MailboxFault::SignInNoLongerValid, $e->fault);
        }

        $this->assertSame(2, $this->tokenRequests(),
            'The reader asked for a fresh token more than once per call, so a dead sign-in '
            .'becomes a loop against Google\'s token endpoint.');

        $this->assertSame(
            2,
            Http::recorded(fn ($request) => str_contains($request->url(), '/users/me/threads'))->count(),
            'The read was attempted more than twice.',
        );
    }
}
