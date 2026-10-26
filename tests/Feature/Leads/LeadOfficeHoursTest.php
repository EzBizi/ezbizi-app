<?php

namespace Tests\Feature\Leads;

use App\Models\Company;
use App\Models\Lead;
use App\Support\Settings\SettingsResolver;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Jose, 2026-10-22: office hours set from Leads › Manage, per company and branch, with the time zone. */
class LeadOfficeHoursTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private const LATE = ['monday' => ['open' => '08:00', 'close' => '20:00'], 'tuesday' => ['open' => '08:00', 'close' => '20:00'],
        'wednesday' => ['open' => '08:00', 'close' => '20:00'], 'thursday' => ['open' => '08:00', 'close' => '20:00'],
        'friday' => ['open' => '08:00', 'close' => '20:00'], 'saturday' => null, 'sunday' => null];

    public function test_a_place_inherits_until_it_sets_its_own_and_the_replies_read_it(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $manager = $this->userWithRole($t, 'Lead manager', ['leads.view', 'leads.manage']);
        $sub = $this->inTenant($t['org'], fn () => Company::create(['organization_id' => $t['org']->id, 'parent_company_id' => $t['company']->id, 'name' => 'AngiSweep']));

        $places = collect($this->signedInAs($manager)->getJson('/api/v1/leads/office-hours')->assertOk()->json('data.places'))->keyBy('id');
        $branch = $places[$t['franchise']->id];
        $this->assertSame(['franchise', false, null, '17:00'], [$branch['scope'], $branch['hours_own'], $branch['hours_from'], $branch['hours']['monday']['close']], 'the default');
        $this->assertSame($t['company']->id, $places[$sub->id]['parent_id'], 'a sub company sits under its parent');

        // The company stays open till 8 PM; its branch and sub company follow.
        $this->signedInAs($manager)->putJson("/api/v1/leads/office-hours/company/{$t['company']->id}", ['hours' => self::LATE])->assertOk()
            ->assertJsonPath('data.hours_own', true);
        $places = collect($this->signedInAs($manager)->getJson('/api/v1/leads/office-hours')->json('data.places'))->keyBy('id');
        $this->assertSame(['20:00', false, $t['company']->name], [$places[$t['franchise']->id]['hours']['monday']['close'],
            $places[$t['franchise']->id]['hours_own'], $places[$t['franchise']->id]['hours_from']]);
        $this->assertSame('20:00', $places[$sub->id]['hours']['monday']['close']);

        // A branch in Texas keeps Central time.
        $this->signedInAs($manager)->putJson("/api/v1/leads/office-hours/franchise/{$t['franchise']->id}", ['timezone' => 'America/Chicago'])
            ->assertOk()->assertJsonPath('data.timezone', 'America/Chicago')->assertJsonPath('data.hours.monday.close', '20:00');

        // 6:30 PM in Texas, a Wednesday: open, so no "closed" reply.
        $this->travelTo(CarbonImmutable::parse('2026-10-21 18:30', 'America/Chicago'));
        $lead = $this->inTenant($t['org'], fn () => Lead::create(['organization_id' => $t['org']->id, 'company_id' => $t['company']->id,
            'franchise_id' => $t['franchise']->id, 'source' => Lead::SOURCE_THUMBTACK, 'remote_id' => 'x', 'status' => 'new', 'received_at' => now()]));
        $this->assertTrue($this->inTenant($t['org'], fn () => $lead->fresh()->office_open));

        // Cleared: inherits again.
        $this->signedInAs($manager)->putJson("/api/v1/leads/office-hours/company/{$t['company']->id}", ['hours' => null])->assertOk()
            ->assertJsonPath('data.hours_own', false)->assertJsonPath('data.hours.monday.close', '17:00');
        $this->signedInAs($manager)->putJson("/api/v1/leads/office-hours/company/{$t['company']->id}", ['hours' => ['monday' => 'x']])->assertStatus(422);
    }

    public function test_only_a_leads_manager_reaches_it(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $viewer = $this->userWithRole($t, 'Dispatcher', ['leads.view']);

        $this->signedInAs($viewer)->getJson('/api/v1/leads/office-hours')->assertForbidden();
        $this->signedInAs($viewer)->putJson("/api/v1/leads/office-hours/company/{$t['company']->id}", ['hours' => self::LATE])->assertForbidden();
        $this->assertSame('17:00', $this->inTenant($t['org'], fn () => app(SettingsResolver::class)->get('leads.office_hours', $t['company'])['monday']['close']));
    }
}
