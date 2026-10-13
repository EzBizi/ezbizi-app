<?php

namespace Tests\Feature\WhatsApp;

use App\Models\StaffBotItem;
use App\Models\StaffBotMenu;
use App\Models\TrainingGuide;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * AI Assistance › Staff bot: a menu for each group of roles, its rows linking
 * to a Training guide's chapter and section, a Library entry or a report
 * (UI queue 88). Every name here is invented.
 */
class StaffBotMenusApiTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private const ENDPOINT = '/api/v1/ai-assistance/staff-bot';

    private array $t;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->provisionTenant('A1-Group');
    }

    private function guide(array $t): TrainingGuide
    {
        return $this->inTenant($t['org'], fn () => TrainingGuide::create([
            'company_id' => null, 'franchise_id' => null, 'title' => 'Dispatcher guide',
            'status' => TrainingGuide::PUBLISHED,
            'screens' => [
                ['title' => 'Answering', 'chapter' => 'Dispatcher Essentials', 'section' => 'Customer Service',
                    'blocks' => [['type' => 'text', 'text' => 'Answer by the third ring.']]],
                ['title' => 'Scripts', 'chapter' => 'New Client calls', 'section' => 'Scripts',
                    'blocks' => [['type' => 'text', 'text' => 'Good morning, Northwind Chimney.']]],
            ],
        ]));
    }

    public function test_a_menu_for_some_roles_with_a_row_that_links_a_guide_section(): void
    {
        $dispatcher = $this->userWithRole($this->t, 'Dispatcher', ['leads.view']);
        $stranger = $this->userWithRole($this->provisionTenant('Premier-Garage'), 'Elsewhere', ['leads.view']);
        $guide = $this->guide($this->t);

        $menu = $this->signedInAs($this->t['admin'])
            ->postJson(self::ENDPOINT.'/menus', [
                'name' => 'Office',
                'role_ids' => [$dispatcher->role_id, $stranger->role_id],
            ])
            ->assertCreated()
            // Another business's role is not stored.
            ->assertJsonPath('data.role_ids', [$dispatcher->role_id])
            ->json('data');

        $this->signedInAs($this->t['admin'])
            ->postJson(self::ENDPOINT.'/menus/'.$menu['id'].'/items', [
                'title' => 'Dispatch guidelines',
                'line' => 'How we answer and what we say',
                'source' => 'guide',
                'training_guide_id' => $guide->id,
                'chapter' => 'Dispatcher Essentials',
                'section' => 'Customer Service',
            ])
            ->assertCreated()
            ->assertJsonPath('data.guide_title', 'Dispatcher guide')
            ->assertJsonPath('data.ready', true);

        $this->signedInAs($this->t['admin'])->getJson(self::ENDPOINT)
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Office')
            ->assertJsonPath('data.0.items.0.title', 'Dispatch guidelines')
            ->assertJsonPath('max_items', 9)
            ->assertJsonFragment(['id' => $dispatcher->role_id, 'name' => 'Dispatcher'])
            ->assertJsonFragment(['name' => 'Dispatcher Essentials', 'sections' => ['Customer Service']])
            ->assertJsonFragment(['key' => 'daily_conversion'])
            ->assertJsonMissing(['name' => 'Elsewhere']);
    }

    public function test_a_row_with_nothing_behind_it_is_not_ready(): void
    {
        $menu = $this->inTenant($this->t['org'], fn () => StaffBotMenu::create(['name' => 'Technicians', 'role_ids' => []]));

        // A report nothing writes yet, and a guide section that does not exist.
        $this->signedInAs($this->t['admin'])
            ->postJson(self::ENDPOINT.'/menus/'.$menu->id.'/items', [
                'title' => 'Daily conversion', 'source' => 'report', 'report' => 'daily_conversion',
            ])
            ->assertCreated()
            ->assertJsonPath('data.ready', false);

        $guide = $this->guide($this->t);
        $this->signedInAs($this->t['admin'])
            ->postJson(self::ENDPOINT.'/menus/'.$menu->id.'/items', [
                'title' => 'Before & After', 'source' => 'guide',
                'training_guide_id' => $guide->id, 'chapter' => 'Gallery',
            ])
            ->assertCreated()
            ->assertJsonPath('data.ready', false);
    }

    public function test_whatsapps_lengths_and_nine_rows_are_held_to(): void
    {
        $menu = $this->inTenant($this->t['org'], fn () => StaffBotMenu::create(['name' => 'Office', 'role_ids' => []]));

        $this->signedInAs($this->t['admin'])
            ->postJson(self::ENDPOINT.'/menus/'.$menu->id.'/items', [
                'title' => str_repeat('x', 25), 'source' => 'report', 'report' => 'daily_conversion',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('title');

        $this->inTenant($this->t['org'], function () use ($menu) {
            foreach (range(1, 9) as $n) {
                StaffBotItem::create(['organization_id' => $menu->organization_id, 'menu_id' => $menu->id,
                    'position' => $n, 'title' => "Topic $n", 'source' => 'report', 'report' => 'daily_conversion']);
            }
        });

        $this->signedInAs($this->t['admin'])
            ->postJson(self::ENDPOINT.'/menus/'.$menu->id.'/items', [
                'title' => 'A tenth', 'source' => 'report', 'report' => 'daily_conversion',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('title');
    }

    public function test_the_rows_are_put_in_order(): void
    {
        [$menu, $a, $b] = $this->inTenant($this->t['org'], function () {
            $menu = StaffBotMenu::create(['name' => 'Office', 'role_ids' => []]);
            $make = fn (string $title, int $p) => StaffBotItem::create(['organization_id' => $menu->organization_id,
                'menu_id' => $menu->id, 'position' => $p, 'title' => $title, 'source' => 'report', 'report' => 'daily_conversion']);

            return [$menu, $make('Locations', 1), $make('Call scripts', 2)];
        });

        $this->signedInAs($this->t['admin'])
            ->putJson(self::ENDPOINT.'/menus/'.$menu->id.'/order', ['item_ids' => [$b->id, $a->id]])
            ->assertOk()
            ->assertJsonPath('data.items.*.title', ['Call scripts', 'Locations']);

        // Every row, once each: a partial order would leave two in one place.
        $this->signedInAs($this->t['admin'])
            ->putJson(self::ENDPOINT.'/menus/'.$menu->id.'/order', ['item_ids' => [$b->id]])
            ->assertUnprocessable();
    }

    public function test_it_is_for_whoever_manages_ai_assistance(): void
    {
        $reader = $this->userWithRole($this->t, 'Reader', ['ai_assistance.view', 'ai_assistance.ai.view']);

        $this->signedInAs($reader)->getJson(self::ENDPOINT)->assertForbidden();
        $this->signedInAs($reader)->postJson(self::ENDPOINT.'/menus', ['name' => 'Office'])->assertForbidden();
    }
}
