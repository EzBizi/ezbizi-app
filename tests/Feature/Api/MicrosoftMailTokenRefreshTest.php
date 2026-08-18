<?php

namespace Tests\Feature\Api;

use App\Models\Mailbox;
use App\Support\Email\CannotReadMailbox;
use App\Support\Email\MailboxFault;
use App\Support\Email\MicrosoftMailboxReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Keeping a shared Outlook inbox open when nobody is standing at a browser.
 *
 * Copied from GoogleMailTokenRefreshTest: reuse, expired-is-ordinary, dead grant.
 */
class MicrosoftMailTokenRefreshTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private const TOKEN_ENDPOINT = 'https://login.microsoftonline.com/common/oauth2/v2.0/token';

    private const LISTING = 'graph.microsoft.com/v1.0/me/mailFolders/inbox/messages*';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.microsoft_mail.client_id'     => 'a-microsoft-client-id',
            'services.microsoft_mail.client_secret' => 'a-microsoft-client-secret',
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
                'provider'        => 'microsoft_oauth',
            ]);

            $mailbox->connect('a-refresh-token');
            $mailbox->save();

            return $mailbox;
        });
    }

    private function tokenRequests(): int
    {
        return Http::recorded(fn ($request) => $request->url() === self::TOKEN_ENDPOINT)->count();
    }

    public function test_an_access_token_is_reused_and_is_not_cached_in_the_clear(): void
    {
        Http::fake([
            'login.microsoftonline.com/common/oauth2/v2.0/token' => Http::response(
                ['access_token' => 'an-access-token', 'expires_in' => 3599],
            ),
            self::LISTING => Http::response(['value' => []]),
        ]);

        $t       = $this->provisionTenant('A1-Group');
        $mailbox = $this->inbox($t);

        $this->inTenant($t['org'], function () use ($mailbox) {
            app(MicrosoftMailboxReader::class)->list($mailbox);
            app(MicrosoftMailboxReader::class)->list($mailbox);
        });

        $this->assertSame(1, $this->tokenRequests(),
            'Every read mints a new access token. A page of twenty-five messages is then '
            .'twenty-six round trips to Microsoft, and token minting is rate limited '
            .'separately — so the cost arrives as a quota failure that looks like a broken '
            .'mailbox.');

        $cached = Cache::get('email.microsoft.access-token.'.$mailbox->getKey());

        $this->assertNotEmpty($cached, 'Nothing was cached, so the assertion above is '
            .'passing for a reason other than the one it names.');

        $this->assertStringNotContainsString('an-access-token', (string) $cached,
            'The access token is in the cache store in the clear — an hour of access to '
            .'somebody\'s mail, readable by anything that can read the cache.');
    }

    public function test_a_token_with_no_stated_lifetime_is_not_cached(): void
    {
        Http::fake([
            'login.microsoftonline.com/common/oauth2/v2.0/token' => Http::response(['access_token' => 'an-access-token']),
            self::LISTING => Http::response(['value' => []]),
        ]);

        $t       = $this->provisionTenant('A1-Group');
        $mailbox = $this->inbox($t);

        $this->inTenant($t['org'], function () use ($mailbox) {
            app(MicrosoftMailboxReader::class)->list($mailbox);
            app(MicrosoftMailboxReader::class)->list($mailbox);
        });

        $this->assertSame(2, $this->tokenRequests());
        $this->assertNull(Cache::get('email.microsoft.access-token.'.$mailbox->getKey()));
    }

    public function test_an_expired_access_token_is_refreshed_without_anybody_present(): void
    {
        Http::fake([
            'login.microsoftonline.com/common/oauth2/v2.0/token' => Http::sequence()
                ->push(['access_token' => 'the-stale-one', 'expires_in' => 3599])
                ->push(['access_token' => 'the-fresh-one', 'expires_in' => 3599]),

            self::LISTING => Http::sequence()
                ->push([], 401)
                ->push(['value' => []]),
        ]);

        $t       = $this->provisionTenant('A1-Group');
        $mailbox = $this->inbox($t);

        $page = $this->inTenant($t['org'], fn () => app(MicrosoftMailboxReader::class)->list($mailbox));

        $this->assertSame([], $page->messages);

        $this->assertSame(2, $this->tokenRequests(),
            'The stale token was not replaced, so the read either failed or reused it.');

        Http::assertSent(fn ($request) => str_contains($request->url(), '/mailFolders/inbox/messages')
            && $request->hasHeader('Authorization', 'Bearer the-fresh-one'));

        $fresh = $this->inTenant($t['org'], fn () => $mailbox->fresh());

        $this->assertSame(Mailbox::STATUS_CONNECTED, $fresh->status);
        $this->assertNull($fresh->status_reason);
    }

    public function test_a_rejected_access_token_is_dropped_from_the_cache(): void
    {
        Http::fake([
            'login.microsoftonline.com/common/oauth2/v2.0/token' => Http::sequence()
                ->push(['access_token' => 'the-stale-one', 'expires_in' => 3599])
                ->push(['access_token' => 'the-fresh-one', 'expires_in' => 3599]),

            self::LISTING => Http::sequence()
                ->push([], 401)
                ->push(['value' => []]),
        ]);

        $t       = $this->provisionTenant('A1-Group');
        $mailbox = $this->inbox($t);

        $this->inTenant($t['org'], fn () => app(MicrosoftMailboxReader::class)->list($mailbox));

        $cached = Cache::get('email.microsoft.access-token.'.$mailbox->getKey());

        $this->assertNotEmpty($cached);
        $this->assertSame(
            'the-fresh-one',
            \Illuminate\Support\Facades\Crypt::decryptString((string) $cached),
            'The rejected token was left in the cache, so the next read starts by presenting '
            .'a token Microsoft has already refused.',
        );
    }

    public function test_a_revoked_grant_becomes_the_existing_sign_in_fault(): void
    {
        Http::fake([
            'login.microsoftonline.com/common/oauth2/v2.0/token' => Http::sequence()
                ->push(['access_token' => 'the-stale-one', 'expires_in' => 3599])
                ->push(['error' => 'invalid_grant',
                    'error_description' => 'The refresh token has expired or been revoked.'], 400),

            self::LISTING => Http::response([], 401),
        ]);

        $t       = $this->provisionTenant('A1-Group');
        $mailbox = $this->inbox($t);

        try {
            $this->inTenant($t['org'], fn () => app(MicrosoftMailboxReader::class)->list($mailbox));

            $this->fail('A withdrawn sign-in returned a page.');
        } catch (CannotReadMailbox $e) {
            $this->assertSame(MailboxFault::SignInNoLongerValid, $e->fault);
        }

        $this->assertSame(2, $this->tokenRequests());
    }

    public function test_a_second_refusal_is_not_retried_again(): void
    {
        Http::fake([
            'login.microsoftonline.com/common/oauth2/v2.0/token' => Http::response(
                ['access_token' => 'an-access-token', 'expires_in' => 3599],
            ),
            self::LISTING => Http::response([], 401),
        ]);

        $t       = $this->provisionTenant('A1-Group');
        $mailbox = $this->inbox($t);

        try {
            $this->inTenant($t['org'], fn () => app(MicrosoftMailboxReader::class)->list($mailbox));

            $this->fail('A mailbox whose every call is refused returned a page.');
        } catch (CannotReadMailbox $e) {
            $this->assertSame(MailboxFault::SignInNoLongerValid, $e->fault);
        }

        $this->assertSame(2, $this->tokenRequests(),
            'The reader asked for a fresh token more than once per call, so a dead sign-in '
            .'becomes a loop against Microsoft\'s token endpoint.');

        $this->assertSame(
            2,
            Http::recorded(fn ($request) => str_contains($request->url(), '/mailFolders/inbox/messages'))->count(),
            'The read was attempted more than twice.',
        );
    }
}
