<?php

namespace App\Support\Dashboard\Glances;

use App\Models\InventoryItem;
use App\Models\InventoryLocation;
use App\Models\User;
use App\Support\Dashboard\DashboardPeriod;
use App\Support\Dashboard\Glance;
use App\Support\Dashboard\GlancePayload;
use App\Support\Inventory\InventoryVisibility;
use App\Support\Scoping\ViewScope;
use App\Support\Tenancy\TenantContext;

/**
 * Inventory box: items and locations in reach (snapshot).
 *
 * Inventory is a live register, so this is not a coming-soon sentence. Empty
 * reach is a sentence, not a pie of zeroes.
 */
final class InventoryGlance implements Glance
{
    public function key(): string
    {
        return 'inventory';
    }

    public function label(): string
    {
        return 'Inventory';
    }

    public function permission(): string
    {
        return 'inventory.view';
    }

    public function allowedPresentations(): array
    {
        return ['pie', 'bar', 'text'];
    }

    public function defaultPresentation(): string
    {
        return 'pie';
    }

    public function snapshot(): bool
    {
        return true;
    }

    public function resolve(User $user, DashboardPeriod $period, array $options): GlancePayload
    {
        return app(TenantContext::class)->run($user->organization, function () use ($user) {
            $scope = ViewScope::forUser($user);
            $items = (int) InventoryVisibility::constrain(InventoryItem::query(), $user, $scope)->count();
            $locations = (int) InventoryVisibility::constrain(InventoryLocation::query(), $user, $scope)->count();

            if ($items === 0 && $locations === 0) {
                return new GlancePayload(
                    key: $this->key(),
                    label: $this->label(),
                    presentation: $this->defaultPresentation(),
                    allowedPresentations: $this->allowedPresentations(),
                    snapshot: $this->snapshot(),
                    comingSoon: false,
                    options: [],
                    data: [
                        'empty' => true,
                        'sentence' => 'No items in reach.',
                    ],
                );
            }

            return new GlancePayload(
                key: $this->key(),
                label: $this->label(),
                presentation: $this->defaultPresentation(),
                allowedPresentations: $this->allowedPresentations(),
                snapshot: $this->snapshot(),
                comingSoon: false,
                options: [],
                data: [
                    'empty' => false,
                    'slices' => [
                        ['label' => 'Items', 'total' => $items],
                        ['label' => 'Locations', 'total' => $locations],
                    ],
                    'sentences' => [
                        $items === 1 ? '1 item in reach.' : "{$items} items in reach.",
                        $locations === 1 ? '1 location in reach.' : "{$locations} locations in reach.",
                    ],
                ],
            );
        });
    }
}
