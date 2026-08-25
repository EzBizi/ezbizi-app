<?php

namespace Tests\Feature\TimeClock;

use App\Models\TimeClockShift;
use App\Support\Privacy\DeclaringModels;
use App\Support\Privacy\Erasure;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

class TimeClockShiftTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_a_user_may_have_only_one_open_shift(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $this->actingAs($alpha['admin']);
        app(TenantContext::class)->set($alpha['org']);

        TimeClockShift::query()->create([
            'organization_id' => $alpha['org']->id,
            'user_id' => $alpha['admin']->id,
            'clocked_in_at' => now(),
            'clocked_out_at' => null,
        ]);

        $this->expectException(QueryException::class);
        TimeClockShift::query()->create([
            'organization_id' => $alpha['org']->id,
            'user_id' => $alpha['admin']->id,
            'clocked_in_at' => now()->addMinute(),
            'clocked_out_at' => null,
        ]);
    }

    public function test_closed_shifts_do_not_block_a_new_open_one(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $this->actingAs($alpha['admin']);
        app(TenantContext::class)->set($alpha['org']);

        TimeClockShift::query()->create([
            'organization_id' => $alpha['org']->id,
            'user_id' => $alpha['admin']->id,
            'clocked_in_at' => now()->subHours(2),
            'clocked_out_at' => now()->subHour(),
        ]);

        $open = TimeClockShift::query()->create([
            'organization_id' => $alpha['org']->id,
            'user_id' => $alpha['admin']->id,
            'clocked_in_at' => now(),
            'clocked_out_at' => null,
        ]);

        $this->assertNotNull($open->id);
        $this->assertNull($open->clocked_out_at);
        $this->assertTrue($open->is($open::openFor($alpha['admin'])));
    }

    public function test_the_inventory_lists_the_table(): void
    {
        $this->assertContains(
            TimeClockShift::class,
            app(DeclaringModels::class)->holdingPersonalData(),
        );
    }

    public function test_erasure_keeps_the_person_and_the_instants(): void
    {
        $purpose = TimeClockShift::personalDataPurpose();
        $this->assertNotEmpty($purpose);
        $this->assertStringContainsString('started', strtolower($purpose));
        $this->assertStringContainsString('finished', strtolower($purpose));

        $held = TimeClockShift::personalDataHeld();
        $byField = [];
        foreach ($held as $entry) {
            $byField[$entry->field] = $entry;
        }

        $this->assertArrayHasKey('user_id', $byField);
        $this->assertSame(Erasure::KEEP, $byField['user_id']->erasure->disposition);

        $this->assertArrayHasKey('clocked_in_at, clocked_out_at', $byField);
        $this->assertSame(
            Erasure::KEEP,
            $byField['clocked_in_at, clocked_out_at']->erasure->disposition,
        );
    }

    public function test_personal_data_about_returns_that_users_rows(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $other = $this->userWithRole($alpha, 'Other', ['time_clock.view']);
        app(TenantContext::class)->set($alpha['org']);

        $mine = TimeClockShift::query()->create([
            'organization_id' => $alpha['org']->id,
            'user_id' => $alpha['admin']->id,
            'clocked_in_at' => now()->subHour(),
            'clocked_out_at' => now(),
        ]);
        TimeClockShift::query()->create([
            'organization_id' => $alpha['org']->id,
            'user_id' => $other->id,
            'clocked_in_at' => now()->subMinutes(30),
            'clocked_out_at' => null,
        ]);

        $about = TimeClockShift::personalDataAbout($alpha['admin']);
        $this->assertNotNull($about);
        $this->assertSame([$mine->id], $about->pluck('id')->all());
    }
}
