<?php

namespace Tests\Feature\Inventory;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * One grant per chip (Jose, 2026-09-17: "grant access by sub tab as well").
 *
 * ── Why the positive control comes first in every case ──────────
 *
 * Every other Inventory test was made to pass by ADDING the tab keys to its
 * fixture. That would have happened just as smoothly if the middleware had
 * never been written: a suite of 403 assertions cannot tell a working gate from
 * a broken route, and a suite of 200 assertions cannot tell a gate from no gate
 * at all. Each test below asserts both halves against the same fixture, so the
 * only way to pass is for the grant to be the thing that decides.
 *
 * ── Why two tabs are gated and two are not ──────────────────────
 *
 * `stock` and `orders` own their endpoints. `items` and `locations` are the
 * module's reference data: six panes read items and seven read locations, so
 * every "new purchase order", "new sale", "new issue" and "new transfer" form
 * needs them. The last test here PINS that, deliberately — it asserts those two
 * still answer WITHOUT their tab grant, so a later session that tidies them
 * behind a blanket middleware goes red with the reason attached instead of
 * refusing the Orders tab in the middle of writing an order.
 */
class InventoryTabPermissionsTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_the_orders_tab_grant_is_what_opens_buying_and_selling(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        $with = $this->userWithRole($tenant, 'Buyer', [
            'inventory.view', 'inventory.orders.view',
        ]);
        $without = $this->userWithRole($tenant, 'Counter', [
            'inventory.view', 'inventory.stock.view',
        ]);

        foreach (['inventory/purchase-orders', 'inventory/sales', 'inventory/issues', 'inventory/vendors'] as $route) {
            // Positive control: the grant reaches it.
            $this->signedInAs($with)->getJson("/api/v1/{$route}")
                ->assertOk();

            // And the same route, same tenant, refused for want of that grant
            // alone — the other person holds the module and a different tab.
            $this->signedInAs($without)->getJson("/api/v1/{$route}")
                ->assertForbidden();
        }
    }

    public function test_the_stock_tab_grant_is_what_opens_on_hand(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        $with = $this->userWithRole($tenant, 'Counter', [
            'inventory.view', 'inventory.stock.view',
        ]);
        $without = $this->userWithRole($tenant, 'Buyer', [
            'inventory.view', 'inventory.orders.view',
        ]);

        $this->signedInAs($with)->getJson('/api/v1/inventory/stock')->assertOk();
        $this->signedInAs($without)->getJson('/api/v1/inventory/stock')->assertForbidden();
    }

    public function test_the_refusal_names_the_tab_rather_than_the_permission_key(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $user = $this->userWithRole($tenant, 'Counter', ['inventory.view']);

        $message = $this->signedInAs($user)
            ->getJson('/api/v1/inventory/stock')
            ->assertForbidden()
            ->json('message');

        // The key says nothing to anybody who has not read the source.
        $this->assertStringNotContainsString('inventory.stock.view', $message);
        $this->assertStringContainsString('tab', $message);
    }

    public function test_the_migration_gives_an_existing_role_every_chip_it_could_already_open(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        // A role as it stood before today: the module, none of its chips.
        $before = $this->userWithRole($tenant, 'Technician', ['inventory.view']);

        /*
         * Wrapped: Role is tenant-scoped and refuses an unscoped read. The
         * MIGRATION needs no wrapper and deliberately has none — it touches
         * only the `permissions` table and the `permission_role` pivot, which
         * are platform tables, and never loads a Role. That is what lets it run
         * from a deploy, where there is no tenant in context at all.
         */
        $this->assertFalse(
            $this->inTenant($tenant['org'], fn () => $before->fresh()->hasPermission('inventory.stock.view')),
            'Fixture is wrong: this role must start WITHOUT the chips, or the '
            .'migration below cannot be shown to be what granted them.',
        );

        // Run the widening migration the way a deploy would.
        $migration = require database_path(
            'migrations/2026_09_17_170000_grant_existing_roles_the_four_inventory_tabs.php',
        );
        $migration->up();

        $held = $this->inTenant($tenant['org'], function () use ($before) {
            $after = $before->fresh();

            return collect(['stock', 'items', 'orders', 'locations'])
                ->mapWithKeys(fn ($tab) => [$tab => $after->hasPermission("inventory.{$tab}.view")])
                ->all();
        });

        foreach (['stock', 'items', 'orders', 'locations'] as $tab) {
            $this->assertTrue(
                $held[$tab],
                "The migration left this role without inventory.{$tab}.view, so it "
                .'would lose that chip on the next deploy.',
            );
        }

        // Idempotent: a second run must not throw on a duplicate pivot row.
        $migration->up();
        $this->assertTrue(
            $this->inTenant($tenant['org'], fn () => $before->fresh()->hasPermission('inventory.stock.view')),
        );
    }

    public function test_items_and_locations_answer_without_their_own_tab_grant(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        /*
         * Holds the module and the ORDERS tab, and neither Items nor Locations.
         * This is the person writing a purchase order: the form lists items to
         * order and a place to receive them into, so both routes must answer or
         * the Orders tab they DO hold cannot be used.
         */
        $buyer = $this->userWithRole($tenant, 'Buyer', [
            'inventory.view', 'inventory.orders.view',
        ]);

        $this->signedInAs($buyer)->getJson('/api/v1/inventory/items')->assertOk();
        $this->signedInAs($buyer)->getJson('/api/v1/inventory/locations')->assertOk();

        /*
         * The control that makes the two above readable: the same fixture IS
         * refused something. Without this, a tenant whose every route answered
         * 200 — a gate that had stopped working entirely — would read as this
         * carve-out working as designed.
         */
        $this->signedInAs($buyer)->getJson('/api/v1/inventory/stock')->assertForbidden();
    }
}
