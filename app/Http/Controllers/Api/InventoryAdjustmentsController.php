<?php

namespace App\Http\Controllers\Api;

use App\Models\InventoryAdjustment;
use App\Models\InventoryItem;
use App\Models\InventoryLocation;
use App\Support\Inventory\InventoryMover;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Symfony\Component\HttpFoundation\Response;

/**
 * Counted-as: post a new on-hand for one item at one location (spec §8.1).
 *
 * Authorizes location `update` (`inventory.edit` + write reach). Delta zero
 * is refused by the mover. Empty reason is a 422; there is no extra sentence.
 */
class InventoryAdjustmentsController extends Controller
{
    public function __construct(
        private readonly InventoryMover $mover,
        private readonly TenantContext $tenant,
    ) {}

    /**
     * An `exists` rule scoped to this organization.
     *
     * Laravel's plain `exists` runs a raw query with no tenant scope, so naming
     * another organization's id passes validation and only fails later as a 404.
     * Two different answers for two different ids is an existence oracle — it
     * tells a caller which ids are real somewhere else on the platform.
     */
    private function existsInTenant(string $table): Exists
    {
        return Rule::exists($table, 'id')
            ->where('organization_id', $this->tenant->idOrFail());
    }

    /** POST /api/v1/inventory/adjustments */
    public function store(Request $request): JsonResponse
    {
        $input = $request->validate([
            'location_id' => ['required', 'uuid', $this->existsInTenant('inventory_locations')],
            'item_id' => ['required', 'uuid', $this->existsInTenant('inventory_items')],
            'counted_as' => ['required', 'numeric', 'min:0'],
            'reason' => ['required', 'string'],
        ]);

        $location = InventoryLocation::query()->findOrFail($input['location_id']);
        $item = InventoryItem::query()->findOrFail($input['item_id']);

        $this->authorize('update', $location);

        $adjustment = $this->mover->adjust(
            $location,
            $item,
            (string) $input['counted_as'],
            $input['reason'],
            $request->user(),
        );

        return response()->json(
            ['data' => $this->present($adjustment->fresh())],
            Response::HTTP_CREATED,
        );
    }

    /** @return array<string, mixed> */
    private function present(InventoryAdjustment $adjustment): array
    {
        $adjustment->loadMissing(['item', 'location']);

        return [
            'id' => $adjustment->id,
            'qty_before' => (string) $adjustment->qty_before,
            'qty_after' => (string) $adjustment->qty_after,
            'reason' => $adjustment->reason,
            'adjusted_on' => $adjustment->adjusted_on?->toDateString(),
            'item' => $adjustment->item === null ? null : [
                'id' => $adjustment->item->id,
                'name' => $adjustment->item->name,
            ],
            'location' => $adjustment->location === null ? null : [
                'id' => $adjustment->location->id,
                'name' => $adjustment->location->name,
            ],
        ];
    }
}
