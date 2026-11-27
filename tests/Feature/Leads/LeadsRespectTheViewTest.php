<?php

namespace Tests\Feature\Leads;

use App\Models\Company;
use App\Models\Franchise;
use App\Models\Lead;
use App\Models\SocialConnection;
use App\Support\Social\SocialProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * The list obeys the switcher at the top of the window.
 *
 * ── The hole this closes ──
 *
 * `index()` narrowed by REACH — "may this person act here" — and reach stops
 * at the organization, so for an owner it filtered nothing at all. The scope
 * switcher changed the connections band and left the rows beneath it alone.
 * Jose, 2026-09-10, standing in Everdoors › A1 Garage Doors: "i should only
 * see them mixed when im in the organization level ... right now it's showing
 * me a1chimney and a1 garage doors."
 *
 * `ConnectionsRespectTheViewTest` says in prose that "index() correctly
 * filters the rows by view". It asserted the connections band and never read a
 * row, so the sentence was true of the half it measured and false of the half
 * it described. Everything below reads rows.
 *
 * The rule is deliberately the one `SocialConnectionAccess::inView` already
 * applies to connections: a lead is filed where its connection was, so if the
 * two disagreed the band would say one thing and the list another.
 */
class LeadsRespectTheViewTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    /**
     * The account these enquiries arrived through.
     *
     * Every row here is `local_services`, and since 2026-09-16 a Google or
     * Thumbtack enquiry with no connection is HIDDEN from the list - it means
     * the account was disconnected. Without this the fixtures were rows that
     * cannot exist in production, and the view assertions below would pass or
     * fail for a reason that has nothing to do with the view.
     *
     * One connection for all of them on purpose: placement is read off the
     * LEAD's company and franchise columns, so which account it came through
     * must not change the answer, and sharing one is the cheapest way to say
     * so.
     */
    private function account(array $t): SocialConnection
    {
        return $this->connection ??= $this->inTenant($t['org'], function () use ($t) {
            $row = SocialConnection::create([
                'organization_id' => $t['org']->getKey(),
                'scope_type'      => SocialConnection::SCOPE_ORGANIZATION,
                'scope_id'        => $t['org']->getKey(),
                'provider'        => SocialProvider::GoogleAds->value,
                'remote_id'       => '4051729356',
                'display_name'    => 'A1 Chimney',
            ]);
            $row->connect(['refresh_token' => 'rt-good']);
            $row->save();

            return $row;
        });
    }

    private ?SocialConnection $connection = null;

    private function lead(array $t, ?Company $company, ?Franchise $franchise, string $name): Lead
    {
        static $n = 0;
        $n++;

        $account = $this->account($t);

        return $this->inTenant($t['org'], fn () => Lead::create([
            'organization_id' => $t['org']->getKey(),
            'company_id'      => $company?->getKey(),
            'franchise_id'    => $franchise?->getKey(),
            'connection_id'   => $account->getKey(),
            'source'          => Lead::SOURCE_LOCAL_SERVICES,
            'remote_id'       => 'L-'.$n,
            'contact_name'    => $name,
            'status'          => Lead::STATUS_NEW,
            'received_at'     => now()->subMinutes($n),
        ]));
    }

    /** @return string[] the contact names the list returned */
    private function names(array $t, ?string $view): array
    {
        $request = $this->signedInAs($t['admin']);

        if ($view !== null) {
            $request = $request->withHeader('X-EzBizi-View', $view);
        }

        return array_column($request->getJson('/api/v1/leads')->assertOk()->json('data'), 'contact_name');
    }

    public function test_a_company_view_shows_that_companys_enquiries_and_not_a_siblings(): void
    {
        $t       = $this->provisionTenant('A1-Group');
        $sibling = $this->inTenant($t['org'], fn () => Company::create([
            'organization_id' => $t['org']->getKey(),
            'name'            => 'A1 Garage Doors',
        ]));

        $this->lead($t, $t['company'], null, 'Chimney caller');
        $this->lead($t, $sibling, null, 'Garage caller');

        $names = $this->names($t, 'company:'.$sibling->getKey());

        $this->assertSame(['Garage caller'], $names);

        // The control on the other side of the boundary: the same owner, the
        // same request, one header different. Without this the assertion above
        // passes just as well on a list that returns nothing at all.
        $this->assertEqualsCanonicalizing(
            ['Chimney caller', 'Garage caller'],
            $this->names($t, 'organization'),
        );
    }

    /**
     * An enquiry filed at organization level belongs to nobody in particular,
     * and shows everywhere — the same answer `inView` gives an
     * organization-scoped connection. Jose's twelve Thumbtack businesses are
     * connected that way, so this is the case that keeps their enquiries
     * visible from inside a company.
     */
    public function test_an_enquiry_filed_at_organization_level_is_visible_from_inside_a_company(): void
    {
        $t = $this->provisionTenant('A1-Group');

        $this->lead($t, null, null, 'Thumbtack customer');
        $this->lead($t, $t['company'], null, 'Chimney caller');

        $this->assertEqualsCanonicalizing(
            ['Thumbtack customer', 'Chimney caller'],
            $this->names($t, 'company:'.$t['company']->getKey()),
        );
    }

    public function test_a_franchise_view_keeps_its_own_companys_work(): void
    {
        $t     = $this->provisionTenant('A1-Group');
        $other = $this->inTenant($t['org'], fn () => Company::create([
            'organization_id' => $t['org']->getKey(),
            'name'            => 'A1 Garage Doors',
        ]));

        $this->lead($t, $t['company'], $t['franchise'], 'Branch caller');
        $this->lead($t, $t['company'], null, 'Company caller');
        $this->lead($t, $other, null, 'Garage caller');

        $names = $this->names($t, 'franchise:'.$t['franchise']->getKey());

        // Its own branch, and the company above it — the same two things a
        // franchise view sees of connections. Never the sibling company.
        $this->assertEqualsCanonicalizing(['Branch caller', 'Company caller'], $names);
    }

    /**
     * Listed means workable. Analorena (A-1 Chimney only) saw an EzBizi Page
     * enquiry filed at organization level and was refused its status change.
     */
    public function test_an_enquiry_listed_from_inside_a_company_can_be_changed_there(): void
    {
        $t       = $this->provisionTenant('A1-Group');
        $sibling = $this->inTenant($t['org'], fn () => Company::create([
            'organization_id' => $t['org']->getKey(),
            'name'            => 'A1 Garage Doors',
        ]));
        $everybody = $this->lead($t, null, null, 'Page enquiry');
        $theirs    = $this->lead($t, $sibling, null, 'Garage caller');
        $branch    = $this->userWithRole($t, 'Dispatcher', ['leads.view', 'leads.edit', 'leads.local_services.view'], onlyAt: $t['franchise']);

        $this->signedInAs($branch)->patchJson('/api/v1/leads/'.$everybody->getKey(), ['status' => Lead::STATUS_ARCHIVED])->assertOk();
        // The control: a sibling company's lead is still out of reach.
        $this->signedInAs($branch)->patchJson('/api/v1/leads/'.$theirs->getKey(), ['status' => Lead::STATUS_ARCHIVED])->assertForbidden();

        // The owner standing in a company view, the other half of the same refusal.
        $this->signedInAs($t['admin'])->withHeader('X-EzBizi-View', 'company:'.$t['company']->getKey())
            ->patchJson('/api/v1/leads/'.$everybody->getKey(), ['status' => Lead::STATUS_NEW])->assertOk();
    }

    /** With no header at all, nothing is narrowed and nothing is lost. */
    public function test_the_organization_view_is_still_everything(): void
    {
        $t       = $this->provisionTenant('A1-Group');
        $sibling = $this->inTenant($t['org'], fn () => Company::create([
            'organization_id' => $t['org']->getKey(),
            'name'            => 'A1 Garage Doors',
        ]));

        $this->lead($t, $t['company'], null, 'Chimney caller');
        $this->lead($t, $sibling, null, 'Garage caller');
        $this->lead($t, null, null, 'Everybody');

        $this->assertEqualsCanonicalizing(
            ['Chimney caller', 'Garage caller', 'Everybody'],
            $this->names($t, null),
        );
    }
}
