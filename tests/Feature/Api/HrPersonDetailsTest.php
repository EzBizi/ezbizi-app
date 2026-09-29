<?php

namespace Tests\Feature\Api;

use App\Models\AuditEvent;
use App\Models\PayRate;
use App\Support\Access\Refusal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * The four lists on a person's record (Jose, 2026-09-29): pay rates, emergency
 * contacts, HR notes, tools and equipment held.
 */
class HrPersonDetailsTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private function record(array $t, $as, $person): array
    {
        return $this->signedInAs($as)->getJson("/api/v1/hr/people/{$person->id}")->assertOk()->json('data');
    }

    public function test_the_three_ordinary_lists_are_added_and_come_back_on_the_record(): void
    {
        $t = $this->provisionTenant('Alpha');
        $person = $this->userWithRole($t, 'Technician', ['customers.view']);
        $base = "/api/v1/hr/people/{$person->id}/details";

        $this->signedInAs($t['admin'])->postJson("{$base}/emergency-contacts", [
            'name' => 'Ana Ortiz', 'relationship' => 'Sister', 'phone' => '(203) 555-0101',
        ])->assertCreated();

        $this->signedInAs($t['admin'])->postJson("{$base}/notes", [
            'body' => 'Prefers the Stamford jobs.',
        ])->assertCreated();

        $this->signedInAs($t['admin'])->postJson("{$base}/equipment", [
            'name' => 'Chimney camera', 'category' => 'equipment', 'identifier' => 'CAM-17',
            'issued_on' => '2026-05-01',
        ])->assertCreated();

        $record = $this->record($t, $t['admin'], $person);

        $this->assertSame('Ana Ortiz', $record['emergency_contacts'][0]['name']);
        $this->assertSame('Prefers the Stamford jobs.', $record['notes'][0]['body']);
        $this->assertSame($t['admin']->name, $record['notes'][0]['author'], 'A note names who wrote it.');
        $this->assertSame('CAM-17', $record['equipment'][0]['identifier']);
        $this->assertNull($record['equipment'][0]['returned_on'], 'Still held.');
    }

    /**
     * Pay is its own permission, in both directions.
     *
     * Somebody who keeps the people list (`hr.edit`) and may see pay is NOT
     * thereby able to change it, and somebody without `hr.pay.view` is told
     * nothing — null, not an empty list, so "none recorded" and "not yours to
     * see" never look alike.
     */
    public function test_pay_needs_its_own_permission_to_see_and_to_change(): void
    {
        $t = $this->provisionTenant('Alpha');
        $person = $this->userWithRole($t, 'Technician', ['customers.view']);
        $keeper = $this->userWithRole($t, 'HR', ['hr.view', 'hr.records.view', 'hr.edit']);
        $reader = $this->userWithRole($t, 'Payroll reader', ['hr.view', 'hr.records.view', 'hr.edit', 'hr.pay.view']);
        $payroll = $this->userWithRole($t, 'Payroll', ['hr.view', 'hr.records.view', 'hr.edit', 'hr.pay.view', 'hr.pay.edit']);

        $rate = ['basis' => 'hourly', 'amount' => '27.50', 'period' => 'hour', 'effective_on' => '2026-06-01'];
        $url = "/api/v1/hr/people/{$person->id}/details/pay-rates";

        $this->assertNull($this->record($t, $keeper, $person)['pay_rates'], 'Pay was shown without hr.pay.view.');

        $this->signedInAs($reader)->postJson($url, $rate)
            ->assertForbidden()
            ->assertJsonPath('message', Refusal::permission('change pay rates')->message());

        $this->signedInAs($payroll)->postJson($url, $rate)->assertCreated();

        $this->assertSame('27.50', $this->record($t, $reader, $person)['pay_rates'][0]['amount']);
        $this->assertNull($this->record($t, $keeper, $person)['pay_rates'],
            'The control: a rate now exists, and the keeper still may not see it.');
    }

    /** An administrator holds both pay keys by identity, like every key. */
    public function test_an_administrator_sees_pay(): void
    {
        $t = $this->provisionTenant('Alpha');
        $person = $this->userWithRole($t, 'Technician', ['customers.view']);

        $this->assertSame([], $this->record($t, $t['admin'], $person)['pay_rates']);
    }

    /**
     * The amount is encrypted at rest, and the audit entry says a rate was added
     * without saying what it is.
     */
    public function test_a_pay_amount_is_neither_stored_nor_logged_in_the_clear(): void
    {
        $t = $this->provisionTenant('Alpha');
        $person = $this->userWithRole($t, 'Technician', ['customers.view']);

        $this->signedInAs($t['admin'])->postJson("/api/v1/hr/people/{$person->id}/details/pay-rates", [
            'basis' => 'salary', 'amount' => '61234', 'period' => 'year', 'effective_on' => '2026-01-01',
            'note' => 'Raise after review',
        ])->assertCreated();

        $raw = DB::table('pay_rates')->where('user_id', $person->id)->first();
        $this->assertStringNotContainsString('61234', $raw->amount);
        $this->assertStringNotContainsString('Raise', (string) $raw->note);
        // The control: the model reads it back.
        $this->assertSame('61234', $this->inTenant($t['org'], fn () => PayRate::query()->sole()->amount));

        $event = $this->inTenant($t['org'], fn () => AuditEvent::query()
            ->where('action', 'person.pay_rate_added')->sole());
        $this->assertStringNotContainsString('61234', json_encode([$event->changes, $event->metadata]));
    }

    /** Somebody who may read the record but not edit the person adds nothing. */
    public function test_reading_is_not_adding(): void
    {
        $t = $this->provisionTenant('Alpha');
        $person = $this->userWithRole($t, 'Technician', ['customers.view']);
        $reader = $this->userWithRole($t, 'Reader', ['hr.view', 'hr.records.view']);

        $this->signedInAs($reader)->postJson("/api/v1/hr/people/{$person->id}/details/notes", [
            'body' => 'x',
        ])->assertForbidden();

        $this->assertSame([], $this->record($t, $t['admin'], $person)['notes']);
    }

    /**
     * A row id from somebody else's record is not found through this person's
     * URL — otherwise the policy would check one person and the write land on
     * another.
     */
    public function test_a_row_cannot_be_reached_through_the_wrong_person(): void
    {
        $t = $this->provisionTenant('Alpha');
        $bea = $this->userWithRole($t, 'Technician', ['customers.view']);
        $jon = $this->userWithRole($t, 'Dispatcher', ['customers.view']);

        $id = $this->signedInAs($t['admin'])->postJson("/api/v1/hr/people/{$bea->id}/details/notes", [
            'body' => 'About Bea.',
        ])->assertCreated()->json('data.id');

        $this->signedInAs($t['admin'])->deleteJson("/api/v1/hr/people/{$jon->id}/details/notes/{$id}")
            ->assertNotFound();

        $this->assertCount(1, $this->record($t, $t['admin'], $bea)['notes']);
    }

    public function test_equipment_is_returned_rather_than_deleted(): void
    {
        $t = $this->provisionTenant('Alpha');
        $person = $this->userWithRole($t, 'Technician', ['customers.view']);
        $base = "/api/v1/hr/people/{$person->id}/details/equipment";

        $id = $this->signedInAs($t['admin'])->postJson($base, [
            'name' => 'Ladder', 'category' => 'tool',
        ])->assertCreated()->json('data.id');

        $this->signedInAs($t['admin'])->patchJson("{$base}/{$id}", ['returned_on' => '2026-09-20'])->assertOk();

        $row = $this->record($t, $t['admin'], $person)['equipment'][0];
        $this->assertSame('2026-09-20', $row['returned_on']);
    }

    public function test_an_unknown_kind_is_not_found(): void
    {
        $t = $this->provisionTenant('Alpha');
        $person = $this->userWithRole($t, 'Technician', ['customers.view']);

        $this->signedInAs($t['admin'])->postJson("/api/v1/hr/people/{$person->id}/details/salaries", [])
            ->assertNotFound();
    }
}
