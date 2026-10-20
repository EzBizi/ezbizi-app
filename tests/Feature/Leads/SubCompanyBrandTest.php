<?php

namespace Tests\Feature\Leads;

use App\Models\Company;
use App\Models\Lead;
use App\Support\Settings\SettingsResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Jose, 2026-10-20: AngiSweep's lead was sent A-1 Chimney's booking link. */
class SubCompanyBrandTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_a_sub_company_never_sends_its_parents_booking_page_phone_or_email(): void
    {
        $t = $this->provisionTenant('A1-Group');

        $this->inTenant($t['org'], function () use ($t) {
            $settings = app(SettingsResolver::class);
            $parent = $t['company'];
            $settings->set('leads.booking_url', $parent, 'https://www.a-1chimney.com/booking/');
            $settings->set('leads.office_phone', $parent, '(800) 111-1111');
            $settings->set('leads.photos_email', $parent, 'office@a-1chimney.com');
            $sub = Company::create(['organization_id' => $t['org']->id, 'parent_company_id' => $parent->id, 'name' => 'AngiSweep']);
            $lead = fn (Company $c) => Lead::create(['organization_id' => $t['org']->id, 'company_id' => $c->id,
                'source' => Lead::SOURCE_WEBSITE_CHAT, 'remote_id' => uniqid(), 'status' => Lead::STATUS_NEW, 'received_at' => now()]);

            $angi = $lead($sub);
            $this->assertSame(['', '', ''], [$angi->booking_link, $angi->office_phone, $angi->photos_email], 'none of A-1 Chimney\'s');
            $this->assertStringStartsWith('https://www.a-1chimney.com/booking/', $lead($parent)->booking_link, 'the parent keeps its own');

            // AngiSweep's own page and number.
            $settings->set('leads.booking_url', $sub, 'https://angisweep.com/booking/');
            $settings->set('leads.office_phone', $sub, '(800) 222-2222');
            $angi = $angi->fresh();
            $this->assertStringStartsWith('https://angisweep.com/booking/', $angi->booking_link);
            $this->assertSame('(800) 222-2222', $angi->office_phone);
        });
    }
}
