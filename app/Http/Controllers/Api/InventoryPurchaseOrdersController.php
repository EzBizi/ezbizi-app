<?php

namespace App\Http\Controllers\Api;

use App\Models\Company;
use App\Models\Franchise;
use App\Models\InventoryItem;
use App\Models\InventoryLocation;
use App\Models\InventoryPurchaseOrder;
use App\Models\InventoryPurchaseOrderLine;
use App\Models\InventoryReceive;
use App\Models\InventoryVendor;
use App\Models\User;
use App\Support\Automations\EventEmitter;
use App\Support\Inventory\InventoryFinancials;
use App\Support\Inventory\InventoryItemStatus;
use App\Support\Inventory\InventoryMover;
use App\Support\Inventory\InventoryPurchaseOrderStatus;
use App\Support\Inventory\InventoryVisibility;
use App\Support\Numbering\RecordNumberGenerator;
use App\Support\Scoping\PlaceInView;
use App\Support\Scoping\ViewScope;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Draft purchase orders, open, cancel, and receive (spec §8.3).
 *
 * Create is a draft with lines. Open freezes the lines. Cancel only from
 * draft or open with nothing received. Receive posts onto on-hand. Costs
 * are stripped on every read unless the caller holds inventory.financials.
 */
class InventoryPurchaseOrdersController extends Controller
{
    public function __construct(
        private readonly RecordNumberGenerator $numbers,
        private readonly InventoryMover $mover,
        private readonly TenantContext $tenant,
        private readonly EventEmitter $events,
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

    /** GET /api/v1/inventory/purchase-orders */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', InventoryPurchaseOrder::class);

        $user = $request->user();
        $scope = ViewScope::forUser($user);

        $input = $request->validate([
            'filter' => ['nullable', Rule::in(self::TILES)],
            'q' => ['nullable', 'string', 'max:200'],
        ]);
        $filter = $input['filter'] ?? null;

        $query = InventoryVisibility::constrain(InventoryPurchaseOrder::query(), $user, $scope);
        if ($filter !== null) {
            $this->narrow($query, $filter);
        }

        // The search bar narrows the list only; the tiles below keep counting
        // the whole view, as they do under a tapped tile.
        $term = trim((string) ($input['q'] ?? ''));
        if ($term !== '') {
            $like = '%'.addcslashes($term, '%_\\').'%';
            $query->where(fn ($w) => $w->where('number', 'like', $like)
                ->orWhereHas('vendor', fn ($v) => $v->where('name', 'like', $like))
                ->orWhereHas('lines.item', fn ($i) => $i->where('name', 'like', $like)->orWhere('sku', 'like', $like)));
        }

        $orders = $query
            ->with(['vendor', 'lines.item'])
            ->orderByDesc('created_at')
            ->paginate(perPage: min((int) $request->query('per_page', 50), 200));

        return response()->json([
            'data' => collect($orders->items())->map(
                fn (InventoryPurchaseOrder $order) => $this->presentOrder($order, $user),
            ),
            'meta' => [
                ...$this->pageMeta($orders),
                // Across every page, before the filter: a tapped tile lands on
                // a list as long as the number it showed.
                'tiles' => collect(self::TILES)->mapWithKeys(fn (string $tile) => [
                    $tile => $this->narrow(
                        InventoryVisibility::constrain(InventoryPurchaseOrder::query(), $user, $scope),
                        $tile,
                    )->count(),
                ])->all(),
            ],
        ]);
    }

    private const TILES = ['to_receive', 'open'];

    /**
     * What a tile counts, and so what its tap shows. To receive is an open
     * order with a line still short: the client's old chip, now across pages.
     */
    private function narrow(Builder $query, string $tile): Builder
    {
        $query->where('status', InventoryPurchaseOrderStatus::Open);

        if ($tile === 'to_receive') {
            $query->whereHas('lines', fn (Builder $line) => $line->whereColumn('qty_received', '<', 'qty_ordered'));
        }

        return $query;
    }

