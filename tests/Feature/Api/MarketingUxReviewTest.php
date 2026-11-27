<?php

namespace Tests\Feature\Api;

use App\Models\Customer;
use App\Models\SocialConnection;
use App\Models\SocialPost;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Marketing UX review 2026-11-09, top-15 items with a server half. */
class MarketingUxReviewTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private const ENDPOINT = '/api/v1/social/posts';

    /** C2: the calendar asks for its month, and gets more than the newest twenty. */
    public function test_a_date_range_returns_every_post_on_those_days(): void
    {
        $t = $this->provisionTenant('Alpha');
        $page = $this->connection($t, 'facebook_page', 'page-1');

        $this->inTenant($t['org'], function () use ($t, $page) {
            for ($i = 1; $i <= 25; $i++) {
                SocialPost::create([
                    'body' => "march {$i}", 'created_by' => $t['admin']->id,
                    'scheduled_for' => sprintf('2026-03-%02d 09:00:00', $i), 'scheduled_timezone' => 'America/New_York',
                ])->targets()->create(['social_connection_id' => $page->id, 'status' => 'scheduled']);
            }
            SocialPost::create([
                'body' => 'april', 'created_by' => $t['admin']->id,
                'scheduled_for' => '2026-04-02 09:00:00', 'scheduled_timezone' => 'America/New_York',
            ])->targets()->create(['social_connection_id' => $page->id, 'status' => 'scheduled']);
        });

        $march = $this->signedInAs($t['admin'])
            ->getJson(self::ENDPOINT.'?from=2026-03-01&to=2026-03-31')
            ->assertOk()->json('data.*.body');

        $this->assertCount(25, $march);
        $this->assertNotContains('april', $march);
        $this->assertCount(20, $this->signedInAs($t['admin'])->getJson(self::ENDPOINT)->json('data'));
    }

    /** C4: the composer's limit and caution come from the rules that refuse. */
    public function test_a_connection_carries_its_limit_and_what_it_refuses(): void
    {
        $t = $this->provisionTenant('Bravo');
        $this->connection($t, 'instagram_professional', 'ig-1');

        $row = collect($this->signedInAs($t['admin'])->getJson('/api/v1/social/connections')->assertOk()->json('data'))
            ->firstWhere('provider', 'instagram_professional');

        $this->assertSame(2200, $row['body_limit']);
        $this->assertStringContainsString('does not post words on their own', (string) $row['refuses']['text']);
        $this->assertNull($row['refuses']['image']);
    }

    /** C5: Needs approval is a tile of its own, and filters to those posts. */
    public function test_posts_waiting_for_approval_have_their_own_tile(): void
    {
        $t = $this->provisionTenant('Charlie');
        $page = $this->connection($t, 'google_business_profile', 'gbp-1');

        $this->inTenant($t['org'], function () use ($t, $page) {
            foreach (['awaiting_approval', 'sent'] as $status) {
                SocialPost::create(['body' => $status, 'created_by' => $t['admin']->id])
                    ->targets()->create(['social_connection_id' => $page->id, 'status' => $status]);
            }
        });

        $this->signedInAs($t['admin'])->getJson(self::ENDPOINT)
            ->assertOk()->assertJsonPath('meta.tiles.awaiting', 1);
        $this->assertSame(['awaiting_approval'], $this->signedInAs($t['admin'])
            ->getJson(self::ENDPOINT.'?status=awaiting')->json('data.*.body'));
    }

    /** C13 (A29): a library picture becomes a public address for the image block. */
    public function test_a_library_picture_is_copied_to_a_public_newsletter_address(): void
    {
        Storage::fake(config('filesystems.default'));
        $t = $this->provisionTenant('Delta');

        $id = $this->signedInAs($t['admin'])
            ->post('/api/v1/social/library', ['file' => UploadedFile::fake()->image('van.jpg', 8, 8), 'name' => 'Van'])
            ->assertCreated()->json('data.id');

        $url = (string) $this->signedInAs($t['admin'])->postJson("/api/v1/newsletter/import/library/{$id}")
            ->assertCreated()->json('data.url');
        $this->assertMatchesRegularExpression('#/api/v1/newsletter/artwork/[A-Za-z0-9-]+/[A-Za-z0-9]{40}\.jpg$#', $url);

        $this->app['auth']->forgetGuards();
        $this->assertSame('image/jpeg', $this->get(parse_url($url, PHP_URL_PATH))->assertOk()->headers->get('Content-Type'));
    }

    /** No marketing email: customers.edit stamps and clears email_opt_out_at. */
    public function test_no_marketing_email_is_set_and_cleared_on_the_customer(): void
    {
        $t = $this->provisionTenant('Echo');
        $as = $this->signedInAs($t['admin']);
        $id = $as->postJson('/api/v1/customers', ['owning_franchise_id' => $t['franchise']->id, 'name' => 'Ann', 'email' => 'ann@example.com'])
            ->assertCreated()->json('data.id');
        $stamp = fn () => $this->inTenant($t['org'], fn () => Customer::query()->findOrFail($id)->email_opt_out_at);

        $as->patchJson("/api/v1/customers/{$id}", ['email_opt_out' => true])
            ->assertOk()->assertJsonPath('data.email_opt_out', true);
        $this->assertNotNull($stamp());

        $as->patchJson("/api/v1/customers/{$id}", ['email_opt_out' => false])
            ->assertOk()->assertJsonPath('data.email_opt_out', false);
        $this->assertNull($stamp());

        $viewer = $this->userWithRole($t, 'Viewer', ['customers.view']);
        $this->signedInAs($viewer)->patchJson("/api/v1/customers/{$id}", ['email_opt_out' => true])->assertForbidden();
        $this->assertNull($stamp());
    }

    private function connection(array $t, string $provider, string $remoteId): SocialConnection
    {
        return $this->inTenant($t['org'], function () use ($t, $provider, $remoteId) {
            $row = new SocialConnection;
            $row->forceFill([
                'organization_id' => $t['org']->id, 'scope_type' => 'company', 'scope_id' => $t['company']->id,
                'provider' => $provider, 'remote_id' => $remoteId, 'display_name' => 'A-1 '.$provider,
            ]);
            $row->connect(['refresh_token' => 'r', 'access_token' => 'a']);
            $row->save();

            return $row;
        });
    }
}
