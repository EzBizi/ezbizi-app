<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Records › Certifications (Jose, 2026-11-20: Leroy's CSIA card). */
class PersonCertificationsTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_a_certification_is_kept_on_the_record_and_its_expiry_is_flagged(): void
    {
        $t = $this->provisionTenant('Alpha');
        $tech = $this->userWithRole($t, 'Technician', ['customers.view'], onlyAt: $t['franchise']);
        $base = "/api/v1/hr/people/{$tech->id}/details/certifications";
        $hr = $this->signedInAs($t['admin']);

        $hr->postJson($base, ['issuer' => 'CSIA'])->assertUnprocessable()->assertJsonValidationErrors('name');
        $id = $hr->postJson($base, [
            'name' => 'CSIA Certified Chimney Sweep', 'issuer' => 'CSIA', 'number' => '12895',
            'expires_on' => now()->addDays(20)->toDateString(),
        ])->assertCreated()->json('data.id');

        $this->assertSame('12895', $hr->getJson("/api/v1/hr/people/{$tech->id}")->assertOk()->json('data.certifications.0.number'));

        $flagged = $hr->getJson('/api/v1/hr/dashboard/overview')->assertOk()->json('data.certifications');
        $this->assertSame(1, $flagged['expiring']);

        // Renewed far ahead: no longer flagged.
        $hr->patchJson("{$base}/{$id}", ['expires_on' => now()->addYears(3)->toDateString()])->assertOk();
        $this->assertSame(0, $hr->getJson('/api/v1/hr/dashboard/overview')->json('data.certifications.count'));

        // Someone who may not change people may not add one.
        $this->signedInAs($tech)->postJson($base, ['name' => 'Mine'])->assertForbidden();
    }
}