    /** POST /api/v1/inventory/purchase-orders */
    public function store(Request $request): JsonResponse
    {
        $input = $request->validate($this->storeRules());

        $company = isset($input['company_id'])
            ? Company::findOrFail($input['company_id'])
            : null;
        $franchise = isset($input['franchise_id'])
            ? Franchise::findOrFail($input['franchise_id'])
            : null;

        PlaceInView::assert($request->user(), $company, $franchise, 'order');

        if ($company === null) {
            $this->authorize('createOnOrganization', InventoryPurchaseOrder::class);
        } else {
            $this->authorize('create', [InventoryPurchaseOrder::class, $company, $franchise]);
        }

        $vendor = InventoryVendor::query()->findOrFail($input['vendor_id']);
        $this->authorize('view', $vendor);

        $user = $request->user();
        $lines = [];
        foreach ($input['lines'] as $line) {
            $item = InventoryItem::query()->findOrFail($line['item_id']);
            $this->authorize('view', $item);

            if ($item->status === InventoryItemStatus::Discontinued) {
                throw ValidationException::withMessages([
                    'item_id' => 'This item is discontinued, so it cannot go out.',
                ]);
            }

            if (array_key_exists('unit_cost_minor', $line)
                && $line['unit_cost_minor'] !== null
                && ! $user->hasPermission('inventory.financials')) {
                abort(Response::HTTP_FORBIDDEN, 'Costs on inventory are hidden for your role.');
            }

            $lines[] = [
                'item' => $item,
                'qty' => (string) $line['qty'],
                'unit_cost_minor' => $line['unit_cost_minor'] ?? null,
            ];
        }

        $order = DB::transaction(function () use ($request, $company, $franchise, $vendor, $lines) {
            $number = $this->numbers->next(
                RecordNumberGenerator::PURCHASE_ORDERS,
                $franchise ?? $company ?? $request->user()->organization,
            );

            $order = InventoryPurchaseOrder::create([
                'company_id' => $company?->getKey(),
                'franchise_id' => $franchise?->getKey(),
                'vendor_id' => $vendor->getKey(),
                'number' => $number,
                'status' => InventoryPurchaseOrderStatus::Draft,
            ]);

            foreach ($lines as $line) {
                InventoryPurchaseOrderLine::create([
                    'purchase_order_id' => $order->getKey(),
                    'item_id' => $line['item']->getKey(),
                    'qty_ordered' => $line['qty'],
                    'qty_received' => '0.000',
                    'unit_cost_minor' => $line['unit_cost_minor'],
                ]);
            }

            return $order;
        });

        return response()->json(
            ['data' => $this->presentOrder($order->fresh(['vendor', 'lines.item']), $user)],
            Response::HTTP_CREATED,
        );
    }

    /** GET /api/v1/inventory/purchase-orders/{purchase_order} */
    public function show(Request $request, InventoryPurchaseOrder $purchaseOrder): JsonResponse
    {
        $this->authorize('view', $purchaseOrder);

        return response()->json([
            'data' => $this->presentOrder(
                $purchaseOrder->fresh(['vendor', 'lines.item', 'company', 'franchise']),
                $request->user(),
            ),
        ]);
    }

    /** POST /api/v1/inventory/purchase-orders/{purchase_order}/open */
    public function open(Request $request, InventoryPurchaseOrder $purchaseOrder): JsonResponse
    {
        $this->authorize('update', $purchaseOrder);

        DB::transaction(function () use ($purchaseOrder) {
            $purchaseOrder = InventoryPurchaseOrder::query()->lockForUpdate()->findOrFail($purchaseOrder->getKey());
            if ($purchaseOrder->status !== InventoryPurchaseOrderStatus::Draft) {
                throw ValidationException::withMessages([
                    'status' => 'This order is not a draft.',
                ]);
            }

            $purchaseOrder->status = InventoryPurchaseOrderStatus::Open;
            $purchaseOrder->ordered_on = $this->calendarDayForPo($purchaseOrder);
            $purchaseOrder->save();

            $purchaseOrder->loadMissing('lines.item');
            $seen = [];
            foreach ($purchaseOrder->lines as $line) {
                $item = $line->item;
                if ($item === null) {
                    continue;
                }
                $id = $item->getKey();
                if (isset($seen[$id])) {
                    continue;
                }
                $seen[$id] = true;
                $this->events->emit('item.ordered', $item, [
                    'after' => [
                        'purchase_order_id' => $purchaseOrder->getKey(),
                        'number' => $purchaseOrder->number,
                    ],
                ]);
            }
        });

        return response()->json([
            'data' => $this->presentOrder(
                $purchaseOrder->fresh(['vendor', 'lines.item']),
                $request->user(),
            ),
        ]);
    }

