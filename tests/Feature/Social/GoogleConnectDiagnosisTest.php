<?php

namespace Tests\Feature\Social;

use App\Support\Social\BusinessProfileOAuth;
use App\Support\Social\GoogleAdsOAuth;
use App\Support\Social\SocialOAuthGrant;
use App\Support\Social\SocialOAuthRefused;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * What the screen says when Google refuses to name the account.
 *
 * Every one of these used to produce the same sentence — "EzBizi could not tell
 * which account that was, wait a moment and connect it again" — and nothing was
 * written to the log, so a real connect failure in production left no evidence
 * of which of four unrelated causes it was. Retrying is the correct advice for
 * exactly none of them.
 *
 * The reasons are pinned here rather than the wording, because the point is that
 * a person reading the screen can tell the causes apart and act.
 */
class GoogleConnectDiagnosisTest extends TestCase
{
    private function grant(): SocialOAuthGrant
    {
        return new SocialOAuthGrant(accessToken: 'at-test', refreshToken: 'rt-test', expiresIn: 3600);
    }

    private function adsFails(array $body, int $status): SocialOAuthRefused
    {
        config(['services.google_ads.developer_token' => 'dev-token']);

        Http::fake(['googleads.googleapis.com/*' => Http::response($body, $status)]);

        try {
            (new GoogleAdsOAuth)->identify($this->grant());
        } catch (SocialOAuthRefused $e) {
            return $e;
        }

        $this->fail('identify() should have refused');
    }

    public function test_an_api_nobody_enabled_says_so_instead_of_asking_for_a_retry(): void
    {
        $e = $this->adsFails([
            'error' => [
                'code'    => 403,
                'status'  => 'PERMISSION_DENIED',
                'message' => 'Google Ads API has not been used in project 247590132639 before or it is disabled.',
                'details' => [[
                    '@type'  => 'type.googleapis.com/google.rpc.ErrorInfo',
                    'reason' => 'SERVICE_DISABLED',
                    'metadata' => ['service' => 'googleads.googleapis.com'],
                ]],
            ],
        ], 403);

        $said = $e->getMessage();

        $this->assertStringContainsString('not enabled', $said);
        $this->assertStringNotContainsString('Wait a moment', $said);
    }

    public function test_a_developer_token_google_has_not_approved_says_which_gate_is_shut(): void
    {
        $e = $this->adsFails([
            'error' => [
                'code'    => 403,
                'status'  => 'PERMISSION_DENIED',
                'message' => 'The caller does not have permission',
                'details' => [[
                    '@type'  => 'type.googleapis.com/google.ads.googleads.v21.errors.GoogleAdsFailure',
                    'errors' => [[
                        'errorCode' => ['authorizationError' => 'DEVELOPER_TOKEN_NOT_APPROVED'],
                        'message'   => 'The developer token is not approved.',
                    ]],
                ]],
            ],
        ], 403);

        $said = $e->getMessage();

        $this->assertStringContainsString('developer token', $said);
        $this->assertStringNotContainsString('Wait a moment', $said);
    }

    /**
     * The one that actually happened, and the reason this file exists.
     *
     * Production sat on v21 after Google retired it. Every call 404'd on a path
     * that no longer existed, and a dead version never reaches the service, so
     * the Cloud console recorded no requests and no errors at all — thirty days
     * of nothing to find. It read as an access problem for weeks and was a URL.
     */
    public function test_a_retired_api_version_says_so_rather_than_looking_like_a_permission_problem(): void
    {
        $e = $this->adsFails([], 404);

        $said = $e->getMessage();

        $this->assertStringContainsString('retired', $said);
        $this->assertStringNotContainsString('Wait a moment', $said);
    }

    public function test_the_pinned_ads_api_version_is_not_one_google_has_retired(): void
    {
        /*
         * Pinned rather than fetched. A test that asked Google which versions
         * are live would go red on their retirement schedule rather than on a
         * change of ours, and would need the network to run at all. This asserts
         * the narrower thing that was actually wrong: the default must not be a
         * version already known to be gone.
         */
        $retired = ['v18', 'v19', 'v20', 'v21'];

        $this->assertNotContains(
            trim((string) config('services.google_ads.version'), '/'),
            $retired,
            'The Google Ads API version is one Google has retired; every call will 404.',
        );
    }

