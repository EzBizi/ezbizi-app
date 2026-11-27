<?php

namespace Tests\Feature\Api;

use App\Models\SocialConnection;
use App\Support\Social\SocialProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Meta's deauthorize and data-deletion callbacks.
 *
 * These are the only unauthenticated write endpoints on the platform, so the
 * signature is the whole security model. Most of what is worth testing here is
 * what happens when it does NOT verify.
 */
class MetaCallbacksTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private const SECRET = 'test-meta-app-secret';

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.meta.client_id', '1750581562857402');
        config()->set('services.meta.client_secret', self::SECRET);
    }

    /** A signed_request exactly as Meta builds one. */
    private function signedRequest(string $userId, ?string $secret = null): string
    {
        $payload = $this->base64url(json_encode([
            'algorithm' => 'HMAC-SHA256',
            'user_id' => $userId,
            'issued_at' => 1787000000,
        ]));

        $signature = $this->base64url(
            hash_hmac('sha256', $payload, $secret ?? self::SECRET, binary: true),
        );

        return $signature.'.'.$payload;
    }

    private function base64url(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private function connectionFor(string $metaUserId, string $provider): SocialConnection
    {
        $tenant = $this->provisionTenant('Meta Co');

        return $this->inTenant($tenant['org'], fn () => SocialConnection::forceCreate([
            'organization_id' => $tenant['org']->id,
            'scope_type' => 'organization',
            'scope_id' => $tenant['org']->id,
            'provider' => $provider,
            'remote_id' => 'page-'.$metaUserId,
            'meta_user_id' => $metaUserId,
            'display_name' => 'A Page',
            'status' => SocialConnection::STATUS_CONNECTED,
        ]));
    }

    public function test_deauthorize_marks_that_persons_connections_revoked(): void
    {
        $row = $this->connectionFor('meta-user-1', SocialProvider::FacebookPage->value);

        $this->postJson('/api/v1/social/meta/deauthorize', [
            'signed_request' => $this->signedRequest('meta-user-1'),
        ])->assertOk()->assertJson(['revoked' => 1]);

        $this->assertSame(
            SocialConnection::STATUS_REVOKED,
            SocialConnection::withoutGlobalScopes()->find($row->id)->status,
        );
    }

    public function test_data_deletion_deletes_them_and_answers_the_way_meta_requires(): void
    {
        $row = $this->connectionFor('meta-user-2', SocialProvider::FacebookPage->value);

        $response = $this->postJson('/api/v1/social/meta/data-deletion', [
            'signed_request' => $this->signedRequest('meta-user-2'),
        ])->assertOk();

        // Meta checks for both keys and shows the url to the person.
        $this->assertSame('https://www.ezbizi.com/delete-account/', $response->json('url'));
        $this->assertNotEmpty($response->json('confirmation_code'));

        $this->assertNull(SocialConnection::withoutGlobalScopes()->find($row->id));
    }

    // Audit 11-27: Ads tokens from the same Facebook login were kept after a deletion request.
    public function test_data_deletion_also_deletes_that_persons_facebook_ads_connections(): void
    {
        $ads = $this->connectionFor('meta-user-ads', SocialProvider::FacebookAds->value);

        $this->postJson('/api/v1/social/meta/data-deletion', [
            'signed_request' => $this->signedRequest('meta-user-ads'),
        ])->assertOk();

        $this->assertNull(SocialConnection::withoutGlobalScopes()->find($ads->id));
    }

    public function test_a_forged_signature_changes_nothing(): void
    {
        $row = $this->connectionFor('meta-user-3', SocialProvider::FacebookPage->value);

        // Right shape, right user id, signed with the wrong secret — which is
        // exactly what somebody who had read the docs but not stolen the secret
        // would be able to build.
        $this->postJson('/api/v1/social/meta/deauthorize', [
            'signed_request' => $this->signedRequest('meta-user-3', 'not-the-secret'),
        ])->assertStatus(400);

        $this->assertSame(
            SocialConnection::STATUS_CONNECTED,
            SocialConnection::withoutGlobalScopes()->find($row->id)->status,
        );
    }

    public function test_a_missing_or_shapeless_signed_request_is_refused(): void
    {
        $this->postJson('/api/v1/social/meta/deauthorize', [])->assertStatus(400);
        $this->postJson('/api/v1/social/meta/data-deletion', [
            'signed_request' => 'not-even-dotted',
        ])->assertStatus(400);
    }

    public function test_another_organizations_connections_are_untouched(): void
    {
        $mine = $this->connectionFor('meta-user-4', SocialProvider::FacebookPage->value);
        $someone = $this->connectionFor('meta-user-5', SocialProvider::FacebookPage->value);

        $this->postJson('/api/v1/social/meta/deauthorize', [
            'signed_request' => $this->signedRequest('meta-user-4'),
        ])->assertOk()->assertJson(['revoked' => 1]);

        $this->assertSame(
            SocialConnection::STATUS_CONNECTED,
            SocialConnection::withoutGlobalScopes()->find($someone->id)->status,
        );
        $this->assertSame(
            SocialConnection::STATUS_REVOKED,
            SocialConnection::withoutGlobalScopes()->find($mine->id)->status,
        );
    }

    public function test_a_non_meta_connection_is_never_matched(): void
    {
        // Same id in the column, but TikTok. Meta's callback has no business
        // touching a connection Meta did not issue.
        $row = $this->connectionFor('meta-user-6', SocialProvider::TikTok->value);

        $this->postJson('/api/v1/social/meta/deauthorize', [
            'signed_request' => $this->signedRequest('meta-user-6'),
        ])->assertOk()->assertJson(['revoked' => 0]);

        $this->assertSame(
            SocialConnection::STATUS_CONNECTED,
            SocialConnection::withoutGlobalScopes()->find($row->id)->status,
        );
    }
}
