<?php

namespace Tests\Feature\Training;

use App\Models\TrainingGuide;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

class TrainingSchemaTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_a_guide_keeps_its_screens_in_the_order_they_were_written(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        $guide = $this->inTenant($tenant['org'], fn () => TrainingGuide::create([
            'company_id' => $tenant['company']->id,
            'title' => 'Ladder Safety',
            'status' => TrainingGuide::PUBLISHED,
            'screens' => [
                ['title' => 'Why this exists', 'body' => 'a', 'media_kind' => 'none', 'media_url' => null],
                ['title' => 'The four-to-one rule', 'body' => 'b', 'media_kind' => 'video', 'media_url' => 'https://youtu.be/x'],
            ],
        ]));

        // Element order survives MySQL. Key order deliberately not asserted.
        $screens = $guide->fresh()->screens;

        $this->assertCount(2, $screens);
        $this->assertSame('Why this exists', $screens[0]['title']);
        $this->assertSame('The four-to-one rule', $screens[1]['title']);
        $this->assertSame('video', $screens[1]['media_kind']);
    }
}
