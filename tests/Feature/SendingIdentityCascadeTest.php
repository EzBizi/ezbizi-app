<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Franchise;
use App\Models\Organization;
use App\Models\SendingIdentity;
use App\Models\Setting;
use App\Services\Organizations\ProvisionOrganization;
use App\Support\Mail\MailTransport;
use App\Support\Mail\SendingIdentityResolver;
use App\Support\Mail\SendingState;
use App\Support\Settings\SettingsResolver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Which address a tenant's mail goes out as (§6).
 *
 * The worked example is the owner's own, and these are live records in
 * production:
 *
 *   A1-Group (organization)
 *     A-1 Chimney (company)          → info@a-1chimney.com
 *       A-1 Northeast (sub company)  → info@ne.a-1chimney.com
 *         Hartford (franchise)       → nothing set
 *       Connecticut (franchise)      → info@ct.a-1chimney.com
 *     Everdoors (company)            → info@everdoors.com
 *       Everdoors NJ (franchise)     → nothing set
 *
 * ── The two tests that earn their place ──────────────────────────
 *
 * The Sub Company one, because SettingsResolver's docblock records the near-miss
 * where a chain written as Franchise → Company → Organization silently skipped
 * them: "nothing would error — the value would just quietly be wrong". Hartford
 * would have sent as A-1 Chimney while A-1 Northeast's own address sat there
 * being ignored.
 *
 * And test_the_chain_is_the_settings_cascade, because this feature deliberately
 * does NOT reuse SettingsResolver's walk (that method is private) and a copied
 * rule is a rule that drifts. That test compares the two by behaviour, both
 * ways round: every link the settings cascade can reach must be in this one, and
 * every link in this one must be reachable by the settings cascade.
 */
class SendingIdentityCascadeTest extends TestCase
{
    use RefreshDatabase;

    private SendingIdentityResolver $identities;

    protected function setUp(): void
    {
        parent::setUp();

        $this->identities = app(SendingIdentityResolver::class);

        // Pinned rather than inherited from whatever .env the box has, so the
        // platform link at the end of the cascade is a known address.
        config([
            'mail.from.address' => 'noreply@ezbizi.test',
            'mail.from.name' => 'EzBizi',
        ]);
    }

    /**
     * A1-Group, in the shape above.
     *
     * @return array<string, Organization|Company|Franchise>
     */
    private function a1Group(): array
    {
        $provisioned = app(ProvisionOrganization::class)(
            organizationName: 'A1-Group',
            administrator: ['name' => 'Owner', 'email' => 'owner@a1group.test', 'password' => 'a-real-password'],
            companyName: 'A-1 Chimney',
            franchiseName: 'Connecticut',
        );

        $org = $provisioned['organization'];

        return app(TenantContext::class)->run($org, function () use ($org, $provisioned) {
            $northeast = Company::create([
                'organization_id' => $org->id,
                'parent_company_id' => $provisioned['company']->id,
                'name' => 'A-1 Northeast',
            ]);

            $everdoors = Company::create([
                'organization_id' => $org->id,
                'name' => 'Everdoors',
            ]);

            return [
                'organization' => $org,
                'a1' => $provisioned['company'],
                'northeast' => $northeast,
                'everdoors' => $everdoors,
                'connecticut' => $provisioned['franchise'],
                'hartford' => Franchise::create([
                    'organization_id' => $org->id,
                    'company_id' => $northeast->id,
                    'name' => 'Hartford',
                ]),
                'everdoorsNj' => Franchise::create([
                    'organization_id' => $org->id,
                    'company_id' => $everdoors->id,
                    'name' => 'Everdoors NJ',
                ]),
            ];
        });
    }

    /**
     * An identity at a scope.
     *
     * `state` is forced rather than filled because it is not fillable — that is
     * the point of the design, and a fixture that could set it by mass
     * assignment would be testing a model this application does not have.
     */
    private function set(Model $scope, string $address, string $name, SendingState $state = SendingState::Verified): SendingIdentity
    {
        $identity = new SendingIdentity([
            'organization_id' => $scope instanceof Organization ? $scope->getKey() : $scope->organization_id,
            'scope_type' => $this->identities->scopeTypeOf($scope),
            'scope_id' => $scope->getKey(),
            'from_address' => $address,
            'from_name' => $name,
            'transport' => MailTransport::VerifiedDomain->value,
        ]);

        $identity->forceFill(['state' => $state->value])->save();
        $this->identities->flush();

        return $identity;
    }

