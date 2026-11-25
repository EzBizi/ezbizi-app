<?php

namespace Tests\Feature\Tasks;

use App\Models\Assignment;
use App\Models\Company;
use App\Models\Franchise;
use App\Models\User;
use App\Support\Tasks\TaskBoards;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Jose 11-25: "It should follow your own permissions and hierarchy." The board
 * list shows every board the person reaches, whatever the Company·Branch picker
 * says; the picker still scopes everything else.
 */
class TaskBoardListReachTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    /** @return array{0: array, 1: Company, 2: Franchise, 3: Franchise} tenant, second company, its branch, a second branch of the first */
    private function world(): array
    {
        $t = $this->provisionTenant('Alpha');
        [$other, $otherBranch, $sideBranch] = $this->inTenant($t['org'], function () use ($t) {
            $other = Company::create(['organization_id' => $t['org']->id, 'name' => 'Everdoors', 'is_active' => true]);
            $otherBranch = Franchise::create(['organization_id' => $t['org']->id, 'company_id' => $other->id, 'name' => 'Ohio',
                'timezone' => 'America/New_York', 'currency' => 'USD', 'is_active' => true]);
            $side = Franchise::create(['organization_id' => $t['org']->id, 'company_id' => $t['company']->id, 'name' => 'Texas',
                'timezone' => 'America/New_York', 'currency' => 'USD', 'is_active' => true]);
            foreach ([$t['company'], $t['franchise'], $other, $otherBranch, $side] as $place) {
                app(TaskBoards::class)->ensureFor($place);
            }

            return [$other, $otherBranch, $side];
        });

        return [$t, $other, $otherBranch, $sideBranch];
    }

    /** @return list<string> the names of the place boards the list shows */
    private function places(User $u): array
    {
        return collect($this->signedInAs($u)->getJson('/api/v1/task-boards')->assertOk()->json('data'))
            ->whereIn('kind', ['company', 'franchise'])->map(fn ($b) => ($b['company_name'] ?? '').' / '.$b['name'])->sort()->values()->all();
    }

    private function pick(array $t, User $u, string $scope): void
    {
        $this->inTenant($t['org'], fn () => User::find($u->id)->forceFill(['preferences' => ['navigation.scope' => $scope]])->save());
    }

    public function test_an_organization_admin_sees_every_company_whatever_the_picker_says(): void
    {
        [$t, $other, $otherBranch] = $this->world();
        $this->pick($t, $t['admin'], 'company:'.$t['company']->id);
        $seen = $this->places($t['admin']);
        $this->assertContains('Everdoors / Everdoors', $seen, 'a company outside the picked one');
        $this->assertContains('Everdoors / Ohio', $seen);
        // And it opens.
        $board = collect($this->signedInAs($t['admin'])->getJson('/api/v1/task-boards')->json('data'))->firstWhere('name', 'Everdoors');
        $this->signedInAs($t['admin'])->getJson("/api/v1/task-boards/{$board['id']}")->assertOk();
        $this->assertArrayHasKey('parent_company_id', $board, 'the tree reads parentage from the board itself');
        $this->assertNull($board['parent_company_id']);
        // Row 68: the company's and the organization's brand colours.
        $this->inTenant($t['org'], function () use ($t, $other) {
            $other->forceFill(['brand_colour' => '#ff7a00'])->save();
            $t['org']->forceFill(['brand_colour' => '#0a66c2'])->save();
        });
        $row = collect($this->signedInAs($t['admin'])->getJson('/api/v1/task-boards')->json('data'))->firstWhere('name', 'Everdoors');
        $this->assertSame('#ff7a00', $row['company_colour']);
        $this->assertSame('#0a66c2', $row['organization_colour']);
    }

    public function test_a_company_manager_sees_that_company_tree_and_not_another(): void
    {
        [$t] = $this->world();
        $manager = $this->userWithRole($t, 'Manager', ['tasks.view', 'tasks.edit']);
        $this->inTenant($t['org'], function () use ($t, $manager) {
            Assignment::query()->where('user_id', $manager->id)->delete();
            Assignment::create(['organization_id' => $t['org']->id, 'user_id' => $manager->id, 'assigned_company_id' => $t['company']->id]);
        });
        $seen = $this->places($manager);
        $company = $t['company']->name;
        $this->assertContains("$company / $company", $seen);
        $this->assertContains("$company / Texas", $seen);
        $this->assertNotContains('Everdoors / Everdoors', $seen);
        $this->assertNotContains('Everdoors / Ohio', $seen);
    }

    public function test_a_branch_user_sees_the_branch_and_its_company_board_only(): void
    {
        [$t] = $this->world();
        $tech = $this->userWithRole($t, 'Tech', ['tasks.view'], onlyAt: $t['franchise']);
        $seen = $this->places($tech);
        $company = $t['company']->name;
        $this->assertContains("$company / {$t['franchise']->name}", $seen);
        $this->assertContains("$company / $company", $seen, 'and the company board');
        $this->assertNotContains("$company / Texas", $seen, 'not a sister branch');
        $this->assertNotContains('Everdoors / Everdoors', $seen);
    }
}
