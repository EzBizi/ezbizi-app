<?php

namespace Tests\Feature\Newsletter;

use App\Models\Automation;
use App\Models\Company;
use App\Models\Newsletter;
use App\Support\Automations\CarriesAutomationScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Where a mailing says it sits, and why the answer has to be checked.
 *
 * ── The gap this fills ───────────────────────────────────────────
 *
 * `AutomationSubjectScopeTest` asks two things: does the module declare what it
 * emits, and does that model implement `CarriesAutomationScope`. Both are
 * questions about the SHAPE. Neither reads what `automationScope()` returns.
 *
 * Measured, not assumed: flattening the franchise arm to `[null, null]` leaves
 * that whole file green. So a mapping that answered "the whole organization"
 * for every mailing would pass the suite while doing exactly the damage the
 * interface exists to prevent — `NotificationDispatcher::audienceFor` widening
 * to every active user. The declaration is checked; the answer was not, until
 * this file.
 *
 * The unsubscribe is the one that makes it matter rather than tidy.
 * `newsletter.unsubscribed` carries an email address in its payload, and a
 * tenant may attach `notify` to any raised trigger. Wrong scope there is one
 * branch's departure announced to the whole organization.
 */
class NewsletterAutomationScopeTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_a_franchise_mailing_names_its_franchise(): void
    {
        $t = $this->provisionTenant('A1-Group');

        $this->inTenant($t['org'], function () use ($t) {
            $mailing = $this->mailing($t, Newsletter::SCOPE_FRANCHISE, $t['franchise']->id);

            $this->assertSame(
                ['franchise', $t['franchise']->id],
                $mailing->automationScope(),
            );

            // And the words actually route. Asserting the return value alone
            // proves the model reports a branch; it does not prove a rule
            // reaches that branch and no other, which is the whole point.
            [$scopeType, $scopeId] = $mailing->automationScope();

            $ownBranch = new Automation(['scope_type' => 'franchise', 'scope_id' => $t['franchise']->id]);
            $nextDoor  = new Automation(['scope_type' => 'franchise', 'scope_id' => 'another-branch']);
            $wholeOrg  = new Automation(['scope_type' => null, 'scope_id' => null]);

            $this->assertTrue(
                $ownBranch->coversScope($scopeType, $scopeId),
                'a branch rule did not cover its own branch mailing',
            );

            $this->assertFalse(
                $nextDoor->coversScope($scopeType, $scopeId),
                "one branch's rule fired on another branch's mailing — and an "
                .'unsubscribe payload carries the subscriber email',
            );

            $this->assertTrue($wholeOrg->coversScope($scopeType, $scopeId));
        });
    }

    public function test_a_company_mailing_names_its_company(): void
    {
        $t = $this->provisionTenant('A1-Group');

        $this->inTenant($t['org'], function () use ($t) {
            $mailing = $this->mailing($t, Newsletter::SCOPE_COMPANY, $t['company']->id);

            $this->assertSame(
                ['company', $t['company']->id],
                $mailing->automationScope(),
            );

            [$scopeType, $scopeId] = $mailing->automationScope();

            $theCompany = new Automation(['scope_type' => 'company', 'scope_id' => $t['company']->id]);
            $aBranch    = new Automation(['scope_type' => 'franchise', 'scope_id' => $t['franchise']->id]);

            /*
             * The THIRD argument is the trap, and it is why this case is worth
             * writing rather than assuming: a company rule is not matched by
             * scope equality. `coversScope` answers it from the company CHAIN,
             * which `AutomationEngine::companyChain` builds so a company rule
             * also reaches the franchises of its Sub Companies — and the engine
             * always passes it. A bare two-argument call here answers false for
             * a rule that fires perfectly well in production, so the obvious
             * version of this test reports a defect that does not exist. Phone
             * lost a red test to exactly that.
             */
            $this->assertTrue(
                $theCompany->coversScope($scopeType, $scopeId, [$t['company']->id]),
                'a company rule did not cover its own company mailing',
            );

            $this->assertFalse(
                $aBranch->coversScope($scopeType, $scopeId, [$t['company']->id]),
                'a branch rule reached up to a company-wide mailing',
            );
        });
    }

    /**
     * An organization mailing answers `[null, null]`, and that is the honest
     * answer rather than the fallback it looks like: a newsletter written at
     * the organization goes to the organization's audience, so only an
     * organization-wide rule sensibly covers it.
     */
    public function test_an_organization_mailing_belongs_to_no_narrower_scope(): void
    {
        $t = $this->provisionTenant('A1-Group');

        $this->inTenant($t['org'], function () use ($t) {
            $mailing = $this->mailing($t, Newsletter::SCOPE_ORGANIZATION, $t['org']->id);

            $this->assertSame([null, null], $mailing->automationScope());
        });
    }

    /**
     * The two words are not free-form: `AutomationEngine` compares them against
     * what `automations.scope_type` stores, so a mailing answering 'branch' or
     * 'org' would match no rule at all and nothing on screen would say why.
     */
    public function test_the_words_are_the_ones_the_engine_compares_against(): void
    {
        $t = $this->provisionTenant('A1-Group');

        $this->inTenant($t['org'], function () use ($t) {
            foreach ([Newsletter::SCOPE_FRANCHISE, Newsletter::SCOPE_COMPANY] as $scope) {
                $id = $scope === Newsletter::SCOPE_FRANCHISE
                    ? $t['franchise']->id
                    : $t['company']->id;

                [$word] = $this->mailing($t, $scope, $id)->automationScope();

                $this->assertContains($word, ['franchise', 'company']);
            }
        });
    }

    /** A sibling company's mailing never claims this company's scope. */
    public function test_a_sibling_companys_mailing_names_the_sibling(): void
    {
        $t = $this->provisionTenant('A1-Group');

        $premier = $this->inTenant($t['org'], fn () => Company::create([
            'organization_id' => $t['org']->id,
            'name'            => 'Premier Garage Repair',
        ]));

        $this->inTenant($t['org'], function () use ($t, $premier) {
            $mailing = $this->mailing($t, Newsletter::SCOPE_COMPANY, $premier->id);

            $this->assertSame(['company', $premier->id], $mailing->automationScope());
            $this->assertNotSame(['company', $t['company']->id], $mailing->automationScope());
        });
    }

    public function test_the_model_still_declares_the_contract(): void
    {
        // The shape check `AutomationSubjectScopeTest` makes, kept here too so
        // this file fails as one unit if somebody drops the interface while
        // leaving the method behind.
        $this->assertInstanceOf(CarriesAutomationScope::class, new Newsletter);
    }

    private function mailing(array $t, string $scopeType, string $scopeId): Newsletter
    {
        $row = new Newsletter;
        $row->forceFill([
            'organization_id' => $t['org']->id,
            'subject'         => 'Autumn sweeps',
            'blocks'          => [],
            'scope_type'      => $scopeType,
            'scope_id'        => $scopeId,
            'status'          => Newsletter::STATUS_DRAFT,
            'created_by'      => $t['admin']->id,
        ]);
        $row->save();

        return $row;
    }
}
