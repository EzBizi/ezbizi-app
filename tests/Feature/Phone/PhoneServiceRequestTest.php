<?php

namespace Tests\Feature\Phone;

use App\Models\PhoneServiceRequest;
use App\Models\PhoneServiceRequestCompany;
use App\Support\Phone\FakeTwilioGateway;
use App\Support\Phone\TwilioGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Asking EzBizi for phone service, and the gate it opens (§4 gate 2).
 *
 * The gate is the point of all of it: on the provisioned path Twilio usage
 * bills EzBizi, so before this existed any tenant administrator could spend
 * EzBizi's balance by clicking a button in their own Settings.
 */
class PhoneServiceRequestTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private function withPlatformTwilio(): void
    {
        config([
            'services.twilio.platform_account_sid' => 'ACplatformplatformplatformplatfor',
            'services.twilio.platform_api_key_sid' => 'SKplatformplatformplatformplatfor',
            'services.twilio.platform_api_secret' => 'platform-secret',
        ]);
    }

    public function test_a_company_without_approval_cannot_activate_phone_service(): void
    {
        $this->withPlatformTwilio();

        $t = $this->provisionTenant();
        $editor = $this->userWithRole($t, 'Phone editor', ['phone.view', 'phone.edit']);

        $response = $this->signedInAs($editor)->postJson('/api/v1/phone/connections', [
            'scope_type' => 'company',
            'scope_id' => $t['company']->id,
            'kind' => 'subaccount',
        ])->assertForbidden();

        $message = (string) $response->json('message');
        $this->assertStringContainsString($t['company']->name, $message);
        $this->assertStringContainsString('Phone tab', $message);

        // Nothing was created on Twilio for a company nobody approved.
        /** @var FakeTwilioGateway $gateway */
        $gateway = $this->app->make(TwilioGateway::class);
        $this->assertNotContains('createSubaccount', array_column($gateway->calls, 'method'));
    }

    public function test_an_approved_company_can_activate(): void
    {
        $this->withPlatformTwilio();

        $t = $this->provisionTenant();
        $editor = $this->userWithRole($t, 'Phone editor', ['phone.view', 'phone.edit']);

        $this->inTenant($t['org'], function () use ($t) {
            $request = PhoneServiceRequest::create([
                'status' => PhoneServiceRequest::STATUS_APPROVED,
                'expected_volume' => 'under_100',
                'wants_sms' => false,
                'purpose' => 'Booking chimney sweeps and calling customers back.',
            ]);

            PhoneServiceRequestCompany::create([
                'request_id' => $request->id,
                'company_id' => $t['company']->id,
                'approved' => true,
            ]);
        });

        $this->signedInAs($editor)->postJson('/api/v1/phone/connections', [
            'scope_type' => 'company',
            'scope_id' => $t['company']->id,
            'kind' => 'subaccount',
        ])->assertCreated();
    }

    /**
     * There is no unguarded way in.
     *
     * Bringing your own Twilio used to be accepted and deliberately ungated —
     * a customer spending their own money needs no permission from us. Since
     * it is not a product EzBizi offers, all that exemption did was answer
     * "how do I skip the approval", so the kind is refused outright.
     */
    public function test_bringing_your_own_twilio_is_no_longer_a_way_in(): void
    {
        $t = $this->provisionTenant();
        $editor = $this->userWithRole($t, 'Phone editor', ['phone.view', 'phone.edit']);

        $this->signedInAs($editor)->postJson('/api/v1/phone/connections', [
            'scope_type' => 'company',
            'scope_id' => $t['company']->id,
            'kind' => 'byo',
            'account_sid' => 'ACaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            'api_key_sid' => 'SKbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
            'api_secret' => 'their-own-secret',
        ])->assertStatus(422);

        $this->assertNull(
            $this->inTenant($t['org'], fn () => \App\Models\PhoneConnection::query()->first()),
        );
    }

    public function test_asking_records_the_companies_the_volume_and_whether_they_want_texting(): void
    {
        $t = $this->provisionTenant();
        $admin = $this->userWithRole($t, 'Phone editor', ['phone.view', 'phone.edit']);

        $response = $this->signedInAs($admin)->postJson('/api/v1/phone/service-request', [
            'company_ids' => [$t['company']->id],
            'expected_volume' => 'from_100',
            'wants_sms' => true,
            'purpose' => 'Booking sweeps, reminding customers, and taking emergency callouts.',
        ])->assertCreated();

        $this->assertSame('pending', $response->json('data.status'));
        $this->assertTrue($response->json('data.wants_sms'));
        $this->assertSame('from_100', $response->json('data.expected_volume'));
        $this->assertSame($t['company']->id, $response->json('data.companies.0.company_id'));
        $this->assertNull($response->json('data.companies.0.approved'));
    }

    public function test_a_branch_manager_cannot_commit_the_organization_to_a_paid_extra(): void
    {
        $t = $this->provisionTenant();
        $branch = $this->userWithRole(
            $t,
            'Branch phone',
            ['phone.view', 'phone.edit'],
            onlyAt: $t['franchise'],
        );

        $this->signedInAs($branch)->postJson('/api/v1/phone/service-request', [
            'company_ids' => [$t['company']->id],
            'expected_volume' => 'under_100',
            'wants_sms' => false,
            'purpose' => 'We would like phone service for the branch office please.',
        ])->assertForbidden();
    }

    public function test_a_second_request_is_refused_while_one_is_still_waiting(): void
    {
        $t = $this->provisionTenant();
        $admin = $this->userWithRole($t, 'Phone editor', ['phone.view', 'phone.edit']);

        $body = [
            'company_ids' => [$t['company']->id],
            'expected_volume' => 'under_100',
            'wants_sms' => false,
            'purpose' => 'Booking sweeps and calling customers back when they miss us.',
        ];

        $this->signedInAs($admin)->postJson('/api/v1/phone/service-request', $body)->assertCreated();

        $response = $this->signedInAs($admin)
            ->postJson('/api/v1/phone/service-request', $body)
            ->assertStatus(422);

        $this->assertStringContainsString(
            'waiting for an answer',
            (string) $response->json('errors.company_ids.0'),
        );
    }

    /**
     * A week's cooling-off after a refusal, and the sentence says when — a
     * refusal with no way forward and no date is the dead end §6 forbids.
     */
    public function test_after_a_refusal_they_must_wait_a_week_and_are_told_when(): void
    {
        $t = $this->provisionTenant();
        $admin = $this->userWithRole($t, 'Phone editor', ['phone.view', 'phone.edit']);

        $this->inTenant($t['org'], function () use ($t) {
            $request = PhoneServiceRequest::create([
                'status' => PhoneServiceRequest::STATUS_DECLINED,
                'expected_volume' => 'under_100',
                'wants_sms' => false,
                'purpose' => 'Booking sweeps and calling customers back when they miss us.',
                'decided_at' => now()->subDays(2),
                'decision_note' => 'Not yet — come back when the second van is running.',
            ]);

            PhoneServiceRequestCompany::create([
                'request_id' => $request->id,
                'company_id' => $t['company']->id,
                'approved' => false,
            ]);
        });

        $response = $this->signedInAs($admin)->postJson('/api/v1/phone/service-request', [
            'company_ids' => [$t['company']->id],
            'expected_volume' => 'under_100',
            'wants_sms' => false,
            'purpose' => 'Asking again because the second van is running now.',
        ])->assertStatus(422);

        $this->assertStringContainsString(
            'You can ask again on',
            (string) $response->json('errors.company_ids.0'),
        );

        // Five days later — the week is up — the same request is accepted.
        $this->travel(6)->days();

        $this->signedInAs($admin)->postJson('/api/v1/phone/service-request', [
            'company_ids' => [$t['company']->id],
            'expected_volume' => 'under_100',
            'wants_sms' => false,
            'purpose' => 'Asking again because the second van is running now.',
        ])->assertCreated();
    }

    public function test_the_tab_reports_what_was_asked_and_which_companies_may_activate(): void
    {
        $t = $this->provisionTenant();
        $viewer = $this->userWithRole($t, 'Phone viewer', ['phone.view']);

        $this->inTenant($t['org'], function () use ($t) {
            $request = PhoneServiceRequest::create([
                'status' => PhoneServiceRequest::STATUS_APPROVED,
                'expected_volume' => 'over_2000',
                'wants_sms' => true,
                'purpose' => 'Dispatch for three crews and text reminders the day before.',
                'decided_at' => now(),
            ]);

            PhoneServiceRequestCompany::create([
                'request_id' => $request->id,
                'company_id' => $t['company']->id,
                'approved' => true,
            ]);
        });

        $response = $this->signedInAs($viewer)
            ->getJson('/api/v1/phone/service-request')
            ->assertOk();

        $this->assertSame('approved', $response->json('data.latest.status'));
        $this->assertContains($t['company']->id, (array) $response->json('data.approved_company_ids'));
        $this->assertTrue($response->json('data.may_request'));
    }
}
