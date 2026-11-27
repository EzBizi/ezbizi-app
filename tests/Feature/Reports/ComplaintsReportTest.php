<?php

namespace Tests\Feature\Reports;

use App\Models\Complaint;
use App\Models\Lead;
use App\Models\LeadConversation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Reports › Complaints (Shoval, 2026-11-18: "up to 48 resulting, up to 24 hours to call"). */
class ComplaintsReportTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_first_contact_and_close_against_the_business_targets(): void
    {
        $this->travelTo('2026-11-20 12:00:00');
        $t = $this->provisionTenant('Alpha');
        $f = $t['franchise'];
        $this->inTenant($t['org'], function () use ($t, $f) {
            $lead = fn (string $id, string $phone) => Lead::query()->create(['organization_id' => $t['org']->id, 'company_id' => $f->company_id,
                'franchise_id' => $f->id, 'source' => Lead::SOURCE_LOCAL_SERVICES, 'remote_id' => $id, 'lead_type' => 'PHONE_CALL',
                'status' => Lead::STATUS_NEW, 'phone' => $phone, 'contact_name' => 'Ann '.$id, 'received_at' => now()->subDays(5)]);
            $complaint = fn (string $kind, string $type, string $source, string $raised, array $more = []) => Complaint::create($more + [
                'organization_id' => $t['org']->id, 'company_id' => $f->company_id, 'franchise_id' => $f->id, 'kind' => $kind,
                'source_type' => $type, 'source_id' => $source, 'status' => 'new', 'raised_at' => $raised]);

            // Called back 3 hours later (Workiz's clock is New York's), closed after 26.
            $one = $lead('L1', '+12125550111');
            $complaint('call_complaint', 'lead', $one->id, '2026-11-18 10:00:00', ['status' => 'solved', 'solved_at' => '2026-11-19 12:00:00']);
            DB::table('workiz_calls')->insert(['id' => (string) Str::uuid(), 'organization_id' => $t['org']->id, 'franchise_id' => $f->id,
                'remote_id' => 'CAL-1', 'direction' => 'outgoing', 'from_number' => '+13055550199', 'to_number' => '2125550111',
                'called_at' => '2026-11-18 08:00:00', 'synced_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            // Called back through Leads 30 hours later, still open after 48.
            $two = $lead('L2', '+12125550122');
            $complaint('call_complaint', 'lead', $two->id, '2026-11-17 10:00:00');
            LeadConversation::create(['organization_id' => $t['org']->id, 'lead_id' => $two->id, 'remote_id' => 'c-1', 'channel' => 'PHONE_CALL',
                'participant' => 'ADVERTISER', 'occurred_at' => '2026-11-18 16:00:00']);
            // A bad review with no phone: its first note is the contact.
            $three = $complaint('bad_review', 'review', (string) Str::uuid(), '2026-11-19 18:00:00');
            DB::table('complaint_notes')->insert(['id' => (string) Str::uuid(), 'organization_id' => $t['org']->id, 'complaint_id' => $three->id,
                'user_id' => $t['admin']->id, 'body' => 'Called her', 'created_at' => '2026-11-19 20:00:00']);
            // Six hours old, nothing yet: not late.
            $complaint('call_complaint', 'lead', $lead('L4', '+12125550144')->id, '2026-11-20 06:00:00');
            $complaint('call_complaint', 'lead', $lead('L5', '+12125550155')->id, '2026-11-01 10:00:00');
        });

        $data = $this->signedInAs($t['admin'])->getJson('/api/v1/reports/complaints?from=2026-11-17&to=2026-11-20')->assertOk()->json('data');

        $this->assertSame(['total' => 4, 'open' => 3, 'closed' => 1, 'contact_kept_percent' => 67, 'solve_kept_percent' => 50,
            'avg_hours_to_contact' => 11.7, 'avg_hours_to_solve' => 26, 'not_contacted' => 1], $data['figures']);
        $by = collect($data['rows'])->keyBy('customer');
        $this->assertSame(['call', 3, true, 26, true], [$by['Ann L1']['contacted_by'], $by['Ann L1']['hours_to_contact'],
            $by['Ann L1']['contact_kept'], $by['Ann L1']['hours_to_solve'], $by['Ann L1']['solve_kept']]);
        $this->assertSame([30, false, false], [$by['Ann L2']['hours_to_contact'], $by['Ann L2']['contact_kept'], $by['Ann L2']['solve_kept']]);
        $this->assertSame([null, null], [$by['Ann L4']['contact_kept'], $by['Ann L4']['solve_kept']]);
        $note = collect($data['rows'])->firstWhere('kind', 'bad_review');
        $this->assertSame(['note', 2, null], [$note['contacted_by'], $note['hours_to_contact'], $note['solve_kept']]);

        // The business moves the contact target to 36 hours: the 30-hour one is on time.
        $this->signedInAs($t['admin'])->postJson('/api/v1/settings/lists/'.\App\Http\Controllers\Api\ComplaintsReportController::LIST.'/items',
            ['value' => ['target' => 'contact', 'hours' => 36]])->assertSuccessful();
        $this->signedInAs($t['admin'])->getJson('/api/v1/reports/complaints?from=2026-11-17&to=2026-11-20&kind[]=call_complaint')->assertOk()
            ->assertJsonPath('data.figures.contact_kept_percent', 100)->assertJsonPath('data.count', 3);
    }

    public function test_it_needs_its_tab(): void
    {
        $t = $this->provisionTenant('Alpha');

        $this->signedInAs($this->userWithRole($t, 'Office', ['reports.view']))
            ->getJson('/api/v1/reports/complaints?from=2026-11-17&to=2026-11-20')->assertForbidden();
    }
}
