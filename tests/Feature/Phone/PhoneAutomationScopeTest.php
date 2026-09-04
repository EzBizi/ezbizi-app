<?php

namespace Tests\Feature\Phone;

use App\Models\Automation;
use App\Models\PhoneCall;
use App\Models\PhoneConnection;
use App\Models\PhoneMessage;
use App\Models\PhoneNumber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Which rules a call or a text concerns — asked of the record, answered here.
 *
 * ── Why the interface being implemented proves nothing ───────────
 *
 * `AutomationEngine` works out which rules an event concerns by asking the
 * subject where it sits, and its fallback is to sniff for a `franchise_id`
 * column. A call has no such column: it belongs where its LINE belongs, and a
 * line carries §3's `scope_type`/`scope_id` pair. So `automationScope()` is the
 * only thing standing between a branch manager's rule and matching nothing at
 * all for the life of the rule.
 *
 * The automation gate checks that the interface is implemented. It does not
 * read the answer. A mapping flattened to `[null, null]` implements the
 * interface perfectly and passes the gate green — which is how Marketing's went
 * wrong — and phone is on the grandfather list, so the gate is not even looking
 * at its shape yet. Nothing anywhere was reading these two words until this
 * file. Raised by the deploy desk on 2026-09-04.
 *
 * ── Composed rather than asserted bare ───────────────────────────
 *
 * Each case runs the answer through `Automation::coversScope`, the predicate
 * the engine actually applies, in the shape Email's MailboxWatchTest uses. A
 * scope reported correctly and then compared wrongly is the same bug with a
 * passing unit test standing in front of it.
 */
class PhoneAutomationScopeTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    /** A line at a given scope, and a call and a text that arrived on it. */
    private function lineAt(array $t, string $scopeType, string $scopeId): array
    {
        return $this->inTenant($t['org'], function () use ($t, $scopeType, $scopeId) {
            $connection = PhoneConnection::factory()->create([
                'scope_type' => PhoneConnection::SCOPE_ORGANIZATION,
                'scope_id' => $t['org']->id,
                'status' => PhoneConnection::STATUS_CONNECTED,
            ]);

            $number = PhoneNumber::factory()->create([
                'connection_id' => $connection->id,
                'scope_type' => $scopeType,
                'scope_id' => $scopeId,
                'e164' => '+1203555'.random_int(1000, 9999),
            ]);

            $call = PhoneCall::query()->create([
                'organization_id' => $t['org']->id,
                'connection_id' => $connection->id,
                'number_id' => $number->id,
                'twilio_sid' => 'CA'.bin2hex(random_bytes(16)),
                'direction' => 'inbound',
                'from_e164' => '+13055550100',
                'to_e164' => $number->e164,
                'status' => 'completed',
            ]);

            $message = PhoneMessage::query()->create([
                'organization_id' => $t['org']->id,
                'connection_id' => $connection->id,
                'number_id' => $number->id,
                'twilio_sid' => 'SM'.bin2hex(random_bytes(16)),
                'direction' => 'inbound',
                'from_e164' => '+13055550100',
                'to_e164' => $number->e164,
                'body' => 'Are you open Saturday',
                'status' => 'received',
            ]);

            // Loaded here, inside the tenant. automationScope() reads
            // $this->number, and a lazy load fired from an assertion outside
            // TenantContext is refused — correctly, since a cross-tenant read
            // of a phone line is exactly what that guard exists to stop. In
            // production the engine calls this mid-request, where the tenant
            // is already resolved, so eager-loading here matches how it runs
            // rather than working around the guard.
            return [$call->fresh('number'), $message->fresh('number')];
        });
    }

    /**
     * A branch's rule fires on that branch's phone traffic, and on nobody
     * else's.
     *
     * The failure this guards is silent in both directions. Too narrow and a
     * branch manager's rule never fires and nothing says why; too wide and one
     * branch's rule reads the caller ID and message body of the branch next
     * door's customers.
     */
    public function test_a_branch_line_belongs_to_that_branch(): void
    {
        $t = $this->provisionTenant();
        [$call, $message] = $this->lineAt($t, PhoneNumber::SCOPE_FRANCHISE, $t['franchise']->id);

        $ownBranch = new Automation(['scope_type' => 'franchise', 'scope_id' => $t['franchise']->id]);
        $nextDoor  = new Automation(['scope_type' => 'franchise', 'scope_id' => 'another-branch']);
        $wholeOrg  = new Automation(['scope_type' => null, 'scope_id' => null]);

        foreach (['call' => $call, 'message' => $message] as $what => $subject) {
            $this->assertSame(
                ['franchise', $t['franchise']->id],
                $subject->automationScope(),
                "a $what on a branch line did not report the branch",
            );

            [$scopeType, $scopeId] = $subject->automationScope();

            $this->assertTrue(
                $ownBranch->coversScope($scopeType, $scopeId),
                "a branch rule did not cover a $what on that branch's own line",
            );

            $this->assertFalse(
                $nextDoor->coversScope($scopeType, $scopeId),
                "one branch's rule fired on another branch's $what, handing them the "
                .'caller and the contents',
            );

            $this->assertTrue($wholeOrg->coversScope($scopeType, $scopeId));
        }
    }

    /**
     * A company line belongs to the company, and a branch rule does not reach
     * up to it.
     *
     * The company is where a shared main number sits, and the branches under it
     * must not each act on everything that arrives there.
     */
    public function test_a_company_line_belongs_to_the_company_and_not_to_its_branches(): void
    {
        $t = $this->provisionTenant();
        [$call, $message] = $this->lineAt($t, PhoneNumber::SCOPE_COMPANY, $t['company']->id);

        $theCompany = new Automation(['scope_type' => 'company', 'scope_id' => $t['company']->id]);
        $aBranch    = new Automation(['scope_type' => 'franchise', 'scope_id' => $t['franchise']->id]);

        foreach (['call' => $call, 'message' => $message] as $what => $subject) {
            $this->assertSame(
                ['company', $t['company']->id],
                $subject->automationScope(),
                "a $what on a company line did not report the company",
            );

            [$scopeType, $scopeId] = $subject->automationScope();

            // The third argument is the point of this case. A company-scoped
            // rule is NOT matched by scope equality — coversScope answers it
            // from the company CHAIN, so that a company rule reaches the
            // franchises of its Sub Companies too. The engine builds that
            // chain itself (AutomationEngine::companyChain) and always passes
            // it; a bare two-argument call answers false for a company rule
            // that in production fires perfectly well.
            $this->assertTrue(
                $theCompany->coversScope($scopeType, $scopeId, [$t['company']->id]),
                'a company rule did not cover its own main line',
            );

            $this->assertFalse(
                $aBranch->coversScope($scopeType, $scopeId, [$t['company']->id]),
                "a branch rule reached up to the company's main line and acted on a $what "
                .'that was never that branch\'s to see',
            );
        }
    }

    /**
     * An organization-wide line answers `[null, null]` — and that is the right
     * answer, not a flattened one.
     *
     * This is the case that makes the whole file worth having. `[null, null]`
     * is what a CORRECT organization line returns and also what a BROKEN
     * mapping returns for everything, so the two are indistinguishable unless
     * the branch and company cases above are pinned as well. Asserting this one
     * alone would pass just as happily against a method that had been reduced
     * to `return [null, null];`.
     */
    public function test_an_organization_line_is_organization_wide_and_does_not_cascade(): void
    {
        $t = $this->provisionTenant();
        [$call, $message] = $this->lineAt($t, PhoneNumber::SCOPE_ORGANIZATION, $t['org']->id);

        $aBranch  = new Automation(['scope_type' => 'franchise', 'scope_id' => $t['franchise']->id]);
        $aCompany = new Automation(['scope_type' => 'company', 'scope_id' => $t['company']->id]);
        $wholeOrg = new Automation(['scope_type' => null, 'scope_id' => null]);

        foreach (['call' => $call, 'message' => $message] as $what => $subject) {
            $this->assertSame([null, null], $subject->automationScope());

            $this->assertTrue($wholeOrg->coversScope(...$subject->automationScope()));

            $this->assertFalse(
                $aBranch->coversScope(...$subject->automationScope()),
                "a branch rule fired on an organization-wide $what",
            );

            // Nor does a company rule. An organization-wide line resolves to
            // no company, so the chain is empty and there is nothing for a
            // company rule to match — which is what keeps one company from
            // acting on the whole organization's main number.
            $this->assertFalse(
                $aCompany->coversScope(...$subject->automationScope()),
                "a company rule fired on an organization-wide $what",
            );
        }
    }

    /**
     * A record with no line left says organization-wide rather than throwing.
     *
     * `number_id` is nullable and a number can be released while its history
     * stays. The engine calls this on every event, so a null here would take
     * out automation processing for the whole organization rather than for one
     * orphaned row — and the narrow answer is the safe one: only an
     * organization-wide rule covers a record nobody can place.
     */
    public function test_a_call_with_no_line_left_is_organization_wide(): void
    {
        $t = $this->provisionTenant();

        [$call, $message] = $this->inTenant($t['org'], function () use ($t) {
            $connection = PhoneConnection::factory()->create([
                'scope_type' => PhoneConnection::SCOPE_ORGANIZATION,
                'scope_id' => $t['org']->id,
                'status' => PhoneConnection::STATUS_CONNECTED,
            ]);

            $call = PhoneCall::query()->create([
                'organization_id' => $t['org']->id,
                'connection_id' => $connection->id,
                'number_id' => null,
                'twilio_sid' => 'CA'.bin2hex(random_bytes(16)),
                'direction' => 'outbound',
                'from_e164' => '+12035550142',
                'to_e164' => '+13055550100',
                'status' => 'completed',
            ]);

            $message = PhoneMessage::query()->create([
                'organization_id' => $t['org']->id,
                'connection_id' => $connection->id,
                'number_id' => null,
                'twilio_sid' => 'SM'.bin2hex(random_bytes(16)),
                'direction' => 'outbound',
                'from_e164' => '+12035550142',
                'to_e164' => '+13055550100',
                'body' => 'On our way',
                'status' => 'sent',
            ]);

            return [$call->fresh(), $message->fresh()];
        });

        $this->assertSame([null, null], $call->automationScope());
        $this->assertSame([null, null], $message->automationScope());
    }
}
