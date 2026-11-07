<?php

namespace App\Http\Controllers\Api;

use App\Models\Company;
use App\Models\FinancialChargeColumn;
use App\Models\FinancialChargeSheet;
use App\Models\FinancialChargeSheetRow;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\Organization;
use App\Models\PaymentAccount;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use App\Support\Invoices\InvoiceChannels;
use App\Support\Invoices\InvoicePdf;
use App\Support\Invoices\Invoicing;
use App\Support\Numbering\RecordNumberGenerator;
use App\Support\Scoping\ViewScope;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Financials › Invoices: charge lines, charge sheets, invoices, payments.
 * Every action is a tick in Roles & Permissions (invoices.view / create /
 * edit / delete / approve / send); an invoice raised from a job also needs
 * jobs.financials. Only invoices and sheets in the current view are seen.
 */
class InvoicesController extends Controller
{
    public function __construct(
        private readonly Invoicing $invoicing,
        private readonly AuditLogger $audit,
        private readonly TenantContext $tenant,
    ) {}

    // ── Charge lines (Settings › Invoicing) ───────────────────

    public function columns(Request $request): JsonResponse
    {
        $this->may($request->user(), 'view');

        return response()->json(['data' => FinancialChargeColumn::query()->orderBy('position')->get()
            ->map(fn ($c) => $c->only('id', 'name', 'source_column', 'position', 'is_active') + [
                'source_label' => $c->source_column === null ? 'Typed in on the sheet' : "From \"{$c->source_column}\" in Expenses by company",
            ])->values()]);
    }

    /** The whole list at once, in order; a missing id is removed. */
    public function saveColumns(Request $request): JsonResponse
    {
        $this->may($request->user(), 'edit');
        $rows = $request->validate([
            'columns' => ['present', 'array', 'max:40'],
            'columns.*.id' => ['nullable', 'uuid'],
            'columns.*.name' => ['required', 'string', 'max:120', 'distinct'],
            'columns.*.source_column' => ['nullable', 'string', 'max:255'],
            'columns.*.is_active' => ['sometimes', 'boolean'],
        ])['columns'];

        DB::transaction(function () use ($rows, $request) {
            $keep = [];
            foreach (array_values($rows) as $i => $row) {
                $column = isset($row['id']) ? FinancialChargeColumn::query()->find($row['id']) : null;
                $column ??= new FinancialChargeColumn;
                $column->fill(['name' => $row['name'], 'source_column' => $row['source_column'] ?? null, 'position' => $i,
                    'is_active' => $row['is_active'] ?? true])->save();
                $keep[] = $column->id;
            }
            FinancialChargeColumn::query()->whereNotIn('id', $keep)->delete();
            $this->audit->log('invoice.columns_saved', metadata: ['columns' => array_column($rows, 'name')], actor: $request->user());
        });

        return $this->columns($request);
    }

    // ── Charge sheets ─────────────────────────────────────────

    public function sheets(Request $request): JsonResponse
    {
        $this->may($request->user(), 'view');
        $term = trim((string) ($request->validate(['q' => ['nullable', 'string', 'max:200']])['q'] ?? ''));

        return response()->json(['data' => FinancialChargeSheet::query()->withCount('rows')
            ->when($term !== '', fn ($q) => $q->where('title', 'like', '%'.addcslashes($term, '%_\\').'%'))
            ->orderByDesc('period_from')->limit(200)->get()
            ->map(fn ($s) => $s->only('id', 'title', 'status', 'rows_count') + [
                'period_from' => $s->period_from->toDateString(), 'period_to' => $s->period_to->toDateString()])->values()]);
    }

    public function storeSheet(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->may($user, 'create');
        $input = $request->validate([
            'period_from' => ['required', 'date_format:Y-m-d'],
            'period_to' => ['required', 'date_format:Y-m-d', 'after_or_equal:period_from'],
            'title' => ['nullable', 'string', 'max:160'],
            'company_id' => ['nullable', 'uuid', Rule::exists('companies', 'id')->where('organization_id', $this->tenant->idOrFail())],
        ]);
        $issuer = isset($input['company_id']) ? Company::findOrFail($input['company_id']) : null;
        $sheet = $this->invoicing->makeSheet($user, $input['period_from'], $input['period_to'], $issuer, $input['title'] ?? null);

        return response()->json(['data' => $this->sheetShape($sheet, $user)], 201);
    }

