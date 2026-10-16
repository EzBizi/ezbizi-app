<?php

namespace Tests\Feature\Leads;

use App\Models\Lead;
use App\Support\Leads\LeadSearch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Customers' search finds leads too (2026-10-16: James Quinn was only an LS Call lead). */
class LeadSearchTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_a_caller_is_found_by_any_spelling_of_their_number_within_the_readers_sources(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $this->inTenant($t['org'], function () use ($t) {
            foreach ([['James Quinn', '+18582542275', Lead::SOURCE_RINGCENTRAL], ['Chat Person', '+18582549999', Lead::SOURCE_WEBSITE_CHAT]] as [$name, $phone, $source]) {
                Lead::create(['organization_id' => $t['org']->id, 'company_id' => $t['company']->id, 'source' => $source, 'remote_id' => uniqid(),
                    'lead_type' => 'PHONE_CALL', 'contact_name' => $name, 'phone' => $phone, 'phone_digits' => ltrim($phone, '+'),
                    'status' => Lead::STATUS_NEW, 'received_at' => now()]);
            }
        });
        $rc = $this->userWithRole($t, 'RC only', ['leads.view', 'leads.ringcentral.view']);

        $none = $this->userWithRole($t, 'None', []);
        $search = fn ($user, string $term) => $this->inTenant($t['org'], fn () => LeadSearch::forUser($user, $term));
        $found = $search($t['admin'], '(858) 254-2275');
        $this->assertSame(['James Quinn'], array_column($found, 'name'));
        $this->assertSame('RingCentral Call', $found[0]['source_label']);
        $this->assertEqualsCanonicalizing(['James Quinn', 'Chat Person'], array_column($search($t['admin'], '858254'), 'name'), 'a partial number');
        $this->assertSame(['James Quinn'], array_column($search($rc, '858254'), 'name'), 'only the sources they were granted');
        $this->assertSame([], $search($none, 'Quinn'));
    }
}
