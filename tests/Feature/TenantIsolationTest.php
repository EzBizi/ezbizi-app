<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\Company;
use App\Models\Franchise;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

/**
 * Tenant isolation — plan §5.1 names a cross-tenant leak the highest-severity bug
 * class in the system, so it gets the most direct tests in the suite.
 *
 * These test the BOUNDARY, not the happy path. Each one should fail loudly if
 * somebody later "simplifies away" the tenant scope or the write guards.
 */
class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private TenantContext $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = app(TenantContext::class);
    }

    // ── helpers ──────────────────────────────────────────────────

    private function makeOrg(string $name): Organization
    {
        return Organization::create([
            'name' => $name,
            'slug' => str($name)->slug()->toString(),
        ]);
    }

    /** Company → Sub Company, each with a franchise. The shape from plan §3. */
    private function makeTree(Organization $org, string $prefix): array
    {
        return $this->tenant->run($org, function () use ($org, $prefix) {
            $company = Company::create([
                'organization_id' => $org->id,
                'name'            => "{$prefix} Co",
            ]);

            $sub = Company::create([
                'organization_id'   => $org->id,
                'parent_company_id' => $company->id,
                'name'              => "{$prefix} Sub",
            ]);

            return [
                'company'      => $company,
                'sub'          => $sub,
                'franchise'    => Franchise::create([
                    'organization_id' => $org->id,
                    'company_id'      => $company->id,
                    'name'            => "{$prefix} F1",
                ]),
                'subFranchise' => Franchise::create([
                    'organization_id' => $org->id,
                    'company_id'      => $sub->id,
                    'name'            => "{$prefix} SubF1",
                ]),
            ];
        });
    }

    private function makeUser(Organization $org, string $email, string $roleName = 'Staff'): User
    {
        return $this->tenant->run($org, function () use ($org, $email, $roleName) {
            $role = Role::create(['organization_id' => $org->id, 'name' => $roleName]);

            return User::create([
                'organization_id' => $org->id,
                'role_id'         => $role->id,
                'name'            => 'Test User',
                'email'           => $email,
                'password'        => 'secret-password',
            ]);
        });
    }

    // ── reads ────────────────────────────────────────────────────

    public function test_never_returns_another_organizations_rows(): void
    {
        $a = $this->makeOrg('Alpha');
        $b = $this->makeOrg('Bravo');
        $this->makeTree($a, 'A');
        $this->makeTree($b, 'B');

        $seenByA = $this->tenant->run($a, fn () => Franchise::all());
        $seenByB = $this->tenant->run($b, fn () => Franchise::all());

        $this->assertCount(2, $seenByA);
        $this->assertCount(2, $seenByB);
        $this->assertSame([$a->id], $seenByA->pluck('organization_id')->unique()->values()->all());
        $this->assertSame([$b->id], $seenByB->pluck('organization_id')->unique()->values()->all());
    }

    public function test_cannot_fetch_another_organizations_record_by_id(): void
    {
        $a = $this->makeOrg('Alpha');
        $b = $this->makeOrg('Bravo');
        $treeB = $this->makeTree($b, 'B');

        // Guessing a UUIDv7 is impractical — but if one ever leaks it must still
        // be useless to the wrong tenant.
        $found = $this->tenant->run($a, fn () => Franchise::find($treeB['franchise']->id));

        $this->assertNull($found);
    }

    // ── writes ───────────────────────────────────────────────────

    public function test_stamps_the_current_tenant_on_create(): void
    {
        $a = $this->makeOrg('Alpha');

        $company = $this->tenant->run($a, fn () => Company::create(['name' => 'Unstamped']));

        $this->assertSame($a->id, $company->organization_id);
    }

    public function test_refuses_to_create_a_record_for_another_organization(): void
    {
        $a = $this->makeOrg('Alpha');
        $b = $this->makeOrg('Bravo');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Refusing to create/');

        $this->tenant->run($a, fn () => Company::create([
            'organization_id' => $b->id,
            'name'            => 'Smuggled',
        ]));
    }

    public function test_refuses_to_move_a_record_between_organizations(): void
    {
        $a = $this->makeOrg('Alpha');
        $b = $this->makeOrg('Bravo');
        $tree = $this->makeTree($a, 'A');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/immutable/');

        $this->tenant->run($a, fn () => $tree['company']->update(['organization_id' => $b->id]));
    }

    public function test_refuses_to_write_with_no_tenant_in_context(): void
    {
        // Silently writing an unscoped row is worse than failing.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/No tenant in context/');

        Company::create(['name' => 'Orphan']);
    }

    public function test_refuses_to_read_with_no_tenant_in_context(): void
    {
        $this->makeTree($this->makeOrg('Alpha'), 'A');
        $this->makeTree($this->makeOrg('Bravo'), 'B');

        /*
         * The read half of the rule the test above states for writes.
         *
         * This one used to pass by returning FOUR franchises — both
         * organizations' — because the scope added no predicate when there was
         * no tenant. Route model binding ran in exactly that state until
         * bootstrap/app.php was ordered, so it was the live path rather than a
         * thought experiment.
         */
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/No tenant in context/');

        Franchise::all();
    }

    public function test_a_token_stops_working_when_its_organization_is_removed(): void
    {
        $a = $this->makeOrg('Alpha');
        $user = $this->makeUser($a, 'admin@alpha.test', 'Administrator');

        $token = $user->createToken('probe')->plainTextToken;

        // Organization is soft-deleted, so every user of it has a null
        // organization while their tokens go on authenticating — nothing revokes
        // them when the tenant goes. The request must be refused at the door,
        // not carried on with no tenant in context.
        $a->delete();

        $this->tenant->forget();

        // The MESSAGE is asserted, not just the status. Sanctum answers 401 too,
        // and "the token did not resolve" would be a different bug wearing the
        // same code — this has to be the tenancy refusal.
        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/me')
            ->assertUnauthorized()
            ->assertJsonPath('message', 'This account is no longer attached to an organization. Sign in again.');
    }

    // ── context safety ───────────────────────────────────────────

    public function test_restores_the_previous_tenant_even_when_the_callback_throws(): void
    {
        $a = $this->makeOrg('Alpha');
        $b = $this->makeOrg('Bravo');

        $this->tenant->set($a);

        try {
            $this->tenant->run($b, fn () => throw new RuntimeException('boom'));
        } catch (RuntimeException) {
            // expected
        }

        // A queue worker handles many jobs in one long-lived process. If a failed
        // job stranded the context, the NEXT job would run as the wrong tenant.
        $this->assertSame($a->id, $this->tenant->id());
    }

    public function test_keeps_nested_contexts_correctly_stacked(): void
    {
        $a = $this->makeOrg('Alpha');
        $b = $this->makeOrg('Bravo');

        $this->tenant->run($a, function () use ($a, $b) {
            $this->assertSame($a->id, $this->tenant->id());

            $this->tenant->run($b, fn () => $this->assertSame($b->id, $this->tenant->id()));

            $this->assertSame($a->id, $this->tenant->id());
        });

        $this->assertFalse($this->tenant->has());
    }

    /**
     * Characterization, not a fix: this pins the case the two comments on
     * `run()` and `organizationWhenThrown()` did not cover, and it passes
     * exactly the same before and after those comments were corrected.
     *
     * "The innermost tenant is the one it happened to" and "wrapping the fault
     * on the way out does not lose the attribution" are both true only while the
     * original throwable is what keeps travelling. Catch an inner failure INSIDE
     * an outer run and rethrow it wrapped, and the wrapper is a throwable no
     * inner run has ever seen: the outer run claims it on the way past, and the
     * getPrevious() walk finds that claim first and stops.
     *
     * Nothing in the codebase catches and wraps inside a run(), so this is
     * documentation of where the edge is, not a defect being repaired.
     */
    public function test_a_fault_wrapped_inside_an_outer_run_is_blamed_on_the_outer_tenant(): void
    {
        $a = $this->makeOrg('Alpha');
        $b = $this->makeOrg('Bravo');

        $inner = new RuntimeException('the actual fault');
        $wrapper = null;

        try {
            $this->tenant->run($a, function () use ($b, $inner) {
                try {
                    $this->tenant->run($b, fn () => throw $inner);
                } catch (RuntimeException $e) {
                    throw new RuntimeException('while doing the thing', 0, $e);
                }
            });
        } catch (RuntimeException $e) {
            $wrapper = $e;
        }

        $this->assertNotNull($wrapper, 'The wrapper never escaped the outer run.');

        // Where the WRAPPING happened, not where the fault did.
        $this->assertSame($a->id, $this->tenant->organizationWhenThrown($wrapper)?->id);

        // The inner throwable still remembers the truth; nothing consults it,
        // because the walk stops at the first claim it finds.
        $this->assertSame($b->id, $this->tenant->organizationWhenThrown($inner)?->id);
    }

    // ── authority: assignment vs reach (§4) ──────────────────────

    public function test_franchise_level_user_reaches_only_that_franchise(): void
    {
        $org  = $this->makeOrg('Alpha');
        $tree = $this->makeTree($org, 'A');
        $user = $this->makeUser($org, 'tech@alpha.test', 'Technician');

        $this->tenant->run($org, function () use ($org, $user, $tree) {
            Assignment::create([
                'organization_id'       => $org->id,
                'user_id'               => $user->id,
                'assigned_franchise_id' => $tree['franchise']->id,
            ]);

            $fresh = $user->fresh();
            $this->assertTrue($fresh->canActOnFranchise($tree['franchise']));
            $this->assertFalse($fresh->canActOnFranchise($tree['subFranchise']));
        });
    }

    public function test_company_level_user_reaches_sub_company_franchises_too(): void
    {
        $org  = $this->makeOrg('Alpha');
        $tree = $this->makeTree($org, 'A');
        $user = $this->makeUser($org, 'mgr@alpha.test', 'Manager');

        $this->tenant->run($org, function () use ($org, $user, $tree) {
            Assignment::create([
                'organization_id'     => $org->id,
                'user_id'             => $user->id,
                'assigned_company_id' => $tree['company']->id,
            ]);

            $fresh = $user->fresh();

            // The Sub Company step is the one easy to miss — without it, a company
            // manager silently cannot reach half their own operation.
            $this->assertTrue($fresh->canActOnFranchise($tree['franchise']));
            $this->assertTrue($fresh->canActOnFranchise($tree['subFranchise']));
            $this->assertCount(2, $fresh->reachableFranchises());
        });
    }

    public function test_authority_never_crosses_organizations(): void
    {
        $a = $this->makeOrg('Alpha');
        $b = $this->makeOrg('Bravo');
        $treeA = $this->makeTree($a, 'A');
        $treeB = $this->makeTree($b, 'B');
        $user  = $this->makeUser($a, 'admin@alpha.test', 'Administrator');

        $this->tenant->run($a, fn () => Assignment::create([
            'organization_id'          => $a->id,
            'user_id'                  => $user->id,
            'assigned_organization_id' => $a->id,
        ]));

        // Asked from inside A, like the two tests above it. canActOnFranchise()
        // walks the user's assignments, which are tenant-owned — so the question
        // needs a tenant to be asked in. It used to be asked with none, and the
        // read scope answered by matching every organization's rows.
        $this->tenant->run($a, function () use ($user, $treeA, $treeB) {
            $fresh = $user->fresh();

            // Organization-wide authority inside A must still stop at A's edge.
            $this->assertTrue($fresh->canActOnFranchise($treeA['franchise']));
            $this->assertFalse($fresh->canActOnFranchise($treeB['franchise']));
        });
    }

    // ── the XOR invariant ────────────────────────────────────────

    public function test_rejects_an_assignment_targeting_nothing(): void
    {
        $org  = $this->makeOrg('Alpha');
        $user = $this->makeUser($org, 'u1@alpha.test');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/exactly one scope/');

        $this->tenant->run($org, fn () => Assignment::create([
            'organization_id' => $org->id,
            'user_id'         => $user->id,
        ]));
    }

    public function test_rejects_an_assignment_targeting_two_scopes(): void
    {
        $org  = $this->makeOrg('Alpha');
        $tree = $this->makeTree($org, 'A');
        $user = $this->makeUser($org, 'u2@alpha.test');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/exactly one scope/');

        $this->tenant->run($org, fn () => Assignment::create([
            'organization_id'          => $org->id,
            'user_id'                  => $user->id,
            'assigned_organization_id' => $org->id,
            'assigned_company_id'      => $tree['company']->id,
        ]));
    }

    // ── same email, two organizations (§4) ───────────────────────

    public function test_allows_the_same_email_in_two_organizations(): void
    {
        $a = $this->makeOrg('Alpha');
        $b = $this->makeOrg('Bravo');

        $one = $this->makeUser($a, 'jose@example.com');
        $two = $this->makeUser($b, 'jose@example.com');

        // Two accounts, one person, no object shared between the tenants.
        $this->assertNotSame($one->id, $two->id);
        $this->assertSame($a->id, $one->organization_id);
        $this->assertSame($b->id, $two->organization_id);
    }
}