    /** POST /api/v1/inventory/purchase-orders/{purchase_order}/cancel */
    public function cancel(Request $request, InventoryPurchaseOrder $purchaseOrder): JsonResponse
    {
        $this->authorize('update', $purchaseOrder);

        DB::transaction(function () use ($purchaseOrder) {
            $purchaseOrder = InventoryPurchaseOrder::query()->lockForUpdate()->findOrFail($purchaseOrder->getKey());
            $cancellable = in_array($purchaseOrder->status, [
                InventoryPurchaseOrderStatus::Draft,
                InventoryPurchaseOrderStatus::Open,
            ], true);

            if (! $cancellable) {
                throw ValidationException::withMessages([
                    'status' => 'This order cannot be cancelled.',
                ]);
            }

            $received = $purchaseOrder->lines()
                ->where('qty_received', '>', 0)
                ->exists();

            if ($received) {
                throw ValidationException::withMessages([
                    'status' => 'This order has already received stock, so it cannot be cancelled.',
                ]);
            }

            $purchaseOrder->status = InventoryPurchaseOrderStatus::Cancelled;
            $purchaseOrder->save();
        });

        return response()->json([
            'data' => $this->presentOrder(
                $purchaseOrder->fresh(['vendor', 'lines.item']),
                $request->user(),
            ),
        ]);
    }

    /** POST /api/v1/inventory/purchase-orders/{purchase_order}/receives */
    public function receive(Request $request, InventoryPurchaseOrder $purchaseOrder): JsonResponse
    {
        $input = $request->validate([
            'location_id' => ['required', 'uuid', $this->existsInTenant('inventory_locations')],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.purchase_order_line_id' => [
                'required',
                'uuid',
                'distinct',
                Rule::exists('inventory_purchase_order_lines', 'id')
                    ->where('purchase_order_id', $purchaseOrder->getKey())
                    ->where('organization_id', $this->tenant->idOrFail()),
            ],
            'lines.*.qty' => ['required', 'numeric', 'gt:0'],
        ]);

        $location = InventoryLocation::query()->findOrFail($input['location_id']);

        $this->authorize('create', [InventoryReceive::class, $purchaseOrder, $location]);

        if (! $this->locationFitsPo($location, $purchaseOrder)) {
            throw ValidationException::withMessages([
                'location_id' => 'The selected location id is invalid.',
            ]);
        }

        $lines = [];
        foreach ($input['lines'] as $line) {
            $poLine = InventoryPurchaseOrderLine::query()->findOrFail($line['purchase_order_line_id']);
            $poLine->loadMissing('item');
            if ($poLine->item !== null) {
                $this->authorize('view', $poLine->item);
            }
            $lines[] = [
                'line' => $poLine,
                'qty' => (string) $line['qty'],
            ];
        }

        $receive = $this->mover->receive($purchaseOrder, $location, $lines, $request->user());

