<?php

namespace App\Support\Invoices;

use App\Mail\InvoiceMail;
use App\Models\Company;
use App\Models\FinancialChargeColumn;
use App\Models\FinancialChargeSheet;
use App\Models\FinancialChargeSheetRow;
use App\Models\FinancialSource;
use App\Models\Franchise;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\Organization;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use App\Support\Automations\EventEmitter;
use App\Support\Mail\MailDispatcher;
use App\Support\Numbering\RecordNumberGenerator;
use App\Support\Scoping\ViewScope;
use App\Support\Settings\SettingsResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * The life of a charge sheet and its invoices (Jose, 2026-10-20/21):
 * make a sheet for a period (one row per place, prefilled from the
 * organization's "expenses by company" source where a charge line names a
 * column of it) → make invoices (a draft per row with money on it, with its
 * PDF) → approve → send (the organization's own mail) → payments → paid.
 * Overdue is raised once per invoice by the daily sweep.
 */
final class Invoicing
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly EventEmitter $events,
        private readonly RecordNumberGenerator $numbers,
        private readonly InvoicePdf $pdf,
        private readonly MailDispatcher $mail,
        private readonly SettingsResolver $settings,
        private readonly InvoiceChannels $channels,
    ) {}

    // ── Charge sheets ─────────────────────────────────────────

    public function makeSheet(User $user, string $from, string $to, ?Company $issuer = null, ?string $title = null): FinancialChargeSheet
    {
        $columns = FinancialChargeColumn::query()->where('is_active', true)->orderBy('position')->get();
        if ($columns->isEmpty()) {
            throw ValidationException::withMessages(['columns' => 'Set up the charge lines first, in Settings › Invoicing.']);
        }
        $scope = ViewScope::forUser($user);
        $places = Franchise::query()->with('company:id,name')->orderBy('name')->get()
            ->filter(fn (Franchise $f) => $scope->coversFranchise($f->id) && ($issuer === null || $f->company_id === $issuer->id))->values();

        return DB::transaction(function () use ($user, $from, $to, $issuer, $title, $columns, $places) {
            $sheet = FinancialChargeSheet::create([
                'company_id' => $issuer?->id, 'period_from' => $from, 'period_to' => $to, 'title' => $title,
                'columns' => $columns->map(fn ($c) => ['id' => $c->id, 'name' => $c->name])->all(),
                'created_by_user_id' => $user->id,
            ]);
            foreach ($places as $i => $place) {
                [$cells, $jobs] = $this->prefill($place, $columns, $from, $to);
                $sheet->rows()->create([
                    'company_id' => $place->company_id, 'franchise_id' => $place->id,
                    'label' => trim(($place->company?->name ?? '').' '.$place->name),
                    'jobs' => $jobs, 'cells' => $cells, 'position' => $i,
                ]);
            }
            $this->audit->log('invoice.sheet_created', $sheet, metadata: ['period' => "{$from}..{$to}", 'rows' => $places->count()], actor: $user);

            return $sheet;
        });
    }

    /**
     * A row's cells from the place's mapped "expenses by company" source
     * (role weekly_data): its latest row dated inside the period. Lines that
     * name no source column, or a place with nothing mapped, start at zero.
     *
     * @return array{0: array<string, int>, 1: ?int}
     */
    private function prefill(Franchise $place, $columns, string $from, string $to): array
    {
        $cells = $columns->mapWithKeys(fn ($c) => [$c->id => 0])->all();
        $source = FinancialSource::forRole('weekly_data')->first(fn (FinancialSource $s) => $s->franchise_id === $place->id && $s->overlaps($from, $to));
        if ($source === null) {
            return [$cells, null];
        }
        $dateField = $source->field('date') ?? 'Invoice Date';
        $row = $source->rows()
            ->filter(fn (array $f) => ($d = substr((string) ($f[$dateField] ?? ''), 0, 10)) !== '' && $d >= $from && $d <= $to)
            ->sortBy(fn (array $f) => (string) $f[$dateField])->last();
        if ($row === null) {
            return [$cells, null];
        }
        foreach ($columns as $c) {
            if ($c->source_column !== null && is_numeric($row[$c->source_column] ?? null)) {
                $cells[$c->id] = (int) round($row[$c->source_column] * 100);
            }
        }
        $jobs = $row[$source->field('jobs') ?? 'Total Jobs'] ?? null;

        return [$cells, is_numeric($jobs) ? (int) $jobs : null];
    }

    // ── Invoices ──────────────────────────────────────────────

    /** One draft per row with money on it and no invoice yet. @return array{made: list<Invoice>, skipped: list<array{row_id: string, label: string, reason: string}>} */
    public function invoiceSheet(User $user, FinancialChargeSheet $sheet): array
    {
        $issuer = $sheet->company_id ? Company::find($sheet->company_id) : null;
        $scope = $issuer ?? Organization::find($sheet->organization_id);
        $due = (int) $this->settings->get('money.payment_terms_days', $scope);
        $names = collect($sheet->columns)->pluck('name', 'id');

        $skipped = [];
        $made = DB::transaction(function () use ($user, $sheet, $issuer, $scope, $due, $names, &$skipped) {
            $made = [];
            foreach ($sheet->rows()->with('franchise.company')->get() as $row) {
                if ($row->invoice_id !== null) {
                    $number = Invoice::query()->whereKey($row->invoice_id)->value('number');
                    $skipped[] = ['row_id' => $row->id, 'label' => $row->label, 'reason' => "Already invoiced ({$number})."];

                    continue;
                }
                if ($row->totalCents() <= 0) {
                    $skipped[] = ['row_id' => $row->id, 'label' => $row->label, 'reason' => 'Nothing to bill.'];

                    continue;
                }
                $invoice = Invoice::create([
                    'company_id' => $issuer?->id,
                    'number' => $this->numbers->next(RecordNumberGenerator::INVOICES, $scope),
                    'issued_on' => today()->toDateString(),
                    'due_on' => today()->addDays($due)->toDateString(),
                    'bill_to_type' => 'franchise',
                    'bill_to_id' => $row->franchise_id,
                    'bill_to_name' => $row->label,
                    'bill_to_address' => $this->addressOf($row->franchise?->company),
                    'source_type' => 'charge_sheet_row',
                    'source_id' => $row->id,
                    'summary' => $row->jobs !== null ? "Total Jobs Created: {$row->jobs}" : null,
                    'created_by_user_id' => $user->id,
                ]);
                $position = 0;
                foreach ((array) $row->cells as $columnId => $cents) {
                    if ((int) $cents === 0) {
                        continue;
                    }
                    $invoice->lines()->create([
                        'position' => $position++, 'description' => (string) ($names[$columnId] ?? 'Charge'),
                        'quantity' => 1, 'unit_cents' => (int) $cents, 'amount_cents' => (int) $cents,
                        'meta' => ['column_id' => $columnId, 'jobs' => $row->jobs],
                    ]);
                }
                $this->total($invoice);
                $row->forceFill(['invoice_id' => $invoice->id])->save();
                $this->audit->log('invoice.created', $invoice, metadata: ['number' => $invoice->number, 'total_cents' => $invoice->total_cents], actor: $user);
                $made[] = $invoice;
            }
            if ($made !== []) {
                $sheet->forceFill(['status' => 'invoiced'])->save();
            }

            return $made;
        });

        foreach ($made as $invoice) {
            $this->pdf->store($invoice->load('lines', 'company'));
        }

        return ['made' => $made, 'skipped' => $skipped];
    }

    /** Totals from the lines: tax per line, rounded per line. */
    public function total(Invoice $invoice): void
    {
        $lines = $invoice->lines()->get();
        foreach ($lines as $line) {
            $tax = $line->taxable ? (int) round($line->amount_cents * $line->tax_rate_bp / 10000) : 0;
            if ($tax !== $line->tax_cents) {
                $line->forceFill(['tax_cents' => $tax])->save();
            }
        }
        $subtotal = (int) $lines->sum('amount_cents');
        $tax = (int) $lines->sum(fn ($l) => $l->taxable ? (int) round($l->amount_cents * $l->tax_rate_bp / 10000) : 0);
        $invoice->forceFill(['subtotal_cents' => $subtotal, 'tax_cents' => $tax, 'total_cents' => $subtotal + $tax])->save();
    }

    /** @param list<array{description: string, cents: int}> $lines */
    public function replaceLines(User $user, Invoice $invoice, array $lines): void
    {
        $this->mustBe($invoice, ['draft'], 'Only a draft can be changed. Void it and make a new one.');
        DB::transaction(function () use ($invoice, $lines) {
            $invoice->lines()->delete();
            foreach (array_values($lines) as $i => $l) {
                $invoice->lines()->create(['position' => $i, 'description' => $l['description'], 'quantity' => 1,
                    'unit_cents' => $l['cents'], 'amount_cents' => $l['cents'], 'meta' => $l['meta'] ?? null]);
            }
            $this->total($invoice);
        });
        $this->audit->logChange('invoice.updated', $invoice, $user);
        $this->pdf->store($invoice->fresh()->load('lines', 'company'));
    }

    public function approve(User $user, Invoice $invoice): void
    {
        $this->mustBe($invoice, ['draft'], 'Only a draft is approved.');
        $invoice->forceFill(['status' => 'approved', 'approved_by_user_id' => $user->id, 'approved_at' => now()])->save();
        $this->audit->log('invoice.approved', $invoice, actor: $user);
        $this->events->emit('invoice.approved', $invoice, ['number' => $invoice->number], $user);
    }

    /**
     * Sends approved invoices by every channel the issuer chose (Settings ›
     * Invoicing): email one per invoice or as one summary, texts and WhatsApp
     * one per invoice to the phones it bills, each carrying a link to the PDF.
     * A chosen channel that is not set up refuses the whole send with why.
     *
     * @param  list<Invoice>  $invoices
     * @param  list<string>  $to  addresses for a single invoice, or the summary's
     * @param  list<string>  $phones  phones for a single invoice
     */
    public function send(User $user, array $invoices, array $to = [], array $phones = []): void
    {
        foreach ($invoices as $invoice) {
            $this->mustBe($invoice, ['approved', 'sent'], 'Approve an invoice before sending it.');
        }
        $first = $invoices[0];
        $issuer = $first->company ?? Organization::find($first->organization_id);
        $chosen = $this->channels->chosen($issuer);
        foreach ($chosen as $channel) {
            $ready = $this->channels->readiness($channel, $issuer);
            if (! $ready['ready']) {
                throw ValidationException::withMessages(['send' => $ready['why_not']]);
            }
        }
        $mode = (string) $this->settings->get('invoicing.email_mode', $issuer);
        $columns = collect($invoices)->flatMap(fn ($i) => $i->lines->pluck('description'))->unique()->values()->all();
        $single = count($invoices) === 1;

        $batches = ! in_array('email', $chosen, true) ? [] : ($mode === 'summary'
            ? [[$invoices, $to !== [] ? $to : $this->addresses((string) $this->settings->get('invoicing.summary_to', $issuer))]]
            : array_map(fn (Invoice $i) => [[$i], $to !== [] && $single ? $to : (array) ($i->bill_to_emails ?? [])], $invoices));
        foreach ($batches as [$group, $addresses]) {
            if ($addresses === []) {
                throw ValidationException::withMessages(['to' => 'Say who '.($mode === 'summary' ? 'the summary' : $group[0]->number).' goes to.']);
            }
        }
        $texting = array_values(array_intersect(['sms', 'whatsapp'], $chosen));
        $phonesFor = fn (Invoice $i) => $phones !== [] && $single ? $phones : (array) ($i->bill_to_phones ?? []);
        if ($texting !== []) {
            foreach ($invoices as $invoice) {
                if ($phonesFor($invoice) === []) {
                    throw ValidationException::withMessages(['phones' => "Say which phone {$invoice->number} is texted to."]);
                }
            }
        }

        $sent = [];
        foreach ($batches as [$group, $addresses]) {
            $subject = count($group) === 1 ? "Invoice {$group[0]->number} from {$issuer->name}" : "Invoices from {$issuer->name}";
            $mail = new InvoiceMail($group, $columns, (string) $issuer->name, $subject);
            try {
                $this->channels->email($issuer, $addresses, $subject, $mail->content()->htmlString, $group, $mail);
            } catch (Throwable $e) {
                throw ValidationException::withMessages(['send' => $e->getMessage() ?: 'The email could not be sent.']);
            }
            foreach ($group as $invoice) {
                $sent[$invoice->id]['email'] = $addresses;
            }
        }
        foreach ($invoices as $invoice) {
            foreach ($texting as $channel) {
                try {
                    $this->channels->{$channel}($issuer, $invoice, $phonesFor($invoice));
                } catch (Throwable $e) {
                    throw ValidationException::withMessages(['send' => $e->getMessage() ?: 'The message could not be sent.']);
                }
                $sent[$invoice->id][$channel] = $phonesFor($invoice);
            }
        }

        foreach ($invoices as $invoice) {
            $by = $sent[$invoice->id] ?? [];
            $invoice->forceFill(['status' => 'sent', 'sent_by_user_id' => $user->id, 'sent_at' => now(),
                'bill_to_emails' => $invoice->bill_to_emails ?: ($by['email'] ?? null),
                'bill_to_phones' => $invoice->bill_to_phones ?: ($by['sms'] ?? $by['whatsapp'] ?? null)])->save();
            $this->audit->log('invoice.sent', $invoice, metadata: ['to' => $by['email'] ?? [], 'channels' => $by], actor: $user);
            $this->events->emit('invoice.sent', $invoice, ['number' => $invoice->number, 'channels' => array_keys($by)], $user);
        }
    }

    /**
     * Who a send would go to, the subject, and the email as it would read.
     *
     * @param  list<Invoice>  $invoices
     * @return array{to: list<string>, subject: string, html: string, mode: string}
     */
    public function preview(array $invoices): array
    {
        $first = $invoices[0];
        $issuer = $first->company ?? Organization::find($first->organization_id);
        $mode = (string) $this->settings->get('invoicing.email_mode', $issuer);
        $columns = collect($invoices)->flatMap(fn ($i) => $i->lines->pluck('description'))->unique()->values()->all();
        $group = $mode === 'summary' ? $invoices : [$first];
        $to = $mode === 'summary'
            ? $this->addresses((string) $this->settings->get('invoicing.summary_to', $issuer))
            : (array) ($first->bill_to_emails ?? []);
        $subject = count($group) === 1 ? "Invoice {$first->number} from {$issuer->name}" : "Invoices from {$issuer->name}";

        $phones = (array) ($first->bill_to_phones ?? []);
        $channels = array_map(fn (string $c) => $this->channels->readiness($c, $issuer) + ['to' => $c === 'email' ? $to : $phones],
            $this->channels->chosen($issuer));

        return ['to' => $to, 'subject' => $subject, 'mode' => $mode, 'channels' => $channels,
            'text' => $this->channels->shortText($issuer, $first),
            'html' => (new InvoiceMail($group, $columns, (string) $issuer->name, $subject))->content()->htmlString];
    }

    public function recordPayment(User $user, Invoice $invoice, array $payment): InvoicePayment
    {
        $this->mustBe($invoice, ['approved', 'sent', 'paid'], 'Payments go against an approved or sent invoice.');
        $made = $invoice->payments()->create($payment + ['recorded_by_user_id' => $user->id]);
        $this->audit->log('invoice.payment_recorded', $invoice, metadata: $made->only('kind', 'paid_on', 'amount_cents', 'method'), actor: $user);

        $invoice->load('payments');
        $paid = $invoice->dueCents() === 0;
        if ($paid && $invoice->status !== 'paid') {
            $invoice->forceFill(['status' => 'paid', 'paid_at' => now()])->save();
            $this->events->emit('invoice.paid', $invoice, ['number' => $invoice->number], $user);
        } elseif (! $paid && $invoice->status === 'paid') {
            // A refund reopened it.
            $invoice->forceFill(['status' => 'sent', 'paid_at' => null])->save();
        }

        return $made;
    }

    public function void(User $user, Invoice $invoice, string $reason): void
    {
        $this->mustBe($invoice, ['draft', 'approved', 'sent'], 'A paid invoice is not voided; record a refund instead.');
        $invoice->forceFill(['status' => 'void', 'voided_at' => now(), 'void_reason' => $reason])->save();
        FinancialChargeSheetRow::query()->where('invoice_id', $invoice->id)->update(['invoice_id' => null]);
        $this->audit->log('invoice.voided', $invoice, metadata: ['reason' => $reason], actor: $user);
    }

    /** Raises invoice.overdue once per invoice. Runs inside the tenant. */
    public function sweepOverdue(): int
    {
        $count = 0;
        Invoice::query()->with('payments')->where('status', 'sent')->whereNull('overdue_announced_at')
            ->whereDate('due_on', '<', today())->each(function (Invoice $invoice) use (&$count) {
                if ($invoice->dueCents() > 0) {
                    $invoice->forceFill(['overdue_announced_at' => now()])->save();
                    $this->events->emit('invoice.overdue', $invoice, ['number' => $invoice->number, 'due_cents' => $invoice->dueCents()]);
                    $count++;
                }
            });

        return $count;
    }

    private function mustBe(Invoice $invoice, array $statuses, string $why): void
    {
        if (! in_array($invoice->status, $statuses, true)) {
            throw ValidationException::withMessages(['status' => $why]);
        }
    }

    private function addressOf(?Company $company): ?string
    {
        if ($company === null) {
            return null;
        }

        return trim(implode("\n", array_filter([$company->address_line1, $company->address_line2,
            trim(($company->city ?? '').', '.($company->state ?? '').' '.($company->postal_code ?? ''), ', ')]))) ?: null;
    }

    /** @return list<string> */
    private function addresses(string $list): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/[,;\s]+/', $list)), fn ($a) => filter_var($a, FILTER_VALIDATE_EMAIL)));
    }
}
