<?php

namespace Tests\Feature\Customers;

use App\Models\BusinessListItem;
use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Placeholder emails/phones are the business's own lists (Jose, 2026-11-04), the constants only their defaults. */
class CustomerPlaceholderListsTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_defaults_apply_until_the_business_edits_its_list(): void
    {
        $alpha = $this->provisionTenant('Alpha', 'a@alpha.test');
        $beta = $this->provisionTenant('Beta', 'b@beta.test');

        $this->inTenant($alpha['org'], fn () => BusinessListItem::create([
            'organization_id' => $alpha['org']->id, 'list_key' => 'customers.placeholder_emails',
            'item_key' => 'Fake@Alpha.test', 'value' => [], 'sort' => 0, 'active' => true,
        ]));
        $this->inTenant($alpha['org'], fn () => BusinessListItem::create([
            'organization_id' => $alpha['org']->id, 'list_key' => 'customers.placeholder_phones',
            'item_key' => '3055550100', 'value' => [], 'sort' => 0, 'active' => true,
        ]));

        $this->inTenant($alpha['org'], function () {
            $this->assertNull(Customer::realEmail(' fake@alpha.test '));
            $this->assertSame('no@gmail.com', Customer::realEmail('no@gmail.com')); // its edit replaced the defaults
            $this->assertNull(Customer::realPhone('+1 (305) 555-0100'));
            $this->assertSame('8187482055', Customer::realPhone('8187482055'));
        });

        $this->inTenant($beta['org'], function () {
            $this->assertSame('fake@alpha.test', Customer::realEmail('fake@alpha.test'));
            $this->assertNull(Customer::realEmail('no@gmail.com'));
            $this->assertNull(Customer::realPhone('8187482055'));
        });
    }
}