    public function showSheet(Request $request, FinancialChargeSheet $sheet): JsonResponse
    {
        $this->may($request->user(), 'view');

        return response()->json(['data' => $this->sheetShape($sheet, $request->user())]);
    }

    /** Edits cells, jobs or labels of rows not yet invoiced. All or nothing. */
    public function updateRows(Request $request, FinancialChargeSheet $sheet): JsonResponse
    {
        $user = $request->user();
        $this->may($user, 'edit');
        $changes = $request->validate([
            'rows' => ['required', 'array', 'max:500'],
            'rows.*.id' => ['required', 'uuid'],
            'rows.*.cells' => ['sometimes', 'array'],
            'rows.*.cells.*' => ['nullable', 'integer'],
            'rows.*.jobs' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'rows.*.label' => ['sometimes', 'string', 'max:160'],
        ])['rows'];
        $columns = collect($sheet->columns)->pluck('id')->all();

        DB::transaction(function () use ($changes, $sheet, $columns, $user) {
            foreach ($changes as $change) {
                $row = $this->visibleRows($sheet, $user)->firstWhere('id', $change['id'])
                    ?? throw ValidationException::withMessages(['rows' => 'A row is not on this sheet or not in your view.']);
                if ($row->invoice_id !== null) {
                    throw ValidationException::withMessages(['rows' => "{$row->label} is already invoiced. Void its invoice to change it."]);
                }
                if (isset($change['cells'])) {
                    if (array_diff(array_keys($change['cells']), $columns) !== []) {
                        throw ValidationException::withMessages(['rows' => 'A cell names a charge line this sheet does not have.']);
                    }
                    $row->cells = array_replace((array) $row->cells, array_map(fn ($v) => (int) ($v ?? 0), $change['cells']));
                }
                foreach (['jobs', 'label'] as $key) {
                    if (array_key_exists($key, $change)) {
                        $row->{$key} = $change[$key];
                    }
                }
                $row->save();
            }
            $this->audit->log('invoice.sheet_edited', $sheet, metadata: ['rows' => count($changes)], actor: $user);
        });

        return response()->json(['data' => $this->sheetShape($sheet->refresh(), $user)]);
    }

    public function invoiceSheet(Request $request, FinancialChargeSheet $sheet): JsonResponse
    {
        $user = $request->user();
        $this->may($user, 'create');
        $result = $this->invoicing->invoiceSheet($user, $sheet);

        return response()->json(['data' => [
            'made' => array_map(fn ($i) => $this->invoiceShape($i->fresh(['lines', 'payments']), $user), $result['made']),
            'skipped' => $result['skipped'],
        ]], 201);
    }

    public function destroySheet(Request $request, FinancialChargeSheet $sheet): JsonResponse
    {
        $this->may($request->user(), 'delete');
        if ($sheet->rows()->whereNotNull('invoice_id')->exists()) {
            throw ValidationException::withMessages(['sheet' => 'This sheet has invoices. Void them first.']);
        }
        $this->audit->log('invoice.sheet_deleted', $sheet, actor: $request->user());
        $sheet->delete();

        return response()->json(null, 204);
    }

