<?php

namespace Tests\Feature\Api;

use App\Models\AuditEvent;
use App\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Review operations on Rodrigo's live routes: draft, match, card, report.
 *
 * What must hold: each is refused before A1 Leads hears anything unless the
 * caller is at A-1's organization, has A-1 Chimney in view, and holds the
 * right permission; the actor sent is the signed-in user; the key sent is the
 * review-ops key and never the reply or read key; and an accepted write leaves
 * an audit row.
 */
class A1LeadsReviewOpsTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private const LIVE = 'http://127.0.0.1:3457';

    private const REVIEW = 'accounts/1/locations/2/reviews/r-1';

    private array $t;

    protected function setUp(): void
    {
        parent::setUp();

        $this->t = $this->provisionTenant('A1-Group');
        config()->set('services.a1leads.organization_id', $this->t['org']->id);
        config()->set('services.a1leads.company_id', $this->t['company']->id);
        config()->set('services.a1leads.review_ops_url', self::LIVE);
        config()->set('services.a1leads.review_ops_key', 'review-ops-key');
        config()->set('services.a1leads.live_reply_url', self::LIVE);
        config()->set('services.a1leads.live_reply_key', 'reply-key');
        config()->set('services.a1leads.api_key', 'read-key');
    }

    private function operator(array $permissions = ['a1leads_gbp.view', 'a1leads_gbp.reply', 'a1leads_gbp.manage'])
    {
        return $this->userWithRole($this->t, 'Operator', $permissions);
    }

    private function audited(string $action): ?AuditEvent
    {
        return $this->inTenant($this->t['org'], fn () => AuditEvent::query()->where('action', $action)->first());
    }

    public function test_a_draft_is_asked_for_with_the_review_ops_key_and_the_signed_in_actor(): void
    {
        Http::fake([self::LIVE.'*' => Http::response(['data' => ['text' => 'Hi Dana, thank you for choosing A1 Chimney.']])]);
        $user = $this->operator();

        $this->signedInAs($user)->postJson('/api/v1/a1leads-gbp/reviews/123/draft', ['actorId' => 'somebody-else'])
            ->assertOk()->assertJsonPath('data.text', 'Hi Dana, thank you for choosing A1 Chimney.');

        Http::assertSent(fn (Request $r) => $r->url() === self::LIVE.'/api/v1/ezbizi/reviews/123/draft'
            && $r->header('X-API-Key') === ['review-ops-key']
            && $r['actorId'] === $user->id);
    }

    public function test_drafting_needs_the_reply_permission_and_nothing_is_asked(): void
    {
        Http::fake();

        $this->signedInAs($this->operator(['a1leads_gbp.view', 'a1leads_gbp.manage']))
            ->postJson('/api/v1/a1leads-gbp/reviews/123/draft')
            ->assertForbidden()->assertJsonPath('error.code', 'no_permission');
        Http::assertNothingSent();
    }

    public function test_another_company_in_view_is_refused_before_a1_leads_hears_anything(): void
    {
        Http::fake();
        $user = $this->operator();
        $other = $this->inTenant($this->t['org'], fn () => Company::create([
            'organization_id' => $this->t['org']->id, 'name' => 'Everdoors',
        ]));

        foreach ([
            ['post', '/api/v1/a1leads-gbp/cards/mark', ['ref' => self::REVIEW, 'status' => 'Done']],
            ['post', '/api/v1/a1leads-gbp/reviews/123/match', ['clear' => true]],
            ['post', '/api/v1/a1leads-gbp/reviews/123/draft', []],
            ['get', '/api/v1/a1leads-gbp/report/status', []],
        ] as [$verb, $url, $body]) {
            $this->signedInAs($user)->withHeader('X-EzBizi-View', 'company:'.$other->id)
                ->json(strtoupper($verb), $url, $body)
                ->assertForbidden()->assertJsonPath('error.code', 'wrong_company_view');
        }
        Http::assertNothingSent();
    }

    public function test_a_card_takes_one_operator_status_or_one_note_and_is_audited(): void
    {
        Http::fake([self::LIVE.'*' => Http::response(['data' => ['ok' => true]])]);
        $user = $this->operator();

        // A1 Leads' own stages are not the operator's to set.
        $this->signedInAs($user)->postJson('/api/v1/a1leads-gbp/cards/mark', ['ref' => self::REVIEW, 'status' => 'Removed'])
            ->assertStatus(422)->assertJsonPath('error.code', 'invalid_status');
        // One change per request.
        $this->signedInAs($user)->postJson('/api/v1/a1leads-gbp/cards/mark', ['ref' => self::REVIEW, 'status' => 'Done', 'add_update' => 'x'])
            ->assertStatus(422)->assertJsonPath('error.code', 'one_change');
        Http::assertNothingSent();

        $this->signedInAs($user)->postJson('/api/v1/a1leads-gbp/cards/mark', ['ref' => self::REVIEW, 'status' => 'Working on it'])
            ->assertOk();
        Http::assertSent(fn (Request $r) => $r->url() === self::LIVE.'/api/v1/ezbizi/reviews/cards/mark'
            && $r['ref'] === self::REVIEW && $r['status'] === 'Working on it' && $r['actorId'] === $user->id
            && ! isset($r['add_update']));

        $this->assertSame('Working on it', $this->audited('a1leads_gbp.card_changed')->metadata['status']);
    }

    public function test_the_writes_need_the_manage_permission(): void
    {
        Http::fake();
        $replier = $this->operator(['a1leads_gbp.view', 'a1leads_gbp.reply']);

        $this->signedInAs($replier)->postJson('/api/v1/a1leads-gbp/cards/mark', ['ref' => self::REVIEW, 'status' => 'Done'])
            ->assertForbidden()->assertJsonPath('error.code', 'no_permission');
        $this->signedInAs($replier)->postJson('/api/v1/a1leads-gbp/reviews/123/match', ['clear' => true])
            ->assertForbidden();
        $this->signedInAs($replier)->postJson('/api/v1/a1leads-gbp/report', ['reviewIds' => [123], 'reason' => 'fake_or_deceptive', 'confirmed' => true])
            ->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_a_match_is_pinned_or_cleared_and_audited(): void
    {
        Http::fake([self::LIVE.'*' => Http::response(['data' => ['ok' => true]])]);
        $user = $this->operator();

        $this->signedInAs($user)->postJson('/api/v1/a1leads-gbp/reviews/123/match', ['tenant' => 'TX', 'jobId' => '700022797'])
            ->assertOk();
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/api/v1/ezbizi/reviews/123/match')
            && $r['tenant'] === 'TX' && $r['jobId'] === '700022797' && $r['actorId'] === $user->id);

        $this->signedInAs($user)->postJson('/api/v1/a1leads-gbp/reviews/123/match', ['clear' => true])->assertOk();
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/match') && ($r->data()['clear'] ?? null) === true && ! isset($r->data()['jobId']));

        $this->assertSame('700022797', $this->audited('a1leads_gbp.match_changed')->metadata['workiz_job_id']);
    }

    public function test_a_report_is_queued_only_when_confirmed(): void
    {
        Http::fake([self::LIVE.'*' => Http::response(['data' => ['queued' => 1]])]);
        $user = $this->operator();

        $this->signedInAs($user)->postJson('/api/v1/a1leads-gbp/report', ['reviewIds' => [123], 'reason' => 'fake_or_deceptive'])
            ->assertStatus(422)->assertJsonPath('error.code', 'not_confirmed');
        Http::assertNothingSent();

        $this->signedInAs($user)->postJson('/api/v1/a1leads-gbp/report', ['reviewIds' => [123], 'reason' => 'fake_or_deceptive', 'confirmed' => true])
            ->assertOk();
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/api/v1/ezbizi/reviews/report/queue')
            && $r['reviewIds'] === [123] && $r['reason'] === 'fake_or_deceptive'
            && $r['confirmed'] === true && $r['businessSlug'] === 'a1-chimney' && $r['actorId'] === $user->id);
        $this->assertNotNull($this->audited('a1leads_gbp.report_queued'));
    }

    public function test_without_its_key_nothing_is_called_and_it_says_so(): void
    {
        Http::fake();
        config()->set('services.a1leads.review_ops_key', null);

        $this->signedInAs($this->operator())->postJson('/api/v1/a1leads-gbp/reviews/123/draft')
            ->assertStatus(503)->assertJsonPath('error.code', 'review_ops_not_configured');
        Http::assertNothingSent();
    }
}
