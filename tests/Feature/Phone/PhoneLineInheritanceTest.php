<?php

namespace Tests\Feature\Phone;

use App\Models\PhoneConnection;
use App\Models\PhoneNumber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Audit 11-27: NULL on a line follows the company; false / a value is the line's own. */
class PhoneLineInheritanceTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_null_follows_the_company_and_false_overrides_it(): void
    {
        $t = $this->provisionTenant();

        $this->inTenant($t['org'], function () use ($t) {
            $connection = PhoneConnection::factory()->create([
                'scope_type' => PhoneConnection::SCOPE_COMPANY, 'scope_id' => $t['company']->id,
                'default_record' => true, 'default_after_hours' => 'forward',
            ]);
            $line = PhoneNumber::factory()->create(['connection_id' => $connection->id]);

            $line->forceFill(['record' => null, 'after_hours' => null])->save();
            $line->refresh();
            $this->assertTrue($line->resolvedFlag('record'));
            $this->assertSame('forward', $line->resolved('after_hours'));

            $line->forceFill(['record' => false, 'after_hours' => 'voicemail'])->save();
            $line->refresh();
            $this->assertFalse($line->resolvedFlag('record'), 'a line switched off is not recorded');
            $this->assertSame('voicemail', $line->resolved('after_hours'));
        });
    }
}