    // ── Invoices ──────────────────────────────────────────────

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->may($user, 'view');
        $input = $request->validate([
            'status' => ['nullable', Rule::in([...Invoice::STATUSES, 'overdue'])],
            'franchise_id' => ['nullable', 'uuid'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'q' => ['nullable', 'string', 'max:200'],
        ]);
        $term = trim((string) ($input['q'] ?? ''));
        $like = '%'.addcslashes($term, '%_\\').'%';
        $invoices = Invoice::query()->with('lines', 'payments')
            // Before the 500 cap, so an old invoice is still findable by its number.
            ->when($term !== '', fn ($q) => $q->where(fn ($w) => $w->where('number', 'like', $like)
                ->orWhere('bill_to_name', 'like', $like)
                ->orWhere('summary', 'like', $like)
                ->orWhere('notes', 'like', $like)))
            ->when(($input['status'] ?? null) && $input['status'] !== 'overdue', fn ($q) => $q->where('status', $input['status']))
            // A branch: what it was billed (or, for its own invoices, what it issued).
            ->when($input['franchise_id'] ?? null, fn ($q, $id) => $q->where(fn ($w) => $w->where(fn ($b) => $b->where('bill_to_type', 'franchise')->where('bill_to_id', $id))->orWhere('franchise_id', $id)))
            ->when($input['from'] ?? null, fn ($q, $d) => $q->where('issued_on', '>=', $d))
            ->when($input['to'] ?? null, fn ($q, $d) => $q->where('issued_on', '<=', $d))
            ->orderByDesc('issued_on')->orderByDesc('number')->limit(500)->get()
            ->filter(fn (Invoice $i) => $this->sees($user, $i))
            ->when(($input['status'] ?? null) === 'overdue', fn ($c) => $c->filter(fn (Invoice $i) => $i->isOverdue()));

        return response()->json([
            'data' => $invoices->map(fn ($i) => $this->invoiceShape($i, $user))->values(),
            'totals' => [
                'sent_cents' => (int) $invoices->whereIn('status', ['sent', 'paid'])->sum('total_cents'),
                'unpaid_cents' => (int) $invoices->sum(fn (Invoice $i) => in_array($i->status, ['sent', 'approved'], true) ? $i->dueCents() : 0),
                'overdue_cents' => (int) $invoices->sum(fn (Invoice $i) => $i->isOverdue() ? $i->dueCents() : 0),
            ],
        ]);
    }

    public function show(Request $request, Invoice $invoice): JsonResponse
    {
        $this->may($request->user(), 'view', $invoice);

        return response()->json(['data' => $this->invoiceShape($invoice->load('lines', 'payments'), $request->user())]);
    }

    /** Lines while a draft; who it bills and its notes until it is sent. */
    public function update(Request $request, Invoice $invoice): JsonResponse
    {
        $user = $request->user();
        $this->may($user, 'edit', $invoice);
        $input = $request->validate([
            'lines' => ['sometimes', 'array', 'min:1', 'max:200'],
            'lines.*.description' => ['required', 'string', 'max:500'],
            'lines.*.cents' => ['required', 'integer'],
            'bill_to_name' => ['sometimes', 'string', 'max:200'],
            'bill_to_address' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'bill_to_emails' => ['sometimes', 'array', 'max:20'],
            'bill_to_emails.*' => ['email'],
            'bill_to_phones' => ['sometimes', 'array', 'max:20'],
            'bill_to_phones.*' => ['string', 'regex:/^\+?[0-9 ().-]{7,20}$/'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'due_on' => ['sometimes', 'date_format:Y-m-d'],
        ]);
        if (in_array($invoice->status, ['sent', 'paid', 'void'], true)) {
            throw ValidationException::withMessages(['status' => 'A sent invoice is not changed. Void it and make a new one.']);
        }
        if (isset($input['lines'])) {
            $this->invoicing->replaceLines($user, $invoice, $input['lines']);
        }
        $invoice->fill(collect($input)->except('lines')->all())->save();
        $this->audit->logChange('invoice.updated', $invoice, $user);
        if (array_intersect(array_keys($input), ['bill_to_name', 'bill_to_address', 'notes', 'due_on']) !== []) {
            app(InvoicePdf::class)->store($invoice->fresh()->load('lines', 'company'));
        }

        return response()->json(['data' => $this->invoiceShape($invoice->fresh(['lines', 'payments']), $request->user())]);
    }

    public function approve(Request $request, Invoice $invoice): JsonResponse
    {
        $this->may($request->user(), 'approve', $invoice);
        $this->invoicing->approve($request->user(), $invoice);

        return response()->json(['data' => $this->invoiceShape($invoice->fresh(['lines', 'payments']), $request->user())]);
    }

    public function send(Request $request): JsonResponse
    {
        $user = $request->user();
        $input = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['uuid'],
            'to' => ['sometimes', 'array', 'max:20'],
            'to.*' => ['email'],
            'phones' => ['sometimes', 'array', 'max:20'],
            'phones.*' => ['string', 'regex:/^\+?[0-9 ().-]{7,20}$/'],
        ]);
        $invoices = Invoice::query()->with('lines', 'company')->whereIn('id', $input['ids'])->get();
        if ($invoices->count() !== count(array_unique($input['ids']))) {
            throw ValidationException::withMessages(['ids' => 'An invoice in the list is not there any more.']);
        }
        foreach ($invoices as $invoice) {
            $this->may($user, 'send', $invoice);
        }
        $this->invoicing->send($user, $invoices->all(), $input['to'] ?? [], $input['phones'] ?? []);

