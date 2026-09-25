<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * The jobs list's Upcoming / Past window, and the counts on its chips.
 *
 * Jose, 2026-09-24, choosing the new Jobs screen: the table, divided by day.
 * The list used to sort `COALESCE(scheduled_for, created_at) DESC`, which put
 * jobs booked months ahead above the week's work. The window opens on today.
 *
 * The counts are the other half. Each chip says how many jobs it would show,
 * so a count leaves ITS OWN filter out and keeps every other — a status count
 * that applied the status filter would read zero on every chip but the lit one.
 */
class JobListWindowTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private const TODAY = '2026-09-24';

    public function test_upcoming_runs_from_today_soonest_first_with_undated_last(): void
    {
        $this->fourJobs();

        $titles = $this->getJson('/api/v1/jobs?when=upcoming&today='.self::TODAY)
            ->assertOk()
            ->json('data.*.title');

        // Today's 8am job counts as upcoming even after 8am: the boundary is
        // midnight, because the day's work is still the day's work.
        $this->assertSame(['Today, early', 'Tomorrow', 'Not dated yet'], $titles);
    }

    public function test_past_is_before_today_most_recent_first(): void
    {
        $this->fourJobs();
        $this->job('Last week', '2026-09-17 10:00');

        $titles = $this->getJson('/api/v1/jobs?when=past&today='.self::TODAY)
            ->assertOk()
            ->json('data.*.title');

        // The undated job is not "past": it is work nobody has placed yet.
        $this->assertSame(['Yesterday', 'Last week'], $titles);
    }

    public function test_each_count_leaves_out_its_own_filter_and_keeps_the_rest(): void
    {
        $this->fourJobs();

        $response = $this->getJson('/api/v1/jobs?when=upcoming&status=scheduled&today='.self::TODAY)
            ->assertOk();

        // The list: upcoming AND scheduled.
        $this->assertSame(['Today, early', 'Not dated yet'], $response->json('data.*.title'));

        // Status chips: every status within the window, the status filter ignored.
        $this->assertEquals(
            ['scheduled' => 2, 'completed' => 1],
            $response->json('meta.status_counts'),
        );

        // Window buttons: each window within the status, the window ignored.
        $this->assertSame(
            ['upcoming' => 2, 'past' => 1],
            $response->json('meta.when_counts'),
        );
    }

    public function test_the_counts_stay_inside_the_reach(): void
    {
        $alpha = $this->fourJobs();

        $beta = $this->provisionTenant('Beta');
        $this->signedInAs($beta['admin']);
        $outsider = $this->postJson('/api/v1/customers', [
            'owning_franchise_id' => $beta['franchise']->id,
            'name'                => 'Somebody Else',
        ])->assertCreated()->json('data.id');
        $this->postJson('/api/v1/jobs', [
            'franchise_id'  => $beta['franchise']->id,
            'customer_id'   => $outsider,
            'title'         => 'Their job',
            'status'        => 'scheduled',
            'scheduled_for' => self::TODAY.' 09:00',
        ])->assertCreated();

        $this->signedInAs($alpha['admin']);

        $meta = $this->getJson('/api/v1/jobs?when=upcoming&today='.self::TODAY)
            ->assertOk()
            ->json('meta');

        $this->assertEquals(['scheduled' => 2, 'completed' => 1], $meta['status_counts']);
        $this->assertSame(['upcoming' => 3, 'past' => 1], $meta['when_counts']);
    }

    public function test_a_day_heading_counts_the_whole_day_when_the_page_cuts_it(): void
    {
        $this->fourJobs();
        $this->job('Today, later', self::TODAY.' 15:00');

        $response = $this->getJson('/api/v1/jobs?when=upcoming&per_page=1&today='.self::TODAY)
            ->assertOk();

        // One row on the page, and its day holds two.
        $this->assertSame(['Today, early'], $response->json('data.*.title'));
        $this->assertSame([self::TODAY => 2], $response->json('meta.day_counts'));

        // The undated heading is counted too, on the page that reaches it.
        $last = $this->getJson('/api/v1/jobs?when=upcoming&per_page=1&page=4&today='.self::TODAY)
            ->assertOk();
        $this->assertSame(['Not dated yet'], $last->json('data.*.title'));
        $this->assertSame(['none' => 1], $last->json('meta.day_counts'));
    }

    public function test_status_counts_are_an_object_when_nothing_matches(): void
    {
        $this->fourJobs();

        $body = $this->getJson('/api/v1/jobs?search=nothing-is-called-this')
            ->assertOk()
            ->getContent();

        // `[]` would reach the client as a list where it reads a map.
        $this->assertStringContainsString('"status_counts":{}', $body);
    }

    public function test_later_pages_carry_no_counts(): void
    {
        $this->fourJobs();

        $meta = $this->getJson('/api/v1/jobs?per_page=1&page=2')
            ->assertOk()
            ->json('meta');

        $this->assertSame(2, $meta['current_page']);
        $this->assertArrayNotHasKey('status_counts', $meta);
        $this->assertArrayNotHasKey('when_counts', $meta);
    }

    public function test_no_window_is_the_old_list(): void
    {
        $this->fourJobs();

        // Every other caller — a customer's history, an export, the older
        // client — asks without a window and must get what it always got.
        $titles = $this->getJson('/api/v1/jobs')->assertOk()->json('data.*.title');

        $this->assertCount(4, $titles);
        $this->assertSame('Tomorrow', $titles[0]);
    }

    public function test_an_unknown_window_is_refused_not_ignored(): void
    {
        $this->fourJobs();

        $this->getJson('/api/v1/jobs?when=soon')->assertUnprocessable();
        $this->getJson('/api/v1/jobs?when=upcoming&today=24/09/2026')->assertUnprocessable();
    }

    /**
     * No date, no window — rather than the server's, which is UTC.
     *
     * Jobs' review of 11f50399: at 21:00 Eastern on the 24th it is already the
     * 25th in UTC, so a server-side "today" filed a 22:00 job as Past an hour
     * before it happened, every evening. Only the reader knows their day, so a
     * window without one is refused, and nothing counts either side of a
     * boundary nobody named.
     */
    public function test_a_window_needs_the_readers_date_and_never_guesses_one(): void
    {
        $this->fourJobs();

        $this->getJson('/api/v1/jobs?when=upcoming')->assertUnprocessable();
        $this->getJson('/api/v1/jobs?when=past')->assertUnprocessable();

        $meta = $this->getJson('/api/v1/jobs')->assertOk()->json('meta');
        $this->assertArrayHasKey('status_counts', $meta);
        $this->assertArrayNotHasKey('when_counts', $meta);
    }

    /**
     * Yesterday, today at 8am, tomorrow, and one with no date — one of them
     * completed, the rest scheduled. Returns the tenant, signed in as its admin.
     */
    private function fourJobs(): array
    {
        $alpha = $this->provisionTenant('Alpha');
        $this->signedInAs($alpha['admin']);
        $this->franchiseId = $alpha['franchise']->id;
        $this->customerId = $this->postJson('/api/v1/customers', [
            'owning_franchise_id' => $alpha['franchise']->id,
            'name'                => 'Alice Roofing',
        ])->assertCreated()->json('data.id');

        $this->job('Yesterday', '2026-09-23 10:00');
        $this->job('Today, early', self::TODAY.' 08:00');
        $this->job('Tomorrow', '2026-09-25 09:00', 'completed');
        $this->job('Not dated yet', null);

        return $alpha;
    }

    private string $franchiseId;

    private string $customerId;

    private function job(string $title, ?string $at, string $status = 'scheduled'): void
    {
        $this->postJson('/api/v1/jobs', [
            'franchise_id'  => $this->franchiseId,
            'customer_id'   => $this->customerId,
            'title'         => $title,
            'status'        => $status,
            'scheduled_for' => $at,
        ])->assertCreated();
    }
}
