<?php

namespace Tests\Feature\Workiz;

use App\Models\Franchise;
use App\Models\SocialConnection;
use App\Support\Reports\Experimental\WorkizJobs;
use App\Support\Social\SocialProvider;
use App\Support\Workiz\WorkizLeadAttributes;
use App\Support\Workiz\WorkizLeads;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * The reader half of the Leads integration.
 *
 * The Leads module owns the source constant, the permission, the reply channel
 * and the Unreplied rule. These tests cover only what this session produces,
 * and two of them exist to prove what it does NOT produce.
 */
class WorkizLeadReaderTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private function lead(array $overrides = []): array
    {
        return array_merge([
            'UUID' => 'LD9KQ2',
            'SerialId' => 900018777,
            'ClientId' => 1002,
            'LeadDateTime' => '2026-09-23 09:15:00',
            'CreatedDate' => '2026-09-23 09:15:00',
            'Status' => 'Submitted',
            'SubStatus' => '',
            'Phone' => '(555) 555-0100',
            'SecondPhone' => '',
            'Email' => 'someone@example.test',
            'FirstName' => 'Pat',
            'LastName' => 'Rivera',
            'Company' => '',
            'Address' => '1 Example Street',
            'City' => 'Hoboken',
            'State' => 'New York',
            'PostalCode' => '07030',
            'Country' => 'US',
            'JobType' => 'Chimney Sweep',
            'JobSource' => 'LS Ads',
            'ServiceArea' => 'Upstate New York ',
            'LeadNotes' => 'Wants a quote this week',
            'Team' => [],
        ], $overrides);
    }

    private function connect(array $tenant, Franchise $branch): SocialConnection
    {
        return $this->inTenant($tenant['org'], function () use ($tenant, $branch) {
            $c = new SocialConnection;
            $c->forceFill([
                'organization_id' => $tenant['org']->id,
                'scope_type' => SocialConnection::SCOPE_FRANCHISE,
                'scope_id' => $branch->id,
                'provider' => SocialProvider::Workiz->value,
                'remote_id' => $branch->id,
                'display_name' => $branch->name,
            ]);
            $c->connect(['api_token' => 'api_good', 'refresh_token' => 'api_good']);
            $c->save();

            return $c;
        });
    }

    public function test_it_reads_a_branchs_leads_whole(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        Http::fake(['api.workiz.com/*' => Http::response(['flag' => true, 'data' => [$this->lead()]])]);

        $out = app(WorkizLeads::class)->raw($this->connect($tenant, $tenant['franchise']));

        $this->assertNull($out['failed']);
        $this->assertCount(1, $out['leads']);
        $this->assertSame('LD9KQ2', $out['leads'][0]['UUID']);

        // JobSource is carried through, because the decision about whether an
        // LS Ads lead is a duplicate of one EzBizi already holds is Jose's and
        // cannot be made without it.
        $this->assertSame('LS Ads', $out['leads'][0]['JobSource']);
    }

    public function test_a_refusal_names_a_status_and_never_the_token(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        Http::fake(['api.workiz.com/*' => Http::response([], 429)]);

        $out = app(WorkizLeads::class)->raw($this->connect($tenant, $tenant['franchise']));

        $this->assertSame([], $out['leads']);
        $this->assertStringContainsString('429', (string) $out['failed']);
        $this->assertStringNotContainsString('api_good', (string) $out['failed']);
        $this->assertStringNotContainsString('workiz.com', (string) $out['failed']);
    }

    public function test_it_maps_onto_the_leads_contract(): void
    {
        $out = WorkizLeadAttributes::fromPayload($this->lead());

        $this->assertSame('LD9KQ2', $out['remote_id']);
        $this->assertSame('Pat Rivera', $out['contact_name']);
        $this->assertSame('(555) 555-0100', $out['phone'], 'the phone is kept as Workiz wrote it');
        $this->assertSame('5555550100', $out['phone_digits']);
        $this->assertSame('Chimney Sweep', $out['category']);
        $this->assertSame('Wants a quote this week', $out['summary']);
        $this->assertSame('2026-09-23 09:15:00', $out['received_at'], 'naive and unconverted');
        $this->assertFalse($out['is_test']);
    }

    /**
     * THE ONE THE LEADS SESSION ASKED FOR BY NAME.
     *
     * `Lead::STATUS_NEW` means "nobody at EzBizi has decided anything yet".
     * Workiz's `Status` is a different namespace that shares some of the same
     * words, so mapping one into the other merges two meanings and the damage
     * is invisible — a lead somebody has triaged reading as untouched.
     */
    public function test_it_never_sets_the_leads_own_status(): void
    {
        $out = WorkizLeadAttributes::fromPayload($this->lead(['Status' => 'new']));

        $this->assertArrayNotHasKey('status', $out);
        $this->assertSame('new', $out['source_status'], 'Workiz\'s word goes here, verbatim');
    }

    /**
     * `LeadThread` derives these from conversation rows. A reader writing them
     * by hand is what corrupted the Unreplied list in September.
     */
    public function test_it_never_writes_the_thread_columns(): void
    {
        $out = WorkizLeadAttributes::fromPayload($this->lead());

        foreach (['last_contact_at', 'last_contact_from', 'preview', 'source'] as $theirs) {
            $this->assertArrayNotHasKey($theirs, $out);
        }
    }

    /**
     * A commercial caller often has no personal name. An empty string in the
     * list is worse than the business that rang.
     */
    public function test_a_lead_with_no_person_falls_back_to_the_company(): void
    {
        $out = WorkizLeadAttributes::fromPayload($this->lead([
            'FirstName' => '', 'LastName' => '', 'Company' => 'Acme Fireplaces',
        ]));

        $this->assertSame('Acme Fireplaces', $out['contact_name']);
    }

    public function test_workizs_empty_strings_become_nulls(): void
    {
        $out = WorkizLeadAttributes::fromPayload($this->lead([
            'Email' => '', 'LeadNotes' => '', 'SubStatus' => '',
        ]));

        $this->assertNull($out['email']);
        $this->assertNull($out['summary']);
        $this->assertSame('Submitted', $out['source_status'], 'no trailing separator when SubStatus is absent');
    }

    public function test_a_substatus_is_joined_onto_the_status_verbatim(): void
    {
        $out = WorkizLeadAttributes::fromPayload($this->lead(['SubStatus' => 'Left Message']));

        $this->assertSame('Submitted · Left Message', $out['source_status']);
    }

    /**
     * `leads` has no address columns — a lead is a person and a phone number.
     * Dropping them is the decision; packing them into `summary` would be the
     * mistake.
     */
    public function test_the_address_is_dropped_rather_than_smuggled_into_the_summary(): void
    {
        $out = WorkizLeadAttributes::fromPayload($this->lead());

        foreach (['address', 'city', 'state', 'postal_code'] as $absent) {
            $this->assertArrayNotHasKey($absent, $out);
        }

        $this->assertStringNotContainsString('Example Street', (string) $out['summary']);
    }

    /**
     * `LeadDateTime` is the APPOINTMENT, not the arrival. Measured 2026-09-28
     * across the 10 live Workiz leads since January, all branches: it is never
     * before `CreatedDate`, later in 9 of 10, and always a round hour, while
     * `CreatedDate` carries seconds. The Leads session found it on Illinois --
     * created 09-24, booked for 10-05 -- when received_at said 10-05 and held
     * that branch's import watermark in the future.
     *
     * The fixture above could not have caught it: it set both to one value.
     */
    public function test_a_lead_is_received_when_it_was_created_not_when_it_is_booked(): void
    {
        $out = WorkizLeadAttributes::fromPayload($this->lead([
            'CreatedDate' => '2026-09-24 10:31:07',
            'LeadDateTime' => '2026-10-05 12:00:00',
        ]));

        $this->assertSame('2026-09-24 10:31:07', $out['received_at']);
    }

    /** A payload with no CreatedDate still says when, from the only date it has. */
    public function test_a_lead_with_no_created_date_falls_back_to_its_booking(): void
    {
        $out = WorkizLeadAttributes::fromPayload($this->lead([
            'CreatedDate' => '',
            'LeadDateTime' => '2026-10-05 12:00:00',
        ]));

        $this->assertSame('2026-10-05 12:00:00', $out['received_at']);
    }

    /**
     * `offset` is a PAGE NUMBER, as measured on /job/all/: the second page of
     * leads is page 1, not "row 100". A-1 has too few leads to fill a page
     * today, which is why the old loop never showed its defect here.
     */
    public function test_the_second_page_of_leads_is_asked_for_by_page_number(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $first = array_map(fn ($i) => $this->lead(['UUID' => 'L'.$i]), range(1, WorkizJobs::PAGE));

        Http::fake(function (Request $request) use ($first) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return Http::response(['flag' => true, 'data' => match ((string) ($query['offset'] ?? '')) {
                '0' => $first,
                '1' => [$this->lead(['UUID' => 'NEXT'])],
                default => [],
            }]);
        });

        $out = app(WorkizLeads::class)->raw($this->connect($tenant, $tenant['franchise']));

        $this->assertCount(WorkizJobs::PAGE + 1, $out['leads']);
        $this->assertSame('NEXT', end($out['leads'])['UUID']);
    }
}
