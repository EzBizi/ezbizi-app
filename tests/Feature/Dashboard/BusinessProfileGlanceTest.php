<?php

namespace Tests\Feature\Dashboard;

use App\Models\BusinessLocation;
use App\Models\User;
use App\Services\Organizations\ProvisionOrganization;
use App\Support\Dashboard\DashboardPeriod;
use App\Support\Dashboard\Glances\BusinessProfileGlance;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Business Profile box sends what it draws, and nothing else.
 *
 * ── Why the file exists at all ───────────────────────────────────
 *
 * It had no suite, and that is how `organization_total` survived here: a bare
 * `BusinessLocation::query()->count()` with no reach clause, shipped in the
 * same payload as sentences built from a scoped collection. Two numbers
 * answering different questions, one of them the whole organization's, to a
 * reader who may see one franchise and can open the network tab. Removing it
 * changed no test, which is also how the same field reached five glances —
 * each written by starting from the last.
 *
 * So this suite is not "the glance works"; it is the one assertion that the
 * removal cannot be undone silently.
 */
class BusinessProfileGlanceTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, mixed> */
    private array $alpha;

    protected function setUp(): void
    {
        parent::setUp();

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

    private function location(bool $live = true): BusinessLocation
    {
        static $n = 0;
        $n++;

        return app(TenantContext::class)->run($this->alpha['org'], fn () => BusinessLocation::create([
            'organization_id' => $this->alpha['org']->id,
            'company_id' => $this->alpha['company']->id,
            'franchise_id' => $this->alpha['franchise']->id,
            'remote_name' => "accounts/a/locations/l-{$n}",
            'account_name' => 'accounts/a',
            'title' => "A-1 Chimney {$n}",
            'verification_state' => $live ? BusinessLocation::VERIFIED : 'UNVERIFIED',
            'is_published' => $live,
        ]));
    }

    /** @return array<string, mixed> */
    private function glance(User $user): array
    {
        return app(BusinessProfileGlance::class)
            ->resolve($user, DashboardPeriod::for($user), [])
            ->toArray()['data'];
    }

    /**
     * An absence needs a populated payload above it.
     *
     * On its own, "organization_total is missing" is satisfied by a payload
     * that is empty, errored, or scoped to nothing — all three of which are
     * failures this box has had. With `empty` false and the sentences asserted
     * PRESENT, the missing key means withheld.
     *
     * Pinned as the whole key list rather than by name, so a sixth
     * organization-wide figure added by the same copying cannot pass it.
     */
    public function test_the_box_sends_no_figure_it_does_not_draw(): void
    {
        $this->location();
        $this->location(live: false);

        $data = $this->glance($this->alpha['admin']);

        $this->assertFalse($data['empty']);
        $this->assertNotEmpty($data['sentences']);

        $this->assertSame(
            ['empty', 'sentences', 'figures', 'notes'],
            array_keys($data),
            'the Business Profile box draws its sentences and its figures; any other '
            .'key is a query on every dashboard load for a figure nobody sees, and an '
            .'organization-wide one reaches a reader scoped to one franchise',
        );
    }

    /**
     * The control for the assertion above: the box still says the thing it is
     * for, so a payload stripped to nothing cannot pass this file.
     */
    public function test_the_listing_google_is_not_showing_leads_the_box(): void
    {
        $this->location();
        $this->location(live: false);

        $data = $this->glance($this->alpha['admin']);

        $this->assertSame('1 listing is not showing on Google.', $data['sentences'][0]);
        $this->assertSame('2 listings connected.', $data['sentences'][1]);
    }

    /** Listings connected leads, not showing follows; a snapshot, so no previous. */
    public function test_figures_are_listings_connected_then_not_showing(): void
    {
        $this->location();
        $this->location();
        $this->location(live: false);

        $data = $this->glance($this->alpha['admin']);

        $this->assertSame([
            ['key' => 'listings', 'label' => 'listings connected', 'value' => 3, 'previous' => null],
            ['key' => 'not_live', 'label' => 'not showing', 'value' => 1, 'previous' => null],
        ], $data['figures']);
    }
}
