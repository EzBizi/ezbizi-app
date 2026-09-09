<?php

namespace Tests\Feature\Dashboard;

use App\Models\Assignment;
use App\Models\Franchise;
use App\Models\Lead;
use App\Models\User;
use App\Services\Organizations\ProvisionOrganization;
use App\Support\Dashboard\DashboardPeriod;
use App\Support\Dashboard\Glances\LeadsGlance;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Leads glance, and the one sentence on it that was a claim.
 *
 * ── Why this file exists ──
 *
 * It did not before, which is how "No enquiries yet." survived on the most
 * scope-sensitive box on the board. The glance filters by franchise reach, so
 * the reader it speaks to may be seeing a slice; "yet" told them the BUSINESS
 * had none. In this module that is the worst available error: the standing
 * instruction on Leads is that not a single enquiry may be missed, and a box
 * that says "none yet" to somebody whose branch is empty reports the quiet
 * week on the morning somebody else is fielding forty.
 *
 * The strings are pinned deliberately. A board-wide ruling moved them
 * (UI/UX 30ff2369) and `docs/UI-DESIGN-QUEUE.md` is what decides; this test is
 * what makes a stale proposal go red instead of shipping.
 */
class LeadsGlanceTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, mixed> */
    private array $alpha;

    protected function setUp(): void
    {
        parent::setUp();

        // Permissions are a PLATFORM table filled by the module registry
        // rather than by provisioning, so a scoped reader needs the rows.
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

    private function lead(?Franchise $franchise, string $status = Lead::STATUS_NEW): Lead
    {
        static $n = 0;
        $n++;

        return $this->inTenant(fn () => Lead::create([
            'organization_id' => $this->alpha['org']->id,
            'company_id'      => $this->alpha['company']->id,
            'franchise_id'    => $franchise?->id,
            'source'          => Lead::SOURCE_LOCAL_SERVICES,
            'remote_id'       => 'L-'.$n,
            'status'          => $status,
            'received_at'     => now()->subHour(),
        ]));
    }

    /** @return array{0: Franchise, 1: User} */
    private function branchReader(): array
    {
        return $this->inTenant(function () {
            $franchise = Franchise::create([
                'organization_id' => $this->alpha['org']->id,
                'company_id'      => $this->alpha['company']->id,
                'name'            => 'Second', 'is_active' => true,
            ]);

            $user = User::create([
                'organization_id' => $this->alpha['org']->id,
                'role_id'         => $this->alpha['admin']->role_id,
                'name'            => 'Branch', 'email' => 'branch@alpha.test',
                'password'        => 'a-real-password', 'is_active' => true,
            ]);

            Assignment::create([
                'organization_id'     => $this->alpha['org']->id,
                'user_id'             => $user->id,
                'assigned_franchise_id' => $franchise->id,
            ]);

            return [$franchise, $user];
        });
    }

    /** @return array<string, mixed> */
    private function glance(User $user): array
    {
        return app(LeadsGlance::class)
            ->resolve($user, DashboardPeriod::for($user), [])
            ->toArray()['data'];
    }

    /**
     * THE DEFECT. A reader who sees none is not told the business has none.
     *
     * The fixture is the real shape: enquiries placed in one branch, read by a
     * manager of another. Both leads exist, neither is theirs, and the old
     * sentence announced that none had ever arrived.
     */
    public function test_a_reader_who_sees_none_is_not_told_the_business_has_none(): void
    {
        [$second, $branch] = $this->branchReader();

        $this->lead($this->alpha['franchise']);
        $this->lead($this->alpha['franchise']);

        $data = $this->glance($branch);

        $this->assertTrue($data['empty']);
        $this->assertSame('No enquiries in this view.', $data['sentence']);

        // The control, and it is what makes the assertion above readable: the
        // organization really does hold enquiries, so "yet" was false here
        // rather than merely clumsy. Without this the test passes on an empty
        // organization, where every wording is true.
        $this->assertSame(2, $this->inTenant(fn () => Lead::query()->withoutGlobalScopes()->count()));
        $this->assertNotNull($second->id);
    }

    /**
     * The second control: the same reader, with one enquiry that IS theirs.
     *
     * Without it, an empty box proves nothing — a glance that returned empty
     * for this user whatever the data would pass the test above, and the
     * sentence would be honest about a scoping bug rather than about scope.
     */
    public function test_the_same_reader_sees_a_lead_placed_in_their_own_branch(): void
    {
        [$second, $branch] = $this->branchReader();

        $this->lead($this->alpha['franchise']);
        $this->lead($second);

        $data = $this->glance($branch);

        $this->assertFalse($data['empty']);
        $this->assertContains('1 lead not yet dealt with.', $data['sentences']);
    }

    /**
     * An organization-wide enquiry belongs to everybody who can open the box.
     *
     * Most enquiries have no franchise at all — the connection is scoped at
     * the top — so a glance that filtered on the column alone would show every
     * reader nothing, and the empty sentence would be drawn constantly.
     */
    public function test_an_unplaced_enquiry_reaches_a_branch_reader(): void
    {
        [, $branch] = $this->branchReader();

        $this->lead(null);

        $data = $this->glance($branch);

        $this->assertFalse($data['empty']);
        $this->assertContains('1 lead not yet dealt with.', $data['sentences']);
    }

    /** With nothing anywhere, the same sentence is simply true. */
    public function test_an_organization_with_no_enquiries_says_the_same_thing(): void
    {
        $data = $this->glance($this->alpha['admin']);

        $this->assertTrue($data['empty']);
        $this->assertSame('No enquiries in this view.', $data['sentence']);
    }

    /**
     * The count lines keep their qualifiers, and one of them keeps "yet".
     *
     * "not yet dealt with" is the box describing its own rows, not a claim
     * about the organization, so the ruling that removed "Nothing posted yet."
     * does not reach it. Pinned here so a later sweep that greps for the word
     * goes red rather than quietly flattening the only number on this board
     * that stands for a person waiting for a call back.
     */
    public function test_the_counts_qualify_themselves_and_never_claim_the_business(): void
    {
        $this->lead(null);
        $this->lead(null);
        $this->lead(null, Lead::STATUS_ARCHIVED);

        $data = $this->glance($this->alpha['admin']);

        $this->assertFalse($data['empty']);
        $this->assertContains('2 leads not yet dealt with.', $data['sentences']);
        // The period word is the dashboard's, not ours: `month` is the
        // default, and asserting the whole sentence keeps the number and its
        // qualifier pinned together.
        $this->assertContains('3 arrived this month.', $data['sentences']);

        foreach ($data['sentences'] as $sentence) {
            $this->assertStringNotContainsString('in reach', $sentence);
        }
    }
}