        return response()->json(['data' => $invoices->map(fn ($i) => $this->invoiceShape($i->fresh(['lines', 'payments']), $request->user()))->values()]);
    }

    public function pay(Request $request, Invoice $invoice): JsonResponse
    {
        $user = $request->user();
        $this->may($user, 'edit', $invoice);
        $input = $request->validate([
            'kind' => ['sometimes', Rule::in(['charge', 'refund'])],
            'paid_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:10000000', 'decimal:0,2'],
            'tip' => ['sometimes', 'numeric', 'min:0', 'decimal:0,2'],
            'method' => ['required', Rule::in(InvoicePayment::METHODS)],
            'reference' => ['nullable', 'string', 'max:120'],
            'collected_by_user_id' => ['nullable', 'uuid', Rule::exists('users', 'id')->where('organization_id', $this->tenant->idOrFail())],
        ]);
        $this->invoicing->recordPayment($user, $invoice, [
            'kind' => $input['kind'] ?? 'charge', 'paid_on' => $input['paid_on'],
            'amount_cents' => (int) round($input['amount'] * 100), 'tip_cents' => (int) round(($input['tip'] ?? 0) * 100),
            'method' => $input['method'], 'reference' => $input['reference'] ?? null,
            'collected_by_user_id' => $input['collected_by_user_id'] ?? null,
        ]);

        return response()->json(['data' => $this->invoiceShape($invoice->fresh(['lines', 'payments']), $request->user())], 201);
    }

    public function void(Request $request, Invoice $invoice): JsonResponse
    {
        $this->may($request->user(), 'delete', $invoice);
        $reason = $request->validate(['reason' => ['required', 'string', 'max:500']])['reason'];
        $this->invoicing->void($request->user(), $invoice, $reason);

        return response()->json(['data' => $this->invoiceShape($invoice->fresh(['lines', 'payments']), $request->user())]);
    }

    /** Who a send would reach, its subject and its body, before anybody presses Send. */
    public function preview(Request $request): JsonResponse
    {
        $user = $request->user();
        $ids = $request->validate(['ids' => ['required', 'array', 'min:1', 'max:100'], 'ids.*' => ['uuid']])['ids'];
        $invoices = Invoice::query()->with('lines', 'company')->whereIn('id', $ids)->get();
        foreach ($invoices as $invoice) {
            $this->may($user, 'view', $invoice);
        }
        abort_if($invoices->isEmpty(), Response::HTTP_NOT_FOUND);

        return response()->json(['data' => $this->invoicing->preview($invoices->all())]);
    }

    /** Settings › Invoicing extras: the next number this business would issue. */
    public function settings(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->may($user, 'view');
        $scope = Organization::find($this->tenant->idOrFail());
        $issuer = $request->filled('company_id') ? Company::query()->findOrFail($request->string('company_id')) : $scope;
        $channels = app(InvoiceChannels::class);

        return response()->json(['data' => [
            'next_number' => app(RecordNumberGenerator::class)->peek(RecordNumberGenerator::INVOICES, $scope),
            'channels' => array_map(fn ($c) => $channels->readiness($c, $issuer) + ['chosen' => in_array($c, $channels->chosen($issuer), true)], InvoiceChannels::ALL),
            'sms_numbers' => $channels->textingNumbers($issuer)->map(fn ($n) => ['id' => $n->id, 'e164' => $n->e164, 'label' => $n->label])->values(),
            'mailbox' => ($m = $channels->mailbox($issuer)) ? ['id' => $m->id, 'address' => $m->address] : null,
            // Card and bank payments: a company's own Stripe account (InvoicePaymentsController).
            'payments' => $issuer instanceof Company ? ['configured' => (string) config('services.stripe.secret') !== '',
                'may_connect' => $user->hasPermission('invoices.approve')]
                + (PaymentAccount::query()->where('company_id', $issuer->id)->first()?->present() ?? ['stripe_account_id' => null]) : null,
        ]]);
    }

    /** The PDF behind a texted or WhatsApp'd link: the signature is the permission. */
    public function shared(string $invoice)
    {
        $found = $this->tenant->runWithoutTenant(fn () => Invoice::query()->find($invoice));
        abort_if($found === null || $found->status === 'void' || $found->pdf_path === null, Response::HTTP_NOT_FOUND);
        $organization = $this->tenant->runWithoutTenant(fn () => Organization::query()->find($found->organization_id));

        return $this->tenant->run($organization, function () use ($found) {
            $this->audit->log('invoice.downloaded', $found, metadata: ['by' => 'link']);

            return Storage::disk($found->pdf_disk)->response($found->pdf_path, $found->number.'.pdf',
                ['Content-Type' => 'application/pdf', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
        });
    }

    public function pdf(Request $request, Invoice $invoice)
    {
        $this->may($request->user(), 'view', $invoice);
        abort_if($invoice->pdf_path === null, Response::HTTP_NOT_FOUND);

        return Storage::disk($invoice->pdf_disk)->response($invoice->pdf_path, $invoice->number.'.pdf', ['Content-Type' => 'application/pdf']);
    }

    // ── Helpers ───────────────────────────────────────────────

    private function may(User $user, string $action, ?Invoice $invoice = null): void
    {
        abort_unless($user->hasPermission("invoices.{$action}"), Response::HTTP_FORBIDDEN, match ($action) {
            'view' => 'Your role cannot see invoices.',
            'approve' => 'Your role cannot approve invoices.',
            'send' => 'Your role cannot send invoices.',
            default => 'Your role cannot change invoices.',
        });
        if ($invoice === null) {
            return;
        }
        // A job's invoice is the job's money: jobs.financials too (Jose's money rule).
        abort_if($invoice->source_type === 'job' && ! $user->hasPermission('jobs.financials'), Response::HTTP_FORBIDDEN, 'Amounts on jobs are hidden for your role.');
        abort_unless($this->sees($user, $invoice), Response::HTTP_NOT_FOUND);
    }

    /** In the current view: the place it bills, else the place that issued it. */
    private function sees(User $user, Invoice $invoice): bool
    {
        $scope = ViewScope::forUser($user);
        if ($invoice->bill_to_type === 'franchise' && $invoice->bill_to_id !== null && $scope->coversFranchise($invoice->bill_to_id)) {
            return true;
        }

        return $invoice->franchise_id !== null
            ? $scope->coversFranchise($invoice->franchise_id)
            : ($invoice->company_id !== null ? $scope->coversCompany($invoice->company_id) : $scope->isOrganization());
    }

    private function visibleRows(FinancialChargeSheet $sheet, User $user)
    {
        $scope = ViewScope::forUser($user);

        return $sheet->rows()->get()->filter(fn (FinancialChargeSheetRow $r) => $r->franchise_id === null ? $scope->isOrganization() : $scope->coversFranchise($r->franchise_id))->values();
    }

    private function sheetShape(FinancialChargeSheet $sheet, User $user): array
    {
        $rows = $this->visibleRows($sheet, $user);
        $invoices = Invoice::query()->whereIn('id', $rows->pluck('invoice_id')->filter())->get(['id', 'status', 'number'])->keyBy('id');

        return [
            'id' => $sheet->id, 'title' => $sheet->title, 'status' => $sheet->status,
            'saved_at' => $sheet->updated_at?->toIso8601String(),
            'period_from' => $sheet->period_from->toDateString(), 'period_to' => $sheet->period_to->toDateString(),
            'columns' => $sheet->columns,
            'rows' => $rows->map(fn (FinancialChargeSheetRow $r) => [
                'id' => $r->id, 'label' => $r->label, 'franchise_id' => $r->franchise_id, 'jobs' => $r->jobs,
                'cells' => (object) ($r->cells ?? []), 'total_cents' => $r->totalCents(), 'invoice_id' => $r->invoice_id,
                'invoice_status' => $invoices[$r->invoice_id]->status ?? null,
                'invoice_number' => $invoices[$r->invoice_id]->number ?? null,
            ])->values(),
            'total_cents' => (int) $rows->sum(fn ($r) => $r->totalCents()),
        ];
    }

    private function invoiceShape(Invoice $i, User $user): array
    {
        $names = User::query()->whereIn('id', array_filter([$i->approved_by_user_id, ...$i->payments->pluck('recorded_by_user_id')]))->pluck('name', 'id');

        // Who issued it, as the PDF shows it: the company, else the organization.
        $issuer = $i->company_id ? Company::find($i->company_id) : Organization::find($i->organization_id);

        return [
            'issuer' => [
                'name' => $issuer?->name,
                'address' => trim(implode('
', array_filter([$issuer?->address_line1, $issuer?->address_line2,
                    trim(($issuer?->city ?? '').', '.($issuer?->state ?? '').' '.($issuer?->postal_code ?? ''), ', ')]))) ?: null,
                'logo_url' => $issuer?->logo_path === null ? null
                    : ($i->company_id ? '/branding/companies/'.$i->company_id : '/branding/organization'),
            ],
            'approved_by_name' => $names[$i->approved_by_user_id] ?? null,
            'amount_due_cents' => $i->dueCents(),
            'can_approve' => $i->status === 'draft' && $user->hasPermission('invoices.approve'),
            'can_send' => in_array($i->status, ['approved', 'sent'], true) && $user->hasPermission('invoices.send'),
            'can_record_payment' => in_array($i->status, ['approved', 'sent', 'paid'], true) && $user->hasPermission('invoices.edit'),
            'can_void' => in_array($i->status, ['draft', 'approved', 'sent'], true) && $user->hasPermission('invoices.delete'),
            'can_edit' => $i->status === 'draft' && $user->hasPermission('invoices.edit'),
            'id' => $i->id, 'number' => $i->number, 'status' => $i->status, 'overdue' => $i->isOverdue(),
            'issued_on' => $i->issued_on?->toDateString(), 'due_on' => $i->due_on?->toDateString(),
            'bill_to' => ['type' => $i->bill_to_type, 'id' => $i->bill_to_id, 'name' => $i->bill_to_name,
                'address' => $i->bill_to_address, 'emails' => $i->bill_to_emails ?? [], 'phones' => $i->bill_to_phones ?? []],
            'summary' => $i->summary, 'notes' => $i->notes,
            'lines' => $i->lines->map(fn ($l) => ['id' => $l->id, 'description' => $l->description, 'quantity' => $l->quantity,
                'unit_cents' => $l->unit_cents, 'amount_cents' => $l->amount_cents, 'taxable' => $l->taxable, 'tax_cents' => $l->tax_cents])->values(),
            'subtotal_cents' => $i->subtotal_cents, 'tax_cents' => $i->tax_cents, 'total_cents' => $i->total_cents,
            'paid_cents' => $i->paidCents(), 'due_cents' => $i->dueCents(),
            'payments' => $i->payments->map(fn ($p) => ['id' => $p->id, 'kind' => $p->kind, 'paid_on' => $p->paid_on?->toDateString(),
                'amount_cents' => $p->amount_cents, 'tip_cents' => $p->tip_cents, 'method' => $p->method, 'reference' => $p->reference,
                'recorded_by_name' => $names[$p->recorded_by_user_id] ?? null])->values(),
            'source' => ['type' => $i->source_type, 'id' => $i->source_id],
            'has_pdf' => $i->pdf_path !== null,
            'approved_at' => $i->approved_at?->toIso8601String(), 'sent_at' => $i->sent_at?->toIso8601String(),
            'voided_at' => $i->voided_at?->toIso8601String(), 'void_reason' => $i->void_reason,
        ];
    }
}
