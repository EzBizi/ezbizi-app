<?php

namespace Tests\Feature\Api;

use App\Models\PlatformStaff;
use App\Models\Suggestion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PragmaRX\Google2FA\Google2FA;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * The control-plane half of Suggestions.
 *
 * Reads across every tenant on purpose — that is the whole point of the queue —
 * and is the only place in the application allowed to do so for this table.
 */
class StaffSuggestionsTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private Google2FA $totp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->totp = app(Google2FA::class);
    }

    private function staffToken(): string
    {
        $staff = PlatformStaff::create([
            'name' => 'Staffer',
            'email' => 'lead@ezbizi.test',
            'password' => 'a-sufficiently-long-password',
            'tier' => PlatformStaff::TIER_LEAD,
            'is_active' => true,
        ]);

        $secret = $this->totp->generateSecretKey(32);
        $staff->totp_secret = $secret;
        $staff->totp_confirmed_at = now();
        $staff->save();

        return $this->postJson('/api/v1/staff/auth/login', [
            'email' => $staff->email,
            'password' => 'a-sufficiently-long-password',
            'code' => $this->totp->getCurrentOtp($secret),
        ])->assertOk()->json('token');
    }

    /**
     * Rows are built through the model inside inTenant(), NOT by signing in and
     * posting.
     *
     * `signedInAs` calls Sanctum::actingAs, which resolves the guard for the
     * rest of the test — a later `withToken` is then ignored, and the staff
     * call authenticates as the tenant administrator and 403s. The failure
     * reads as a broken `staff` middleware rather than as a test-harness
     * problem. StaffPhoneRequestsTest::pendingRequest avoids it the same way.
     *
     * The reverse order is safe: `signedInAs` calls forgetGuards() first, so a
     * tenant call AFTER a staff call works.
     */
    private function suggestionIn(array $t, string $body): Suggestion
    {
        return $this->inTenant($t['org'], fn () => Suggestion::create([
            'user_id' => $t['admin']->id,
            'body' => $body,
            'status' => Suggestion::STATUS_NEW,
        ]));
    }

    public function test_the_queue_spans_organizations(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $beta = $this->provisionTenant('Beta', 'beta@example.test');

        $this->suggestionIn($alpha, 'From Alpha.');
        $this->suggestionIn($beta, 'From Beta.');

        $body = $this->withToken($this->staffToken())
            ->getJson('/api/v1/staff/suggestions')
            ->assertOk()
            ->json('data');

        $this->assertCount(2, $body);
        $this->assertEqualsCanonicalizing(
            ['Alpha', 'Beta'],
            array_column($body, 'organization'),
        );
    }

    public function test_the_queue_names_the_author_and_the_organization(): void
    {
        $t = $this->provisionTenant('Alpha');

        $this->suggestionIn($t, 'Name me.');

        $this->withToken($this->staffToken())
            ->getJson('/api/v1/staff/suggestions')
            ->assertOk()
            ->assertJsonPath('data.0.organization', 'Alpha')
            ->assertJsonPath('data.0.author', 'Alpha Admin')
            ->assertJsonPath('data.0.status', Suggestion::STATUS_NEW);
    }

    public function test_a_tenant_token_cannot_reach_the_queue(): void
    {
        $t = $this->provisionTenant();

        $this->signedInAs($t['admin'])
            ->getJson('/api/v1/staff/suggestions')
            ->assertForbidden();
    }
}
