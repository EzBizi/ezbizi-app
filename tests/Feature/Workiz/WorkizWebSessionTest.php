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
        return $this->signedInAs($this->t['admin'])->postJson('/api/v1/social/workiz-web/link')->assertOk()->json('data.link');
    }

    public function test_a_session_sent_to_the_link_is_kept_and_proved(): void
    {
        Http::fake(['app.workiz.com/root/home' => Http::response('<script>var accountId = "98765";</script>')]);
        $link = $this->link();

        $this->postJson($link, ['cookies' => $this->cookies() + ['other' => 'x']])->assertOk()->assertExactJson(['data' => ['connected' => true]]);

        $row = $this->inTenant($this->t['org'], fn () => SocialConnection::query()->where('provider', SocialProvider::WorkizWeb->value)->sole());
        $this->assertSame(['connected', '406487', '98765'], [$row->status, $row->credentials['user_id'], $row->credentials['account_id']]);
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

    /** The login reads every account: only the organization view sets it up or maps it (AGENTS.md). */
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
                'organization_id' => $this->t['org']->id, 'scope_type' => SocialConnection::SCOPE_ORGANIZATION,
                'scope_id' => $this->t['org']->id, 'provider' => SocialProvider::WorkizWeb->value,
                'remote_id' => $this->t['org']->id, 'display_name' => 'Workiz web login',
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
            'app.workiz.com/ajaxc/franchise/index/' => Http::response(['flag' => true, 'data' => [
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
}
