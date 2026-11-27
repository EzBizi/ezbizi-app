<?php

namespace App\Support\Financials\Plaid;

use App\Models\BankTransaction;
use App\Models\Franchise;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\SocialConnection;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Money that came into a bank, matched to what EzBizi already knows it was
 * for: a payment recorded on an invoice (paid within a week of the deposit,
 * for the same money: the charge, with its tip, less the processor's fee), or
 * an open invoice for exactly that amount issued in the 90 days before.
 *
 * Only inside the bank's own place: a bank connected at a company matches
 * that company's invoices (its branches' included), one connected at a branch
 * matches that branch's, one connected at the organization matches any. And
 * nothing already matched to another deposit is offered again.
 *
 * The sync matches on its own only when exactly ONE recorded payment fits
 * (matched_by = auto); everything else waits for a person, who can also
 * undo an automatic match. The invoice side is never written.
 */
final class BankMatcher
{
    public const PAYMENT_DAYS = 7;

    public const INVOICE_DAYS = 90;

    public const TYPES = ['invoice_payment', 'invoice'];

    /** @return list<array{type: string, id: string, label: string, detail: string, amount_cents: int, date: ?string}> */
    public function candidates(BankTransaction $t, ?SocialConnection $bank = null): array
    {
        if ($t->amount_cents >= 0) {
            return [];   // money out: nothing in EzBizi to match it to yet
        }
        $bank ??= SocialConnection::query()->find($t->social_connection_id);
        $cents = -$t->amount_cents;
        $date = CarbonImmutable::parse($t->date);
        $inPlace = Invoice::query()->where(fn ($q) => $this->inPlace($q, $bank))->select('id');
        $payments = InvoicePayment::query()
            ->where('kind', 'charge')
            ->whereBetween('paid_on', [$date->subDays(self::PAYMENT_DAYS)->toDateString(), $date->addDays(self::PAYMENT_DAYS)->toDateString()])
            ->where(fn ($q) => $q->where('amount_cents', $cents)
                ->orWhereRaw('amount_cents + tip_cents = ?', [$cents])
                ->orWhereRaw('amount_cents + tip_cents - fee_cents = ?', [$cents]))
            ->whereIn('invoice_id', $inPlace)
            ->whereNotIn('id', $this->taken($t, 'invoice_payment'))
            ->orderByRaw('abs(datediff(paid_on, ?))', [$date->toDateString()])
            ->limit(10)->get();
        $owners = Invoice::query()->whereIn('id', $payments->pluck('invoice_id'))->get(['id', 'number', 'bill_to_name'])->keyBy('id');

        $invoices = Invoice::query()->whereIn('status', ['approved', 'sent'])->where('total_cents', $cents)
            ->whereBetween('issued_on', [$date->subDays(self::INVOICE_DAYS)->toDateString(), $date->toDateString()])
            ->where(fn ($q) => $this->inPlace($q, $bank))
            ->whereNotIn('id', $this->taken($t, 'invoice'))
            ->orderByDesc('issued_on')->limit(10)->get();

        return [
            ...$payments->map(fn (InvoicePayment $p) => [
                'type' => 'invoice_payment', 'id' => (string) $p->getKey(),
                'label' => 'Payment on invoice '.$owners[$p->invoice_id]?->number,
                'detail' => trim(($owners[$p->invoice_id]?->bill_to_name ?? '').' · '.$p->method, ' ·'),
                'amount_cents' => (int) $p->amount_cents + (int) $p->tip_cents, 'date' => $p->paid_on?->toDateString(),
            ])->all(),
            ...$invoices->map(fn (Invoice $i) => [
                'type' => 'invoice', 'id' => (string) $i->getKey(),
                'label' => 'Invoice '.$i->number,
                'detail' => trim($i->bill_to_name.' · '.$i->status, ' ·'),
                'amount_cents' => (int) $i->total_cents, 'date' => $i->issued_on?->toDateString(),
            ])->all(),
        ];
    }

    /** The one match the sync may make on its own: exactly one recorded payment fits. */
    public function autoMatch(BankTransaction $t, SocialConnection $bank): bool
    {
        if ($t->matched_type !== null || $t->pending || $t->amount_cents >= 0) {
            return false;
        }
        $payments = array_values(array_filter($this->candidates($t, $bank), fn ($c) => $c['type'] === 'invoice_payment'));
        if (count($payments) !== 1) {
            return false;
        }
        $t->forceFill(['matched_type' => 'invoice_payment', 'matched_id' => $payments[0]['id'], 'matched_by' => 'auto', 'matched_at' => now()])->save();

        return true;
    }

    /** Is this one of the transaction's candidates right now? What a person's choice is checked against. */
    public function offers(BankTransaction $t, string $type, string $id): bool
    {
        return collect($this->candidates($t))->contains(fn ($c) => $c['type'] === $type && $c['id'] === $id);
    }

    /**
     * What each matched transaction is matched to, for the list, in three queries.
     *
     * @param  iterable<BankTransaction>  $txns
     * @return array<string, array{type: string, id: string, label: string, by: ?string}> by transaction id
     */
    public static function describe(iterable $txns): array
    {
        $txns = collect($txns)->whereNotNull('matched_type');
        $payments = InvoicePayment::query()->whereIn('id', $txns->where('matched_type', 'invoice_payment')->pluck('matched_id'))->get(['id', 'invoice_id'])->keyBy('id');
        $invoices = Invoice::query()->whereIn('id', $txns->where('matched_type', 'invoice')->pluck('matched_id')->merge($payments->pluck('invoice_id')))
            ->get(['id', 'number', 'bill_to_name'])->keyBy('id');
        $out = [];
        foreach ($txns as $t) {
            $invoice = $t->matched_type === 'invoice_payment' ? $invoices[$payments[$t->matched_id]->invoice_id ?? ''] ?? null : $invoices[$t->matched_id] ?? null;
            $out[(string) $t->getKey()] = [
                'type' => (string) $t->matched_type,
                'id' => (string) $t->matched_id,
                'label' => $invoice ? 'Invoice '.$invoice->number.' · '.$invoice->bill_to_name : 'A record since removed',
                'by' => $t->matched_by,
            ];
        }

        return $out;
    }

    /** Ids of this type other deposits already hold, as a subquery the database checks (no rows loaded per deposit). */
    private function taken(BankTransaction $t, string $type): Builder
    {
        return BankTransaction::query()->where('matched_type', $type)->whereKeyNot($t->getKey())->select('matched_id');
    }

    /** An invoice in the bank's own place (see the class comment). */
    private function inPlace(Builder $q, ?SocialConnection $bank): Builder
    {
        return match ($bank?->scope_type) {
            SocialConnection::SCOPE_ORGANIZATION => $q,
            SocialConnection::SCOPE_COMPANY => $q->where(fn ($w) => $w->where('company_id', $bank->scope_id)
                ->orWhereIn('franchise_id', Franchise::query()->where('company_id', $bank->scope_id)->pluck('id'))),
            SocialConnection::SCOPE_FRANCHISE => $q->where('franchise_id', $bank->scope_id),
            default => $q->whereRaw('1 = 0'),
        };
    }
}