    // ── the platform is the root, not a fallback beside it ───────

    public function test_nothing_set_anywhere_resolves_to_the_platform_identity(): void
    {
        $t = $this->a1Group();

        app(TenantContext::class)->run($t['organization'], function () use ($t) {
            // CONTROL. Without it this test would pass just as well against a
            // resolver that ignored the table entirely, or against a tree where
            // the rows had failed to save.
            $this->assertSame(0, SendingIdentity::count(), 'The table must be empty for this to mean anything.');

            $identity = $this->identities->for($t['connecticut']);

            $this->assertSame('noreply@ezbizi.test', $identity->fromAddress);
            $this->assertNull($this->identities->sourceOf($t['connecticut']));

            // And it can send. The platform has nothing to prove to itself —
            // whether the wire works is MailStatus's separate question.
            $this->assertTrue($identity->canSend());
        });
    }

    // ── the owner's worked example ───────────────────────────────

    public function test_a_franchise_with_nothing_set_inherits_its_company(): void
    {
        $t = $this->a1Group();

        app(TenantContext::class)->run($t['organization'], function () use ($t) {
            $this->set($t['a1'], 'info@a-1chimney.com', 'A-1 Chimney');

            $identity = $this->identities->for($t['connecticut']);

            $this->assertSame('info@a-1chimney.com', $identity->fromAddress);
            $this->assertSame('A-1 Chimney', $identity->fromName);

            // Where it came from, which is a different question from what it is.
            $source = $this->identities->sourceOf($t['connecticut']);
            $this->assertTrue($source->is($t['a1']));
        });
    }

    public function test_two_companies_in_one_organization_send_as_themselves(): void
    {
        $t = $this->a1Group();

        app(TenantContext::class)->run($t['organization'], function () use ($t) {
            $this->set($t['a1'], 'info@a-1chimney.com', 'A-1 Chimney');
            $this->set($t['everdoors'], 'info@everdoors.com', 'Everdoors');

            // The whole reason this is not one organization-wide address.
            $this->assertSame('info@a-1chimney.com', $this->identities->for($t['connecticut'])->fromAddress);
            $this->assertSame('info@everdoors.com', $this->identities->for($t['everdoorsNj'])->fromAddress);
        });
    }

    public function test_a_franchise_may_hold_its_own_regional_address(): void
    {
        $t = $this->a1Group();

        app(TenantContext::class)->run($t['organization'], function () use ($t) {
            $this->set($t['a1'], 'info@a-1chimney.com', 'A-1 Chimney');
            $this->set($t['connecticut'], 'info@ct.a-1chimney.com', 'A-1 Chimney Connecticut');

            $identity = $this->identities->for($t['connecticut']);

            $this->assertSame('info@ct.a-1chimney.com', $identity->fromAddress);
            $this->assertTrue($this->identities->sourceOf($t['connecticut'])->is($t['connecticut']));

            // And the sibling that set nothing is untouched by it.
            $this->assertSame('info@a-1chimney.com', $this->identities->for($t['a1'])->fromAddress);
        });
    }

    /**
     * The step that is easy to leave out, and silent when it is.
     *
     * Hartford hangs off A-1 Northeast, which hangs off A-1 Chimney. A chain
     * written as franchise → company → organization reaches A-1 Chimney and
     * never asks A-1 Northeast at all.
     */
    public function test_a_sub_company_is_consulted_before_its_parent(): void
    {
        $t = $this->a1Group();

        app(TenantContext::class)->run($t['organization'], function () use ($t) {
            $this->set($t['organization'], 'noreply@a1group.com', 'A1-Group');
            $this->set($t['a1'], 'info@a-1chimney.com', 'A-1 Chimney');
            $this->set($t['northeast'], 'info@ne.a-1chimney.com', 'A-1 Northeast');

            $this->assertSame('info@ne.a-1chimney.com', $this->identities->for($t['hartford'])->fromAddress);
            $this->assertTrue($this->identities->sourceOf($t['hartford'])->is($t['northeast']));
        });
    }

    public function test_a_franchise_under_a_sub_company_still_reaches_the_parent_company(): void
    {
        $t = $this->a1Group();

        app(TenantContext::class)->run($t['organization'], function () use ($t) {
            // Nothing on A-1 Northeast this time — the parent company answers.
            $this->set($t['organization'], 'noreply@a1group.com', 'A1-Group');
            $this->set($t['a1'], 'info@a-1chimney.com', 'A-1 Chimney');

            $this->assertSame('info@a-1chimney.com', $this->identities->for($t['hartford'])->fromAddress);
            $this->assertTrue($this->identities->sourceOf($t['hartford'])->is($t['a1']));
        });
    }

