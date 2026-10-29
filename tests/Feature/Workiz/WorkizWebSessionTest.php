<?php

namespace Tests\Feature\Workiz;

use App\Models\SocialConnection;
use App\Models\WorkizRecord;
use App\Support\Social\SocialProvider;
use App\Support\Workiz\WorkizWebSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * The Workiz web session the box worker reads Workiz's own reports with
 * (Jose, 2026-10-20). EzBizi never signs in: the session arrives from the
 * person's browser at a signed, 30-minute link.
 */
class WorkizWebSessionTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private array $t;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->provisionTenant('Alpha');
    }

    /** sendajob_user is base64 of userid_<id>, as Workiz sets it. */
    private function cookies(): array
    {
        return ['sendajob_sessid' => 'sess-abc', 'sendajob_user' => base64_encode('userid_406487'), 'sajFranchise' => 'fr-main'];
    }

    private function link(): string
    {
        return $this->signedInAs($this->t['admin'])->withHeader('X-EzBizi-View', 'company:'.$this->t['company']->id)
            ->postJson('/api/v1/social/workiz-web/link')->assertOk()->json('data.link');
    }

    public function test_a_session_sent_to_the_link_is_kept_and_proved(): void
    {
        Http::fake(['app.workiz.com/root/home' => Http::response('<script>var accountId = "98765";</script>')]);
        $link = $this->link();

        $this->postJson($link, ['cookies' => $this->cookies() + ['other' => 'x']])->assertOk()->assertExactJson(['data' => ['connected' => true]]);

        $row = $this->inTenant($this->t['org'], fn () => SocialConnection::query()->where('provider', SocialProvider::WorkizWeb->value)->sole());
        $this->assertSame(['connected', '406487', '98765'], [$row->status, $row->credentials['user_id'], $row->credentials['account_id']]);
        $this->assertSame([SocialConnection::SCOPE_COMPANY, $this->t['company']->id], [$row->scope_type, $row->scope_id], 'the company in view owns it');
        $this->assertNotNull($row->last_reached_at, 'the card says when it was sent');
        $this->assertSame($this->cookies(), $row->credentials['cookies'], 'only the three cookies Workiz needs');
        Http::assertSent(fn (Request $r) => str_contains($r->header('Cookie')[0] ?? '', 'sendajob_sessid=sess-abc'));
    }

    public function test_a_tampered_link_or_a_signed_out_browser_is_refused(): void
    {
        Http::fake(['app.workiz.com/*' => Http::response('', 302, ['Location' => 'https://app.workiz.com/login/'])]);
        $link = $this->link();

        $this->postJson($link.'x', ['cookies' => $this->cookies()])->assertForbidden();
        $this->postJson($link, ['cookies' => ['sendajob_sessid' => 'x']])->assertStatus(422);
        $this->postJson($link, ['cookies' => $this->cookies()])->assertStatus(422)->assertJsonPath('message', WorkizWebSession::EXPIRED);
    }

    /** Roles & Permissions, 2026-10-20: a link is good once. A replayed one cannot re-point the reports. */
    public function test_a_link_works_once(): void
    {
        Http::fake(['app.workiz.com/root/home' => Http::response('<script>var accountId = "98765";</script>')]);
        $link = $this->link();
        $this->postJson($link, ['cookies' => $this->cookies()])->assertOk();

        $this->postJson($link, ['cookies' => ['sendajob_sessid' => 'theirs'] + $this->cookies()])->assertStatus(410);

        $row = $this->inTenant($this->t['org'], fn () => SocialConnection::query()->where('provider', SocialProvider::WorkizWeb->value)->sole());
        $this->assertSame('sess-abc', $row->credentials['cookies']['sendajob_sessid']);
        $this->assertArrayNotHasKey('handoff_nonce', $row->credentials);
    }

    /** Jose, 2026-10-21: the Workiz belongs to one company, so a branch of another company cannot be mapped. */
    public function test_only_a_branch_of_the_logins_company_can_be_mapped(): void
    {
        $c = $this->connected();
        $other = $this->inTenant($this->t['org'], function () {
            $company = \App\Models\Company::create(['organization_id' => $this->t['org']->id, 'name' => 'Other Co']);

            return \App\Models\Franchise::query()->create(['organization_id' => $this->t['org']->id, 'company_id' => $company->id, 'name' => 'Other branch']);
        });
        $put = fn (string $f) => $this->signedInAs($this->t['admin'])->putJson('/api/v1/social/workiz-web/'.$c->id.'/accounts', ['account' => '555', 'franchise_id' => $f]);

        $put($other->id)->assertStatus(422);
        $put($this->t['franchise']->id)->assertOk();
    }

    /** The login reads every account of its company: a branch view never sets it up or maps it (AGENTS.md). */
    public function test_a_branch_view_cannot_get_a_link_or_map_accounts(): void
    {
        $c = $this->connected();
        $branch = $this->userWithRole($this->t, 'Branch', ['reports.build', 'reports.view'], onlyAt: $this->t['franchise']);

        $this->signedInAs($branch)->postJson('/api/v1/social/workiz-web/link')->assertForbidden();
        $this->signedInAs($branch)->putJson('/api/v1/social/workiz-web/'.$c->id.'/accounts', ['account' => 'main', 'franchise_id' => null])->assertForbidden();
    }

    /** One connected login, the main account mapped to the branch and a sub-account not mapped. */
    private function connected(): SocialConnection
    {
        return $this->inTenant($this->t['org'], function () {
            $c = new SocialConnection;
            $c->forceFill([
                'organization_id' => $this->t['org']->id, 'scope_type' => SocialConnection::SCOPE_COMPANY,
                'scope_id' => $this->t['company']->id, 'provider' => SocialProvider::WorkizWeb->value,
                'remote_id' => $this->t['company']->id, 'display_name' => 'Workiz web login',
            ]);
            $c->connect(['cookies' => $this->cookies(), 'user_id' => '406487', 'account_id' => '98765', 'accounts' => ['main' => $this->t['franchise']->id]]);
            $c->forceFill(['status' => 'connected'])->save();

            return $c;
        });
    }

    public function test_the_payments_report_lands_per_mapped_account(): void
    {
        $this->connected();
        Http::fake([
            'app.workiz.com/ajaxc/franchise/' => Http::response(['flag' => true, 'data' => [
                ['fid' => '555', 'account_custom_name' => 'Sub branch', 'invite_status' => '1', 'status' => '1', 'account_status' => 'active'],
            ]]),
            'app.workiz.com/ajaxc/payments/getPaymentsReport/' => Http::response(['flag' => true, 'aaData' => [
                ['id' => 71, 'amount' => '250.00', 'date' => '2026-10-19 10:00:00', 'payment_type' => 'credit', 'status' => 'Paid', 'technician_name' => 'Ana Ruiz'],
                ['isTotalsRow' => true, 'amount' => '250.00'],
            ]]),
        ]);

        $this->artisan('ezbizi:workiz-web-payments', ['--from' => '2026-10-19', '--to' => '2026-10-20'])
            ->expectsOutputToContain('Sub branch is not mapped')->assertSuccessful();

        $row = $this->inTenant($this->t['org'], fn () => WorkizRecord::query()->sole());
        $this->assertSame(['web_payment', 'WP-71', '250.00', $this->t['franchise']->id], [$row->type, $row->remote_id, $row->amount, $row->franchise_id]);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'getPaymentsReport') && $r['date_query'] === '19.10.26_20.10.26'
            && $r->hasHeader('User-Id', '406487') && $r->hasHeader('Account-Id', '98765') && $r->hasHeader('Origin', 'https://app.workiz.com'));
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'franchiseLogin'));
    }

    public function test_a_dead_session_marks_the_card_and_reads_nothing_more(): void
    {
        $c = $this->connected();
        Http::fake(['app.workiz.com/*' => Http::response('Session Expired')]);

        $this->artisan('ezbizi:workiz-web-payments')->assertSuccessful();

        $c = $this->inTenant($this->t['org'], fn () => $c->fresh());
        $this->assertSame(['expired', WorkizWebSession::EXPIRED], [$c->status, $c->status_reason]);
        $this->assertSame(0, $this->inTenant($this->t['org'], fn () => WorkizRecord::query()->count()));
    }

    /** Jose, 2026-10-21: Workiz's own client-portal link, kept on the estimate and offered to rules as {{estimate_link}}. */
    public function test_estimate_links_land_on_the_mirrored_estimate(): void
    {
        $c = $this->connected();
        [$record, $job] = $this->inTenant($this->t['org'], function () use ($c) {
            $customer = \App\Models\Customer::factory()->create(['owning_franchise_id' => $this->t['franchise']->id]);
            $job = \App\Models\Job::factory()->create(['franchise_id' => $this->t['franchise']->id, 'customer_id' => $customer->id]);
            $record = WorkizRecord::query()->create(['franchise_id' => $this->t['franchise']->id, 'connection_id' => $c->id,
                'type' => 'estimate', 'remote_id' => 'ES-abc', 'job_id' => $job->id, 'payload' => ['serialId' => '100077936-1'], 'synced_at' => now()]);

            return [$record, $job];
        });
        Http::fake([
            'app.workiz.com/ajaxc/franchise/' => Http::response(['flag' => true, 'data' => []]),
            'app.workiz.com/ajaxc/estimate/estimateReport/' => Http::response(['flag' => true, 'aaData' => [
                ['id' => '6793881', 'estimate_id' => '100077936-1', 'status_updated_at' => now()->toDateTimeString()],
                ['id' => '1', 'estimate_id' => '1-1', 'status_updated_at' => '2020-01-01 00:00:00'],
            ]]),
            'app.workiz.com/ajaxc/estimate/getEstimateById/6793881/' => Http::response(['flag' => true, 'data' => ['estimate' => [
                'estimate_link' => 'https://app.workiz.com/clientPortal/estimate/ES-abc:'.str_repeat('e', 40),
                'estimate_portal_url' => 'https://wrkz.me/hs8I92', 'proposal_portal_url' => 'https://wrkz.me/5rrcui',
            ]]]),
        ]);

        $this->artisan('ezbizi:workiz-web-estimate-links')->expectsOutputToContain('1 Workiz estimate link(s) read.')->assertSuccessful();

        $this->inTenant($this->t['org'], function () use ($record, $job) {
            $this->assertSame('https://wrkz.me/hs8I92', $record->fresh()->links['portal']);
            $this->assertSame('https://wrkz.me/hs8I92', $job->fresh()->estimate_link);
        });
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'getEstimateById/1/'));
    }

    /** Jose, 2026-10-21: "can't it be just assigned to the company?" The account's own name files it. */
    public function test_an_unmapped_account_files_to_the_branch_its_name_carries(): void
    {
        $c = $this->connected();
        $this->inTenant($this->t['org'], fn () => tap($c, fn ($c) => $c->connect(array_diff_key((array) $c->credentials, ['accounts' => 1])))->save());
        $branch = $this->t['franchise']->name;
        Http::fake([
            'app.workiz.com/ajaxc/account/getInfo/' => Http::response(['flag' => true, 'data' => ['account_name' => 'A1 Chimney - '.$branch]]),
            'app.workiz.com/ajaxc/franchise/' => Http::response(['flag' => true, 'data' => []]),
            'app.workiz.com/ajaxc/payments/getPaymentsReport/' => Http::response(['flag' => true, 'aaData' => [
                ['id' => 72, 'amount' => '50.00', 'date' => '2026-10-19 10:00:00'],
            ]]),
        ]);

        $this->artisan('ezbizi:workiz-web-payments', ['--from' => '2026-10-19', '--to' => '2026-10-20'])->assertSuccessful();

        $this->assertSame($this->t['franchise']->id, $this->inTenant($this->t['org'], fn () => WorkizRecord::query()->sole()->franchise_id));
    }

    /** A login sent before 0.0.0466 belonged to the organization: it moves to a company, session kept, nothing re-sent. */
    public function test_a_legacy_organization_login_moves_to_a_company(): void
    {
        $c = $this->connected();
        $this->inTenant($this->t['org'], fn () => $c->forceFill(['scope_type' => SocialConnection::SCOPE_ORGANIZATION, 'scope_id' => $this->t['org']->id])->save());

        $this->signedInAs($this->t['admin'])->putJson('/api/v1/social/workiz-web/'.$c->id.'/company', ['company_id' => $this->t['company']->id])->assertOk();

        $row = $this->inTenant($this->t['org'], fn () => $c->fresh());
        $this->assertSame([SocialConnection::SCOPE_COMPANY, $this->t['company']->id, 'sess-abc'], [$row->scope_type, $row->scope_id, $row->credentials['cookies']['sendajob_sessid']]);
        $this->signedInAs($this->t['admin'])->putJson('/api/v1/social/workiz-web/'.$c->id.'/company', ['company_id' => $this->t['company']->id])->assertNotFound();
    }

    /** Jose, 2026-10-21: one session; the box switches to each Workiz account in turn. "A1 Chimney - CT" is Connecticut. */
    public function test_each_account_is_switched_to_and_filed_by_its_state(): void
    {
        $c = $this->connected();
        $ct = $this->inTenant($this->t['org'], fn () => $this->extraFranchise($this->t, 'Connecticut'));
        $ga = $this->inTenant($this->t['org'], fn () => $this->extraFranchise($this->t, 'Georgia'));
        Http::fake([
            'app.workiz.com/ajaxc/franchise/' => Http::response(['flag' => true, 'data' => [
                ['fid' => '132666', 'account_custom_name' => 'A1 Chimney - CT', 'invite_status' => '1', 'status' => '1', 'account_status' => 'converted'],
                ['fid' => '143612', 'account_custom_name' => 'A1 Chimney - GA', 'invite_status' => '1', 'status' => '1', 'account_status' => 'converted'],
            ]]),
            'app.workiz.com/ajaxc/franchise/franchiseLogin/' => fn (Request $r) => Http::response(['flag' => $r['acid'] === '132666']),
            'app.workiz.com/ajaxc/payments/getPaymentsReport/' => Http::response(['flag' => true, 'aaData' => [['id' => 81, 'amount' => '10.00', 'date' => '2026-10-19 10:00:00']]]),
        ]);

        $this->artisan('ezbizi:workiz-web-payments', ['--from' => '2026-10-19', '--to' => '2026-10-20'])->assertSuccessful();

        $this->inTenant($this->t['org'], function () use ($c, $ct, $ga) {
            $this->assertEqualsCanonicalizing([$this->t['franchise']->id, $ct->id], WorkizRecord::query()->pluck('franchise_id')->all());
            $reads = $c->fresh()->credentials['reads'];
            $this->assertArrayHasKey('payments_at', $reads['132666']);
            $this->assertSame('Workiz would not switch to this account, so it was not read.', $reads['143612']['error']);
            $this->assertNotNull($ga);
        });
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'goToMaster'));
        // The list is read from the master, which is reached first.
        $urls = collect(Http::recorded())->map(fn ($pair) => $pair[0]->url())->values();
        $this->assertLessThan($urls->search(fn ($u) => str_ends_with($u, '/ajaxc/franchise/')), $urls->search(fn ($u) => str_contains($u, 'goToMaster')));
    }

    /** Jobs' Book panel, 2026-10-27: each account's service areas (with ZIPs) and external companies. */
    public function test_service_areas_and_external_companies_land_per_branch(): void
    {
        $this->connected();
        Http::fake([
            'app.workiz.com/ajaxc/franchise/' => Http::response(['flag' => true, 'data' => []]),
            'app.workiz.com/ajaxc/metroAreas/index/' => Http::response(['flag' => true, 'data' => [['id' => '198005', 'metro_name' => 'Austin', 'status' => '1']]]),
            'app.workiz.com/ajaxc/metroAreas/getBoundaryAndZip/198005/' => Http::response(['flag' => true, 'polygonPaths' => [], 'zipCode' => '78701, 78702']),
            'app.workiz.com/ajaxc/companies/' => Http::response(['flag' => true, 'data' => [['id' => '9', 'company_name' => 'Home Warranty Co', 'status' => '1', 'company_notes' => 'private']]]),
        ]);

        $this->artisan('ezbizi:workiz-web-lists')->expectsOutputToContain('2 Workiz list row(s) read.')->assertSuccessful();

        $this->inTenant($this->t['org'], function () {
            $area = WorkizRecord::query()->where('type', 'service_area')->sole();
            $this->assertSame(['SA-198005', 'Austin', ['78701', '78702'], $this->t['franchise']->id], [$area->remote_id, $area->payload['name'], $area->payload['zips'], $area->franchise_id]);
            $company = WorkizRecord::query()->where('type', 'external_company')->sole();
            $this->assertSame('Home Warranty Co', $company->payload['company_name']);
            $this->assertArrayNotHasKey('company_notes', $company->payload);
        });
    }

    /** 2026-10-29: payments and estimate links share one login; a held lock ends the run quietly instead of throwing. */
    public function test_a_login_another_reader_holds_is_skipped_this_run(): void
    {
        $this->connected();
        Http::fake(['app.workiz.com/ajaxc/franchise/' => Http::response(['flag' => true, 'data' => []])]);
        $held = \Illuminate\Support\Facades\Cache::lock('workiz-web:'.$this->inTenant($this->t['org'], fn () => SocialConnection::query()->sole()->id), 300);
        $held->get();
        \Illuminate\Support\Facades\Cache::shouldReceive('lock')->andReturn(new class { public function block($s, $cb) { throw new \Illuminate\Contracts\Cache\LockTimeoutException; } });

        $this->artisan('ezbizi:workiz-web-payments')->expectsOutputToContain(WorkizWebSession::BUSY)->assertSuccessful();
    }
}
