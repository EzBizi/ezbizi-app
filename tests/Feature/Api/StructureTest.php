<?php

namespace Tests\Feature\Api;

use App\Models\Company;
use App\Models\Franchise;
use App\Services\Organizations\ProvisionOrganization;
use App\Support\Settings\SettingsResolver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The shape of the business (plan §3).
 *
 * §3's rules are absolutes — fixed depth, no company without a franchise —
 * and until now they lived only in a document. "No moving between parents" was
 * one of them until 2026-09-14, when Jose asked for a company to be convertible
 * to a sub company and back; that act is `CompanyReparentTest`'s, and the depth
 * rule it still obeys is below. The
 * model could express all three violations and nothing stopped it, because the
 * only code that ever created a company was the provisioning service.
 *
 * These are the rules restated as things that fail.
 */
class StructureTest extends TestCase
{
    use RefreshDatabase;

    private array $alpha;

    protected function setUp(): void
    {
        parent::setUp();

        $r = app(ProvisionOrganization::class)(
            organizationName: 'Alpha',
            administrator: ['name' => 'Admin', 'email' => 'a@alpha.test', 'password' => 'a-real-password'],
            companyName: 'First Co', franchiseName: 'F1',
        );

        $this->alpha = [
            'org'       => $r['organization'],
            'admin'     => $r['administrator'],
            'company'   => $r['company'],
            'franchise' => $r['franchise'],
        ];
    }

    private function as(): static
    {
        $token = $this->postJson('/api/v1/auth/login', [
            'email' => 'a@alpha.test', 'password' => 'a-real-password',
        ])->assertOk()->json('token');

        $this->app['auth']->forgetGuards();

        return $this->withHeader('Authorization', "Bearer {$token}");
    }

    private function inTenant(callable $fn): mixed
    {
        return app(TenantContext::class)->run($this->alpha['org'], $fn);
    }

    // ── creating ─────────────────────────────────────────────────

    public function test_a_company_is_created_with_its_first_franchise(): void
    {
        $this->as()->postJson('/api/v1/companies', [
            'name' => 'Everdoors',
            'franchise' => ['name' => 'Connecticut'],
        ])->assertOk();

        $this->inTenant(function () {
            $company = Company::where('name', 'Everdoors')->firstOrFail();

            // §3: "creating a Company requires creating a Franchise." Taking
            // both in one request is what makes that true rather than something
            // a second form is trusted to finish.
            $this->assertSame(
                ['Connecticut'],
                Franchise::where('company_id', $company->id)->pluck('name')->all(),
            );
        });
    }

    public function test_a_company_cannot_be_created_without_one(): void
    {
        $this->as()->postJson('/api/v1/companies', ['name' => 'Everdoors'])
            ->assertStatus(422);

        $this->inTenant(fn () => $this->assertFalse(
            Company::where('name', 'Everdoors')->exists()
        ));
    }

    public function test_a_sub_company_hangs_off_its_parent(): void
    {
        $this->as()->postJson('/api/v1/companies', [
            'name' => 'Premier Garage Repair',
            'parent_company_id' => $this->alpha['company']->id,
            'franchise' => ['name' => 'Connecticut'],
        ])->assertOk();

        $this->inTenant(function () {
            $sub = Company::where('name', 'Premier Garage Repair')->firstOrFail();

            $this->assertSame($this->alpha['company']->id, $sub->parent_company_id);
        });
    }

    public function test_depth_stops_at_a_sub_company(): void
    {
        $sub = $this->inTenant(fn () => Company::create([
            'organization_id' => $this->alpha['org']->id,
            'parent_company_id' => $this->alpha['company']->id,
            'name' => 'Sub', 'is_active' => true,
        ]));

        // §3: depth is fixed at Org → Company → Sub Company → Franchise.
        // Refused rather than quietly re-parented to the grandparent, which
        // would build a structure nobody asked for and nobody can see is wrong.
        $this->as()->postJson('/api/v1/companies', [
            'name' => 'Sub of a sub',
            'parent_company_id' => $sub->id,
            'franchise' => ['name' => 'Anywhere'],
        ])->assertStatus(422)
            ->assertJsonPath('errors.parent_company_id.0',
                fn ($m) => str_contains($m, 'sub company'));
    }

    public function test_a_new_franchise_inherits_the_organizations_timezone(): void
    {
        $this->as()->postJson('/api/v1/companies', [
            'name' => 'Everdoors', 'franchise' => ['name' => 'Connecticut'],
        ])->assertOk();

        $this->inTenant(function () {
            $f = Franchise::where('name', 'Connecticut')->firstOrFail();

            // A business in one timezone should not have to restate it for
            // every place it operates. The value comes from the organization
            // setting, not from whichever franchise was provisioned first.
            $this->assertSame(
                app(SettingsResolver::class)->get('timezone', $this->alpha['org']),
                $f->timezone,
            );
        });
    }

    // ── never moved ──────────────────────────────────────────────