    public function test_the_organization_answers_when_no_company_has(): void
    {
        $t = $this->a1Group();

        app(TenantContext::class)->run($t['organization'], function () use ($t) {
            $this->set($t['organization'], 'noreply@a1group.com', 'A1-Group');

            $this->assertSame('noreply@a1group.com', $this->identities->for($t['everdoorsNj'])->fromAddress);
            $this->assertSame('noreply@a1group.com', $this->identities->for($t['hartford'])->fromAddress);
        });
    }

    // ── the copy of the settings chain, kept honest ──────────────

    /**
     * This walk and SettingsResolver's must reach the same links, in the same order.
     *
     * SettingsResolver::chainFor is private, so this feature writes the walk out
     * again — and a copied rule drifts. This compares them by behaviour instead
     * of by reading, and both ways round:
     *
     *   1. Set a scratch SETTING at exactly one scope in the tree, anywhere, and
     *      ask SettingsResolver where the value came from. If it found it, that
     *      scope is in the settings chain and must therefore be in this one.
     *      If it did not, it must NOT be — that is the direction that catches
     *      an extra link here, which would be a leak: an identity inherited
     *      from a company the settings cascade does not consider an ancestor.
     *
     *   2. Set it at two consecutive links and confirm the nearer one wins, so
     *      the two agree on ORDER and not merely on membership.
     */
    public function test_the_chain_is_the_settings_cascade(): void
    {
        $t = $this->a1Group();
        $settings = app(SettingsResolver::class);

        app(TenantContext::class)->run($t['organization'], function () use ($t, $settings) {
            $everyScope = [
                $t['organization'], $t['a1'], $t['northeast'], $t['everdoors'],
                $t['connecticut'], $t['hartford'], $t['everdoorsNj'],
            ];

            foreach ([$t['hartford'], $t['connecticut'], $t['everdoorsNj'], $t['northeast'], $t['a1'], $t['organization']] as $subject) {
                $mine = $this->identities->chainFor($subject);

                // CONTROL: a chain is never empty, so an assertion loop over it
                // cannot pass by iterating nothing.
                $this->assertNotEmpty($mine, 'A scope with no chain would make every check below vacuous.');

                foreach ($everyScope as $candidate) {
                    Setting::query()->delete();
                    $settings->flush();
                    $settings->set('locale', $candidate, 'es');

                    $found = $settings->sourceOf('locale', $subject);

                    $settingsReachesIt = $found !== null && $found->is($candidate);
                    $iReachIt = collect($mine)->contains(fn (Model $link) => $link->is($candidate));

                    $this->assertSame(
                        $settingsReachesIt,
                        $iReachIt,
                        sprintf(
                            'The settings cascade and the sending-identity cascade disagree about '
                            .'whether %s (%s) is an ancestor of %s (%s). One of the two walks has '
                            .'learned about a level the other has not.',
                            $candidate->name, $candidate::class, $subject->name, $subject::class,
                        ),
                    );
                }

                // Order, not just membership: the nearer of two consecutive
                // links must be the one that answers, for both walks.
                for ($i = 0; $i < count($mine) - 1; $i++) {
                    Setting::query()->delete();
                    $settings->flush();
                    $settings->set('locale', $mine[$i], 'es');
                    $settings->set('locale', $mine[$i + 1], 'en');

                    $this->assertTrue(
                        $settings->sourceOf('locale', $subject)->is($mine[$i]),
                        'The settings cascade prefers a different link of the chain than this one does.',
                    );
                }
            }
        });
    }

    // ── the resolver answers about the level it was asked about ──

    public function test_the_row_at_a_scope_is_not_the_inherited_answer(): void
    {
        $t = $this->a1Group();

        app(TenantContext::class)->run($t['organization'], function () use ($t) {
            $this->set($t['a1'], 'info@a-1chimney.com', 'A-1 Chimney');

            // Connecticut sends as A-1 Chimney and holds nothing of its own.
            // An editor that cannot tell those apart writes a copy of the
            // parent's address the first time somebody opens the panel.
            $this->assertSame('info@a-1chimney.com', $this->identities->for($t['connecticut'])->fromAddress);
            $this->assertNull($this->identities->rowAt($t['connecticut']));
            $this->assertNotNull($this->identities->rowAt($t['a1']));
        });
    }
}
