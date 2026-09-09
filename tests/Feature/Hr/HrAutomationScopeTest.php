<?php

namespace Tests\Feature\Hr;

use App\Models\Assignment;
use App\Models\Automation;
use App\Models\Contract;
use App\Models\ContractVersion;
use App\Models\Invitation;
use App\Models\Onboarding;
use App\Support\Automations\CarriesAutomationScope;
use App\Support\Automations\EventScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Where an invitation and an onboarding say they sit, read back.
 *
 * `AutomationSubjectScopeTest` checks the SHAPE — HR declares what it emits and
 * the two models implement `CarriesAutomationScope` — and its own docblock says
 * these two were the records the interface was discovered on, fixed, and never
 * read back. Flattening either `automationScope()` to `[null, null]` leaves that
 * file green, and what the flattened answer does in production is tell every
 * active user about one branch's new starter.
 *
 * Two checks per arm, as that file asks: the MODEL check on the two words, and
 * the ENGINE check through `Automation::coversScope()` with the negatives. The
 * company arm passes the chain, because a company rule is answered from the
 * chain and a bare call reports a defect that does not exist.
 *
 * Owed since 2026-09-04 (16b58530); HR's, so HR wrote it.
 */
class HrAutomationScopeTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    // ── Invitation ───────────────────────────────────────────────

    public function test_an_invitation_into_a_franchise_names_that_franchise(): void
    {
        $t = $this->provisionTenant('Alpha');
        $nextDoor = $this->extraFranchise($t, 'Alpha F2');

        $this->inTenant($t['org'], function () use ($t, $nextDoor) {
            $invitation = $this->invitation($t, ['assigned_franchise_id' => $t['franchise']->id]);

            $this->assertSame(['franchise', $t['franchise']->id], $invitation->automationScope());

            [$type, $id] = $invitation->automationScope();
            $chain = $this->chain($invitation);

            $this->assertTrue(
                $this->rule('franchise', $t['franchise']->id)->coversScope($type, $id, $chain),
                'a branch rule did not cover an invitation into its own branch',
            );
            $this->assertFalse(
                $this->rule('franchise', $nextDoor->id)->coversScope($type, $id, $chain),
                "one branch's rule fired on another branch's invitation",
            );
            $this->assertTrue($this->rule('company', $t['company']->id)->coversScope($type, $id, $chain));
            $this->assertTrue($this->rule(null, null)->coversScope($type, $id, $chain));
        });
    }

    public function test_an_invitation_into_a_company_names_that_company(): void
    {
        $t = $this->provisionTenant('Alpha');

        $this->inTenant($t['org'], function () use ($t) {
            $invitation = $this->invitation($t, ['assigned_company_id' => $t['company']->id]);

            $this->assertSame(['company', $t['company']->id], $invitation->automationScope());

            [$type, $id] = $invitation->automationScope();
            $chain = $this->chain($invitation);

            $this->assertTrue(
                $this->rule('company', $t['company']->id)->coversScope($type, $id, $chain),
                'a company rule did not cover an invitation issued at its own company',
            );
            $this->assertFalse(
                $this->rule('franchise', $t['franchise']->id)->coversScope($type, $id, $chain),
                'a branch rule reached up to a company-level invitation',
            );
            $this->assertTrue($this->rule(null, null)->coversScope($type, $id, $chain));
        });
    }

    /** Organization-wide is the honest answer, not a fallback: only an organization rule covers it. */
    public function test_an_invitation_into_the_organization_belongs_to_no_narrower_scope(): void
    {
        $t = $this->provisionTenant('Alpha');

        $this->inTenant($t['org'], function () use ($t) {
            $invitation = $this->invitation($t, ['assigned_organization_id' => $t['org']->id]);

            $this->assertSame([null, null], $invitation->automationScope());

            [$type, $id] = $invitation->automationScope();
            $chain = $this->chain($invitation);

            // No scope, no chain: a company rule is answered from the chain, so
            // handing it one here would make it cover an organization-wide record.
            $this->assertSame([], $chain);

            $this->assertFalse($this->rule('franchise', $t['franchise']->id)->coversScope($type, $id, $chain));
            $this->assertFalse($this->rule('company', $t['company']->id)->coversScope($type, $id, $chain));
            $this->assertTrue($this->rule(null, null)->coversScope($type, $id, $chain));
        });
    }

    // ── Onboarding ───────────────────────────────────────────────

    /** An onboarding has no scope column of its own; it sits where its invitation was issued. */
    public function test_an_onboarding_sits_where_its_invitation_was_issued(): void
    {
        $t = $this->provisionTenant('Alpha');
        $nextDoor = $this->extraFranchise($t, 'Alpha F2');

        $this->inTenant($t['org'], function () use ($t, $nextDoor) {
            $invitation = $this->invitation($t, ['assigned_franchise_id' => $t['franchise']->id]);
            $onboarding = $this->onboarding($t, ['invitation_id' => $invitation->id]);

            $this->assertSame(['franchise', $t['franchise']->id], $onboarding->automationScope());

            [$type, $id] = $onboarding->automationScope();
            $chain = $this->chain($onboarding);

            $this->assertTrue($this->rule('franchise', $t['franchise']->id)->coversScope($type, $id, $chain));
            $this->assertFalse(
                $this->rule('franchise', $nextDoor->id)->coversScope($type, $id, $chain),
                "one branch's rule fired on another branch's onboarding — a named person's contract",
            );
            $this->assertTrue($this->rule('company', $t['company']->id)->coversScope($type, $id, $chain));
        });
    }

    /** The company arm, reached the same way: an invitation issued at the company. */
    public function test_an_onboarding_from_a_company_invitation_names_that_company(): void
    {
        $t = $this->provisionTenant('Alpha');

        $this->inTenant($t['org'], function () use ($t) {
            $invitation = $this->invitation($t, ['assigned_company_id' => $t['company']->id]);
            $onboarding = $this->onboarding($t, ['invitation_id' => $invitation->id]);

            $this->assertSame(['company', $t['company']->id], $onboarding->automationScope());

            [$type, $id] = $onboarding->automationScope();
            $chain = $this->chain($onboarding);

            $this->assertTrue($this->rule('company', $t['company']->id)->coversScope($type, $id, $chain));
            $this->assertFalse(
                $this->rule('franchise', $t['franchise']->id)->coversScope($type, $id, $chain),
                'a branch rule reached up to a company-level onboarding',
            );
        });
    }

    /** Without an invitation — a rehire, a contract change — it sits where the person is assigned. */
    public function test_an_onboarding_without_an_invitation_sits_where_the_person_is_assigned(): void
    {
        $t = $this->provisionTenant('Alpha');
        $nextDoor = $this->extraFranchise($t, 'Alpha F2');
        $person = $this->userWithRole($t, 'Technician', [], onlyAt: $t['franchise']);

        $this->inTenant($t['org'], function () use ($t, $nextDoor, $person) {
            $this->assertSame(
                Assignment::SCOPE_FRANCHISE,
                $person->assignments()->first()->scopeType(),
                'fixture: the person was meant to be assigned at the franchise',
            );

            $onboarding = $this->onboarding($t, ['user_id' => $person->id]);

            $this->assertSame(['franchise', $t['franchise']->id], $onboarding->automationScope());

            [$type, $id] = $onboarding->automationScope();
            $chain = $this->chain($onboarding);

            $this->assertTrue($this->rule('franchise', $t['franchise']->id)->coversScope($type, $id, $chain));
            $this->assertFalse($this->rule('franchise', $nextDoor->id)->coversScope($type, $id, $chain));
        });
    }

    public function test_an_onboarding_with_neither_belongs_to_no_narrower_scope(): void
    {
        $t = $this->provisionTenant('Alpha');

        $this->inTenant($t['org'], function () use ($t) {
            $this->assertSame([null, null], $this->onboarding($t, [])->automationScope());
        });
    }

    public function test_both_models_still_declare_the_contract(): void
    {
        // The shape check `AutomationSubjectScopeTest` makes, kept here so this
        // file fails as one unit if somebody drops the interface and keeps the method.
        $this->assertInstanceOf(CarriesAutomationScope::class, new Invitation);
        $this->assertInstanceOf(CarriesAutomationScope::class, new Onboarding);
    }

    // ── fixtures ─────────────────────────────────────────────────

    private function rule(?string $type, ?string $id): Automation
    {
        return new Automation(['scope_type' => $type, 'scope_id' => $id]);
    }

    /**
     * The chain the ENGINE hands `coversScope`, built by the same code — not
     * written by hand, because a hand-written chain is the trap the owed-test
     * note warns about, in both directions.
     */
    private function chain(Model $record): array
    {
        return EventScope::capture($record)['company_chain'];
    }

    private function invitation(array $t, array $scope): Invitation
    {
        $row = new Invitation;
        $row->forceFill([
            'organization_id' => $t['org']->id,
            'email' => 'starter@alpha.test',
            'invited_by_user_id' => $t['admin']->id,
            'token_hash' => hash('sha256', 'scope-test'),
            'sent_at' => now(),
            'expires_at' => now()->addWeek(),
            ...$scope,
        ]);
        $row->save();

        return $row;
    }

    private function onboarding(array $t, array $placement): Onboarding
    {
        $contract = Contract::create([
            'organization_id' => $t['org']->id,
            'name' => 'Technician',
            'created_by' => $t['admin']->id,
        ]);
        $version = ContractVersion::create([
            'organization_id' => $t['org']->id,
            'contract_id' => $contract->id,
            'version' => 1,
            'fields' => [],
            'body' => '',
            'created_by' => $t['admin']->id,
        ]);

        return Onboarding::create([
            'organization_id' => $t['org']->id,
            'contract_version_id' => $version->id,
            'status' => 'in_progress',
            'started_at' => now(),
            'started_by_user_id' => $t['admin']->id,
            ...$placement,
        ]);
    }
}
