<?php

namespace App\Http\Controllers\Api;

use App\Models\InventoryItem;
use App\Models\InventoryLocation;
use App\Models\InventoryTransfer;
use App\Support\Inventory\InventoryMover;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Symfony\Component\HttpFoundation\Response;

/**
 * Location A → location B, posted on save (spec §8.2).
 *
 * From: location `update`. To: visible and not a sibling company. Each
 * line's item must be visible in this view — siblings do not share
 * catalogues. The whole POST rolls back if any line fails.
 */
class InventoryTransfersController extends Controller
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

    /** POST /api/v1/inventory/transfers */
    public function store(Request $request): JsonResponse
    {
        $input = $request->validate([
            'from_location_id' => ['required', 'uuid', $this->existsInTenant('inventory_locations')],
            'to_location_id' => ['required', 'uuid', 'different:from_location_id', $this->existsInTenant('inventory_locations')],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.item_id' => ['required', 'uuid', $this->existsInTenant('inventory_items')],
            'lines.*.qty' => ['required', 'numeric', 'gt:0'],
        ]);

        $from = InventoryLocation::query()->findOrFail($input['from_location_id']);
        $to = InventoryLocation::query()->findOrFail($input['to_location_id']);

        $this->authorize('create', [InventoryTransfer::class, $from, $to]);

        $lines = [];
        foreach ($input['lines'] as $line) {
            $item = InventoryItem::query()->findOrFail($line['item_id']);
            $this->authorize('view', $item);
            $lines[] = [
                'item' => $item,
                'qty' => (string) $line['qty'],
            ];
        }

        $transfer = $this->mover->transfer($from, $to, $lines, $request->user());

        return response()->json(
            ['data' => $this->present($transfer->fresh(['lines.item', 'fromLocation', 'toLocation']))],
            Response::HTTP_CREATED,
        );
    }

    /** @return array<string, mixed> */
    private function present(InventoryTransfer $transfer): array
    {
        return [
            'id' => $transfer->id,
            'number' => $transfer->number,
            'transferred_on' => $transfer->transferred_on?->toDateString(),
            'from_location' => $transfer->fromLocation === null ? null : [
                'id' => $transfer->fromLocation->id,
                'name' => $transfer->fromLocation->name,
            ],
            'to_location' => $transfer->toLocation === null ? null : [
                'id' => $transfer->toLocation->id,
                'name' => $transfer->toLocation->name,
            ],
            'lines' => $transfer->lines->map(static function ($line): array {
                return [
                    'id' => $line->id,
                    'qty' => (string) $line->qty,
                    'item' => $line->item === null ? null : [
                        'id' => $line->item->id,
                        'name' => $line->item->name,
                    ],
                ];
            })->values()->all(),
        ];
    }
}
