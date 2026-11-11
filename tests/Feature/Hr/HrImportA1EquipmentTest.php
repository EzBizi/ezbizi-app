<?php

namespace Tests\Feature\Hr;

use App\Models\HeldEquipment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** A1 Tools' tools and equipment, onto each person's HR record (Jose, 2026-11-03). */
class HrImportA1EquipmentTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_rows_match_by_email_then_phone_skip_strangers_and_import_once(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $byEmail = $this->userWithRole($alpha, 'Technician', ['jobs.view'], 'tech@example.test');
        $byPhone = $this->userWithRole($alpha, 'Helper', ['jobs.view'], 'helper@example.test');
        $this->inTenant($alpha['org'], fn () => $byPhone->forceFill(['phone' => '(305) 555-0199'])->save());

        $file = tempnam(sys_get_temp_dir(), 'a1eq');
        file_put_contents($file, json_encode([
            ['ref' => 'a1-tool-assignment:1', 'email' => 'TECH@example.test', 'phone' => null, 'name' => 'Tech',
                'item' => 'Chimney brush', 'category' => 'tool', 'quantity' => 2, 'issued_on' => '2026-05-01',
                'returned_on' => null, 'note' => null],
            ['ref' => 'a1-hr-equipment:7', 'email' => 'old@a1.test', 'phone' => '+1 305 555 0199', 'name' => 'Helper',
                'item' => 'Ladder', 'category' => 'equipment', 'quantity' => 1, 'issued_on' => null,
                'returned_on' => null, 'note' => null],
            ['ref' => 'a1-tool-assignment:9', 'email' => 'gone@a1.test', 'phone' => null, 'name' => 'Left Already',
                'item' => 'Camera', 'category' => 'tool', 'quantity' => 1, 'issued_on' => null,
                'returned_on' => null, 'note' => null],
        ]));
        $run = fn (array $o = []) => $this->artisan('hr:import-a1-equipment', ['file' => $file, '--organization' => 'Alpha'] + $o);

        $run(['--dry-run' => true])->expectsOutputToContain('2 to add')->expectsOutputToContain('no account: Left Already')->assertSuccessful();
        $this->assertSame(0, $this->inTenant($alpha['org'], fn () => HeldEquipment::count()));

        $run()->expectsOutputToContain('2 added.')->assertSuccessful();
        $run()->expectsOutputToContain('0 to add, 2 already imported')->assertSuccessful();

        $held = $this->inTenant($alpha['org'], fn () => HeldEquipment::orderBy('name')->get());
        $this->assertSame(['Chimney brush', 'Ladder'], $held->pluck('name')->all());
        $this->assertSame([$byEmail->id, $byPhone->id], $held->pluck('user_id')->all());
        $this->assertSame(2, (int) $held[0]->quantity);
        $this->assertSame('equipment', $held[1]->category);
    }
}
