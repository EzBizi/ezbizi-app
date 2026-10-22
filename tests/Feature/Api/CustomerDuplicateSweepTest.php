<?php

namespace Tests\Feature\Api;

use App\Models\Customer;
use App\Models\Franchise;
use App\Support\Settings\SettingsResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * GET /customers/duplicates — the sweep, as opposed to the create-time check.
 *
 * The two answer different questions and only one of them can see an import.
 * `possible_duplicates` rides along when somebody WRITES a customer, so every
 * pair a bulk import manufactures is invisible to it: nobody typed them. This
 * asks the same question of the book that is already there.
 *
 * The cases worth pinning are the ones a reasonable reimplementation gets
 * wrong: that it crosses franchises (the duplicate most worth finding is the
 * one two branches each created), that "off" is a different answer from "none
 * found", and that the short-number floor the one-record check applies is
 * applied here too — without it a sweep groups half a book on a shared
 * extension.
 */
class CustomerDuplicateSweepTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private array $alpha;

    protected function setUp(): void
    {
        parent::setUp();
        $this->alpha = $this->provisionTenant('Alpha', 'a@alpha.test');
    }

    private function customer(Franchise $franchise, string $name, array $attributes = []): Customer
    {
        return $this->inTenant($this->alpha['org'], fn () => Customer::create($attributes + [
            'owning_franchise_id' => $franchise->id,
            'number' => 'C-'.bin2hex(random_bytes(3)),
            'name' => $name,
        ]));
    }

    private function matchOn(string $mode): void
    {
        $this->inTenant(
            $this->alpha['org'],
            fn () => app(SettingsResolver::class)->set('customers.duplicate_match_on', $this->alpha['org'], $mode)
        );
    }

    public function test_it_finds_one_person_two_branches_each_created(): void
    {
        $this->matchOn('phone');
        $second = $this->extraFranchise($this->alpha, 'Alpha F2');

        // The same number typed two ways, by two branches that never saw each
        // other's list. This is the case the feature exists for.
        $this->customer($this->alpha['franchise'], 'Philip Ash', ['phone' => '(555) 123-4567']);
        $this->customer($second, 'Phil Ash', ['phone' => '555-123-4567']);

        // A CONTROL that must not appear: without it, a sweep that returned
        // every customer in the book would pass every other assertion here.
        $this->customer($this->alpha['franchise'], 'Someone Else', ['phone' => '555-999-0000']);

        $groups = $this->signedInAs($this->alpha['admin'])
            ->getJson('/api/v1/customers/duplicates')->assertOk()->json('data');

        $this->assertCount(1, $groups);
        $this->assertSame('phone', $groups[0]['by']);

        $names = array_column($groups[0]['customers'], 'name');
        sort($names);
        $this->assertSame(['Phil Ash', 'Philip Ash'], $names);

        // Named rather than counted: the group has to carry WHO is accountable
        // for each side, because merging them is a decision about two branches'
        // numbers and not only about two rows.
        $owners = array_column(array_column($groups[0]['customers'], 'owning_franchise'), 'name');
        sort($owners);
        $this->assertSame(['Alpha F1', 'Alpha F2'], $owners);
    }

    public function test_a_number_too_short_to_mean_anything_is_not_a_match(): void
    {
        $this->matchOn('phone');

        // Six digits is an extension or a typo. Two people sharing one are not
        // the same person, and grouping on it would sweep half a book into one
        // pile — the same floor `duplicatePhone()` applies to one record.
        $this->customer($this->alpha['franchise'], 'One', ['phone' => '123456']);
        $this->customer($this->alpha['franchise'], 'Two', ['phone' => '123456']);

        // A positive control in the same run: seven digits DOES group, so a
        // green here means the floor held rather than that the sweep is dead.
        $this->customer($this->alpha['franchise'], 'Three', ['phone' => '1234567']);
        $this->customer($this->alpha['franchise'], 'Four', ['phone' => '123-4567']);

        $groups = $this->signedInAs($this->alpha['admin'])
            ->getJson('/api/v1/customers/duplicates')->assertOk()->json('data');

        $this->assertCount(1, $groups);
        $names = array_column($groups[0]['customers'], 'name');
        sort($names);
        $this->assertSame(['Four', 'Three'], $names);
    }

    public function test_it_says_how_many_there_really_are_when_it_truncates(): void
    {
        $this->matchOn('phone');

        // 201 groups against a cap of 200. Built because the cap was wrong on
        // the first real data anybody measured -- A-1's Workiz book holds 149
        // phone groups and this shipped capped at 50 -- and a truncating screen
        // that cannot say how much it is hiding is the defect, not the cap.
        //
        // This is also the only case that can tell `total` from "however many
        // came back". Under the cap the two coincide, so a test that never
        // exceeds it passes just as well against a `total` hardcoded to the
        // size of the list it sits beside.
        $this->inTenant($this->alpha['org'], function () {
            for ($i = 0; $i < 201; $i++) {
                $phone = '555-'.str_pad((string) $i, 3, '0', STR_PAD_LEFT).'-0000';
                Customer::create([
                    'owning_franchise_id' => $this->alpha['franchise']->id,
                    'number' => "C-A{$i}", 'name' => "A{$i}", 'phone' => $phone,
                ]);
                Customer::create([
                    'owning_franchise_id' => $this->alpha['franchise']->id,
                    'number' => "C-B{$i}", 'name' => "B{$i}", 'phone' => $phone,
                ]);
            }
        });

        $response = $this->signedInAs($this->alpha['admin'])
            ->getJson('/api/v1/customers/duplicates')->assertOk();

        $this->assertCount(200, $response->json('data'));
        $this->assertSame(201, $response->json('total'));
        $this->assertTrue($response->json('limited'));
    }

    public function test_off_says_so_rather_than_reporting_none_found(): void
    {
        $this->matchOn('off');
        $this->customer($this->alpha['franchise'], 'One', ['phone' => '555-123-4567']);
        $this->customer($this->alpha['franchise'], 'Two', ['phone' => '555-123-4567']);

        $response = $this->signedInAs($this->alpha['admin'])
            ->getJson('/api/v1/customers/duplicates')->assertOk();

        // An empty list alone would read as "we looked and there are none",
        // which is the opposite of true here: there are two and nobody looked.
        $this->assertSame([], $response->json('data'));
        $this->assertSame('off', $response->json('mode'));
        $this->assertNotNull($response->json('message'));
    }

    public function test_phone_or_email_does_not_chain_two_people_into_one_group(): void
    {
        $this->matchOn('phone_or_email');

        // A shares a phone with B. B shares an email with C. A and C have
        // nothing in common, and a sweep that grouped on "either" as one key
        // would put all three together and invite somebody to merge A into C.
        $this->customer($this->alpha['franchise'], 'A', ['phone' => '555-111-2222', 'email' => 'a@x.test']);
        $this->customer($this->alpha['franchise'], 'B', ['phone' => '555-111-2222', 'email' => 'shared@x.test']);
        $this->customer($this->alpha['franchise'], 'C', ['phone' => '555-333-4444', 'email' => 'shared@x.test']);

        $groups = $this->signedInAs($this->alpha['admin'])
            ->getJson('/api/v1/customers/duplicates')->assertOk()->json('data');

        $this->assertCount(2, $groups);

        foreach ($groups as $group) {
            $names = array_column($group['customers'], 'name');
            sort($names);
            $this->assertContains($names, [['A', 'B'], ['B', 'C']], 'A and C reached each other through B.');
        }
    }

    public function test_somebody_assigned_to_one_branch_still_sees_a_duplicate_spanning_two(): void
    {
        $this->matchOn('phone');
        $second = $this->extraFranchise($this->alpha, 'Alpha F2');

        $this->customer($this->alpha['franchise'], 'Here', ['phone' => '555-123-4567']);
        $this->customer($second, 'There', ['phone' => '555-123-4567']);

        // Assigned at one franchise only. The sweep is deliberately NOT scoped
        // to reach: customers are readable organization-wide (§5.2), and the
        // duplicate worth finding is precisely the one whose other half sits
        // somewhere this person does not work. A reach filter added later would
        // hide exactly the rows this feature exists to surface, and would look
        // like a security improvement while doing it.
        $narrow = $this->userWithRole(
            $this->alpha, 'Branch', ['customers.view'], 'branch@alpha.test', onlyAt: $this->alpha['franchise'],
        );

        $groups = $this->signedInAs($narrow)
            ->getJson('/api/v1/customers/duplicates')->assertOk()->json('data');

        $this->assertCount(1, $groups);
        $this->assertCount(2, $groups[0]['customers']);
    }

    /**
     * The search bar: `q` keeps a whole group when one member matches, so the
     * group is not split into a "duplicate" of one. A blank `q` is no filter.
     */
    public function test_q_narrows_to_whole_groups_and_a_blank_q_changes_nothing(): void
    {
        $this->matchOn('phone');
        $this->customer($this->alpha['franchise'], 'Philip Ash', ['phone' => '(555) 123-4567']);
        $this->customer($this->alpha['franchise'], 'Phil Ash', ['phone' => '555-123-4567']);
        $this->customer($this->alpha['franchise'], 'Rosa Vidal', ['phone' => '555-777-8888']);
        $this->customer($this->alpha['franchise'], 'R. Vidal', ['phone' => '5557778888']);

        $as = $this->signedInAs($this->alpha['admin']);
        $found = $as->getJson('/api/v1/customers/duplicates?q=philip')->assertOk()->assertJsonPath('total', 1)->json('data');
        $this->assertCount(1, $found);
        $this->assertEqualsCanonicalizing(['Phil Ash', 'Philip Ash'], array_column($found[0]['customers'], 'name'));

        $as->getJson('/api/v1/customers/duplicates?q=')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('total', 2);
        $as->getJson('/api/v1/customers/duplicates')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('total', 2);
    }
}
