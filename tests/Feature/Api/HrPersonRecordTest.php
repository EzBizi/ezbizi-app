<?php

namespace Tests\Feature\Api;

use App\Models\AuditEvent;
use App\Models\AutomationEvent;
use App\Models\Company;
use App\Models\EmploymentEvent;
use App\Models\Franchise;
use App\Support\Access\Refusal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * One person's whole record, and editing it from HR (2026-09-24).
 *
 * Jose: "i'd like the users to be on a left side bar and clicking on them
 * brings out all the information, which can be edited." Before this, HR could
 * change two things about a person — their role and whether their account
 * works — and nothing about who they are. Their name, phone, address and date
 * of birth could only be changed by them, under Your account.
 *
 * The one-home rule that refusal rested on still holds: these tests pin that HR
 * writes the SAME columns, rather than keeping a copy.
 */
class HrPersonRecordTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── reading ──────────────────────────────────────────────────

    public function test_the_record_carries_the_personal_details_the_list_does_not(): void
    {
        $t = $this->provisionTenant('Alpha');
        $person = $this->userWithRole($t, 'Technician', ['customers.view']);

        $this->inTenant($t['org'], fn () => $person->forceFill([
            'phone' => '(203) 555-0148',
            'address' => '41 Elm St, Stamford CT',
            'date_of_birth' => '1991-03-04',
        ])->save());

        $record = $this->signedInAs($t['admin'])
            ->getJson("/api/v1/hr/people/{$person->id}")
            ->assertOk()
            ->json('data');

        $this->assertSame('(203) 555-0148', $record['phone']);
        $this->assertSame('41 Elm St, Stamford CT', $record['address']);
        $this->assertSame('1991-03-04', $record['date_of_birth']);

        // The control: the LIST row stays short. A date of birth and an address
        // for fifty people at once is fifty disclosures nobody asked for.
        $row = collect($this->signedInAs($t['admin'])->getJson('/api/v1/hr/people')->json('data'))
            ->firstWhere('id', $person->id);

        $this->assertArrayNotHasKey('date_of_birth', $row);
        $this->assertArrayNotHasKey('address', $row);
    }

    /**
     * The list says how many boxes on a record are empty, and not what is in
     * the full ones (Jose, 2026-09-29, the counter Web Management has).
     */
    public function test_the_list_counts_the_empty_boxes_without_showing_the_full_ones(): void
    {
        $t = $this->provisionTenant('Alpha');
        $person = $this->userWithRole($t, 'Technician', ['customers.view']);

        // Empty: address, gender, and no start date. Middle name is empty too
        // and is NOT a gap — most people have none, and nothing records that.
        $this->inTenant($t['org'], fn () => $person->forceFill([
            'first_name' => 'Bea',
            'middle_name' => null,
            'last_name' => 'Ortiz',
            'phone' => '(203) 555-0148',
            'address' => '  ',
            'gender' => null,
            'date_of_birth' => '1991-03-04',
        ])->save());

        $row = fn () => collect($this->signedInAs($t['admin'])->getJson('/api/v1/hr/people')->json('data'))
            ->firstWhere('id', $person->id);

        $this->assertSame(3, $row()['missing'], 'A missing middle name was counted as a gap.');
        $this->assertArrayNotHasKey('phone', $row());

        $this->inTenant($t['org'], fn () => EmploymentEvent::create([
            'organization_id' => $t['org']->id,
            'user_id' => $person->id,
            'kind' => EmploymentEvent::STARTED,
            'occurred_on' => '2024-02-05',
        ]));

        $this->assertSame(2, $row()['missing'], 'A start date is one box fewer.');
    }

    /** Opening somebody's personal details is itself recorded (§9). */
    public function test_reading_a_record_is_audited(): void
    {
        $t = $this->provisionTenant('Alpha');
        $person = $this->userWithRole($t, 'Technician', ['customers.view']);

        $this->signedInAs($t['admin'])->getJson("/api/v1/hr/people/{$person->id}")->assertOk();

        $event = $this->inTenant($t['org'], fn () => AuditEvent::query()
            ->where('action', 'data.record_read')->sole());

        $this->assertSame($person->id, $event->target_id);
        $this->assertSame($t['admin']->id, $event->actor_id);
    }

    /**
     * Needs the Records tab, and the refusal names the gate.
     *
     * Named rather than a bare 403: the policy behind the gate refuses too, and
     * a bare 403 would keep passing if the gate were removed.
     */
    public function test_the_record_needs_the_records_tab(): void
    {
        $t = $this->provisionTenant('Alpha');
        $person = $this->userWithRole($t, 'Technician', ['customers.view']);
        $hoursOnly = $this->userWithRole($t, 'Dispatcher', ['hr.hours.view']);

        $this->signedInAs($hoursOnly)->getJson("/api/v1/hr/people/{$person->id}")
            ->assertForbidden()
            ->assertJsonPath('code', 'permission_denied')
            ->assertJsonPath('message', Refusal::permission('open this tab')->message());
    }

    /** Past the gate, reach still decides whose record opens. */
    public function test_a_branch_manager_cannot_open_somebody_at_another_branch(): void
    {
        $t = $this->provisionTenant('Alpha');

        $elsewhere = $this->inTenant($t['org'], function () use ($t) {
            $company = Company::create(['organization_id' => $t['org']->id, 'name' => 'Other Co']);

            return Franchise::create([
                'organization_id' => $t['org']->id,
                'company_id' => $company->id,
                'name' => 'Other F1',
            ]);
        });

        $distant = $this->userWithRole($t, 'Distant', ['customers.view'], onlyAt: $elsewhere);
        $manager = $this->userWithRole(
            $t, 'Branch Manager', ['hr.view', 'hr.records.view', 'hr.edit'],
            onlyAt: $t['franchise'],
        );

        $this->signedInAs($manager)->getJson("/api/v1/hr/people/{$distant->id}")
            ->assertForbidden()
            ->assertJsonMissingPath('code');
    }

    // ── editing who they are ─────────────────────────────────────

    public function test_hr_edits_the_same_columns_the_person_edits(): void
    {
        $t = $this->provisionTenant('Alpha');
        $person = $this->userWithRole($t, 'Technician', ['customers.view']);

        $this->signedInAs($t['admin'])->patchJson("/api/v1/hr/people/{$person->id}", [
            'first_name' => 'Jonathan',
            'last_name' => 'Ames',
            'phone' => '(203) 555-0199',
            'address' => '9 Oak Ave, Norwalk CT',
            'gender' => 'Male',
        ])->assertOk();

        $fresh = $person->fresh();

        $this->assertSame('(203) 555-0199', $fresh->phone);
        $this->assertSame('9 Oak Ave, Norwalk CT', $fresh->address);

        // `name` is recomposed from the parts, the rule Your account keeps: every
        // list and every audit entry reads it, so it cannot drift from them.
        $this->assertSame('Jonathan Ames', $fresh->name);
    }

    /**
     * A date of birth changes, and the log records THAT it changed — never what
     * it is.
     *
     * The generic `logChange` would have copied it into an append-only table
     * erasure cannot reach, because `User` is not a sensitive-values model.
     * Your account refuses that for the same column.
     */
    public function test_a_date_of_birth_never_enters_the_audit_log(): void
    {
        $t = $this->provisionTenant('Alpha');
        $person = $this->userWithRole($t, 'Technician', ['customers.view']);

        $this->inTenant($t['org'], fn () => $person->forceFill(['date_of_birth' => '1980-01-01'])->save());

        $this->signedInAs($t['admin'])->patchJson("/api/v1/hr/people/{$person->id}", [
            'date_of_birth' => '1991-03-04',
        ])->assertOk();

        $this->assertSame('1991-03-04', $person->fresh()->date_of_birth->toDateString());

        $event = $this->inTenant($t['org'], fn () => AuditEvent::query()
            ->where('action', 'user.updated')->where('target_id', $person->id)->sole());

        $logged = json_encode($event->changes);

        $this->assertStringNotContainsString('1991', $logged, 'The new date of birth was written to the audit log.');
        $this->assertStringNotContainsString('1980', $logged, 'The old date of birth was written to the audit log.');
        $this->assertArrayHasKey('date_of_birth', $event->changes['after'], 'The FACT of the change must stay on the row.');
    }

    /** Email is theirs: they sign in with it. Sent anyway, it is ignored. */
    public function test_email_cannot_be_changed_from_hr(): void
    {
        $t = $this->provisionTenant('Alpha');
        $person = $this->userWithRole($t, 'Technician', ['customers.view']);
        $before = $person->email;

        $this->signedInAs($t['admin'])->patchJson("/api/v1/hr/people/{$person->id}", [
            'email' => 'taken-over@example.test',
            'phone' => '(203) 555-0100',
        ])->assertOk();

        $this->assertSame($before, $person->fresh()->email);
        // The control: the rest of the same request DID save, so the email was
        // dropped rather than the whole request refused.
        $this->assertSame('(203) 555-0100', $person->fresh()->phone);
    }

    public function test_reading_is_not_editing(): void
    {
        $t = $this->provisionTenant('Alpha');
        $person = $this->userWithRole($t, 'Technician', ['customers.view']);
        $reader = $this->userWithRole($t, 'Reader', ['hr.view', 'hr.records.view']);

        $this->signedInAs($reader)->patchJson("/api/v1/hr/people/{$person->id}", [
            'phone' => '(203) 555-0100',
        ])->assertForbidden();

        $this->assertNull($person->fresh()->phone);
    }

    // ── the start date ───────────────────────────────────────────

    /**
     * Somebody who predates the employment record gets a start date — and paid
     * time off can finally answer for them.
     *
     * This is the state the PTO screen could only apologise for: "there is no
     * record of when you started". Nothing could write one, because both writers
     * of a `started` event stamp today.
     */
    public function test_a_start_date_can_be_recorded_for_somebody_who_has_none(): void
    {
        Carbon::setTestNow('2026-09-24 12:00:00');

        $t = $this->provisionTenant('Alpha');
        $person = $this->userWithRole($t, 'Technician', ['customers.view']);

        $this->assertNull($this->record($t, $person)['started_on'], 'The fixture must start with no start date.');

        $this->signedInAs($t['admin'])->patchJson("/api/v1/hr/people/{$person->id}", [
            'started_on' => '2025-06-02',
        ])->assertOk();

        $this->assertSame('2025-06-02', $this->record($t, $person)['started_on']);
    }

    /**
     * And it does NOT go through activation.
     *
     * The only earlier way to get a `started` row was deactivate-and-reactivate,
     * which stamps today and fires `user.activated` — telling every automation
     * that somebody who has been here for years has just started.
     */
    public function test_recording_a_start_date_fires_no_activation(): void
    {
        $t = $this->provisionTenant('Alpha');
        $person = $this->userWithRole($t, 'Technician', ['customers.view']);

        $this->signedInAs($t['admin'])->patchJson("/api/v1/hr/people/{$person->id}", [
            'started_on' => '2025-06-02',
        ])->assertOk();

        $this->assertSame(0, AutomationEvent::withoutGlobalScopes()
            ->where('event_key', 'user.activated')->count());
    }

    /**
     * A rehire has two starts; the date moved is the first, and only the first.
     *
     * PtoPolicy counts from the earliest, and that is what somebody means by
     * their start date. Moving the latest would change nothing anybody reads.
     */
    public function test_the_earliest_start_is_the_one_that_moves(): void
    {
        $t = $this->provisionTenant('Alpha');
        $person = $this->userWithRole($t, 'Technician', ['customers.view']);

        $this->inTenant($t['org'], function () use ($t, $person) {
            foreach (['2020-03-02', '2026-09-01'] as $on) {
                EmploymentEvent::create([
                    'organization_id' => $t['org']->id,
                    'user_id' => $person->id,
                    'kind' => EmploymentEvent::STARTED,
                    'occurred_on' => $on,
                ]);
            }
        });

        $this->signedInAs($t['admin'])->patchJson("/api/v1/hr/people/{$person->id}", [
            'started_on' => '2019-11-18',
        ])->assertOk();

        $starts = $this->inTenant($t['org'], fn () => EmploymentEvent::query()
            ->where('user_id', $person->id)
            ->where('kind', EmploymentEvent::STARTED)
            ->orderBy('occurred_on')
            ->pluck('occurred_on')
            ->map(fn ($d) => $d->toDateString())
            ->all());

        $this->assertSame(['2019-11-18', '2026-09-01'], $starts,
            'Either a third start was written, or the rehire was moved instead of the first.');
    }

    private function record(array $t, $person): array
    {
        return $this->signedInAs($t['admin'])
            ->getJson("/api/v1/hr/people/{$person->id}")
            ->assertOk()
            ->json('data');
    }
}
