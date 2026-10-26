<?php

namespace Tests\Feature\Leads;

use App\Support\Settings\SettingsResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Settings › Pending lists a place whose office hours nobody set, own or inherited (desk, 2026-10-26). */
class OfficeHoursPendingTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private function placesMissingHours(): array
    {
        return collect($this->getJson('/api/v1/settings/pending')->assertOk()->json('data'))
            ->filter(fn ($g) => collect($g['items'] ?? [])->contains('key', 'leads.office_hours'))
            ->pluck('place.name')->values()->all();
    }

    public function test_a_place_on_the_default_hours_is_pending_until_it_or_a_parent_sets_them(): void
    {
        $t = $this->provisionTenant('Alpha');
        $this->signedInAs($t['admin']);

        $this->assertContains('Alpha Co', $this->placesMissingHours(), 'the 8-5 default is not a choice');

        $week = ['monday' => ['open' => '08:00', 'close' => '20:00'], 'tuesday' => null, 'wednesday' => null,
            'thursday' => null, 'friday' => null, 'saturday' => null, 'sunday' => null];
        $this->inTenant($t['org'], fn () => app(SettingsResolver::class)->set('leads.office_hours', $t['company'], $week));

        $this->assertNotContains('Alpha Co', $this->placesMissingHours());
        $this->assertNotContains($t['franchise']->name, $this->placesMissingHours(), 'its branch inherits them');
        $this->assertContains('Alpha', $this->placesMissingHours(), 'control: the organization still has none');
    }
}
