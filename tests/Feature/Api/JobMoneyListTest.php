<?php

namespace Tests\Feature\Api;

use App\Models\Customer;
use App\Models\Job;
use App\Models\WorkizJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * The jobs list filtered and totalled by Workiz's money (spec 2026-09-29 §3),
 * for people holding the Financials toggle only.
 */
class JobMoneyListTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private array $alpha;

    /** @var array<string, string> fixture name => job id */
    private array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->alpha = $this->provisionTenant('Alpha');
        $this->ids = [
            'unpaid'  => $this->job(['JobTotalPrice' => 100, 'JobAmountDue' => 100, 'PaymentDueDate' => '2026-10-05 00:00:00']),
            'late'    => $this->job(['JobTotalPrice' => 50, 'JobAmountDue' => 50, 'PaymentDueDate' => '2026-09-01 00:00:00']),
            // Due TODAY is not overdue: the boundary, which only this row can show.
            'today'   => $this->job(['JobTotalPrice' => 70, 'JobAmountDue' => 70, 'PaymentDueDate' => '2026-09-29 00:00:00']),
            'partial' => $this->job(['JobTotalPrice' => 200, 'JobAmountDue' => 80, 'PaymentDueDate' => '2026-10-05 00:00:00']),
            'paid'    => $this->job(['JobTotalPrice' => 300, 'JobAmountDue' => 0]),
            'credit'  => $this->job(['JobTotalPrice' => 40, 'JobAmountDue' => -10]),
        ];
    }

    public function test_each_state_filters(): void
    {
        $this->assertSame(['late', 'today', 'unpaid'], $this->filtered('unpaid'));
        $this->assertSame(['partial'], $this->filtered('partial'));
        $this->assertSame(['paid'], $this->filtered('paid'));
        $this->assertSame(['credit'], $this->filtered('credit'));
        $this->assertSame(['late'], $this->filtered('overdue'));
    }

    /**
     * Faceted like the chips: the money filter is left out of its own totals.
     * Total 100+50+70+200+300+40 = $760; due counts positives only,
     * 100+50+70+80 = $300; paid is the rest, $460; overdue is `late` alone, $50
     * -- `today` is due today, not overdue.
     */
    public function test_totals_ignore_the_money_filter_and_keep_the_others(): void
    {
        $meta = $this->signedInAs($this->alpha['admin'])
            ->getJson('/api/v1/jobs?money=paid&today=2026-09-29')->assertOk()->json('meta');

        $this->assertSame(['total' => 76000, 'paid' => 46000, 'due' => 30000, 'overdue' => 5000],
            $meta['money_totals']['USD']);
    }

    /**
     * The money chips' counts, faceted like the status chips: the money filter
     * is left out, everything else kept. `unpaid` counts `late` and `today`
     * too, since overdue is a date on top of a state; `overdue` is `late` only.
     */
    public function test_money_counts_are_faceted_like_the_chips(): void
    {
        $meta = $this->signedInAs($this->alpha['admin'])
            ->getJson('/api/v1/jobs?money=paid&today=2026-09-29')->assertOk()->json('meta');

        $this->assertSame(['unpaid' => 3, 'partial' => 1, 'overdue' => 1, 'paid' => 1, 'credit' => 1, 'due' => 4],
            $meta['money_counts']);
    }

    public function test_a_row_carries_its_money(): void
    {
        $row = collect($this->signedInAs($this->alpha['admin'])->getJson('/api/v1/jobs')->json('data'))
            ->firstWhere('id', $this->ids['partial']);

        $this->assertSame(['total' => 20000, 'due' => 8000, 'state' => 'partial', 'due_on' => '2026-10-05', 'currency' => 'USD'], $row['money']);
    }

    public function test_without_the_toggle_the_filter_is_refused_and_nothing_leaks(): void
    {
        $dispatcher = $this->userWithRole($this->alpha, 'Dispatcher', ['jobs.view']);

        $this->signedInAs($dispatcher)->getJson('/api/v1/jobs?money=unpaid')
            ->assertForbidden()->assertJsonPath('message', 'Money on jobs is hidden for your role.');

        $body = $this->signedInAs($dispatcher)->getJson('/api/v1/jobs')->assertOk()->json();
        $this->assertArrayNotHasKey('money_totals', $body['meta']);
        $this->assertArrayNotHasKey('money_counts', $body['meta']);
        $this->assertArrayNotHasKey('money', $body['data'][0]);
    }

    public function test_totals_are_an_object_even_when_empty(): void
    {
        $raw = $this->signedInAs($this->alpha['admin'])
            ->getJson('/api/v1/jobs?search=nothing-matches-this')->assertOk()->getContent();

        $this->assertStringContainsString('"money_totals":{}', $raw);
    }

    /** @return list<string> the fixture names the filter returned, sorted */
    private function filtered(string $state): array
    {
        $ids = array_column($this->signedInAs($this->alpha['admin'])
            ->getJson("/api/v1/jobs?money={$state}&today=2026-09-29")->assertOk()->json('data'), 'id');
        $names = array_keys(array_intersect($this->ids, $ids));
        sort($names);

        return $names;
    }

    private function job(array $payload): string
    {
        return $this->inTenant($this->alpha['org'], function () use ($payload) {
            $customer = Customer::create(['owning_franchise_id' => $this->alpha['franchise']->id,
                'number' => 'C-'.bin2hex(random_bytes(3)), 'name' => 'A Customer']);
            $job = Job::create(['franchise_id' => $this->alpha['franchise']->id, 'customer_id' => $customer->id,
                'number' => 'J-'.bin2hex(random_bytes(3)), 'title' => 'A job', 'status' => 'scheduled',
                'currency' => 'USD']);
            $row = WorkizJob::create(['franchise_id' => $this->alpha['franchise']->id,
                'remote_id' => strtoupper(bin2hex(random_bytes(4))), 'status' => 'Submitted',
                'payload' => $payload, 'synced_at' => now()]);
            $row->job_id = $job->id;
            $row->save();

            return $job->id;
        });
    }
}
