<?php

namespace Tests\Feature\Payments;

use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\PaymentAccount;
use App\Support\Invoices\InvoiceChannels;
use App\Support\Payments\StripeGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Invoices paid online (Jose 2026-11-06): connect a company's Stripe account,
 * the Pay link opens Checkout for what is due, the signed webhook writes the
 * invoice_payments row once, refunds reopen the invoice.
 */
class InvoicePaymentsTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private const SECRET = 'whsec_invoice_payments_test_only';

    private FakeStripeGateway $stripe;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.stripe.secret' => 'sk_test_fake', 'services.stripe.connect_webhook_secret' => self::SECRET, 'services.stripe.webhook_secret' => '']);
        $this->stripe = new FakeStripeGateway;
        $this->app->instance(StripeGateway::class, $this->stripe);
    }

    /** A sent $125.00 invoice from the tenant's company, whose Stripe account takes charges. */
    private function sentInvoice(array $t, string $account = 'acct_alpha', bool $enabled = true): Invoice
    {
        return $this->inTenant($t['org'], function () use ($t, $account, $enabled) {
            PaymentAccount::query()->create(['company_id' => $t['company']->id, 'stripe_account_id' => $account, 'charges_enabled' => $enabled]);

            return Invoice::query()->create([
                'company_id' => $t['company']->id, 'number' => 'INV-00007', 'issued_on' => today(), 'status' => 'sent', 'currency' => 'USD',
                'bill_to_type' => 'customer', 'bill_to_name' => 'Pat Customer', 'subtotal_cents' => 12500, 'total_cents' => 12500,
            ]);
        });
    }

    private function deliver(array $event): TestResponse
    {
        $payload = json_encode($event);
        $time = time();

        return $this->call('POST', '/api/v1/payments/stripe/webhook', [], [], [], [
            'HTTP_STRIPE_SIGNATURE' => 't='.$time.',v1='.hash_hmac('sha256', $time.'.'.$payload, self::SECRET),
            'CONTENT_TYPE' => 'application/json',
        ], $payload);
    }

    private function paidSession(Invoice $invoice, string $type = 'checkout.session.completed', string $status = 'paid', string $account = 'acct_alpha'): array
    {
        return ['type' => $type, 'account' => $account, 'data' => ['object' => [
            'id' => 'cs_test_1', 'payment_status' => $status, 'payment_intent' => 'pi_alpha_1', 'amount_total' => 12500,
            'metadata' => ['invoice_id' => $invoice->id],
        ]]];
    }

    private function payments(array $t)
    {
        return $this->inTenant($t['org'], fn () => InvoicePayment::query()->orderBy('created_at')->get());
    }

    public function test_the_pay_link_opens_checkout_for_what_is_due_on_the_companys_account(): void
    {
        $t = $this->provisionTenant('Alpha');
        $invoice = $this->sentInvoice($t);
        $link = $this->inTenant($t['org'], fn () => InvoiceChannels::payLink($invoice));

        $this->get($link)->assertRedirect('https://checkout.stripe.test/cs_test_1');
        $session = $this->stripe->sessions[0];
        $this->assertSame('acct_alpha', $session['accountId']);
        $this->assertSame(12500, $session['params']['line_items'][0]['price_data']['unit_amount']);
        $this->assertSame($invoice->id, $session['params']['metadata']['invoice_id']);
        $this->assertStringStartsWith("invoice:{$invoice->id}:12500:", $session['idempotencyKey']);
        $this->assertArrayNotHasKey('application_fee_amount', $session['params']['payment_intent_data']);

        // Unsigned is refused; the text carries the link.
        $this->get('/api/v1/invoices/pay/'.$invoice->id)->assertForbidden();
        $this->assertStringContainsString('Pay online: ', $this->inTenant($t['org'], fn () => app(InvoiceChannels::class)->shortText($t['company'], $invoice)));
    }

    public function test_no_pay_link_until_the_company_can_take_charges_and_nothing_due_opens_no_checkout(): void
    {
        $t = $this->provisionTenant('Alpha');
        $invoice = $this->sentInvoice($t, enabled: false);
        $this->assertNull($this->inTenant($t['org'], fn () => InvoiceChannels::payLink($invoice)));

        // A link sent while it could, opened after it cannot: a page, no Checkout.
        $this->get(URL::temporarySignedRoute('invoice.pay', now()->addDay(), ['invoice' => $invoice->id]))->assertOk()->assertSee('Nothing to pay');
        $this->assertSame([], $this->stripe->sessions);
    }

    public function test_a_paid_session_records_one_payment_and_marks_the_invoice_paid(): void
    {
        $t = $this->provisionTenant('Alpha');
        $invoice = $this->sentInvoice($t);

        $this->deliver($this->paidSession($invoice))->assertOk();
        $this->deliver($this->paidSession($invoice))->assertOk();   // redelivered

        $payments = $this->payments($t);
        $this->assertCount(1, $payments);
        $this->assertSame(['charge', 12500, 393, 'card', 'stripe', 'pi_alpha_1', null],
            [$payments[0]->kind, $payments[0]->amount_cents, $payments[0]->fee_cents, $payments[0]->method, $payments[0]->provider, $payments[0]->provider_reference, $payments[0]->recorded_by_user_id]);
        $this->assertSame('paid', $this->inTenant($t['org'], fn () => $invoice->fresh()->status));

        // The paid page marks nothing and needs no invoice.
        $this->get('/api/v1/payments/pages/paid')->assertOk()->assertSee('Thank you');
        $this->get('/api/v1/payments/pages/elsewhere')->assertNotFound();
    }

    public function test_ach_is_recorded_when_it_settles_not_when_the_session_completes(): void
    {
        $t = $this->provisionTenant('Alpha');
        $invoice = $this->sentInvoice($t);
        $this->stripe->details = ['method' => 'transfer', 'fee_cents' => 100];

        $this->deliver($this->paidSession($invoice, status: 'unpaid'))->assertOk();
        $this->assertCount(0, $this->payments($t));

        $this->deliver($this->paidSession($invoice, 'checkout.session.async_payment_succeeded'))->assertOk();
        $this->assertSame('transfer', $this->payments($t)->sole()->method);
    }

    public function test_an_event_from_another_account_or_unsigned_changes_nothing(): void
    {
        $t = $this->provisionTenant('Alpha');
        $invoice = $this->sentInvoice($t);

        $this->deliver($this->paidSession($invoice, account: 'acct_someone_else'))->assertOk();
        $this->call('POST', '/api/v1/payments/stripe/webhook', [], [], [], ['HTTP_STRIPE_SIGNATURE' => 't=1,v1=bad', 'CONTENT_TYPE' => 'application/json'],
            json_encode($this->paidSession($invoice)))->assertStatus(400);

        $this->assertCount(0, $this->payments($t));
        $this->assertSame('sent', $this->inTenant($t['org'], fn () => $invoice->fresh()->status));
    }

    public function test_a_real_sandbox_refund_records_the_difference_once_and_reopens_the_invoice(): void
    {
        $t = $this->provisionTenant('Alpha');
        $raw = (string) file_get_contents(base_path('tests/Fixtures/stripe/charge.refunded.json'));
        $event = json_decode($raw, true);
        $invoice = $this->sentInvoice($t, $event['account']);
        $this->deliver(['type' => 'checkout.session.completed', 'account' => $event['account'], 'data' => ['object' => [
            'id' => 'cs_x', 'payment_status' => 'paid', 'payment_intent' => $event['data']['object']['payment_intent'], 'amount_total' => 12500,
            'metadata' => ['invoice_id' => $invoice->id],
        ]]])->assertOk();

        // Byte for byte as Stripe sent it, twice.
        foreach ([1, 2] as $_) {
            $time = time();
            $this->call('POST', '/api/v1/payments/stripe/webhook', [], [], [], [
                'HTTP_STRIPE_SIGNATURE' => 't='.$time.',v1='.hash_hmac('sha256', $time.'.'.$raw, self::SECRET), 'CONTENT_TYPE' => 'application/json',
            ], $raw)->assertOk();
        }

        $refund = $this->payments($t)->where('kind', 'refund')->sole();
        $this->assertSame(2500, $refund->amount_cents);
        $this->assertSame('sent', $this->inTenant($t['org'], fn () => $invoice->fresh()->status));
        $this->assertSame(2500, $this->inTenant($t['org'], fn () => $invoice->fresh('payments')->dueCents()));
    }

    public function test_connecting_creates_one_standard_account_per_company_and_needs_the_approve_tick(): void
    {
        $t = $this->provisionTenant('Alpha');
        $as = $this->signedInAs($t['admin']);

        $first = $as->postJson('/api/v1/invoices/payments-account/onboarding', ['company_id' => $t['company']->id])->assertOk()->json('data');
        $as->postJson('/api/v1/invoices/payments-account/onboarding', ['company_id' => $t['company']->id])->assertOk();
        $this->assertSame('https://connect.stripe.test/setup/acct_fake1', $first['url']);
        $this->assertCount(1, $this->stripe->accountsCreated);

        $this->stripe->flags = ['charges_enabled' => true, 'payouts_enabled' => true, 'details_submitted' => true];
        $as->getJson('/api/v1/invoices/payments-account?company_id='.$t['company']->id)->assertOk()
            ->assertJsonPath('data.stripe_account_id', 'acct_fake1')->assertJsonPath('data.charges_enabled', true);

        $tech = $this->userWithRole($t, 'technician');
        $this->signedInAs($tech)->postJson('/api/v1/invoices/payments-account/onboarding', ['company_id' => $t['company']->id])->assertForbidden();
    }
}
