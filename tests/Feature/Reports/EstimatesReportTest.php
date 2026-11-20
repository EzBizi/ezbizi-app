<?php

namespace Tests\Feature\Reports;

use App\Models\Customer;
use App\Models\PhoneConnection;
use App\Models\PhoneMessage;
use App\Models\PhoneNumber;
use App\Models\WorkizJob;
use App\Models\WorkizRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Reports › Estimates (Shoval, 2026-11-18): both stores, five states, texted and answered derived from the texts. */
class EstimatesReportTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_open_approved_declined_and_texted_with_no_answer_from_both_stores(): void
    {
        $t = $this->provisionTenant('Alpha');
        $f = $t['franchise'];
        $this->inTenant($t['org'], function () use ($f) {
            $estimate = function (string $id, string $status, float $total, string $created, ?string $phone = null, array $more = []) use ($f) {
                if ($phone !== null) {
                    WorkizJob::query()->create(['franchise_id' => $f->id, 'remote_id' => 'J'.$id, 'serial_id' => '70'.$id, 'status' => 'Submitted', 'booked_at' => $created,
                        'payload' => ['Phone' => $phone, 'FirstName' => 'Ann', 'LastName' => 'Lee', 'v2' => ['id' => 'JOB-'.$id]], 'synced_at' => now()]);
                }
                WorkizRecord::query()->create(['franchise_id' => $f->id, 'type' => 'estimate', 'remote_id' => 'ES-'.$id, 'synced_at' => now(),
                    'payload' => $more + ['serialId' => (int) $id, 'status' => $status, 'total' => $total, 'created' => $created,
                        'sentDate' => $created, 'jobId' => 'JOB-'.$id, 'declineReason' => '']]);
            };
            // Open and texted, never answered.
            $estimate('1', 'Pending', 1000, '2026-11-10 09:00:00', '(212) 555-0101');
            $estimate('2', 'Won', 500, '2026-11-10 10:00:00');
            // Declined after the client answered the text.
            $estimate('3', 'Declined', 300, '2026-11-09 10:00:00', '2125550103', ['declineReason' => 'Too expensive for my budget']);
            // Archived: texted, never answered.
            $estimate('4', 'Archived', 200, '2026-11-09 11:00:00', '+12125550104');
            $estimate('5', 'Pending', 9999, '2026-11-01 10:00:00');
            $estimate('6', 'Unsent', 50, '2026-11-10 12:00:00');

            $connection = PhoneConnection::factory()->create(['scope_type' => PhoneConnection::SCOPE_COMPANY, 'scope_id' => $f->company_id]);
            $line = PhoneNumber::factory()->create(['connection_id' => $connection->id, 'scope_type' => PhoneNumber::SCOPE_FRANCHISE, 'scope_id' => $f->id, 'e164' => '+13055550199']);
            // Texts are stored in UTC; the estimates are on the wall clock (New York, 5 hours behind).
            foreach ([['+12125550101', 'outbound', '2026-11-10 15:00:00'], ['+12125550103', 'outbound', '2026-11-09 16:00:00'],
                ['+12125550103', 'inbound', '2026-11-09 17:00:00'], ['+12125550104', 'outbound', '2026-11-09 17:00:00'],
                // Before estimate 4 was sent: no answer to it.
                ['+12125550104', 'inbound', '2026-11-09 15:00:00']] as [$phone, $dir, $at]) {
                PhoneMessage::query()->create(['connection_id' => $connection->id, 'number_id' => $line->id, 'twilio_sid' => 'SM'.Str::random(32),
                    'direction' => $dir, 'from_e164' => $dir === 'inbound' ? $phone : $line->e164, 'to_e164' => $dir === 'inbound' ? $line->e164 : $phone,
                    'body' => 'x'])->forceFill(['created_at' => $at])->save();
            }

            // EzBizi's own: one sent in the dates; one that is Workiz's estimate 2; A-1 Tools' archive with no date of its own.
            $client = Customer::factory()->create(['owning_franchise_id' => $f->id]);
            $native = fn (string $number, string $status, ?string $issued, array $more = []) => DB::table('estimates')->insert($more + [
                'id' => (string) Str::uuid(), 'organization_id' => $f->organization_id, 'franchise_id' => $f->id, 'customer_id' => $client->id,
                'number' => $number, 'status' => $status, 'issued_on' => $issued, 'currency' => 'USD', 'total_minor' => 7000, 'source' => 'ezbizi',
                'created_at' => now(), 'updated_at' => now()]);
            $native('E-1', 'sent', '2026-11-10');
            $native('E-2', 'accepted', '2026-11-10', ['external_ref' => 'ES-2']);
            $native('E-3', 'archived', null, ['import_batch' => 'a1-2026-10', 'source' => 'a1']);
        });
        $get = fn (string $query = '') => $this->signedInAs($t['admin'])->getJson('/api/v1/reports/estimates?from=2026-11-09&to=2026-11-10'.$query)->assertOk();

        $all = $get();
        $figures = $all->json('data.figures');
        // Six in the dates: Workiz 1-4 and 6, and E-1; E-2 is Workiz's 2, E-3 has no date.
        $this->assertSame(['count' => 6, 'value_minor' => 212000], $figures['total']);
        $this->assertSame(['count' => 2, 'value_minor' => 107000], $figures['open']);
        $this->assertSame(['count' => 1, 'value_minor' => 50000], $figures['approved']);
        $this->assertSame(['count' => 1, 'value_minor' => 30000], $figures['declined']);
        $this->assertSame(['count' => 1, 'value_minor' => 100000], $figures['texted_no_answer']);
        $this->assertSame(['count' => 1, 'value_minor' => 20000], $figures['closed_no_answer']);
        $this->assertSame(['count' => 1, 'value_minor' => 30000], $figures['closed_answered']);

        $rows = collect($all->json('data.rows'))->keyBy('number');
        $this->assertSame(['open', '2026-11-10 10:00:00', null, true, '701', 'Ann Lee'],
            [$rows['1']['state'], $rows['1']['texted_at'], $rows['1']['answered_at'], $rows['1']['no_answer'], $rows['1']['job_number'], $rows['1']['client']]);
        $this->assertSame(['declined', 'Too expensive for my budget', '2026-11-09 12:00:00'], [$rows['3']['state'], $rows['3']['reason'], $rows['3']['answered_at']]);
        $this->assertSame(['draft', 'ezbizi'], [$rows['6']['state'], $rows['E-1']['store']]);
        $this->assertFalse($rows->has('E-2'));

        $get('&state[]=open')->assertJsonPath('data.count', 2)->assertJsonPath('data.figures.total.count', 6);
        $get('&q=budget')->assertJsonPath('data.count', 1)->assertJsonPath('data.rows.0.number', '3');
    }

    public function test_it_needs_its_tab(): void
    {
        $t = $this->provisionTenant('Alpha');

        $this->signedInAs($this->userWithRole($t, 'marketer', ['reports.view']))
            ->getJson('/api/v1/reports/estimates?from=2026-11-09&to=2026-11-10')->assertForbidden();
    }
}
