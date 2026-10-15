<?php

namespace Tests\Feature\Reviews;

use App\Models\Review;
use App\Models\SocialConnection;
use App\Services\Reviews\BusinessProfileReviews;
use App\Support\Social\SocialProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Reading reviews across every location, and saying the true thing when Google
 * refuses.
 */
class BusinessProfileReviewsTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private function connection(array $t): SocialConnection
    {
        return $this->inTenant($t['org'], function () use ($t) {
            $c = new SocialConnection;
            $c->forceFill([
                'organization_id' => $t['org']->id,
                'scope_type'      => SocialConnection::SCOPE_ORGANIZATION,
                'scope_id'        => $t['org']->id,
                'provider'        => SocialProvider::GoogleBusinessProfile->value,
                'remote_id'       => '111',
                'display_name'    => 'A-1 on Maps',
            ]);
            $c->connect(['refresh_token' => 'rt-test']);
            $c->save();

            return $c;
        });
    }

    private function fakeGoogle(array $routes): void
    {
        config([
            'services.business_profile.client_id'     => 'client.apps.googleusercontent.com',
            'services.business_profile.client_secret' => 'client-secret',
        ]);

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'at-fresh', 'expires_in' => 3600], 200),
            ...$routes,
        ]);
    }

    private function review(string $name): array
    {
        return [
            'name'       => $name,
            'reviewer'   => ['displayName' => 'Dana'],
            'starRating' => 'FOUR',
            'comment'    => 'Fine.',
            'createTime' => '2026-09-01T09:00:00Z',
        ];
    }

    /**
     * An account with more locations than one page holds must have ALL of them
     * read. locations() used to make one call with pageSize 100 and never look at
     * nextPageToken, so a multi-location operator - the market this product is
     * for - had reviews synced for the first hundred listings and silently
     * nothing for the rest. The sibling service for listings paginates; this
     * one did not.
     */
    public function test_every_page_of_locations_is_read(): void
    {
        $t = $this->provisionTenant('A1-Chimney');
        $this->connection($t);

        $this->fakeGoogle([
            // The locations listing, two pages, on v1 Business Information
            // (Google retired v4's list; reviews stay on v4).
            'mybusinessbusinessinformation.googleapis.com/*' => fn ($request) => ($request->data()['pageToken'] ?? null) === 'p2'
                ? Http::response(['locations' => [['name' => 'locations/2']]], 200)
                : Http::response(['locations' => [['name' => 'locations/1']], 'nextPageToken' => 'p2'], 200),
            // One closure for the whole v4 host, dispatching on the path.
            'mybusiness.googleapis.com/v4/*' => function ($request) {
                $url = $request->url();

                if (str_contains($url, '/locations/1/reviews')) {
                    return Http::response(['reviews' => [$this->review('accounts/111/locations/1/reviews/a')]], 200);
                }

                if (str_contains($url, '/locations/2/reviews')) {
                    return Http::response(['reviews' => [$this->review('accounts/111/locations/2/reviews/b')]], 200);
                }

                return Http::response([], 404);
            },
        ]);

        $result = $this->inTenant($t['org'], fn () => app(BusinessProfileReviews::class)->sync(
            SocialConnection::query()->firstOrFail(),
        ));

        $this->assertSame(2, $result['fetched']);
        $this->assertSame(
            2,
            $this->inTenant($t['org'], fn () => Review::query()->count()),
            'The review on the second page of locations was never fetched.',
        );
    }

    /**
     * 403 is PERMISSION_DENIED - a revoked grant, or a location this user may
     * not read. That IS a problem with the connection, and the fix is to
     * reconnect. Telling the customer it is quota "and not a problem with the
     * connected profile" sends them to wait for an approval that will never
     * repair a revoked grant.
     */
    public function test_a_permission_refusal_says_to_reconnect_rather_than_wait_for_quota(): void
    {
        $t = $this->provisionTenant('A1-Chimney');
        $this->connection($t);

        $this->fakeGoogle([
            'mybusinessbusinessinformation.googleapis.com/*' => Http::response(
                ['error' => ['code' => 403, 'status' => 'PERMISSION_DENIED', 'message' => 'The caller does not have permission']],
                403,
            ),
        ]);

        try {
            $this->inTenant($t['org'], fn () => app(BusinessProfileReviews::class)->sync(
                SocialConnection::query()->firstOrFail(),
            ));
            $this->fail('sync() should have refused');
        } catch (RuntimeException $e) {
            $this->assertStringNotContainsString('not a problem with the connected profile', $e->getMessage());
            $this->assertStringContainsString('onnect', $e->getMessage());
        }
    }

    public function test_zero_quota_is_still_named_as_quota(): void
    {
        $t = $this->provisionTenant('A1-Chimney');
        $this->connection($t);

        $this->fakeGoogle([
            'mybusinessbusinessinformation.googleapis.com/*' => Http::response(
                ['error' => ['code' => 429, 'status' => 'RESOURCE_EXHAUSTED', 'message' => "Quota exceeded for quota metric 'Requests' and limit 'Requests per minute'"]],
                429,
            ),
        ]);

        try {
            $this->inTenant($t['org'], fn () => app(BusinessProfileReviews::class)->sync(
                SocialConnection::query()->firstOrFail(),
            ));
            $this->fail('sync() should have refused');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('quota', strtolower($e->getMessage()));
        }
    }
}
