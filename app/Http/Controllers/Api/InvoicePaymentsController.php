<?php

namespace App\Http\Controllers\Api;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\Organization;
use App\Models\PaymentAccount;
use App\Support\Audit\AuditLogger;
use App\Support\Invoices\Invoicing;
use App\Support\Payments\StripeGateway;
use App\Support\Scoping\ViewScope;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Invoices paid online (Payments, Jose 2026-11-06): each company connects its
 * own Stripe account (Standard), the invoice's Pay link opens a Checkout
 * Session on that account for what is still due, and Stripe's signed webhook
 * writes the invoice_payments row through Invoicing::recordPayment, so a card
 * payment is the same row, audit and `invoice.paid` as one typed in by hand.
 *
 * Nothing but the webhook marks money paid: the Pay link and the return pages
 * are public and anybody can open them.
 */
final class InvoicePaymentsController extends Controller
{
    private const PAGES = [
        'paid' => ['Thank you', 'Your payment went through. You can close this page.'],
        'cancelled' => ['Payment not made', 'Nothing was charged. Use the same link again whenever you are ready.'],
        'nothing-due' => ['Nothing to pay', 'This invoice has nothing left to pay online.'],
        'onboarding-return' => ['Back to EzBizi', 'Stripe has what you entered. Go back to EzBizi › Settings › Invoicing to see whether anything is still needed.'],
        'onboarding-refresh' => ['This setup link has expired', 'Go back to EzBizi › Settings › Invoicing and press Connect Stripe again for a fresh one.'],
    ];

    public function __construct(
        private readonly StripeGateway $stripe,
        private readonly TenantContext $tenant,
        private readonly Invoicing $invoicing,
        private readonly AuditLogger $audit,
    ) {}

    // ── Signed in: Settings › Invoicing ───────────────────────

    /** GET /invoices/payments-account?company_id= */
    public function account(Request $request): JsonResponse
    {
        $company = $this->company($request, 'view');
        $account = PaymentAccount::query()->where('company_id', $company->id)->first();

        if ($account !== null && $this->stripe->configured()) {
            try {
                $account->absorb($this->stripe->retrieveAccount($account->stripe_account_id));
                $account->save();
            } catch (RuntimeException) {
                // The stored flags are the last thing Stripe told us.
            }
        }

        return response()->json(['data' => ['configured' => $this->stripe->configured()]
            + ($account?->present() ?? ['company_id' => $company->id, 'stripe_account_id' => null])]);
    }

    /** POST /invoices/payments-account/onboarding — start, or resume, Stripe's hosted setup. */
    public function onboarding(Request $request): JsonResponse
    {
        $user = $request->user();
        $company = $this->company($request, 'approve');
        $account = PaymentAccount::query()->where('company_id', $company->id)->first();
        $base = rtrim((string) config('app.url'), '/').'/api/v1/payments/pages/';

        try {
            if ($account === null) {
                $account = PaymentAccount::query()->create([
                    'company_id' => $company->id,
                    'stripe_account_id' => $this->stripe->createConnectedAccount(
                        ['organization_id' => $user->organization_id, 'company_id' => $company->id], $user->email, (string) $company->name),
                ]);
                $this->audit->log('payment_account.created', $account, metadata: ['company_id' => $company->id], actor: $user);
            }
            $url = $this->stripe->accountLink($account->stripe_account_id, $base.'onboarding-refresh', $base.'onboarding-return');
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['company_id' => $e->getMessage()]);
        }