    /**
     * Renamed on 2026-09-14: it used to be called
     * `test_a_company_cannot_be_moved_to_another_parent`, and that stopped
     * being true.
     *
     * Jose asked for the move — a company can be made a sub company and back —
     * and it exists as its own route, `PATCH /companies/{id}/parent`, covered
     * by `CompanyReparentTest`. What this test still guards is the part that
     * did NOT change: `update()` refuses `parent_company_id` outright, so a
     * field that reshapes the organization cannot ride along in the same
     * request as a phone number.
     *
     * The name says the invariant rather than the old rule, because a test
     * name is not executable and nothing would have caught it still asserting
     * "cannot be moved" while the app moved companies all day.
     */
    public function test_a_company_cannot_be_moved_by_the_ordinary_update(): void
    {
        $other = $this->inTenant(fn () => Company::create([
            'organization_id' => $this->alpha['org']->id,
            'name' => 'Other', 'is_active' => true,
        ]));

        // Not "companies are never moved" any more. This route still refuses
        // it, and the reason the rule existed at all is why the real one is
        // organization-level and audited: every setting, assignment and roll-up
        // below a company is expressed in terms of where it sits.
        $this->as()->patchJson('/api/v1/companies/'.$other->id, [
            'parent_company_id' => $this->alpha['company']->id,
        ])->assertStatus(422);

        $this->assertNull(Company::withoutGlobalScopes()->find($other->id)->parent_company_id);
    }

    public function test_a_franchise_cannot_be_moved_to_another_company(): void
    {
        $this->as()->patchJson('/api/v1/franchises/'.$this->alpha['franchise']->id, [
            'company_id' => $this->alpha['company']->id,
        ])->assertStatus(422);
    }

    public function test_a_franchises_currency_cannot_be_changed(): void
    {
        // Jobs store an amount in minor units beside the currency they were
        // priced in. Changing it later would not convert anything — it would
        // re-denominate every historical figure, turning $1,000 into €1,000.
        $this->as()->patchJson('/api/v1/franchises/'.$this->alpha['franchise']->id, [
            'currency' => 'EUR',
        ])->assertStatus(422);
    }

    // ── switching things off ─────────────────────────────────────

    public function test_the_last_franchise_of_a_company_cannot_be_switched_off(): void
    {
        // The rule is usually read as being about creation, but deactivating
        // the last one leaves exactly the state §3 forbids — a company with
        // nowhere to put work — and does it invisibly.
        $this->as()->patchJson('/api/v1/franchises/'.$this->alpha['franchise']->id, [
            'is_active' => false,
        ])->assertStatus(422);
    }

    public function test_a_franchise_can_be_switched_off_once_there_is_another(): void
    {
        $this->as()->postJson('/api/v1/franchises', [
            'company_id' => $this->alpha['company']->id,
            'name' => 'Second place',
        ])->assertOk();

        $this->app['auth']->forgetGuards();

        $this->as()->patchJson('/api/v1/franchises/'.$this->alpha['franchise']->id, [
            'is_active' => false,
        ])->assertOk()->assertJsonPath('is_active', false);
    }

    public function test_the_only_company_cannot_be_switched_off(): void
    {
        // An organization with no active company can create nothing at all:
        // every job needs a franchise, every franchise needs a company.
        $this->as()->patchJson('/api/v1/companies/'.$this->alpha['company']->id, [
            'is_active' => false,
        ])->assertStatus(422);
    }

    // ── reading ──────────────────────────────────────────────────

    public function test_the_tree_nests_sub_companies_under_their_parent(): void
    {
        $this->as()->postJson('/api/v1/companies', [
            'name' => 'Everdoors', 'franchise' => ['name' => 'Connecticut'],
        ])->assertOk();

        $this->app['auth']->forgetGuards();

        $everdoors = $this->inTenant(fn () => Company::where('name', 'Everdoors')->firstOrFail());

        $this->as()->postJson('/api/v1/companies', [
            'name' => 'Premier Garage Repair',
            'parent_company_id' => $everdoors->id,
            'franchise' => ['name' => 'Connecticut'],
        ])->assertOk();

        $this->app['auth']->forgetGuards();

        $tree = $this->as()->getJson('/api/v1/companies')->assertOk()->json();

        // Nested, not flat. A flat list is what let the settings screen render a
        // Sub Company as a sibling of its own parent.
        $names = array_column($tree['companies'], 'name');
        sort($names);

        $this->assertSame(['Everdoors', 'First Co'], $names);

        $ever = collect($tree['companies'])->firstWhere('name', 'Everdoors');

        $this->assertSame(
            ['Premier Garage Repair'],
            array_column($ever['sub_companies'], 'name'),
        );
    }

    public function test_the_tree_is_scoped_to_this_organization(): void
    {
        $beta = app(ProvisionOrganization::class)(
            organizationName: 'Beta',
            administrator: ['name' => 'B', 'email' => 'b@beta.test', 'password' => 'a-real-password'],
            companyName: 'Beta Co', franchiseName: 'BF1',
        );

        $names = array_column(
            $this->as()->getJson('/api/v1/companies')->assertOk()->json('companies'),
            'name',
        );

        $this->assertNotContains('Beta Co', $names);
        $this->assertNotNull($beta['company']);
    }

    public function test_none_of_it_works_signed_out(): void
    {
        $this->getJson('/api/v1/companies')->assertUnauthorized();
        $this->postJson('/api/v1/companies', ['name' => 'X'])->assertUnauthorized();
        $this->postJson('/api/v1/franchises', ['name' => 'X'])->assertUnauthorized();
    }
}
