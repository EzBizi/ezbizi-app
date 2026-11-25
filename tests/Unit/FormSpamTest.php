<?php

namespace Tests\Unit;

use App\Support\Web\WebsiteEnquiries;
use PHPUnit\Framework\TestCase;

/** The two tells of the form spam seen 2026-09-30, and a real enquiry. */
class FormSpamTest extends TestCase
{
    public function test_russian_form_spam_is_flagged_and_a_real_enquiry_is_not(): void
    {
        $this->assertTrue(WebsiteEnquiries::looksLikeSpam(['email' => 'muflzmnawsr@victoria-photographer.ru'], ['Message' => 'hello']));
        $this->assertTrue(WebsiteEnquiries::looksLikeSpam(['email' => 'x@bazavodolazov.store'], ['Message' => 'Заказ оформляется по ссылке']));
        $this->assertFalse(WebsiteEnquiries::looksLikeSpam(
            ['name' => 'Tammy Selby', 'email' => 'tsample@gmail.com'],
            ['Message' => 'Chimney sweep and inspection please, 12 Hillcrest Ct'],
        ));
    }
}
