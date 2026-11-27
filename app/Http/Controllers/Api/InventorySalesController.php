<?php

namespace App\Http\Controllers\Api;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Franchise;
use App\Models\InventoryItem;
use App\Models\InventoryLocation;
use App\Models\InventorySale;
use App\Models\InventorySaleFulfillment;
use App\Models\InventorySaleLine;
use App\Models\User;
use App\Support\Automations\EventEmitter;
use App\Support\Inventory\InventoryFinancials;
use App\Support\Inventory\InventoryItemStatus;
use App\Support\Inventory\InventoryMover;
use App\Support\Inventory\InventorySalePaymentStatus;
use App\Support\Inventory\InventorySaleStatus;
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
 * Draft sales, open, cancel, pay, and fulfill (spec §8.5).
 *
 * Create is a draft with lines. Open freezes the lines. Fulfill posts
 * stock off a location. Cancel only from draft or open with nothing
 * fulfilled. Prices and payment are stripped without financials.
 */
class InventorySalesController extends Controller
{
    public function __construct(
        private readonly RecordNumberGenerator $numbers,
        private readonly InventoryMover $mover,
        private readonly TenantContext $tenant,
        private readonly EventEmitter $events,
    ) {}

    private function existsInTenant(string $table): Exists
    {
        return Rule::exists($table, 'id')
            ->where('organization_id', $this->tenant->idOrFail());
    }

    /** GET /api/v1/inventory/sales */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', InventorySale::class);

        $user = $request->user();
        $scope = ViewScope::forUser($user);

        $input = $request->validate([
            'filter' => ['nullable', Rule::in(self::TILES)],
            'q' => ['nullable', 'string', 'max:200'],
        ]);
        $filter = $input['filter'] ?? null;

        $query = InventoryVisibility::constrain(InventorySale::query(), $user, $scope);
        if ($filter !== null) {
            $this->narrow($query, $filter);
        }

