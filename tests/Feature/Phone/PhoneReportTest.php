<?php

namespace Tests\Feature\Phone;

use App\Models\PhoneCall;
use App\Models\PhoneConnection;
use App\Models\PhoneNumber;
use App\Models\PhoneNumberGrant;
use App\Support\Phone\CallReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Counting calls (Phone > Reports).
 */
class PhoneReportTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    /**
     * @param  list<string>  $statuses
     * @return array{0: array<string, mixed>, 1: PhoneNumber}
     */
    private function lineWithCalls(array $statuses): array
    {
        $t = $this->provisionTenant();

        $number = $this->inTenant($t['org'], function () use ($t, $statuses) {
            $c = new PhoneConnection([
                'organization_id' => $t['org']->id,
                'scope_type' => PhoneConnection::SCOPE_COMPANY,
                'scope_id' => $t['company']->id,
                'kind' => PhoneConnection::KIND_SUBACCOUNT,
                'account_sid' => 'ACrptrptrptrptrptrptrptrptrptrp',
                'api_key_sid' => 'SKrptrptrptrptrptrptrptrptrptrp',
                'status' => PhoneConnection::STATUS_CONNECTED,
            ]);
            $c->writeSecrets('s', 't');
            $c->save();

            $n = PhoneNumber::query()->create([
                'organization_id' => $t['org']->id,
                'connection_id' => $c->id,
                'scope_type' => PhoneNumber::SCOPE_COMPANY,
                'scope_id' => $t['company']->id,
                'twilio_sid' => 'PNrptrptrptrptrptrptrptrptrptrp',
                'e164' => '+12035550300',
                'label' => 'Main line',
                'after_hours' => 'voicemail',
            ]);

            foreach ($statuses as $i => $status) {
                $call = PhoneCall::query()->create([
                    'organization_id' => $t['org']->id,
                    'number_id' => $n->id,
                    'connection_id' => $c->id,
                    'twilio_sid' => 'CArpt'.str_pad((string) $i, 27, '0', STR_PAD_LEFT),
                    'direction' => 'inbound',
                    'from_e164' => '+12035550111',
                    'to_e164' => '+12035550300',
                    'status' => $status,
                    'duration_seconds' => $status === 'completed' ? 60 : 0,
                ]);

                // created_at is NOT fillable, so passing it to create() was
                // silently dropped and every "yesterday" call was stamped
                // now(). That is why this file never noticed the window bug:
                // a call at now() sits inside a wrong window as happily as a
                // right one, until the wall clock crosses the offset.
                PhoneCall::query()->whereKey($call->getKey())->update([
                    'created_at' => Carbon::now()->subDays(1),
                ]);
            }

            return $n;
        });

        return [$t, $number];
    }

    /**
     * Reports read only granted lines — the same gate Records uses — so a
     * report test without a grant counts nothing and proves nothing.
     */
    private function grant(array $t, PhoneNumber $number, $user): void
    {
        $this->inTenant($t['org'], fn () => PhoneNumberGrant::create([
            'organization_id' => $t['org']->id,
            'number_id' => $number->id,
            'role_id' => $user->role_id,
        ]));
    }

    /**
     * A call made this evening still belongs to today.
     *
     * ── The bug this pins ────────────────────────────────────────
     *
     * `created_at` is UTC. The window is built in the ORGANIZATION's timezone,
     * and a Carbon binds as its own wall-clock string with no conversion — so
     * for a UTC-5 org the query compared UTC timestamps against Eastern
     * strings and the window sat five hours late.
     *
     * Every call made in the last five hours of the local day fell past the
     * upper bound and vanished from "today" — then reappeared next morning
     * inside the following day's window. Wrong only in the evening, right
     * again by the time anybody looked. It is not the kind of thing that gets
     * reported; it is the kind that quietly makes a report untrustworthy.
     *
     * The clock is FROZEN here on purpose. This defect is wall-clock
     * dependent: the rest of this file passed all day and went red at 20:07
     * local, which is not a property a test should have.
     */
    public function test_a_call_made_this_evening_is_still_counted_today(): void
    {
        // 01:07 UTC is 20:07 the previous day at UTC-5 — inside the local day,
        // and past the naive upper bound the bug compared against.
        Carbon::setTestNow(Carbon::parse('2026-08-29 01:07:00', 'UTC'));

        try {
            [$t, $number] = $this->lineWithCalls(['completed']);

            $this->inTenant($t['org'], fn () => PhoneCall::query()
                ->where('number_id', $number->id)
                ->update(['created_at' => Carbon::parse('2026-08-29 00:30:00', 'UTC')]));

            $admin = $this->userWithRole($t, 'Phone', ['phone.view', 'phone.edit']);
            $this->grant($t, $number, $admin);

            $response = $this->signedInAs($admin)
                ->getJson('/api/v1/phone/reports/run?period=last_7&group_by=day')
                ->assertOk();

            // If the timezone ever stops being UTC-5 this assertion goes
            // first, so the test cannot quietly stop testing anything.
            $this->assertSame('Etc/GMT+5', $response->json('data.window.timezone'));

            $this->assertSame(
                1,
                $response->json('data.totals.calls'),
                'an evening call fell outside the window and was not counted',
            );
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_missed_rate_counts_every_way_a_call_goes_unanswered(): void
    {
        // Two answered, three missed in three different ways.
        [$t, $number] = $this->lineWithCalls([
            'completed', 'completed', 'no-answer', 'busy', 'failed',
        ]);

        $admin = $this->userWithRole($t, 'Phone', ['phone.view', 'phone.edit']);
        $this->grant($t, $number, $admin);

        $response = $this->signedInAs($admin)
            ->getJson('/api/v1/phone/reports/run?period=last_7&group_by=day')
            ->assertOk();

        $this->assertSame(5, $response->json('data.totals.calls'));
        $this->assertSame(3, $response->json('data.totals.missed'));
        // JSON renders 60.0 as 60, so compare numerically rather than by type.
        $this->assertEquals(60, $response->json('data.totals.missed_rate'));
    }

    /**
     * "0% missed" out of no calls reads as a good week. It is not a rate at all.
     */
    public function test_a_period_with_no_calls_has_no_missed_rate_rather_than_zero(): void
    {
        $t = $this->provisionTenant();
        $admin = $this->userWithRole($t, 'Phone', ['phone.view', 'phone.edit']);

        $response = $this->signedInAs($admin)
            ->getJson('/api/v1/phone/reports/run?period=last_7')
            ->assertOk();

        $this->assertSame(0, $response->json('data.totals.calls'));
        $this->assertNull($response->json('data.totals.missed_rate'));
    }

    /**
     * An aggregate is still an answer about the underlying rows.
     */
    public function test_a_report_only_counts_lines_this_person_may_read(): void
    {
        [$t] = $this->lineWithCalls(['completed', 'completed', 'no-answer']);

        // phone.view, but standing somewhere the line is not.
        //
        // This used to be "phone.view, but no grant on any line", which stopped
        // meaning anything on 2 Sep when grants stopped being per number. The
        // assertion it protects is unchanged and still worth having — a total
        // is an answer about rows, so it must not count rows this person could
        // not open one at a time. The boundary is now §4 reach: the line is
        // company-scoped, and pinning this viewer to a single franchise puts
        // it outside what they can act on.
        $stranger = $this->userWithRole(
            $t,
            'Phone viewer',
            ['phone.view'],
            onlyAt: $t['franchise'],
        );

        $response = $this->signedInAs($stranger)
            ->getJson('/api/v1/phone/reports/run?period=last_7')
            ->assertOk();

        $this->assertSame(0, $response->json('data.totals.calls'));
    }

    public function test_a_saved_report_stores_the_question_not_the_dates(): void
    {
        [$t, $number] = $this->lineWithCalls(['completed']);
        $admin = $this->userWithRole($t, 'Phone', ['phone.view', 'phone.edit']);
        $this->grant($t, $number, $admin);

        $saved = $this->signedInAs($admin)->postJson('/api/v1/phone/reports', [
            'name' => 'Missed calls this month',
            'period' => 'this_month',
            'group_by' => 'day',
            'number_ids' => [$number->id],
        ])->assertCreated();

        // Relative, so opening it in October asks about October.
        $this->assertSame('this_month', $saved->json('data.period'));
        $this->assertNull($saved->json('data.from'));
        $this->assertSame([$number->id], $saved->json('data.number_ids'));

        $this->signedInAs($admin)
            ->getJson('/api/v1/phone/reports/'.$saved->json('data.id').'/run')
            ->assertOk()
            ->assertJsonPath('report.name', 'Missed calls this month');
    }

    public function test_two_reports_cannot_share_a_name(): void
    {
        $t = $this->provisionTenant();
        $admin = $this->userWithRole($t, 'Phone', ['phone.view', 'phone.edit']);

        $body = ['name' => 'Weekly', 'period' => 'last_7', 'group_by' => 'day'];

        $this->signedInAs($admin)->postJson('/api/v1/phone/reports', $body)->assertCreated();
        $this->signedInAs($admin)->postJson('/api/v1/phone/reports', $body)->assertStatus(422);
    }

    /**
     * Spreading this list across a controller and a screen is how two numbers
     * on one page stop agreeing.
     */
    public function test_the_missed_list_is_defined_in_one_place(): void
    {
        $this->assertContains('no-answer', CallReport::MISSED_STATUSES);
        $this->assertContains('busy', CallReport::MISSED_STATUSES);
        $this->assertContains('failed', CallReport::MISSED_STATUSES);
        $this->assertNotContains('completed', CallReport::MISSED_STATUSES);
    }
}
