<?php

namespace Tests\Feature\Api;

use App\Models\AuditEvent;
use App\Models\AutomationEvent;
use App\Models\Company;
use App\Models\EmploymentEvent;
use App\Models\Franchise;
use App\Support\Access\Refusal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
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
            // Filled, so this test counts only the boxes it is about; the
            // lists are the next test's.
            'work_schedule' => ['timezone' => 'America/New_York', 'days' => []],
        ])->save());
        $this->fillTheLists($t, $person);

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

    /**
     * No emergency contact, no pay rate and no work schedule count too (Jose,
     * 2026-09-30: "it should highlight all the missing stuff"). Pay only for
     * a reader who may see pay: for anybody else the number would say
     * whether a rate exists.
     */
    public function test_the_empty_lists_and_schedule_count_and_pay_only_for_its_readers(): void
    {
        $t = $this->provisionTenant('Alpha');
        $person = $this->userWithRole($t, 'Technician', ['customers.view']);
        $keeper = $this->userWithRole($t, 'HR', ['hr.view', 'hr.records.view', 'hr.edit']);

        $row = fn ($as) => collect($this->signedInAs($as)->getJson('/api/v1/hr/people')->json('data'))
            ->firstWhere('id', $person->id)['missing'];

        $before = $row($t['admin']);
        $this->assertSame($before - 1, $row($keeper), 'A reader without hr.pay.view was told no rate exists.');

        $this->fillTheLists($t, $person);
        $this->assertSame($before - 2, $row($t['admin']), 'A contact and a pay rate are two gaps fewer.');
        $this->assertSame($before - 2, $row($keeper));

        $this->inTenant($t['org'], fn () => $person->forceFill([
            'work_schedule' => ['timezone' => 'America/New_York', 'days' => []],
        ])->save());
        $this->assertSame($before - 3, $row($t['admin']), 'A schedule is one gap fewer.');
    }

    private function fillTheLists(array $t, $person): void
    {
        $base = "/api/v1/hr/people/{$person->id}/details";
        $this->signedInAs($t['admin'])->postJson("{$base}/emergency-contacts", [
            'name' => 'Ana Ortiz', 'relationship' => 'Sister', 'phone' => '(203) 555-0101',
        ])->assertCreated();
        $this->signedInAs($t['admin'])->postJson("{$base}/pay-rates", [
            'basis' => 'salary', 'amount' => '61234', 'period' => 'year', 'effective_on' => '2026-01-01',
        ])->assertCreated();
    }

    /** Jose, 2026-10-01: "on record i should be able to filter by role too". */
    public function test_the_list_narrows_to_one_role(): void
    {
        $t = $this->provisionTenant('Alpha');
        $tech = $this->userWithRole($t, 'Technician', ['customers.view']);
        $dispatcher = $this->userWithRole($t, 'Dispatcher', ['customers.view']);

        $ids = collect($this->signedInAs($t['admin'])
            ->getJson("/api/v1/hr/people?role_id={$dispatcher->role_id}")->assertOk()->json('data'))
            ->pluck('id')->all();

        $this->assertSame([$dispatcher->id], $ids);
        $this->assertNotContains($tech->id, $ids);
    }

    /** Jose, 2026-10-01: "adding division by company too aside from role". */
    public function test_the_list_narrows_to_a_company_with_its_franchises_or_to_one_franchise(): void
    {
        $t = $this->provisionTenant('Alpha');
        $atFranchise = $this->userWithRole($t, 'Technician', ['customers.view'], onlyAt: $t['franchise']);
        $other = $this->inTenant($t['org'], fn () => Company::create([
            'organization_id' => $t['org']->id, 'name' => 'Elsewhere Co', 'is_active' => true,
        ]));
        $atOther = $this->userWithRole($t, 'Dispatcher', ['customers.view']);
        $this->inTenant($t['org'], function () use ($atOther, $other) {
            $atOther->assignments()->delete();
            \App\Models\Assignment::create(['organization_id' => $atOther->organization_id,
                'user_id' => $atOther->id, 'assigned_company_id' => $other->id]);
        });

        $ids = fn (string $query) => collect($this->signedInAs($t['admin'])
            ->getJson('/api/v1/hr/people?'.$query)->assertOk()->json('data'))->pluck('id')->all();

        // The franchise's company takes in the franchise's people.
        $this->assertContains($atFranchise->id, $ids('company_id='.$t['franchise']->company_id));
        $this->assertNotContains($atOther->id, $ids('company_id='.$t['franchise']->company_id));
        $this->assertSame([$atOther->id], $ids('company_id='.$other->id));
        $this->assertContains($atFranchise->id, $ids('franchise_id='.$t['franchise']->id));
        $this->assertNotContains($atOther->id, $ids('franchise_id='.$t['franchise']->id));
        $this->assertSame([], $ids('company_id='.$other->id.'&role_id='.$atFranchise->role_id), 'The filters did not combine.');
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

    // ── where they work ──────────────────────────────────────────

    /**
     * Mia, 2026-10-02: "assigning andy to A1-Group instead so she has full
     * access to all companies and franchises". Works at is editable, and a
     * move replaces where they were rather than adding to it.
     */
    public function test_works_at_moves_somebody_to_the_whole_organization(): void
    {
        $t = $this->provisionTenant('Alpha');
        $andy = $this->userWithRole($t, 'Dispatcher', ['customers.view'], onlyAt: $t['franchise']);

        $this->signedInAs($t['admin'])->patchJson("/api/v1/hr/people/{$andy->id}", [
            'scope_type' => 'organization',
            'scope_id' => $t['org']->id,
        ])->assertOk()
            ->assertJsonPath('data.assignments.0.type', 'organization');

        $rows = $this->inTenant($t['org'], fn () => $andy->assignments()->get());
        $this->assertCount(1, $rows);
        $this->assertSame($t['org']->id, $rows->first()->assigned_organization_id);

        $this->assertTrue($this->inTenant($t['org'], fn () => AuditEvent::query()
            ->where('action', 'user.assignment_moved')->where('target_id', $andy->id)->exists()));
    }

    /** Nobody places somebody further up than they reach themselves. */
    public function test_works_at_is_bounded_by_the_editors_own_reach(): void
    {
        $t = $this->provisionTenant('Alpha');
        $manager = $this->userWithRole(
            $t, 'Branch Manager', ['hr.view', 'hr.records.view', 'hr.edit'],
            onlyAt: $t['franchise'],
        );
        $tech = $this->userWithRole($t, 'Technician', ['customers.view'], onlyAt: $t['franchise']);

        $this->signedInAs($manager)->patchJson("/api/v1/hr/people/{$tech->id}", [
            'scope_type' => 'organization',
            'scope_id' => $t['org']->id,
        ])->assertStatus(422);

        $this->assertSame($t['franchise']->id, $this->inTenant($t['org'], fn () => $tech->assignments()->sole()->assigned_franchise_id));
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

    /** Gender is a pick from hr.gender_options (Jose, 2026-11-06); the record carries the choices. */
    public function test_gender_is_one_of_the_organization_choices(): void
    {
        $t = $this->provisionTenant('Alpha');
        $person = $this->userWithRole($t, 'Technician', ['customers.view']);
        $url = "/api/v1/hr/people/{$person->id}";

        $this->signedInAs($t['admin'])->patchJson($url, ['gender' => 'Prefer_Not_To_Answer'])->assertOk();
        $this->assertSame('prefer_not_to_answer', $person->fresh()->gender);
        $this->signedInAs($t['admin'])->patchJson($url, ['gender' => 'Female'])->assertOk();
        $this->assertSame('female', $person->fresh()->gender);
        $this->signedInAs($t['admin'])->patchJson($url, ['gender' => 'Prefers not to answer'])->assertOk();
        $this->assertSame('prefer_not_to_answer', $person->fresh()->gender);
        $this->signedInAs($t['admin'])->patchJson($url, ['gender' => 'Genderfluid'])->assertUnprocessable();

        $this->assertSame(['male', 'female', 'prefer_not_to_answer'],
            array_column($this->signedInAs($t['admin'])->getJson($url)->assertOk()->json('data.gender_options'), 'value'));
    }

    /** HR sets and removes someone's picture; someone without hr.edit cannot. */
    public function test_hr_uploads_a_persons_picture(): void
    {
        Storage::fake('local');
        $t = $this->provisionTenant('Alpha');
        $person = $this->userWithRole($t, 'Technician', ['customers.view']);
        $photo = fn () => UploadedFile::fake()->image('me.png', 200, 200);

        $this->signedInAs($person)->postJson("/api/v1/hr/people/{$t['admin']->id}/avatar", ['photo' => $photo()])->assertForbidden();
        $this->signedInAs($t['admin'])->postJson("/api/v1/hr/people/{$person->id}/avatar", ['photo' => $photo()])
            ->assertOk()->assertExactJson(['avatar' => true]);
        $this->assertNotNull($person->fresh()->avatar_path);
        $this->assertTrue($this->signedInAs($t['admin'])->getJson("/api/v1/hr/people/{$person->id}")->json('data.has_avatar'));

        $this->signedInAs($t['admin'])->deleteJson("/api/v1/hr/people/{$person->id}/avatar")->assertOk();
        $this->assertNull($person->fresh()->avatar_path);
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

    /** Several places at once (Jose, 2026-11-06); never zero, audited, reach-checked. */
    public function test_a_person_works_at_several_places(): void
    {
        $t = $this->provisionTenant('Alpha');
        $person = $this->userWithRole($t, 'Technician', ['customers.view'], onlyAt: $t['franchise']);
        $second = $this->extraFranchise($t, 'Second');
        $url = "/api/v1/hr/people/{$person->id}/places";

        $places = $this->signedInAs($t['admin'])->postJson($url, ['scope_type' => 'franchise', 'scope_id' => $second->id])
            ->assertOk()->json('data.assignments');
        $this->assertCount(2, $places);
        $this->assertSame($t['company']->name, collect($places)->firstWhere('scope_id', $second->id)['company']);
        // What they reach is the union of both places.
        $this->assertEqualsCanonicalizing([$t['franchise']->id, $second->id], $this->inTenant($t['org'], fn () => $person->fresh()->reachableFranchises()->pluck('id')->all()));

        // Adding the same place twice writes nothing.
        $this->signedInAs($t['admin'])->postJson($url, ['scope_type' => 'franchise', 'scope_id' => $second->id])
            ->assertOk()->assertJsonCount(2, 'data.assignments');

        $first = collect($places)->firstWhere('scope_id', $t['franchise']->id)['id'];
        $this->signedInAs($t['admin'])->deleteJson("$url/$first")->assertOk()->assertJsonCount(1, 'data.assignments');
        $last = $this->inTenant($t['org'], fn () => $person->fresh()->assignments()->value('id'));
        $this->signedInAs($t['admin'])->deleteJson("$url/$last")->assertUnprocessable();

        // Someone without hr edit cannot add places.
        $this->signedInAs($person)->postJson("/api/v1/hr/people/{$t['admin']->id}/places",
            ['scope_type' => 'franchise', 'scope_id' => $second->id])->assertForbidden();

        $this->assertDatabaseHas('audit_events', ['action' => 'user.assignment_added']);
        $this->assertDatabaseHas('audit_events', ['action' => 'user.assignment_removed']);
    }

    /** Everyone view: active A-Z, then inactive A-Z (Jose, 2026-11-09). */
    public function test_inactive_people_sort_after_active_ones(): void
    {
        $t = $this->provisionTenant('Alpha');
        $aaron = $this->userWithRole($t, 'Aaron', ['customers.view']);
        $zed = $this->userWithRole($t, 'Zed', ['customers.view']);
        $this->inTenant($t['org'], function () use ($aaron, $zed) {
            $aaron->forceFill(['name' => 'Aaron Gone', 'is_active' => false])->save();
            $zed->forceFill(['name' => 'Zed Here'])->save();
        });

        $names = array_column($this->signedInAs($t['admin'])->getJson('/api/v1/hr/people')->assertOk()->json('data'), 'name');
        $this->assertLessThan(array_search('Aaron Gone', $names), array_search('Zed Here', $names));
        $this->assertSame('Aaron Gone', end($names));
    }

    /** Inactive finds an inactive person past the first page (Jose, 2026-11-10). */
    public function test_status_filters_on_the_server_across_pages(): void
    {
        $t = $this->provisionTenant('Alpha');
        $gone = $this->userWithRole($t, 'Gone', ['customers.view']);
        $this->inTenant($t['org'], fn () => $gone->forceFill(['is_active' => false])->save());
        $this->userWithRole($t, 'Here', ['customers.view']);

        // One per page: the inactive one sorts last, so it is not on page 1.
        $this->assertNotContains($gone->id, array_column($this->signedInAs($t['admin'])
            ->getJson('/api/v1/hr/people?per_page=1')->json('data'), 'id'));

        $this->assertSame([$gone->id], array_column($this->signedInAs($t['admin'])
            ->getJson('/api/v1/hr/people?per_page=1&status=inactive')->assertOk()->json('data'), 'id'));
        $this->assertNotContains($gone->id, array_column($this->signedInAs($t['admin'])
            ->getJson('/api/v1/hr/people?status=active')->json('data'), 'id'));
    }

    /** Removed (deleted) people are off Records unless asked for (Jose, 2026-11-12). */
    public function test_removed_people_are_off_records(): void
    {
        $t = $this->provisionTenant('Alpha');
        $gone = $this->userWithRole($t, 'Gone', ['customers.view']);
        $this->signedInAs($t['admin'])->deleteJson("/api/v1/hr/people/{$gone->id}", ['confirm' => $gone->name])->assertOk();

        foreach (['', '?status=inactive', '?status=active'] as $q) {
            $this->assertNotContains($gone->id, array_column($this->signedInAs($t['admin'])
                ->getJson("/api/v1/hr/people{$q}")->assertOk()->json('data'), 'id'));
        }
        $this->assertSame([$gone->id], array_column($this->signedInAs($t['admin'])
            ->getJson('/api/v1/hr/people?removed=1')->json('data'), 'id'));
    }

    /** An unknown key is refused, not dropped (desk, 2026-11-23: PATCH {"name"} said 200). */
    public function test_an_unknown_field_is_refused_rather_than_ignored(): void
    {
        $t = $this->provisionTenant('Alpha');
        $person = $this->userWithRole($t, 'Technician', ['customers.view']);
        $url = "/api/v1/hr/people/{$person->id}";
        $before = $person->fresh()->name;

        $this->signedInAs($t['admin'])->patchJson($url, ['name' => 'Nicholas Frias'])
            ->assertUnprocessable()->assertJsonValidationErrors('fields');
        $this->assertSame($before, $person->fresh()->name);

        $this->signedInAs($t['admin'])->patchJson($url, ['first_name' => 'Nicholas', 'last_name' => 'Frias'])->assertOk();
        $this->assertSame('Nicholas Frias', $person->fresh()->name);
    }
}