        // The search bar narrows the list only; the tiles below keep counting
        // the whole view, as they do under a tapped tile.
        $term = trim((string) ($input['q'] ?? ''));
        if ($term !== '') {
            $like = '%'.addcslashes($term, '%_\\').'%';
            $query->where(fn ($w) => $w->where('number', 'like', $like)
                ->orWhere('note', 'like', $like)
                ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', $like)
                    ->orWhere('phone', 'like', $like)
                    ->orWhere('email', 'like', $like)));
        }

        $sales = $query
            ->with(['customer', 'lines.item'])
            ->orderByDesc('created_at')
            ->paginate(perPage: min((int) $request->query('per_page', 50), 200));

        return response()->json([
            'data' => collect($sales->items())->map(
                fn (InventorySale $sale) => $this->presentSale($sale, $user),
            ),
            'meta' => [
                ...$this->pageMeta($sales),
                // Across every page, before the filter: a tapped tile lands on
                // a list as long as the number it showed.
                'tiles' => collect(self::TILES)->mapWithKeys(fn (string $tile) => [
                    $tile => $this->narrow(
                        InventoryVisibility::constrain(InventorySale::query(), $user, $scope),
                        $tile,
                    )->count(),
                ])->all(),
            ],
        ]);
    }

    private const TILES = ['open', 'unpaid'];

    /**
     * What a tile counts, and so what its tap shows. Open is not yet fully
     * handed over; unpaid is owed — so never a draft (not a sale yet) or a
     * cancelled sale (never was one).
     */
    private function narrow(Builder $query, string $tile): Builder
    {
        return $tile === 'open'
            ? $query->whereIn('status', [InventorySaleStatus::Open, InventorySaleStatus::Partial])
            : $query->where('payment_status', InventorySalePaymentStatus::Unpaid)
                ->whereNotIn('status', [InventorySaleStatus::Draft, InventorySaleStatus::Cancelled]);
    }

    /** POST /api/v1/inventory/sales */
    public function store(Request $request): JsonResponse
    {
        $input = $request->validate($this->storeRules());

        $company = isset($input['company_id'])
            ? Company::findOrFail($input['company_id'])
            : null;
        $franchise = isset($input['franchise_id'])
            ? Franchise::findOrFail($input['franchise_id'])
            : null;

        PlaceInView::assert($request->user(), $company, $franchise, 'sale');

        if ($company === null) {
            $this->authorize('createOnOrganization', InventorySale::class);
        } else {
            $this->authorize('create', [InventorySale::class, $company, $franchise]);
        }

        $note = isset($input['note']) ? trim((string) $input['note']) : '';
        if ($note === '') {
            $note = null;
        }
        $customerId = $input['customer_id'] ?? null;

        if ($customerId === null && $note === null) {
            throw ValidationException::withMessages([
                'customer_id' => 'Say who bought this, or that it was a counter sale.',
            ]);
        }

        $customer = $customerId === null
            ? null
            : $this->customerInTenant($customerId);

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

            if (array_key_exists('unit_price_minor', $line)
                && $line['unit_price_minor'] !== null
                && ! $user->hasPermission('inventory.financials')) {
                abort(Response::HTTP_FORBIDDEN, 'Costs on inventory are hidden for your role.');
            }

            $lines[] = [
                'item' => $item,
                'qty' => (string) $line['qty'],
                'unit_price_minor' => $line['unit_price_minor'] ?? null,
            ];
        }

        $sale = DB::transaction(function () use ($request, $company, $franchise, $customer, $note, $lines) {
            $number = $this->numbers->next(
                RecordNumberGenerator::SALES,
                $franchise ?? $company ?? $request->user()->organization,
            );

            $sale = InventorySale::create([
                'company_id' => $company?->getKey(),
                'franchise_id' => $franchise?->getKey(),
                'customer_id' => $customer?->getKey(),
                'number' => $number,
                'status' => InventorySaleStatus::Draft,
                'payment_status' => InventorySalePaymentStatus::Unpaid,
                'note' => $note,
            ]);

            foreach ($lines as $line) {
                InventorySaleLine::create([
                    'sale_id' => $sale->getKey(),
                    'item_id' => $line['item']->getKey(),
                    'qty_ordered' => $line['qty'],
                    'qty_fulfilled' => '0.000',
                    'unit_price_minor' => $line['unit_price_minor'],
                ]);
            }

            return $sale;
        });

        return response()->json(
            ['data' => $this->presentSale($sale->fresh(['customer', 'lines.item']), $user)],
            Response::HTTP_CREATED,
        );
    }

    /** GET /api/v1/inventory/sales/{sale} */
    public function show(Request $request, InventorySale $sale): JsonResponse
    {
        $this->authorize('view', $sale);

        return response()->json([
            'data' => $this->presentSale($sale->fresh(['customer', 'lines.item']), $request->user()),
        ]);
    }

    /** POST /api/v1/inventory/sales/{sale}/open */
    public function open(Request $request, InventorySale $sale): JsonResponse
    {
        $this->authorize('update', $sale);

        DB::transaction(function () use ($sale) {
            $sale = InventorySale::query()->lockForUpdate()->findOrFail($sale->getKey());
            if ($sale->status !== InventorySaleStatus::Draft) {
                throw ValidationException::withMessages([
                    'status' => 'This sale is not a draft.',
                ]);
            }

            $sale->status = InventorySaleStatus::Open;
            $sale->sold_on = now(\App\Support\Settings\OrgTimezone::of())->toDateString();
            $sale->save();

            $sale->loadMissing('lines.item');
            $seen = [];
            foreach ($sale->lines as $line) {
                $item = $line->item;
                if ($item === null) {
                    continue;
                }
                $id = $item->getKey();
                if (isset($seen[$id])) {
                    continue;
                }
                $seen[$id] = true;
                $this->events->emit('item.sale_opened', $item, [
                    'after' => [
                        'sale_id' => $sale->getKey(),
                        'number' => $sale->number,
                    ],
                ]);
            }
        });

        return response()->json([
            'data' => $this->presentSale(
                $sale->fresh(['customer', 'lines.item']),
                $request->user(),
            ),
        ]);
    }

    /** POST /api/v1/inventory/sales/{sale}/cancel */
    public function cancel(Request $request, InventorySale $sale): JsonResponse
    {
        $this->authorize('update', $sale);

        DB::transaction(function () use ($sale) {
            $sale = InventorySale::query()->lockForUpdate()->findOrFail($sale->getKey());
            $cancellable = in_array($sale->status, [
                InventorySaleStatus::Draft,
                InventorySaleStatus::Open,
            ], true);

            if (! $cancellable) {
                throw ValidationException::withMessages([
                    'status' => 'This sale cannot be cancelled.',
                ]);
            }

            $fulfilled = $sale->lines()
                ->where('qty_fulfilled', '>', 0)
                ->exists();

            if ($fulfilled) {
                throw ValidationException::withMessages([
                    'status' => 'This sale has already fulfilled stock, so it cannot be cancelled.',
                ]);
            }

            $sale->status = InventorySaleStatus::Cancelled;
            $sale->save();
        });

        return response()->json([
            'data' => $this->presentSale(
                $sale->fresh(['customer', 'lines.item']),
                $request->user(),
            ),
        ]);
    }

    /** POST /api/v1/inventory/sales/{sale}/pay */
    public function pay(Request $request, InventorySale $sale): JsonResponse
    {
        $this->authorize('update', $sale);

        if (! $request->user()->hasPermission('inventory.financials')) {
            abort(Response::HTTP_FORBIDDEN, 'Costs on inventory are hidden for your role.');
        }

        $input = $request->validate([
            'payment_status' => ['required', 'string', Rule::enum(InventorySalePaymentStatus::class)],
        ]);

        $sale->payment_status = InventorySalePaymentStatus::from($input['payment_status']);
        $sale->save();

        return response()->json([
            'data' => $this->presentSale(
                $sale->fresh(['customer', 'lines.item']),
                $request->user(),
            ),
        ]);
    }

    /** POST /api/v1/inventory/sales/{sale}/fulfillments */
    public function fulfill(Request $request, InventorySale $sale): JsonResponse
    {
        $input = $request->validate([
            'location_id' => ['required', 'uuid', $this->existsInTenant('inventory_locations')],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.sale_line_id' => [
                'required',
                'uuid',
                'distinct',
                Rule::exists('inventory_sale_lines', 'id')
                    ->where('sale_id', $sale->getKey())
                    ->where('organization_id', $this->tenant->idOrFail()),
            ],
            'lines.*.qty' => ['required', 'numeric', 'gt:0'],
        ]);

        $location = InventoryLocation::query()->findOrFail($input['location_id']);

        $this->authorize('fulfill', [$sale, $location]);

        if (! $this->locationFitsSale($location, $sale)) {
            throw ValidationException::withMessages([
                'location_id' => 'The selected location id is invalid.',
            ]);
        }

        $lines = [];
        foreach ($input['lines'] as $line) {
            $saleLine = InventorySaleLine::query()->findOrFail($line['sale_line_id']);
            $saleLine->loadMissing('item');
            if ($saleLine->item !== null) {
                $this->authorize('view', $saleLine->item);
            }
            $lines[] = [
                'line' => $saleLine,
                'qty' => (string) $line['qty'],
            ];
        }

        $fulfillment = $this->mover->fulfillSale($sale, $location, $lines, $request->user());

        return response()->json(
            ['data' => $this->presentFulfillment($fulfillment->fresh(['location', 'lines', 'sale']))],
            Response::HTTP_CREATED,
        );
    }

    private function locationFitsSale(InventoryLocation $location, InventorySale $sale): bool
    {
        if ($location->company_id === null) {
            return true;
        }

        if ($sale->company_id === null || $location->company_id !== $sale->company_id) {
            return false;
        }

        if ($location->franchise_id === null) {
            return true;
        }

        return $sale->franchise_id !== null
            && $location->franchise_id === $sale->franchise_id;
    }

    private function customerInTenant(string $customerId): Customer
    {
        $customer = Customer::query()->find($customerId);

        if ($customer === null || $customer->organization_id !== $this->tenant->idOrFail()) {
            throw ValidationException::withMessages([
                'customer_id' => 'That customer is not in this view.',
            ]);
        }

        return $customer;
    }

    /** @return array<string, mixed> */
    private function storeRules(): array
    {
        return [
            'customer_id' => ['nullable', 'uuid', $this->existsInTenant('customers')],
            'company_id' => ['nullable', 'uuid', $this->existsInTenant('companies')],
            'franchise_id' => ['nullable', 'uuid', $this->existsInTenant('franchises')],
            'note' => ['nullable', 'string'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.item_id' => ['required', 'uuid', $this->existsInTenant('inventory_items')],
            'lines.*.qty' => ['required', 'numeric', 'gt:0'],
            'lines.*.unit_price_minor' => ['nullable', 'integer', 'min:0'],
        ];
    }

    /** @return array<string, mixed> */
    private function presentSale(InventorySale $sale, User $user): array
    {
        $sale->loadMissing([
            'customer:id,name,number',
            'company:id,name',
            'franchise:id,name',
            'lines.item',
        ]);

        $row = [
            'id' => $sale->id,
            'number' => $sale->number,
            'status' => $sale->status->value,
            'payment_status' => $sale->payment_status->value,
            'sold_on' => $sale->sold_on?->toDateString(),
            'note' => $sale->note,
            'customer' => $sale->customer === null ? null : [
                'id' => $sale->customer->id,
                'name' => $sale->customer->name,
                'number' => $sale->customer->number,
            ],
            'company' => $sale->company_id === null ? null : [
                'id' => $sale->company_id,
                'name' => $sale->company?->name,
            ],
            'franchise' => $sale->franchise_id === null ? null : [
                'id' => $sale->franchise_id,
                'name' => $sale->franchise?->name,
            ],
            'lines' => $sale->lines->map(static function (InventorySaleLine $line): array {
                return [
                    'id' => $line->id,
                    'qty_ordered' => (string) $line->qty_ordered,
                    'qty_fulfilled' => (string) $line->qty_fulfilled,
                    'unit_price_minor' => $line->unit_price_minor,
                    'item' => $line->item === null ? null : [
                        'id' => $line->item->id,
                        'name' => $line->item->name,
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
    private function presentFulfillment(InventorySaleFulfillment $fulfillment): array
    {
        return [
            'id' => $fulfillment->id,
            'fulfilled_on' => $fulfillment->fulfilled_on?->toDateString(),
            'location' => $fulfillment->location === null ? null : [
                'id' => $fulfillment->location->id,
                'name' => $fulfillment->location->name,
            ],
            'sale' => $fulfillment->sale === null ? null : [
                'id' => $fulfillment->sale->id,
                'number' => $fulfillment->sale->number,
                'status' => $fulfillment->sale->status->value,
            ],
            'lines' => $fulfillment->lines->map(static function ($line): array {
                return [
                    'id' => $line->id,
                    'sale_line_id' => $line->sale_line_id,
                    'qty' => (string) $line->qty,
                ];
            })->values()->all(),
        ];
    }
}