    public function test_business_profile_at_zero_quota_is_not_described_as_a_passing_rate_limit(): void
    {
        /*
         * Google's answer at zero quota is IDENTICAL to its answer when
         * throttling: 429, RESOURCE_EXHAUSTED, RATE_LIMIT_EXCEEDED, "Quota
         * exceeded for quota metric 'Requests' and limit 'Requests per minute'".
         * At zero QPM every request exceeds the limit, so the status cannot tell
         * the two apart.
         *
         * Production proved which it was, on 2026-09-03: five attempts logged
         * across fifty minutes - 17:19, 17:21, 18:05, 18:08, 18:09 - all the
         * same. A forty-four minute gap clears any per-minute limit that is
         * really per-minute. The Business Profile APIs ship at zero and stay
         * there until an access application is approved, which is what
         * BusinessProfileOAuth has said in its own doc comment all along.
         *
         * So for this network the wait advice is not merely unhelpful, it is
         * the one instruction guaranteed to fail, and it sent Jose back through
         * Continue twice.
         */
        Http::fake(['mybusinessaccountmanagement.googleapis.com/*' => Http::response([
            'error' => [
                'code'    => 429,
                'status'  => 'RESOURCE_EXHAUSTED',
                'message' => "Quota exceeded for quota metric 'Requests' and limit 'Requests per minute' "
                    ."of service 'mybusinessaccountmanagement.googleapis.com'.",
                'details' => [[
                    '@type'  => 'type.googleapis.com/google.rpc.ErrorInfo',
                    'reason' => 'RATE_LIMIT_EXCEEDED',
                ]],
            ],
        ], 429)]);

        try {
            (new BusinessProfileOAuth)->identify($this->grant());
            $this->fail('identify() should have refused');
        } catch (SocialOAuthRefused $e) {
            $said = $e->getMessage();

            $this->assertStringNotContainsString('Wait a minute', $said);
            $this->assertStringContainsString('access application', $said);
        }
    }

    public function test_a_network_whose_quota_was_granted_still_gets_the_wait_advice(): void
    {
        // The guard must not swallow real throttling. Google Ads has Basic
        // Access, so RATE_LIMIT_EXCEEDED there genuinely is a passing limit and
        // waiting genuinely is the answer.
        $e = $this->adsFails([
            'error' => [
                'code'    => 429,
                'status'  => 'RESOURCE_EXHAUSTED',
                'message' => 'Too many requests.',
                'details' => [[
                    '@type'  => 'type.googleapis.com/google.rpc.ErrorInfo',
                    'reason' => 'RATE_LIMIT_EXCEEDED',
                ]],
            ],
        ], 429);

        $this->assertStringContainsString('Wait a minute', $e->getMessage());
    }

    /**
     * An account is named the way Google names it, not by its digits.
     *
     * listAccessibleCustomers returns resource names and nothing else, so every
     * connection was stored as "405-172-9356" - and twenty-two of those look
     * alike. Google holds a descriptive name per customer ("A1 Chimney - Ads
     * Manager", measured live on 2026-09-08); one GAQL read per account at
     * connect time fetches it. The digits stay as the fallback, never the
     * default.
     */
    public function test_a_connected_account_is_named_the_way_google_names_it(): void
    {
        config(['services.google_ads.developer_token' => 'dev-token', 'services.google_ads.version' => 'v24']);

        Http::fake([
            'googleads.googleapis.com/v24/customers:listAccessibleCustomers' => Http::response(
                ['resourceNames' => ['customers/4051729356', 'customers/1111111111']],
                200,
            ),
            'googleads.googleapis.com/v24/customers/4051729356/googleAds:search' => Http::response(
                ['results' => [['customer' => ['id' => '4051729356', 'descriptiveName' => 'A1 Chimney - Ads Manager']]]],
                200,
            ),
            // The second account's name read fails: the row must still exist,
            // under its digits, rather than the whole connect falling over.
            'googleads.googleapis.com/v24/customers/1111111111/googleAds:search' => Http::response(
                ['error' => ['status' => 'PERMISSION_DENIED']],
                403,
            ),
        ]);

        $accounts = (new GoogleAdsOAuth)->identify($this->grant());

        $byId = [];
        foreach ($accounts as $a) {
            $byId[$a->remoteId] = $a->displayName;
        }

        $this->assertSame('A1 Chimney - Ads Manager', $byId['4051729356']);
        $this->assertSame('111-111-1111', $byId['1111111111'], 'Digits are the fallback when Google will not name it.');
    }

