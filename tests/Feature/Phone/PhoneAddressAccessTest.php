<?php

namespace Tests\Feature\Phone;

use App\Models\PhoneConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Who may read and write a company's emergency addresses.
 *
 * ── Why these are worth guarding specifically ────────────────────
 *
 * A Twilio emergency address is what 911 is given when somebody dials it from
 * a line. It holds a customer name and a real street address, so the list is
 * personal data under §9 and creating one writes to the company's Twilio
 * account.
 *
 * Both endpoints validated that the scope belonged to the TENANT and stopped
 * there — no permission, no §4 reach. Tenant-safe and nothing else, which
 * means every signed-in person in the organization could read every branch's
 * addresses and add new ones, including somebody whose role has no Phone
 * permission at all.
 *
 * That is the same shape found the same evening on
 * PATCH /phone/connections/{connection}: a check that looks like
 * authorisation because it refuses SOMETHING, and refuses the wrong thing.
 */
class PhoneAddressAccessTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private function companyConnection(array $t): PhoneConnection
    {
        return $this->inTenant($t['org'], fn () => PhoneConnection::factory()->create([
            'scope_type' => PhoneConnection::SCOPE_COMPANY,
            'scope_id' => $t['company']->id,
            'status' => PhoneConnection::STATUS_CONNECTED,
        ]));
    }

    /** @return array<string, string> */
    private function scope(array $t): array
    {
        return ['scope_type' => 'company', 'scope_id' => $t['company']->id];
    }

    public function test_reading_emergency_addresses_needs_phone_permission(): void
    {
        $t = $this->provisionTenant();
        $this->companyConnection($t);

        $outsider = $this->userWithRole($t, 'Scheduler', ['jobs.view'], 'sch@t.test');

        $this->signedInAs($outsider)
            ->getJson('/api/v1/phone/addresses?'.http_build_query($this->scope($t)))
            ->assertForbidden();
    }

    public function test_creating_an_emergency_address_needs_phone_edit(): void
    {
        $t = $this->provisionTenant();
        $this->companyConnection($t);

        $readOnly = $this->userWithRole($t, 'Tech', ['phone.view', 'phone.settings.view'], 'tech@t.test');

        $this->signedInAs($readOnly)
            ->postJson('/api/v1/phone/addresses', $this->scope($t) + [
                'customer_name' => 'A-1 Chimney',
                'street' => '1 Test Street',
                'city' => 'Hartford',
                'region' => 'CT',
                'postal_code' => '06103',
                'iso_country' => 'US',
            ])
            ->assertForbidden();
    }

    /**
     * And reach, not just the permission.
     *
     * A branch manager with full Phone rights over their own franchise must
     * not read the parent company's addresses by naming the company scope —
     * the same §4 boundary the connection endpoints enforce.
     */
    public function test_a_branch_cannot_read_the_companys_addresses(): void
    {
        $t = $this->provisionTenant();
        $this->companyConnection($t);

        $branch = $this->userWithRole(
            $t,
            'Branch phone',
            ['phone.view', 'phone.settings.view', 'phone.edit'],
            'branch@t.test',
            onlyAt: $t['franchise'],
        );

        $this->signedInAs($branch)
            ->getJson('/api/v1/phone/addresses?'.http_build_query($this->scope($t)))
            ->assertForbidden();
    }

    /**
     * The control, and the reason the three refusals above mean anything.
     *
     * If an administrator were refused too, those tests would be asserting
     * that the endpoint is broken for everybody rather than that it is guarded
     * — the same shape as a test whose expected value is also the fallback.
     */
    public function test_an_administrator_can_read_them(): void
    {
        $t = $this->provisionTenant();
        $this->companyConnection($t);

        $admin = $this->userWithRole(
            $t,
            'Phone admin',
            ['phone.view', 'phone.settings.view', 'phone.edit'],
            'admin@t.test',
        );

        $this->signedInAs($admin)
            ->getJson('/api/v1/phone/addresses?'.http_build_query($this->scope($t)))
            ->assertOk();
    }
}
