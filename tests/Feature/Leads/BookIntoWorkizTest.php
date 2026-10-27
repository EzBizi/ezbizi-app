<?php

namespace Tests\Feature\Leads;

use App\Models\Job;
use App\Models\Lead;
use App\Models\SocialConnection;
use App\Models\WorkizJob;
use App\Support\Social\SocialProvider;
use App\Support\Workiz\WorkizV2;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Jose, 2026-10-27: Book makes the job in the branch's Workiz too, in the
 * service area the dispatcher picked, and says so when Workiz refuses.
 */
class BookIntoWorkizTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private function v2Job(array $overrides = []): array
    {
        return array_replace_recursive(require base_path('tests/Fixtures/workiz/v2_job.php'), $overrides);
    }

    private function page(array $rows): array
    {
        return ['pageSize' => 300, 'page' => 1, 'totalResults' => count($rows), 'hasMore' => false, 'data' => $rows];
    }

    /** @return array{0: array, 1: Lead} */
    private function branch(bool $workiz = true): array
    {
        $t = $this->provisionTenant('Alpha');

        $lead = $this->inTenant($t['org'], function () use ($t, $workiz) {
            if ($workiz) {
                $c = new SocialConnection;
                $c->forceFill([
                    'organization_id' => $t['org']->id, 'scope_type' => SocialConnection::SCOPE_FRANCHISE,
                    'scope_id' => $t['franchise']->id, 'provider' => SocialProvider::Workiz->value,
                    'remote_id' => $t['franchise']->id, 'display_name' => 'Nationwide',
                ]);
                $c->connect(['api_token' => 'v2key_NW', 'api_version' => 'v2', 'v1' => ['api_token' => 'api_NW', 'auth_secret' => 'sec_NW']]);
                $c->save();
            }

            return Lead::create([
                'organization_id' => $t['org']->id, 'franchise_id' => $t['franchise']->id, 'source' => Lead::SOURCE_THUMBTACK,
                'remote_id' => uniqid(), 'contact_name' => 'Pat Rivera', 'phone' => '+13055550100', 'postal_code' => '33180',
                'status' => 'new', 'received_at' => now(),
            ]);
        });

        return [$t, $lead];
    }

    private function book(array $t, Lead $lead, array $extra = [])
    {
        return $this->signedInAs($t['admin'])->postJson("/api/v1/leads/{$lead->id}/book", [
            'franchise_id' => $t['franchise']->id,
            'customer' => ['name' => 'Pat Rivera', 'postal_code' => '33180'],
            'title' => 'Chimney inspection',
            'status' => $this->inTenant($t['org'], fn () => Job::allowedStatuses($t['franchise'])[0]),
            'scheduled_for' => '2026-11-10 09:00:00',
        ] + $extra);
    }

    public function test_booking_makes_the_workiz_job_and_sets_its_service_area(): void
    {
        [$t, $lead] = $this->branch();
        // Workiz spells it "Miami ", which a request trims: its own spelling is what is sent.
        $this->inTenant($t['org'], fn () => WorkizJob::land(SocialConnection::query()->where('provider', 'workiz')->first(),
            WorkizV2::job($this->v2Job(['id' => 'JOB-old', 'uuid' => 'OLD001', 'clientInfo' => ['serviceArea' => ['name' => 'Miami ']]]))));
        Http::fake([
            'api.workiz.com/crm/api/v2/jobtypes*' => Http::response($this->page([['id' => 'JT-x1', 'name' => 'Chimney Inspection']])),
            'api.workiz.com/crm/api/v2/jobs' => Http::response($this->v2Job(['id' => 'JOB-new', 'uuid' => 'NEW001', 'serialId' => 11009]), 201),
            'api.workiz.com/api/v1/api_NW/job/update/' => Http::response(['flag' => true], 200),
            'api.workiz.com/crm/api/v2/jobs/JOB-new' => Http::response($this->v2Job(['id' => 'JOB-new', 'uuid' => 'NEW001', 'serialId' => 11009])),
        ]);

        $data = $this->book($t, $lead, ['workiz_job_type' => 'Chimney Inspection', 'workiz_service_area' => 'Miami '])
            ->assertCreated()->json('data');

        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/v2/jobs') && $r['jobTypeId'] === 'JT-x1');
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/api_NW/job/update/')
            && $r['UUID'] === 'NEW001' && $r['ServiceArea'] === 'Miami ' && $r['auth_secret'] === 'sec_NW');
        $this->assertSame(['serial_id' => 11009, 'service_area' => 'Miami', 'refused' => null], $data['workiz']);
        $this->assertSame($data['job']['id'], $this->inTenant($t['org'], fn () => WorkizJob::query()->where('remote_id', 'NEW001')->value('job_id')));
    }

    public function test_a_workiz_branch_asks_for_the_job_type_before_making_anything(): void
    {
        [$t, $lead] = $this->branch();
        Http::fake();

        $this->book($t, $lead)->assertUnprocessable()->assertJsonValidationErrors('workiz_job_type');

        $this->assertSame(0, $this->inTenant($t['org'], fn () => Job::query()->count()));
        Http::assertNothingSent();
    }

    public function test_a_refusal_is_said_and_the_ezbizi_job_stays(): void
    {
        [$t, $lead] = $this->branch();
        Http::fake([
            'api.workiz.com/crm/api/v2/jobtypes*' => Http::response($this->page([['id' => 'JT-x1', 'name' => 'Chimney Inspection']])),
            'api.workiz.com/crm/api/v2/jobs' => Http::response(['message' => 'Invalid date', 'statusCode' => 400], 400),
        ]);

        $data = $this->book($t, $lead, ['workiz_job_type' => 'Chimney Inspection', 'workiz_service_area' => 'Miami '])
            ->assertCreated()->json('data');

        $this->assertNull($data['workiz']['serial_id']);
        $this->assertSame('Not in Workiz: Invalid date Enter job '.$data['job']['number'].' in Workiz by hand.', $data['workiz']['refused']);
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/job/update/'));
    }

    public function test_a_branch_without_workiz_books_in_ezbizi_only(): void
    {
        [$t, $lead] = $this->branch(workiz: false);
        Http::fake();

        $this->book($t, $lead)->assertCreated()->assertJsonPath('data.workiz', null);

        Http::assertNothingSent();
    }

    public function test_choices_list_the_branchs_types_sources_and_areas_seen_at_the_zip_first(): void
    {
        [$t, $lead] = $this->branch();
        $this->inTenant($t['org'], function () {
            $connection = SocialConnection::query()->where('provider', 'workiz')->first();
            foreach ([['A1', 'Miami ', '33180'], ['A2', 'Miami ', '33180-1234'], ['A3', 'Fort Lauderdale', '33301'], ['A4', 'Fort Lauderdale', '33302'], ['A5', 'Fort Lauderdale', '33303']] as [$uuid, $area, $zip]) {
                WorkizJob::land($connection, WorkizV2::job($this->v2Job(['id' => 'JOB-'.$uuid, 'uuid' => $uuid,
                    'clientInfo' => ['serviceArea' => ['name' => $area], 'addressDetails' => ['zipCode' => $zip]]])));
            }
        });
        Http::fake([
            'api.workiz.com/crm/api/v2/jobtypes*' => Http::response($this->page([['id' => 'JT-x1', 'name' => 'Chimney Inspection'], ['id' => 'JT-x2', 'name' => 'Old', 'disabled' => true]])),
            'api.workiz.com/crm/api/v2/adgroups*' => Http::response($this->page([['id' => 'AG-1', 'name' => 'Thumbtack']])),
        ]);

        $this->signedInAs($t['admin'])->getJson("/api/v1/leads/{$lead->id}/workiz-choices?franchise_id={$t['franchise']->id}")
            ->assertOk()
            ->assertJsonPath('data.job_types', ['Chimney Inspection'])
            ->assertJsonPath('data.job_sources', ['Thumbtack'])
            ->assertJsonPath('data.areas', ['Fort Lauderdale', 'Miami '])
            ->assertJsonPath('data.zip_areas', ['Miami ']);
    }
}
