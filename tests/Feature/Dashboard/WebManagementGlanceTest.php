<?php

namespace Tests\Feature\Dashboard;

use App\Models\Assignment;
use App\Models\Franchise;
use App\Models\User;
use App\Models\WebSite;
use App\Services\Organizations\ProvisionOrganization;
use App\Support\Dashboard\DashboardPeriod;
use App\Support\Dashboard\Glances\WebManagementGlance;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Web Management glance.
 *
 * ── Why this file exists now ──
 *
 * It did not before, which is how a sentence claiming something the box cannot
 * know survived: the glance is scoped by company reach, and its empty state
 * said "No websites are set up yet." — a statement about the ORGANIZATION,
 * delivered to a reader who can only see part of it. The count line is copy;
 * the empty line was a claim, and a claim needs a test.
 *
 * The strings are pinned deliberately. Two board-wide rulings moved them in one
 * day, and the proposal that reached me was a version behind the queue both
 * times — so docs/UI-DESIGN-QUEUE.md is what decides, and this test is what
 * makes a stale proposal go red instead of shipping.
 */
class WebManagementGlanceTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, mixed> */
    private array $alpha;

    protected function setUp(): void
    {
        parent::setUp();

        // Permissions are a PLATFORM table filled by the module registry rather
        // than by provisioning, so a non-admin role needs the rows first.
        $this->artisan('ezbizi:sync-permissions')->assertSuccessful();

        $r = app(ProvisionOrganization::class)(
            organizationName: 'Alpha',
            administrator: ['name' => 'Admin', 'email' => 'a@alpha.test', 'password' => 'a-real-password'],
            companyName: 'Co', franchiseName: 'F1',
        );

        $this->alpha = [
            'org' => $r['organization'], 'admin' => $r['administrator'],
            'company' => $r['company'], 'franchise' => $r['franchise'],
        ];
    }

    private function inTenant(callable $fn): mixed
    {
        return app(TenantContext::class)->run($this->alpha['org'], $fn);
    }

    private function site(string $host, bool $connected): WebSite
    {
        return $this->inTenant(function () use ($host, $connected) {
            $site = new WebSite([
                'company_id' => $this->alpha['company']->id,
                'host' => $host,
            ]);
            $site->token_hash = hash('sha256', $host.'-token');
            $site->connected_at = $connected ? now() : null;
            $site->save();

            return $site;
        });
    }

    /** @return array{0: Franchise, 1: User} */
    private function branchReader(): array
    {
        return $this->inTenant(function () {
            $franchise = Franchise::create([
                'organization_id' => $this->alpha['org']->id,
                'company_id' => $this->alpha['company']->id,
                'name' => 'Second', 'is_active' => true,
            ]);

            $user = User::create([
                'organization_id' => $this->alpha['org']->id,
                'role_id' => \Tests\Concerns\BranchManagerRole::idFor($this->alpha['admin']),
                'name' => 'Branch', 'email' => 'branch@alpha.test',
                'password' => 'a-real-password', 'is_active' => true,
            ]);

            Assignment::create([
                'organization_id' => $this->alpha['org']->id,
                'user_id' => $user->id, 'assigned_franchise_id' => $franchise->id,
            ]);

            return [$franchise, $user];
        });
    }

    /** @return array<string, mixed> */
    private function glance(User $user): array
    {
        return app(WebManagementGlance::class)
            ->resolve($user, DashboardPeriod::for($user), [])
            ->toArray()['data'];
    }

    /**
     * The sentence describes the BOX, not the world.
     *
     * A franchise-assigned reader sees no sites by design — the brand's domain
     * is not theirs to manage. The old wording told them none were SET UP,
     * which is a fact about the organization they cannot see and which was, in
     * this exact fixture, false.
     */
    public function test_a_reader_who_sees_none_is_not_told_the_business_has_none(): void
    {
        $this->site('a-1chimney.com', connected: true);
        $this->site('nj.a-1chimney.com', connected: true);

        [, $branch] = $this->branchReader();

        $data = $this->glance($branch);

        $this->assertTrue($data['empty']);
        $this->assertSame('No websites to show.', $data['sentence']);

        // The control: the organization really does have websites, so a
        // sentence claiming otherwise would have been wrong rather than merely
        // clumsy. Without this the assertion above passes on an empty org too.
        $this->assertSame(2, $this->inTenant(fn () => WebSite::query()->count()));
    }

    /** With nothing anywhere, the same sentence is simply true. */
    public function test_an_organization_with_no_websites_says_the_same_thing(): void
    {
        $data = $this->glance($this->alpha['admin']);

        $this->assertTrue($data['empty']);
        $this->assertSame('No websites to show.', $data['sentence']);
    }

    /**
     * The count line carries a PARTICIPLE, not a bare number.
     *
     * "in reach" went because a reader cannot parse it. What replaced it is NOT
     * "2 websites.": Services measured all 23 count sentences across 13 glances
     * and every one carries a qualifier, so a bare count would have been the
     * only one on the board. "registered" is this module's own verb.
     */
    public function test_the_count_says_what_the_box_holds_and_nothing_more(): void
    {
        $this->site('a-1chimney.com', connected: true);
        $this->site('nj.a-1chimney.com', connected: false);

        $data = $this->glance($this->alpha['admin']);

        $this->assertFalse($data['empty']);
        $this->assertContains('1 website is waiting for its plugin.', $data['sentences']);
        $this->assertContains('2 websites registered.', $data['sentences']);

        foreach ($data['sentences'] as $sentence) {
            $this->assertStringNotContainsString('in reach', $sentence);
            // "yet" went with it: all six boxes carrying it were scoped too.
            $this->assertStringNotContainsString(' yet', $sentence);
        }
    }

    /** Websites lead, waiting-for-plugin follows; a snapshot, so no previous. */
    public function test_figures_are_websites_then_waiting_for_plugin(): void
    {
        $this->site('a-1chimney.com', connected: true);
        $this->site('nj.a-1chimney.com', connected: false);
        $this->site('ny.a-1chimney.com', connected: true);

        $data = $this->glance($this->alpha['admin']);

        $this->assertSame([
            ['key' => 'sites', 'label' => 'websites', 'value' => 3, 'previous' => null],
            ['key' => 'waiting', 'label' => 'waiting for plugin', 'value' => 1, 'previous' => null],
        ], $data['figures']);
    }

    /** Dashboard, 2026-10-05: the rows that need somebody, and none when nothing does. */
    public function test_attention_names_the_quiet_and_the_waiting(): void
    {
        $this->site('a-1chimney.com', connected: true);
        $this->assertArrayNotHasKey('attention', $this->glance($this->alpha['admin']));

        $quiet = $this->site('ny.a-1chimney.com', connected: true);
        $this->inTenant(fn () => $quiet->forceFill(['went_quiet_at' => now()])->save());
        $this->site('nj.a-1chimney.com', connected: false);

        $this->assertSame([
            ['tone' => 'bad', 'text' => '1 website stopped reading EzBizi.'],
            ['tone' => 'caution', 'text' => '1 website is waiting for its plugin.'],
        ], $this->glance($this->alpha['admin'])['attention']);
    }

    public function test_one_website_is_singular(): void
    {
        $this->site('a-1chimney.com', connected: false);

        $data = $this->glance($this->alpha['admin']);

        $this->assertContains('1 website registered.', $data['sentences']);
    }

    /** Nothing waiting is a different sentence, and it still has no qualifier. */
    public function test_every_website_connected_says_so(): void
    {
        $this->site('a-1chimney.com', connected: true);
        $this->site('nj.a-1chimney.com', connected: true);

        $data = $this->glance($this->alpha['admin']);

        $this->assertSame(['All 2 websites connected.'], $data['sentences']);
    }

    /**
     * The box sends what it draws, and nothing else (Dashboard's rule).
     *
     * This glance used to send `organization_total` — an unfiltered
     * `WebSite::query()->count()` beside a body scoped by company and view. No
     * client read it, in this glance or in the four others that carried it,
     * which is the tell that it was copied rather than decided: a figure with
     * no reader is a query on every dashboard load and a disclosure on every
     * response, bought for nothing. A franchise reader received the whole
     * organization's count, invisible on screen and plain in the network tab.
     *
     * REMOVING IT CHANGED NO TEST, which is why this one exists. The suite
     * asserted the sentences and never the key, so the field could be dropped
     * — or copied back in by the next person starting from this file — with
     * everything still green. Asserted by absence, because absence is the whole
     * property.
     *
     * Not a ban on extra data. CustomersGlance keeps its copy legitimately:
     * §5.2 shares the book, and its docblock argues the case at the line that
     * sends it. That is the price — one sentence, where it is sent.
     */
    public function test_the_box_sends_no_figure_it_does_not_draw(): void
    {
        $this->site('a-1chimney.com', connected: true);

        $data = $this->glance($this->alpha['admin']);

        $this->assertArrayNotHasKey('organization_total', $data);

        // The control: the payload is populated, so the assertion above is
        // about the key being withheld rather than about an empty response.
        $this->assertArrayHasKey('sentences', $data);
        $this->assertFalse($data['empty']);
    }
}
