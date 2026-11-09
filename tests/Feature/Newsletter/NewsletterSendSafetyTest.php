<?php

namespace Tests\Feature\Newsletter;

use App\Jobs\SendNewsletter;
use App\Models\AutomationEvent;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Franchise;
use App\Models\Newsletter;
use App\Models\NewsletterAudience;
use App\Models\NewsletterSend;
use App\Models\NewsletterUnsubscribe;
use App\Models\SocialConnection;
use App\Support\Newsletter\BlockRenderer;
use App\Support\Social\SocialProvider;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Newsletter review 2026-11-09, section A: who a send reaches, what it says it
 * is from, and that it always ends in sent or failed.
 */
class NewsletterSendSafetyTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private const ENDPOINT = '/api/v1/newsletters';

    /** @var list<string> */
    private array $left = [];

    /** @var list<array<string, string>> */
    private array $refusals = [];

    // ── A1 / A5 / A15: whose customers ──────────────────────────

    public function test_a_company_customers_list_excludes_a_sibling_companys_customers(): void
    {
        $t = $this->provisionTenant('Scoped');
        $this->inTenant($t['org'], function () use ($t) {
            $sibling = Company::create(['name' => 'Everdoors']);
            $theirs = Franchise::create(['company_id' => $sibling->id, 'name' => 'Everdoors F1']);
            $this->customer($t['franchise']->id, 'ours@example.com');
            $this->customer($theirs->id, 'theirs@example.com');
        });

        $this->sendCustomersMailing($t, 'company', $t['company']->id);

        $this->assertSame(['ours@example.com'], $this->pushed());
    }

    public function test_inactive_and_opted_out_customers_are_not_pushed_and_fname_is_the_first_name(): void
    {
        $t = $this->provisionTenant('Consent');
        $this->inTenant($t['org'], function () use ($t) {
            $this->customer($t['franchise']->id, 'kept@example.com', 'Maria del Mar');
            $this->customer($t['franchise']->id, 'inactive@example.com')->forceFill(['is_active' => false])->save();
            $this->customer($t['franchise']->id, 'optout@example.com')->forceFill(['email_opt_out_at' => now()])->save();
        });

        $this->sendCustomersMailing($t, 'company', $t['company']->id);

        $this->assertSame(['kept@example.com'], $this->pushed());
        Http::assertSent(fn ($r) => $this->isBatch($r)
            && (array) $r['members'][0]['merge_fields'] === ['FNAME' => 'Maria', 'LNAME' => 'del Mar']);
    }

    // ── A2 / A3: batches, and one bad address ───────────────────

    public function test_members_go_500_per_call_and_existing_members_are_never_updated(): void
    {
        $t = $this->provisionTenant('Batches');
        $this->inTenant($t['org'], function () use ($t) {
            foreach (range(1, 1201) as $i) {
                $this->customer($t['franchise']->id, "p{$i}@example.com");
            }
        });

        $this->sendCustomersMailing($t, 'organization', $t['org']->id);

        $batches = Http::recorded()->map(fn ($pair) => $pair[0])->filter(fn ($r) => $this->isBatch($r))->values();
        $this->assertSame([500, 500, 201], $batches->map(fn ($r) => count($r['members']))->all());
        $this->assertTrue($batches->every(fn ($r) => $r['update_existing'] === false));
        Http::assertNotSent(fn ($r) => $r->method() === 'PUT' && str_contains($r->url(), '/members/'));
    }

    public function test_a_refused_address_is_skipped_and_counted_on_the_send(): void
    {
        $t = $this->provisionTenant('Refused');
        $this->inTenant($t['org'], function () use ($t) {
            $this->customer($t['franchise']->id, 'good@example.com');
            $this->customer($t['franchise']->id, 'none@none.com');
            $this->customer($t['franchise']->id, 'old@example.com');
        });
        $this->refusals = [
            ['email_address' => 'none@none.com', 'error' => 'looks fake', 'error_code' => 'ERROR_GENERIC'],
            ['email_address' => 'old@example.com', 'error' => 'already a member', 'error_code' => 'ERROR_CONTACT_EXISTS'],
        ];

        $send = $this->sendCustomersMailing($t, 'organization', $t['org']->id);

        $this->assertSame(NewsletterSend::STATUS_SENT, $send->status);
        $this->assertSame('1 address was refused by Mailchimp and skipped.', $send->notice);
    }

    // ── A4: unsubscribes ────────────────────────────────────────

    public function test_mailchimp_unsubscribes_are_recorded_and_never_pushed_as_new(): void
    {
        $t = $this->provisionTenant('Left');
        $this->inTenant($t['org'], function () use ($t) {
            $this->customer($t['franchise']->id, 'stay@example.com');
            $this->customer($t['franchise']->id, 'Gone@example.com');
        });
        $this->left = ['gone@example.com'];

        $this->sendCustomersMailing($t, 'company', $t['company']->id);

        $this->assertSame(['stay@example.com'], $this->pushed());
        $this->inTenant($t['org'], function () use ($t) {
            $row = NewsletterUnsubscribe::query()->sole();
            $this->assertSame(['gone@example.com', 'company', $t['company']->id],
                [$row->email, $row->scope_type, $row->scope_id]);
            $this->assertSame(1, AutomationEvent::where('event_key', 'newsletter.unsubscribed')->count());
        });
    }

    public function test_an_organization_opt_out_suppresses_a_franchise_mailing(): void
    {
        $t = $this->provisionTenant('Covering');
        $this->inTenant($t['org'], function () use ($t) {
            $this->customer($t['franchise']->id, 'stay@example.com');
            $this->customer($t['franchise']->id, 'orgwide@example.com');
            $this->customer($t['franchise']->id, 'companywide@example.com');
            NewsletterUnsubscribe::create(['scope_type' => 'organization', 'scope_id' => $t['org']->id,
                'email' => 'orgwide@example.com', 'unsubscribed_at' => now()]);
            NewsletterUnsubscribe::create(['scope_type' => 'company', 'scope_id' => $t['company']->id,
                'email' => 'companywide@example.com', 'unsubscribed_at' => now()]);
        });

        $this->sendCustomersMailing($t, 'franchise', $t['franchise']->id);

        $this->assertSame(['stay@example.com'], $this->pushed());
    }

    // ── A2: nothing stays "sending" ─────────────────────────────

    public function test_a_killed_job_marks_its_send_and_newsletter_failed(): void
    {
        $t = $this->provisionTenant('Killed');
        [$newsletter, $send] = $this->sendingRow($t, now());

        app(TenantContext::class)->forget();
        (new SendNewsletter($send->id, 'x'))->failed(new \RuntimeException('Timed out'));

        $this->inTenant($t['org'], function () use ($newsletter, $send) {
            $this->assertSame(NewsletterSend::STATUS_FAILED, $send->fresh()->status);
            $this->assertStringContainsString('outcome is unknown', (string) $send->fresh()->failure);
            $this->assertSame(Newsletter::STATUS_FAILED, $newsletter->fresh()->status);
        });
    }

    public function test_the_sweep_fails_a_send_stuck_for_30_minutes_and_leaves_a_fresh_one(): void
    {
        $t = $this->provisionTenant('Stuck');
        [$stuckLetter, $stuck] = $this->sendingRow($t, now()->subMinutes(31));
        [, $fresh] = $this->sendingRow($t, now()->subMinutes(5));

        app(TenantContext::class)->forget();
        $this->artisan('ezbizi:send-due-newsletters')->assertSuccessful();

        $this->inTenant($t['org'], function () use ($stuckLetter, $stuck, $fresh) {
            $this->assertSame(NewsletterSend::STATUS_FAILED, $stuck->fresh()->status);
            $this->assertSame(Newsletter::STATUS_FAILED, $stuckLetter->fresh()->status);
            $this->assertSame(NewsletterSend::STATUS_SENDING, $fresh->fresh()->status);
        });
    }

    public function test_the_job_runs_where_long_jobs_run_inside_their_retry_window(): void
    {
        config(['queue.default' => 'database']);
        $job = new SendNewsletter('s', 'c');

        $this->assertSame('database-long', $job->connection);
        $this->assertSame('long', $job->queue);
        $this->assertGreaterThan($job->timeout, (int) config('queue.connections.database-long.retry_after'));
    }

    // ── A6 / A8: what the composer gets back and saves ──────────

    public function test_a_later_time_round_trips_and_an_unchanged_past_time_still_saves(): void
    {
        $t = $this->provisionTenant('Later');
        $id = $this->draft($t);
        $when = now()->addDays(2)->format('Y-m-d H:i');

        $this->signedInAs($t['admin'])->putJson(self::ENDPOINT."/{$id}", ['subject' => 'A', 'scheduled_for' => $when])
            ->assertOk()->assertJsonPath('data.scheduled_for', $when);

        // An older client hands back the stored seconds.
        $this->signedInAs($t['admin'])->putJson(self::ENDPOINT."/{$id}", ['subject' => 'B', 'scheduled_for' => $when.':00'])
            ->assertOk();

        // The time has since passed; fixing a typo must still save.
        $this->inTenant($t['org'], fn () => Newsletter::findOrFail($id)
            ->forceFill(['scheduled_for' => now()->subDay()->format('Y-m-d H:i:s')])->save());
        $past = $this->signedInAs($t['admin'])->getJson(self::ENDPOINT)->json('data.0.scheduled_for');
        $this->signedInAs($t['admin'])->putJson(self::ENDPOINT."/{$id}", ['subject' => 'C', 'scheduled_for' => $past])
            ->assertOk();
    }

    public function test_custom_html_comes_back_and_survives_a_save_that_omits_it(): void
    {
        $t = $this->provisionTenant('Html');
        $id = $this->signedInAs($t['admin'])->postJson(self::ENDPOINT, [
            'subject' => 'Custom', 'html_override' => '<p>Mine</p>',
        ])->assertCreated()->assertJsonPath('data.html_override', '<p>Mine</p>')->json('data.id');

        $this->signedInAs($t['admin'])->putJson(self::ENDPOINT."/{$id}", ['subject' => 'Renamed'])
            ->assertOk()->assertJsonPath('data.html_override', '<p>Mine</p>');

        $this->signedInAs($t['admin'])->putJson(self::ENDPOINT."/{$id}", ['subject' => 'Blocks', 'html_override' => null])
            ->assertOk()->assertJsonPath('data.html_override', null);
    }

    // ── A9 / A10 / A11: how it looks and who it is from ─────────

    public function test_columns_stack_under_620_pixels(): void
    {
        $html = app(BlockRenderer::class)->render([], ['company' => 'A', 'address' => 'B', 'unsubscribe_url' => 'u']);

        $this->assertMatchesRegularExpression('/<head>.*@media[^{]*max-width:620px[^{]*\{\.ez-col\{display:block!important;width:100%!important/s', $html);
    }

    public function test_a_company_mailing_is_from_the_company_with_its_address(): void
    {
        $t = $this->provisionTenant('Group');
        $this->inTenant($t['org'], function () use ($t) {
            $t['org']->forceFill(['address' => '1 Group Way'])->save();
            $t['company']->forceFill(['name' => 'A-1 Chimney', 'address_line1' => '800 SE 4th Ave',
                'city' => 'Hallandale Beach', 'state' => 'FL', 'postal_code' => '33009'])->save();
            $this->customer($t['franchise']->id, 'ours@example.com');
        });

        $send = $this->sendCustomersMailing($t, 'company', $t['company']->id);
        $newsletter = $this->inTenant($t['org'], fn () => $send->newsletter);

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/campaigns')
            && $r['settings']['from_name'] === 'A-1 Chimney');
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/content')
            && str_contains($r['html'], '800 SE 4th Ave, Hallandale Beach FL 33009')
            && ! str_contains($r['html'], '1 Group Way'));

        $preview = (string) $this->signedInAs($t['admin'])
            ->getJson(self::ENDPOINT."/{$newsletter->id}/preview")->assertOk()->json('data.html');
        $this->assertStringContainsString('A-1 Chimney', $preview);
        $this->assertStringContainsString('800 SE 4th Ave, Hallandale Beach FL 33009', $preview);
    }

    public function test_send_is_refused_when_there_is_no_postal_address(): void
    {
        $t = $this->provisionTenant('Nowhere');
        $this->connection($t);
        $audience = $this->customersAudience($t, 'organization', $t['org']->id);
        Http::fake();
        $id = $this->signedInAs($t['admin'])->postJson(self::ENDPOINT, ['subject' => 'X', 'audience_id' => $audience->id])
            ->assertCreated()->json('data.id');

        $this->signedInAs($t['admin'])->postJson(self::ENDPOINT."/{$id}/send")
            ->assertUnprocessable()
            ->assertJsonPath('errors.subject.0', fn (string $m) => str_contains($m, 'no postal address'));
        Http::assertNothingSent();
    }

    public function test_failed_mailings_have_a_filter_and_a_count(): void
    {
        $t = $this->provisionTenant('Failures');
        $id = $this->draft($t);
        $this->draft($t);
        $this->inTenant($t['org'], fn () => Newsletter::findOrFail($id)->forceFill(['status' => 'failed'])->save());

        $this->signedInAs($t['admin'])->getJson(self::ENDPOINT.'?status=failed')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('stats.failed', 1);
    }

    // ── helpers ─────────────────────────────────────────────────

    private function sendCustomersMailing(array $t, string $scopeType, string $scopeId): NewsletterSend
    {
        $connection = $this->connection($t);
        $audience = $this->customersAudience($t, $scopeType, $scopeId);
        $this->fakeMailchimp($t);

        $send = $this->inTenant($t['org'], function () use ($t, $audience, $connection, $scopeType, $scopeId) {
            if ($t['org']->fresh()->formattedAddress() === null) {
                $t['org']->forceFill(['address' => '1 Main St'])->save();
            }
            $n = new Newsletter(['subject' => 'Hello', 'blocks' => [], 'status' => Newsletter::STATUS_SENDING]);
            $n->forceFill(['scope_type' => $scopeType, 'scope_id' => $scopeId, 'newsletter_audience_id' => $audience->id])->save();

            return $n->sends()->create(['connection_id' => $connection->id, 'provider' => 'mailchimp',
                'status' => NewsletterSend::STATUS_SENDING, 'recipients' => 0]);
        });

        app(TenantContext::class)->forget();
        app()->call([new SendNewsletter($send->id, $connection->id), 'handle']);

        return $this->inTenant($t['org'], fn () => $send->fresh());
    }

    /** @return array{0: Newsletter, 1: NewsletterSend} */
    private function sendingRow(array $t, $startedAt): array
    {
        return $this->inTenant($t['org'], function () use ($t, $startedAt) {
            $n = new Newsletter(['subject' => 'Big list', 'blocks' => [], 'status' => Newsletter::STATUS_SENDING]);
            $n->forceFill(['scope_type' => 'organization', 'scope_id' => $t['org']->id])->save();
            $s = $n->sends()->create(['provider' => 'mailchimp', 'status' => NewsletterSend::STATUS_SENDING, 'recipients' => 0]);
            $s->forceFill(['started_at' => $startedAt])->save();

            return [$n, $s];
        });
    }

    private function fakeMailchimp(array $t): void
    {
        $everyone = $this->inTenant($t['org'], fn () => Customer::query()->pluck('email')
            ->map(fn ($e) => ['email_address' => mb_strtolower($e), 'status' => 'subscribed'])->all());

        Http::fake(function ($r) use ($everyone) {
            $url = $r->url();
            if ($r->method() === 'GET' && str_contains($url, '/members')) {
                parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
                $members = ($q['status'] ?? '') === 'subscribed'
                    ? $everyone
                    : (($q['status'] ?? '') === 'unsubscribed'
                        ? array_map(fn ($e) => ['email_address' => $e, 'status' => 'unsubscribed'], $this->left)
                        : []);

                return Http::response(['members' => $members, 'total_items' => count($members)]);
            }
            if ($this->isBatch($r)) {
                return Http::response(['new_members' => [], 'errors' => $this->refusals, 'error_count' => count($this->refusals)]);
            }
            if (str_ends_with($url, '/segments')) {
                return Http::response(['id' => 5]);
            }
            if (str_ends_with($url, '/campaigns')) {
                return Http::response(['id' => 'camp', 'recipients' => ['recipient_count' => 1]]);
            }

            return Http::response([]);
        });
    }

    private function isBatch($r): bool
    {
        return $r->method() === 'POST' && str_ends_with($r->url(), '/lists/list-1');
    }

    /** @return list<string> every address pushed to the list, in order */
    private function pushed(): array
    {
        return Http::recorded()->map(fn ($pair) => $pair[0])->filter(fn ($r) => $this->isBatch($r))
            ->flatMap(fn ($r) => array_column($r['members'], 'email_address'))->values()->all();
    }

    private function customer(string $franchiseId, string $email, string $name = 'Pat Doe'): Customer
    {
        return Customer::create(['owning_franchise_id' => $franchiseId, 'number' => 'C-'.$email,
            'name' => $name, 'email' => $email, 'is_active' => true]);
    }

    private function draft(array $t): string
    {
        return $this->signedInAs($t['admin'])->postJson(self::ENDPOINT, ['subject' => 'A mailing'])
            ->assertCreated()->json('data.id');
    }

    private function customersAudience(array $t, string $scopeType, string $scopeId): NewsletterAudience
    {
        return $this->inTenant($t['org'], function () use ($scopeType, $scopeId) {
            $a = new NewsletterAudience(['name' => 'Customers', 'kind' => NewsletterAudience::KIND_CUSTOMERS,
                'mailchimp_list_id' => 'list-1']);
            $a->forceFill(['scope_type' => $scopeType, 'scope_id' => $scopeId])->save();

            return $a;
        });
    }

    private function connection(array $t): SocialConnection
    {
        return $this->inTenant($t['org'], function () use ($t) {
            $c = new SocialConnection;
            $c->forceFill(['scope_type' => 'organization', 'scope_id' => $t['org']->id,
                'provider' => SocialProvider::Mailchimp->value, 'remote_id' => 'us14:owner@example.com',
                'display_name' => 'Owner login name', 'handle' => 'owner@example.com']);
            $c->connect(['api_key' => 'abc-us14', 'account_email' => 'owner@example.com']);
            $c->save();

            return $c;
        });
    }
}
