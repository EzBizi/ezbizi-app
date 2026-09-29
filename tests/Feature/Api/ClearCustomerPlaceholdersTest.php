<?php

namespace Tests\Feature\Api;

use App\Models\AuditEvent;
use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * The placeholder cleanup blanks what was never an address and nothing else.
 *
 * The case worth the most is the one it must LEAVE: a real address several
 * customers share. A cleanup keyed on "used more than once" -- the obvious rule,
 * and exactly how the problem was found -- would blank a property manager's
 * email off six houses.
 */
class ClearCustomerPlaceholdersTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private array $alpha;

    protected function setUp(): void
    {
        parent::setUp();
        $this->alpha = $this->provisionTenant('Alpha', 'a@alpha.test');
    }

    private function customer(string $name, ?string $email, ?string $phone = null): Customer
    {
        return $this->inTenant($this->alpha['org'], fn () => Customer::create([
            'owning_franchise_id' => $this->alpha['franchise']->id,
            'number' => 'C-'.bin2hex(random_bytes(3)),
            'name' => $name,
            'email' => $email,
            'phone' => $phone,
        ]));
    }

    private function phoneOf(Customer $customer): ?string
    {
        return Customer::withoutGlobalScopes()->find($customer->id)->phone;
    }

    private function emailOf(Customer $customer): ?string
    {
        return Customer::withoutGlobalScopes()->find($customer->id)->email;
    }

    public function test_it_blanks_placeholders_and_keeps_a_real_address_many_share(): void
    {
        // Case and padding as staff actually typed them.
        $placeholder = $this->customer('One', '  No@Gmail.com ');
        $declined = $this->customer('Two', 'cxdeclined@gmail.com');

        // Shared by several customers, and real. Must survive.
        $managerA = $this->customer('House A', 'manager@realestate.test');
        $managerB = $this->customer('House B', 'manager@realestate.test');

        // Starts like a placeholder, is a person.
        $nora = $this->customer('Nora', 'nora@gmail.com');

        $this->artisan('ezbizi:customers-clear-placeholders')->assertSuccessful();

        $this->assertNull($this->emailOf($placeholder));
        $this->assertNull($this->emailOf($declined));
        $this->assertSame('manager@realestate.test', $this->emailOf($managerA));
        $this->assertSame('manager@realestate.test', $this->emailOf($managerB));
        $this->assertSame('nora@gmail.com', $this->emailOf($nora));
    }

    public function test_a_dry_run_changes_nothing_and_writes_no_audit_row(): void
    {
        $placeholder = $this->customer('One', 'no@gmail.com');

        $this->artisan('ezbizi:customers-clear-placeholders', ['--dry-run' => true])
            ->expectsOutputToContain('[dry run] 1 placeholder emails')
            ->assertSuccessful();

        $this->assertSame('no@gmail.com', $this->emailOf($placeholder));
        $this->assertSame(0, AuditEvent::withoutGlobalScopes()
            ->where('action', 'customer.placeholders_cleared')->count());
    }

    public function test_one_audit_row_per_run_saying_how_many_of_each(): void
    {
        $this->customer('One', 'no@gmail.com');
        $this->customer('Two', 'no@gmail.com');
        $this->customer('Three', 'nm@m.com');

        $this->artisan('ezbizi:customers-clear-placeholders')->assertSuccessful();

        $rows = AuditEvent::withoutGlobalScopes()
            ->where('action', 'customer.placeholders_cleared')->get();

        $this->assertCount(1, $rows);
        $this->assertSame(3, $rows[0]->metadata['emails']);
        $this->assertSame(['nm@m.com' => 1, 'no@gmail.com' => 2], collect($rows[0]->metadata['by_email'])->sortKeys()->all());
    }

    public function test_real_email_is_what_importers_call(): void
    {
        $this->assertNull(Customer::realEmail(' NO@gmail.com '));
        $this->assertNull(Customer::realEmail(''));
        $this->assertNull(Customer::realEmail(null));
        $this->assertSame('nora@gmail.com', Customer::realEmail(' nora@gmail.com'));
    }

    public function test_a_relay_line_is_cleared_in_any_written_form_and_a_real_one_is_kept(): void
    {
        // The same relay line three ways, as the three sources store it.
        $e164 = $this->customer('One', null, '+18187482055');
        $dashed = $this->customer('Two', null, '(818) 748-2055');
        $dotted = $this->customer('Three', null, '818.748.2055');

        // Shared by a dozen customers, and on purpose NOT on the list: 8 names
        // and 7 addresses reads as one property manager, not a relay.
        $manager = $this->customer('Four', null, '+13477245006');

        $this->artisan('ezbizi:customers-clear-placeholders')->assertSuccessful();

        $this->assertNull($this->phoneOf($e164));
        $this->assertNull($this->phoneOf($dashed));
        $this->assertNull($this->phoneOf($dotted));
        $this->assertSame('+13477245006', $this->phoneOf($manager));
    }

    public function test_real_phone_is_what_importers_call(): void
    {
        $this->assertNull(Customer::realPhone('+18187482055'));
        $this->assertNull(Customer::realPhone('818-748-2055'));
        $this->assertNull(Customer::realPhone(''));
        $this->assertNull(Customer::realPhone(null));

        // Handed back as given: this decides WHETHER to keep a number, not how
        // to write it.
        $this->assertSame('(347) 724-5006', Customer::realPhone(' (347) 724-5006 '));
    }
}