    /**
     * The accounts a user reaches only THROUGH a manager are connected too.
     *
     * listAccessibleCustomers named twenty-two accounts for A-1 Chimney; the
     * Ads Manager among them has thirty-six enabled clients, and the other
     * twenty-three answered PERMISSION_DENIED to every read that did not carry
     * the manager as login-customer-id. They were never connected, so their
     * leads never arrived (2026-09-09). A client the user also reaches directly
     * keeps its direct row.
     */
    public function test_a_managers_enabled_clients_are_connected_through_the_manager(): void
    {
        config(['services.google_ads.developer_token' => 'dev-token', 'services.google_ads.version' => 'v24']);

        Http::fake(function ($request) {
            if (str_contains($request->url(), 'listAccessibleCustomers')) {
                return Http::response(['resourceNames' => ['customers/4051729356', 'customers/4941850746']]);
            }

            $query = (string) (((array) $request->data())['query'] ?? '');

            if (str_contains($query, 'FROM customer_client')) {
                $this->assertSame(['4051729356'], $request->header('login-customer-id'), 'clients are listed through the manager');

                return Http::response(['results' => [
                    ['customerClient' => ['id' => '4051729356', 'descriptiveName' => 'A1 Chimney - Ads Manager', 'level' => 0, 'manager' => true, 'status' => 'ENABLED']],
                    ['customerClient' => ['id' => '4941850746', 'descriptiveName' => 'A1 Chimney - Cleveland', 'level' => 1, 'manager' => false, 'status' => 'ENABLED']],
                    ['customerClient' => ['id' => '2796623321', 'descriptiveName' => 'A1 Chimney - Stamford', 'level' => 2, 'manager' => false, 'status' => 'ENABLED']],
                    ['customerClient' => ['id' => '4634809513', 'descriptiveName' => 'A1 Chimney - Connecticut', 'level' => 1, 'manager' => false, 'status' => 'CANCELED']],
                    ['customerClient' => ['id' => '3747134697', 'descriptiveName' => 'A1 Chimney - Philadelphia (MCC)', 'level' => 1, 'manager' => true, 'status' => 'ENABLED']],
                ]]);
            }

            if (str_contains($request->url(), 'customers/4051729356/')) {
                return Http::response(['results' => [['customer' => ['id' => '4051729356', 'descriptiveName' => 'A1 Chimney - Ads Manager', 'manager' => true]]]]);
            }

            return Http::response(['results' => [['customer' => ['id' => '4941850746', 'descriptiveName' => 'A1 Chimney - Cleveland', 'manager' => false]]]]);
        });

        $accounts = (new GoogleAdsOAuth)->identify($this->grant());

        $byId = [];
        foreach ($accounts as $a) {
            $byId[$a->remoteId] = $a;
        }

        $this->assertSame(['4051729356', '4941850746', '2796623321'], array_map('strval', array_keys($byId)), 'direct accounts, then the manager\'s clients that are not already there; never a cancelled or manager client');
        $this->assertNull($byId['4941850746']->loginCustomerId, 'reached directly, so read directly');
        $this->assertSame('4051729356', $byId['2796623321']->loginCustomerId, 'reached only through the manager');
        $this->assertSame('A1 Chimney - Stamford', $byId['2796623321']->displayName);
    }

    public function test_signing_in_with_an_account_that_has_no_ads_accounts_is_not_a_failure_to_identify(): void
    {
        config(['services.google_ads.developer_token' => 'dev-token']);

        Http::fake(['googleads.googleapis.com/*' => Http::response(['resourceNames' => []], 200)]);

        try {
            (new GoogleAdsOAuth)->identify($this->grant());
            $this->fail('identify() should have refused');
        } catch (SocialOAuthRefused $e) {
            // The one case where the sign-in worked perfectly and the answer is
            // "that Google account owns nothing to connect". Telling somebody to
            // wait and try again sends them round the same loop for ever.
            $this->assertStringContainsString('no Google Ads account', $e->getMessage());
            $this->assertStringNotContainsString('Wait a moment', $e->getMessage());
        }
    }

    /*
     * Removed: test_a_per_minute_rate_limit_says_to_wait_rather_than_claiming_
     * quota_was_never_granted.
     *
     * It faked Business Profile returning RATE_LIMIT_EXCEEDED and required the
     * screen to answer "wait a minute". Production disproved that on the day it
     * shipped - five identical refusals over fifty minutes, including a
     * forty-four minute gap - and the test would have kept the wrong sentence
     * in place, because it had written the bug down as a requirement.
     *
     * The case it meant to cover, a genuine passing limit, is covered by
     * test_a_network_whose_quota_was_granted_still_gets_the_wait_advice, using
     * a network whose quota Google has actually granted.
     */

    public function test_business_profile_quota_is_named_because_enabling_the_api_does_not_grant_it(): void
    {
        Http::fake(['mybusinessaccountmanagement.googleapis.com/*' => Http::response([
            'error' => [
                'code'    => 429,
                'status'  => 'RESOURCE_EXHAUSTED',
                'message' => "Quota exceeded for quota metric 'Requests' and limit 'Requests per minute'.",
            ],
        ], 429)]);

        try {
            (new BusinessProfileOAuth)->identify($this->grant());
            $this->fail('identify() should have refused');
        } catch (SocialOAuthRefused $e) {
            $this->assertStringContainsString('quota', strtolower($e->getMessage()));
            $this->assertStringNotContainsString('Wait a moment', $e->getMessage());
        }
    }

    public function test_the_refusal_reaches_the_log_with_the_status_and_never_the_token(): void
    {
        config(['services.google_ads.developer_token' => 'dev-token']);

        $lines = [];

        Log::listen(function ($message) use (&$lines) {
            $lines[] = ['message' => $message->message, 'context' => $message->context];
        });

        Http::fake(['googleads.googleapis.com/*' => Http::response([
            'error' => ['code' => 403, 'status' => 'PERMISSION_DENIED', 'message' => 'The caller does not have permission'],
        ], 403)]);

        try {
            (new GoogleAdsOAuth)->identify($this->grant());
        } catch (SocialOAuthRefused) {
            // expected
        }

        $this->assertNotSame([], $lines, 'a refused identify() must leave a log line');

        $dumped = json_encode($lines);

        $this->assertStringContainsString('403', $dumped);
        $this->assertStringNotContainsString('at-test', $dumped, 'the access token must never be logged');
        $this->assertStringNotContainsString('rt-test', $dumped, 'the refresh token must never be logged');
        $this->assertStringNotContainsString('dev-token', $dumped, 'the developer token must never be logged');
    }
}
