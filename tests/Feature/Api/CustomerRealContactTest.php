<?php

namespace Tests\Feature\Api;

use App\Models\Customer;
use Tests\TestCase;

/**
 * What the importers keep of a customer's email and phone: a placeholder or
 * relay line is never an address.
 */
class CustomerRealContactTest extends TestCase
{
    public function test_real_email_is_what_importers_call(): void
    {
        $this->assertNull(Customer::realEmail(' NO@gmail.com '));
        // A-1's own office, not the customer -- Jose, 2026-09-29.
        $this->assertNull(Customer::realEmail('Office@A-1Chimney.com'));
        $this->assertNull(Customer::realEmail(''));
        $this->assertNull(Customer::realEmail(null));
        $this->assertSame('nora@gmail.com', Customer::realEmail(' nora@gmail.com'));
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
