<?php

namespace Tests\Feature\Leads;

use App\Models\Assignment;
use App\Models\Lead;
use App\Models\User;
use App\Support\Notifications\NotificationDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Where an enquiry says it sits, and who therefore hears about it.
 *
 * ── The defect ──────────────────────────────────────────────────
 *
 * `Lead` did not implement `CarriesAutomationScope`, so
 * `NotificationDispatcher` fell through to sniffing `franchise_id`, found null
 * on a website chat, and took the "nothing to scope by" branch — which returns
 * every active user. Measured on production by Automations, 2026-09-16: all
 * five website_chat leads carry a company and no franchise, so Jose's live
 * "Live Chat Lead Warning" rule was notifying all twelve people in the
 * business about each enquiry.
 *
 * ── Why the audience is asserted and not only the tuple ─────────
 *
 * `automationScope()` returning the right two words proves nothing a reader
 * cares about. The claim is that FEWER PEOPLE are told, and the only way that
 * claim can be wrong while the tuple is right is in the dispatcher. So the
 * franchise arm below goes through `NotificationDispatcher` itself and counts
 * the audience, with a user who must NOT be in it.
 *
 * ── The answer that was nearly shipped ──────────────────────────
 *
 * The suggested fix was to return the company unconditionally. The third test
 * is the one that forbids it: a lead from an organization-scoped connection
 * carries company and franchise both null, and a blanket answer would hand
 * back `['company', null]` — a scope type naming no id, which no user can be
 * covered by. That is every Thumbtack row, silently told to nobody.
 */
class LeadAutomationScopeTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private function lead(array $t, array $placement): Lead
    {
        static $n = 0;
        $n++;

        return $this->inTenant($t['org'], fn () => Lead::create([
            'organization_id' => $t['org']->getKey(),
            'source' => Lead::SOURCE_WEBSITE_CHAT,
            'remote_id' => 'chat-'.$n,
            'contact_name' => 'Visitor '.$n,
            'status' => Lead::STATUS_NEW,
            'received_at' => now(),
            ...$placement,
        ]));
    }

    public function test_an_enquiry_in_a_franchise_names_that_franchise(): void
    {
        $t = $this->provisionTenant('Alpha');

        $this->inTenant($t['org'], function () use ($t) {
            $lead = $this->lead($t, [
                'company_id' => $t['company']->getKey(),
                'franchise_id' => $t['franchise']->getKey(),
            ]);

            $this->assertSame(
                [Assignment::SCOPE_FRANCHISE, $t['franchise']->getKey()],
                $lead->automationScope(),
                'a placed enquiry must name its branch, not the company above it',
            );
        });
    }

    /**
     * THE ONE JOSE IS LIVING WITH. A website chat carries a company and no
     * branch, which is the shape that reached the everybody-active branch.
     */
    public function test_a_website_chat_names_its_company(): void
    {
        $t = $this->provisionTenant('Alpha');

        $this->inTenant($t['org'], function () use ($t) {
            $lead = $this->lead($t, ['company_id' => $t['company']->getKey()]);

            $this->assertSame(
                [Assignment::SCOPE_COMPANY, $t['company']->getKey()],
                $lead->automationScope(),
            );
        });
    }

    /**
     * AND THE CASE A BLANKET COMPANY ANSWER BREAKS.
     *
     * Every row from an organization-scoped connection — Thumbtack's twelve
     * businesses among them — carries neither column. `[null, null]` is the
     * interface's word for "only an organization-wide rule covers this", and
     * the dispatcher turns it into the people who stand at the organization.
     * `['company', null]` would instead name a scope with no id.
     */
    public function test_an_organization_level_enquiry_claims_no_narrower_scope(): void
    {
        $t = $this->provisionTenant('Alpha');

        $this->inTenant($t['org'], function () use ($t) {
            $lead = $this->lead($t, []);

            $this->assertSame([null, null], $lead->automationScope());

            // Said twice on purpose: the id being null is the half that would
            // have been shipped, and asserting the pair alone reads as a typo.
            [$type, $id] = $lead->automationScope();
            $this->assertNull($type, 'a scope type naming no id is worse than no scope');
            $this->assertNull($id);
        });
    }

    /**
     * THE ARTEFACT: how many people are actually told.
     *
     * The tuple tests above all pass on a dispatcher that ignores the
     * interface entirely. This one counts recipients through `dispatch()`,
     * which is the number that was twelve in production.
     *
     * The comparison is against EVERYBODY ACTIVE, because that is what the old
     * fallback returned - not against another scope. A first attempt compared
     * an organization-level enquiry with a placed one and got 1 against 1: a
     * user assigned to one branch does not stand at the organization either, so
     * neither number moved and the test proved nothing. The control below is
     * the active head count, so "fewer than everybody" is measured rather than
     * assumed.
     */
    public function test_a_placed_enquiry_is_not_announced_to_everybody(): void
    {
        $t = $this->provisionTenant('Alpha');
        $nextDoor = $this->extraFranchise($t, 'Alpha F2');

        $this->userWithRole(
            $t,
            'Branch Two Dispatcher',
            ['leads.view'],
            'outsider@alpha.test',
            onlyAt: $nextDoor,
        );

        [$told, $active] = $this->inTenant($t['org'], function () use ($t) {
            $placed = $this->lead($t, [
                'company_id' => $t['company']->getKey(),
                'franchise_id' => $t['franchise']->getKey(),
            ]);

            return [
                app(NotificationDispatcher::class)->dispatch(
                    title: 'New enquiry',
                    subject: $placed,
                    source: 'leads',
                ),
                User::query()->where('is_active', true)->count(),
            ];
        });

        // The control. Without it, "told fewer than everybody" passes on a
        // business with one employee, which is every fixture by default.
        $this->assertGreaterThan(
            1,
            $active,
            'there must be somebody to leave out, or the assertion below is vacuous',
        );

        $this->assertGreaterThan(0, $told, 'somebody has to hear about the enquiry');

        $this->assertLessThan(
            $active,
            $told,
            'an enquiry placed in one branch was announced to every active user - '
            .'this is the count that was twelve',
        );
    }
}
