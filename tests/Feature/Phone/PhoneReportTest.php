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
                PhoneCall::query()->create([
                    'organization_id' => $t['org']->id,
                    'number_id' => $n->id,
                    'connection_id' => $c->id,
                    'twilio_sid' => 'CArpt'.str_pad((string) $i, 27, '0', STR_PAD_LEFT),
                    'direction' => 'inbound',
                    'from_e164' => '+12035550111',
                    'to_e164' => '+12035550300',
                    'status' => $status,
                    'duration_seconds' => $status === 'completed' ? 60 : 0,
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

        // phone.view, but no grant on any line.
        $stranger = $this->userWithRole($t, 'Phone viewer', ['phone.view']);

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