        return response()->json(
            ['data' => $this->presentReceive($receive->fresh(['location', 'lines', 'purchaseOrder']))],
            Response::HTTP_CREATED,
        );
    }

    /**
     * Receive into the PO’s franchise, that company’s General, or an
     * organization location — not a sibling franchise or another company.
     */
    private function locationFitsPo(InventoryLocation $location, InventoryPurchaseOrder $order): bool
    {
        if ($location->company_id === null) {
            return true;
        }

        if ($order->company_id === null || $location->company_id !== $order->company_id) {
            return false;
        }

        if ($location->franchise_id === null) {
            return true;
        }

        return $order->franchise_id !== null
            && $location->franchise_id === $order->franchise_id;
    }

    /** Calendar day in the PO’s place timezone (spec §15). */
    private function calendarDayForPo(InventoryPurchaseOrder $order): string
    {
        $order->loadMissing('franchise');

        $tz = $order->franchise?->timezone;

        if (! filled($tz) && $order->company_id !== null) {
            $tz = Franchise::query()
                ->where('company_id', $order->company_id)
                ->orderBy('created_at')
                ->value('timezone');
        }

        if (! filled($tz)) {
            $tz = Franchise::query()->orderBy('created_at')->value('timezone');
        }

        return now(filled($tz) ? $tz : 'America/New_York')->toDateString();
    }

    /** @return array<string, mixed> */
    private function storeRules(): array
    {
        return [
            'vendor_id' => ['required', 'uuid', $this->existsInTenant('inventory_vendors')],
            'company_id' => ['nullable', 'uuid', $this->existsInTenant('companies')],
            'franchise_id' => ['nullable', 'uuid', $this->existsInTenant('franchises')],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.item_id' => ['required', 'uuid', $this->existsInTenant('inventory_items')],
            'lines.*.qty' => ['required', 'numeric', 'gt:0'],
            'lines.*.unit_cost_minor' => ['nullable', 'integer', 'min:0'],
        ];
    }

    /** @return array<string, mixed> */
    private function presentOrder(InventoryPurchaseOrder $order, User $user): array
    {
        $order->loadMissing([
            'vendor:id,name',
            'company:id,name',
            'franchise:id,name',
            'lines.item',
        ]);

        $row = [
            'id' => $order->id,
            'number' => $order->number,
            'status' => $order->status->value,
            'ordered_on' => $order->ordered_on?->toDateString(),
            'vendor' => $order->vendor === null ? null : [
                'id' => $order->vendor->id,
                'name' => $order->vendor->name,
            ],
            'company' => $order->company_id === null ? null : [
                'id' => $order->company_id,
                'name' => $order->company?->name,
            ],
            'franchise' => $order->franchise_id === null ? null : [
                'id' => $order->franchise_id,
                'name' => $order->franchise?->name,
            ],
            'lines' => $order->lines->map(static function (InventoryPurchaseOrderLine $line): array {
                return [
                    'id' => $line->id,
                    'qty_ordered' => (string) $line->qty_ordered,
                    'qty_received' => (string) $line->qty_received,
                    'unit_cost_minor' => $line->unit_cost_minor,
                    'item' => $line->item === null ? null : [
                        'id' => $line->item->id,
                        'name' => $line->item->name,
                        'last_cost_minor' => $line->item->last_cost_minor,
                    ],
                ];
            })->values()->all(),
        ];

        if ($user->hasPermission('inventory.financials')) {
            return $row;
        }

        return InventoryFinancials::withoutCosts($row);
    }

    /** @return array<string, mixed> */
    private function presentReceive(InventoryReceive $receive): array
    {
        return [
            'id' => $receive->id,
            'received_on' => $receive->received_on?->toDateString(),
            'location' => $receive->location === null ? null : [
                'id' => $receive->location->id,
                'name' => $receive->location->name,
            ],
            'purchase_order' => $receive->purchaseOrder === null ? null : [
                'id' => $receive->purchaseOrder->id,
                'number' => $receive->purchaseOrder->number,
                'status' => $receive->purchaseOrder->status->value,
            ],
            'lines' => $receive->lines->map(static function ($line): array {
                return [
                    'id' => $line->id,
                    'purchase_order_line_id' => $line->purchase_order_line_id,
                    'qty' => (string) $line->qty,
                ];
            })->values()->all(),
        ];
    }
}
