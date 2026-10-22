<?php

namespace Tests\Feature\Api;

use App\Models\PhoneServiceRequest;
use App\Models\PhoneServiceRequestCompany;
use App\Models\PlatformStaff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PragmaRX\Google2FA\Google2FA;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * The control-plane side of §4 gate 2: answering a request for phone service.
 *
 * The decision is per company, and anything short of a full yes needs a
 * sentence — the customer reads it word for word, and a refusal that does not
 * explain itself is the dead end §6 forbids.
 */
class StaffPhoneRequestsTest extends TestCase
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

    /** @return array{0: array, 1: PhoneServiceRequest} */
    private function pendingRequest(): array
    {
        $t = $this->provisionTenant();

        $row = $this->inTenant($t['org'], function () use ($t) {
            $row = PhoneServiceRequest::create([
                'status' => PhoneServiceRequest::STATUS_PENDING,
                'expected_volume' => 'from_100',
                'wants_sms' => true,
                'purpose' => 'Booking sweeps and calling customers back when we miss them.',
            ]);

            PhoneServiceRequestCompany::create([
                'request_id' => $row->id,
                'company_id' => $t['company']->id,
                'approved' => null,
            ]);

            return $row;
        });

        return [$t, $row];
    }

    public function test_the_queue_lists_a_pending_request_with_what_was_asked(): void
    {
        [$t] = $this->pendingRequest();
        $token = $this->staffToken();

        $response = $this->withToken($token)
            ->getJson('/api/v1/staff/phone-requests')
            ->assertOk();

        $this->assertSame($t['org']->name, $response->json('data.0.organization'));
        $this->assertSame('pending', $response->json('data.0.status'));
        $this->assertTrue($response->json('data.0.wants_sms'));
        $this->assertSame('100 to 500 calls a month', $response->json('data.0.expected_volume_label'));
    }

    public function test_approving_switches_the_company_on(): void
    {
        [$t, $row] = $this->pendingRequest();
        $token = $this->staffToken();

        $this->withToken($token)
            ->postJson('/api/v1/staff/phone-requests/'.$row->id.'/decide', [
                'approved_company_ids' => [$t['company']->id],
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        $this->assertTrue(
            $this->inTenant($t['org'], fn () => PhoneServiceRequest::approvedFor($t['company']->id)),
        );
    }

    public function test_refusing_without_a_sentence_is_itself_refused(): void
    {
        [, $row] = $this->pendingRequest();
        $token = $this->staffToken();

        $response = $this->withToken($token)
            ->postJson('/api/v1/staff/phone-requests/'.$row->id.'/decide', [
                'approved_company_ids' => [],
            ])
            ->assertStatus(422);

        $this->assertStringContainsString(
            'Say why',
            (string) $response->json('errors.note.0'),
        );
    }

    public function test_a_refusal_carries_the_sentence_and_leaves_the_company_off(): void
    {
        [$t, $row] = $this->pendingRequest();
        $token = $this->staffToken();

        $this->withToken($token)
            ->postJson('/api/v1/staff/phone-requests/'.$row->id.'/decide', [
                'approved_company_ids' => [],
                'note' => 'Come back when the second van is running.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'declined')
            ->assertJsonPath('data.decision_note', 'Come back when the second van is running.');

        $this->assertFalse(
            $this->inTenant($t['org'], fn () => PhoneServiceRequest::approvedFor($t['company']->id)),
        );
    }

    public function test_a_request_cannot_be_answered_twice(): void
    {
        [$t, $row] = $this->pendingRequest();
        $token = $this->staffToken();

        $body = ['approved_company_ids' => [$t['company']->id]];

        $this->withToken($token)
            ->postJson('/api/v1/staff/phone-requests/'.$row->id.'/decide', $body)
            ->assertOk();

        $this->withToken($token)
            ->postJson('/api/v1/staff/phone-requests/'.$row->id.'/decide', $body)
            ->assertStatus(422);
    }

    /**
     * The queue is control-plane only. A tenant administrator reaching it would
     * see every other customer who has asked for phone service.
     */
    public function test_a_tenant_administrator_cannot_reach_the_queue(): void
    {
        $t = $this->provisionTenant();
        $admin = $this->userWithRole($t, 'Phone editor', ['phone.view', 'phone.edit']);

        $this->signedInAs($admin)
            ->getJson('/api/v1/staff/phone-requests')
            ->assertForbidden();
    }

    /** The search bar: `q` narrows by purpose or company, across tenants; a blank `q` is no filter. */
    public function test_q_narrows_the_queue_and_a_blank_q_changes_nothing(): void
    {
        $this->pendingRequest();
        $beta = $this->provisionTenant('Beta', 'beta@example.test');
        $this->inTenant($beta['org'], fn () => PhoneServiceRequest::create([
            'status' => PhoneServiceRequest::STATUS_PENDING,
            'expected_volume' => 'from_100',
            'wants_sms' => true,
            'purpose' => 'Text reminders the day before.',
        ]));
        $token = $this->staffToken();

        $this->withToken($token)->getJson('/api/v1/staff/phone-requests?q=reminders')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.organization', 'Beta');
        $this->withToken($token)->getJson('/api/v1/staff/phone-requests?q=alpha%20co')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.organization', 'Alpha');
        $this->withToken($token)->getJson('/api/v1/staff/phone-requests?q=')->assertOk()->assertJsonCount(2, 'data');
        $this->withToken($token)->getJson('/api/v1/staff/phone-requests')->assertOk()->assertJsonCount(2, 'data');
    }
}
