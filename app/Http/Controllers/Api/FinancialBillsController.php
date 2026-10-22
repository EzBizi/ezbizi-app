<?php

namespace App\Http\Controllers\Api;

use App\Models\Company;
use App\Models\FinancialBill;
use App\Models\Franchise;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use App\Support\Files\FileStore;
use App\Support\Financials\BillReader;
use App\Support\Scoping\PlaceInView;
use App\Support\Scoping\ViewScope;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Financials → Bills: a photo of a place's bills is read into lines and a
 * total, a person checks it, and it is saved when the lines add up to the
 * cent. Reading needs no write; saving is financials.edit at that place.
 */
class FinancialBillsController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly FileStore $files,
        private readonly TenantContext $tenant,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->may($request->user(), 'view');
        $input = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'q' => ['nullable', 'string', 'max:200'],
        ]);
        $term = trim((string) ($input['q'] ?? ''));
        $like = '%'.addcslashes($term, '%_\\').'%';

        $bills = FinancialBill::query()->with('company:id,name', 'franchise:id,name')
            // The place a bill is for, which is the name the row shows.
            ->when($term !== '', fn ($q) => $q->where(fn ($w) => $w
                ->whereHas('company', fn ($c) => $c->where('name', 'like', $like))
                ->orWhereHas('franchise', fn ($f) => $f->where('name', 'like', $like))))
            ->when($input['from'] ?? null, fn ($q, $d) => $q->where('period_to', '>=', $d))
            ->when($input['to'] ?? null, fn ($q, $d) => $q->where('period_from', '<=', $d))
            ->orderByDesc('period_from')->limit(500)->get()
            ->filter(fn (FinancialBill $b) => $this->inView($request->user(), $b));

        return response()->json(['data' => $bills->map(fn ($b) => $this->shape($b))->values()]);
    }

    /** Reads a photo; keeps it for ten minutes so saving can attach it. */
    public function read(Request $request): JsonResponse
    {
        $this->may($request->user(), 'edit');
        $image = $request->validate(['image' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,heic', 'max:20480']])['image'];
        $bytes = (string) file_get_contents($image->getRealPath());

        $draft = BillReader::read($bytes, strtolower($image->getClientOriginalExtension() ?: 'jpg'));
        $token = $this->keep($bytes, strtolower($image->getClientOriginalExtension() ?: 'jpg'));

        return response()->json(['data' => $draft + ['image_token' => $token]]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->may($user, 'edit');
        $org = $this->tenant->idOrFail();
        $input = $request->validate([
            'company_id' => ['nullable', 'uuid', Rule::exists('companies', 'id')->where('organization_id', $org)],
            'franchise_id' => ['nullable', 'uuid', Rule::exists('franchises', 'id')->where('organization_id', $org)],
            'period_from' => ['required', 'date_format:Y-m-d'],
            'period_to' => ['required', 'date_format:Y-m-d', 'after_or_equal:period_from'],
            'lines' => ['required', 'array'],
            'lines.*.name' => ['required', 'string', 'max:160'],
            'lines.*.cents' => ['required', 'integer'],
            'total_cents' => ['required', 'integer', 'min:0'],
            'image_token' => ['nullable', 'string', 'max:64'],
        ]);

        $company = isset($input['company_id']) ? Company::findOrFail($input['company_id']) : null;
        $franchise = isset($input['franchise_id']) ? Franchise::findOrFail($input['franchise_id']) : null;
        PlaceInView::assert($user, $company, $franchise, 'bill');

        return response()->json(['data' => $this->shape($this->save($user, $company, $franchise, $input, 'upload'))], 201);
    }

    public function image(Request $request, FinancialBill $bill)
    {
        $this->may($request->user(), 'view');
        abort_unless($this->inView($request->user(), $bill) && $bill->image_path !== null, Response::HTTP_NOT_FOUND);

        return Storage::disk($bill->image_disk)->response($bill->image_path);
    }

    public function destroy(Request $request, FinancialBill $bill): JsonResponse
    {
        $this->may($request->user(), 'delete');
        abort_unless($this->inView($request->user(), $bill), Response::HTTP_NOT_FOUND);
        $this->audit->log('financials.bill_deleted', $bill, metadata: ['total_cents' => $bill->total_cents, 'period_from' => $bill->period_from?->toDateString()], actor: $request->user());
        $bill->delete();

        return response()->json(null, 204);
    }

    /**
     * Saves a checked bill. Also the entry point for a photo sent to the
     * WhatsApp staff bot (source "whatsapp"), which hands over the same input.
     */
    public function save(User $user, ?Company $company, ?Franchise $franchise, array $input, string $source): FinancialBill
    {
        BillReader::check($input['lines'], (int) $input['total_cents'], $input['period_from'], $input['period_to']);
        $kept = isset($input['image_token']) ? Cache::pull('financial-bill-image:'.$user->id.':'.$input['image_token']) : null;

        $bill = FinancialBill::create([
            'company_id' => $franchise?->company_id ?? $company?->getKey(),
            'franchise_id' => $franchise?->getKey(),
            'period_from' => $input['period_from'],
            'period_to' => $input['period_to'],
            'lines' => array_map(fn ($l) => ['name' => trim($l['name']), 'cents' => (int) $l['cents']], $input['lines']),
            'total_cents' => (int) $input['total_cents'],
            'image_disk' => $kept['disk'] ?? null,
            'image_path' => $kept['path'] ?? null,
            'image_sha256' => $kept['sha256'] ?? null,
            'source' => $source,
            'created_by_user_id' => $user->getKey(),
        ]);
        $this->audit->log('financials.bill_saved', $bill, metadata: ['lines' => count($bill->lines), 'total_cents' => $bill->total_cents, 'source' => $source], actor: $user);

        return $bill;
    }

    /** Stores the photo under the organization and returns a short-lived token naming it. */
    public function keep(string $bytes, string $extension, ?User $user = null): string
    {
        $user ??= request()->user();
        $disk = $this->files->defaultDisk();
        $path = $this->files->directoryFor('financial-bills', $this->tenant->idOrFail()).'/'.Str::uuid().'.'.$extension;
        Storage::disk($disk)->put($path, $bytes);
        $token = Str::random(32);
        Cache::put('financial-bill-image:'.$user->id.':'.$token, ['disk' => $disk, 'path' => $path, 'sha256' => hash('sha256', $bytes)], now()->addMinutes(10));

        return $token;
    }

    private function inView(User $user, FinancialBill $bill): bool
    {
        $scope = ViewScope::forUser($user);

        return $bill->franchise_id !== null
            ? $scope->coversFranchise($bill->franchise_id)
            : $scope->isOrganization() || ($bill->company_id !== null && $scope->coversCompany($bill->company_id));
    }

    private function may(User $user, string $action): void
    {
        abort_unless($user->hasPermission("financials.{$action}"), Response::HTTP_FORBIDDEN, 'Your role cannot '.($action === 'view' ? 'see' : 'change').' bills.');
    }

    private function shape(FinancialBill $b): array
    {
        return [
            'id' => $b->id,
            'company_id' => $b->company_id,
            'franchise_id' => $b->franchise_id,
            'place' => $b->franchise?->name ?? $b->company?->name,
            'period_from' => $b->period_from?->toDateString(),
            'period_to' => $b->period_to?->toDateString(),
            'lines' => $b->lines,
            'total_cents' => $b->total_cents,
            'has_image' => $b->image_path !== null,
            'source' => $b->source,
        ];
    }
}