        return response()->json(['data' => ['url' => $url] + $account->present()]);
    }

    // ── Public: the customer ──────────────────────────────────

    /** The invoice's Pay link (signed): a Checkout Session for what is due, then Stripe. */
    public function pay(string $invoice)
    {
        $found = $this->tenant->runWithoutTenant(fn () => Invoice::query()->with('payments')->find($invoice));
        abort_if($found === null, Response::HTTP_NOT_FOUND);
        $organization = $this->tenant->runWithoutTenant(fn () => Organization::query()->find($found->organization_id));

        return $this->tenant->run($organization, function () use ($found) {
            $account = PaymentAccount::query()->where('company_id', $found->company_id)->first();
            $due = $found->dueCents();
            if (! in_array($found->status, ['approved', 'sent'], true) || $due <= 0 || ! $account?->charges_enabled) {
                return $this->page('nothing-due');
            }

            $base = rtrim((string) config('app.url'), '/').'/api/v1/payments/pages/';
            $fee = (int) round($due * (float) config('services.stripe.application_fee_percent') / 100);
            $meta = ['invoice_id' => $found->id, 'organization_id' => $found->organization_id];
            try {
                $session = $this->stripe->createCheckoutSession($account->stripe_account_id, [
                    'mode' => 'payment',
                    'payment_method_types' => ['card', 'us_bank_account'],
                    'line_items' => [['quantity' => 1, 'price_data' => [
                        'currency' => strtolower((string) ($found->currency ?: 'usd')),
                        'unit_amount' => $due,
                        'product_data' => ['name' => 'Invoice '.$found->number],
                    ]]],
                    'payment_intent_data' => array_filter(['application_fee_amount' => $fee ?: null, 'metadata' => $meta]),
                    'metadata' => $meta,
                    'success_url' => $base.'paid?invoice='.$found->id,
                    'cancel_url' => $base.'cancelled',
                ], 'invoice:'.$found->id.':'.$due.':'.now()->format('Y-m-d'));
            } catch (RuntimeException $e) {
                report($e);

                return $this->page('cancelled');
            }

            return redirect()->away($session['url']);
        });
    }

    /** Where Stripe sends a browser back to. It marks nothing. */
    public function pages(string $page)
    {
        abort_unless(isset(self::PAGES[$page]), Response::HTTP_NOT_FOUND);

        return $this->page($page);
    }

    // ── Public: Stripe ────────────────────────────────────────

    /**
     * The signature is the authentication. Idempotent in the database: the
     * (provider, provider_reference) pair is unique, so a redelivery finds its
     * row and answers 200 (anything else makes Stripe retry for three days).
     */
    public function webhook(Request $request)
    {
        try {
            $event = $this->stripe->constructEvent($request->getContent(), (string) $request->header('Stripe-Signature', ''));
        } catch (RuntimeException) {
            return response('', 400);
        }
        $object = $event['data']['object'] ?? [];
        $accountId = (string) ($event['account'] ?? '');

        match ($event['type'] ?? '') {
            'account.updated' => $this->accountUpdated($object),
            // ACH completes the session unpaid and is paid when it settles.
            'checkout.session.completed', 'checkout.session.async_payment_succeeded' => ($object['payment_status'] ?? null) === 'paid'
                ? $this->sessionPaid($accountId, $object) : null,
            'charge.refunded' => $this->chargeRefunded($accountId, $object),
            default => null,
        };

        return response('', 200);
    }

    private function accountUpdated(array $account): void
    {
        $row = $this->tenant->runWithoutTenant(fn () => PaymentAccount::query()->where('stripe_account_id', (string) ($account['id'] ?? ''))->first());
        if ($row !== null) {
            $this->inTenantOf($row->organization_id, function () use ($row, $account) {
                $row->absorb($account);
                $row->save();
            });
        }
    }

    private function sessionPaid(string $accountId, array $session): void
    {
        $intent = (string) ($session['payment_intent'] ?? '');
        $invoice = $this->invoiceOn($accountId, (string) ($session['metadata']['invoice_id'] ?? ''));
        if ($invoice === null || $intent === '') {
            return;
        }

        $this->inTenantOf($invoice->organization_id, function () use ($invoice, $accountId, $intent, $session) {
            $details = $this->stripe->paymentDetails($accountId, $intent);
            $this->once($invoice, $intent, [
                'kind' => 'charge', 'amount_cents' => (int) ($session['amount_total'] ?? 0),
                'method' => $details['method'], 'fee_cents' => $details['fee_cents'],
            ]);
        });
    }

    /** Stripe sends the charge's running total refunded; record the part not yet recorded. */
    private function chargeRefunded(string $accountId, array $charge): void
    {
        $intent = (string) ($charge['payment_intent'] ?? '');
        $paid = $intent === '' ? null : $this->tenant->runWithoutTenant(fn () => InvoicePayment::query()
            ->where('provider', 'stripe')->where('provider_reference', $intent)->first());
        $invoice = $paid ? $this->invoiceOn($accountId, $paid->invoice_id) : null;
        if ($invoice === null) {
            return;
        }

        $this->inTenantOf($invoice->organization_id, function () use ($invoice, $charge, $paid) {
            $total = (int) ($charge['amount_refunded'] ?? 0);
            $recorded = (int) InvoicePayment::query()->where('provider', 'stripe')->where('kind', 'refund')
                ->where('reference', $paid->provider_reference)->sum('amount_cents');
            if ($total > $recorded) {
                $this->once($invoice, $charge['id'].':'.$total, [
                    'kind' => 'refund', 'amount_cents' => $total - $recorded, 'method' => $paid->method, 'reference' => $paid->provider_reference,
                ]);
            }
        });
    }

    /** The invoice named by Stripe, if it belongs to the company whose account sent the event. */
    private function invoiceOn(string $accountId, string $invoiceId): ?Invoice
    {
        return $this->tenant->runWithoutTenant(function () use ($accountId, $invoiceId) {
            $invoice = $invoiceId === '' ? null : Invoice::query()->find($invoiceId);

            return $invoice !== null && PaymentAccount::query()->where('company_id', $invoice->company_id)
                ->where('stripe_account_id', $accountId)->exists() ? $invoice : null;
        });
    }

    /** One invoice_payments row per Stripe reference, under the invoice's lock. */
    private function once(Invoice $invoice, string $reference, array $row): void
    {
        try {
            DB::transaction(function () use ($invoice, $reference, $row) {
                $locked = Invoice::query()->whereKey($invoice->id)->lockForUpdate()->first();
                if (InvoicePayment::query()->where('provider', 'stripe')->where('provider_reference', $reference)->exists()) {
                    return;
                }
                if (! in_array($locked->status, ['approved', 'sent', 'paid'], true)) {
                    // Money arrived for a voided invoice: keep the trail, Stripe has the money.
                    $this->audit->log('invoice.payment_unrecorded', $locked, metadata: ['provider_reference' => $reference, 'amount_cents' => $row['amount_cents'], 'status' => $locked->status]);

                    return;
                }
                $this->invoicing->recordPayment(null, $locked, $row + [
                    'paid_on' => today()->toDateString(), 'provider' => 'stripe', 'provider_reference' => $reference,
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            // A concurrent redelivery won the race; its row stands.
        }
    }

    private function company(Request $request, string $action): Company
    {
        $user = $request->user();
        abort_unless($user->hasPermission("invoices.{$action}"), Response::HTTP_FORBIDDEN, $action === 'view'
            ? 'Your role cannot see invoices.' : 'Your role cannot connect payments for this company.');
        $id = (string) $request->validate(['company_id' => ['required', 'uuid']])['company_id'];
        abort_unless(ViewScope::forUser($user)->coversCompany($id), Response::HTTP_NOT_FOUND);

        return Company::query()->findOrFail($id);
    }

    private function inTenantOf(?string $organizationId, callable $fn): void
    {
        $organization = $organizationId ? $this->tenant->runWithoutTenant(fn () => Organization::query()->find($organizationId)) : null;
        if ($organization !== null) {
            $this->tenant->run($organization, $fn);
        }
    }

    private function page(string $page)
    {
        [$title, $message] = self::PAGES[$page];

        return response()->view('payments.page', compact('title', 'message') + ['business' => null, 'amount' => null])
            ->header('Cache-Control', 'no-store')->header('X-Robots-Tag', 'noindex');
    }
}
