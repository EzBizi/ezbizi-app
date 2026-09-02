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
