<?php

namespace App\Support\Payments;

use RuntimeException;
use Stripe\StripeClient;
use Stripe\Webhook;
use Throwable;

/**
 * Every Stripe call EzBizi makes, and nothing else.
 *
 * Resolved from the container so tests swap a fake for the network calls.
 * Webhook verification is NOT faked: it is pure HMAC, and a test that signs
 * with the configured secret proves the real check.
 *
 * Returns plain arrays so nothing above this class holds an SDK object.
 */
class StripeGateway
{
    private ?StripeClient $client = null;

    public function configured(): bool
    {
        return (string) config('services.stripe.secret') !== '';
    }

    /**
     * A new connected account for one company; returns its `acct_` id.
     *
     * Accounts v2, because the platform refuses v1 `POST /v1/accounts`
     * outright (measured in the sandbox 2026-10-13: "We recommend building
     * your integration using Accounts v2"). Everything after creation (links,
     * status, Checkout on the account) still answers on v1 for a v2 account.
     *
     * `full` is Standard (Jose, 2026-11-06: "Stripe (Standard, $0)"): Stripe
     * collects fees and carries losses, the business gets the full Dashboard.
     * `express` makes EzBizi the losses collector, which Stripe refuses until
     * the platform accepts that liability in its Connect settings.
     */
    public function createConnectedAccount(array $metadata, ?string $email, string $displayName): string
    {
        $express = config('services.stripe.connect_dashboard', 'full') === 'express';

        return $this->call(fn () => $this->client()->v2->core->accounts->create(array_filter([
            'contact_email' => $email,
            'display_name' => $displayName,
            'dashboard' => $express ? 'express' : 'full',
            'identity' => ['country' => 'us'],
            'defaults' => ['responsibilities' => $express
                ? ['fees_collector' => 'application', 'losses_collector' => 'application']
                : ['fees_collector' => 'stripe', 'losses_collector' => 'stripe']],
            'configuration' => ['merchant' => ['capabilities' => [
                'card_payments' => ['requested' => true],
                'ach_debit_payments' => ['requested' => true],
            ]]],
            'metadata' => $metadata,
        ]))->id);
    }

    /** A one-time onboarding (or resume) link Stripe hosts. */
    public function accountLink(string $accountId, string $refreshUrl, string $returnUrl): string
    {
        return $this->call(fn () => $this->client()->accountLinks->create([
            'account' => $accountId,
            'refresh_url' => $refreshUrl,
            'return_url' => $returnUrl,
            'type' => 'account_onboarding',
        ])->url);
    }

    /** @return array{charges_enabled: bool, payouts_enabled: bool, details_submitted: bool} */
    public function retrieveAccount(string $accountId): array
    {
        return $this->call(function () use ($accountId) {
            $account = $this->client()->accounts->retrieve($accountId);

            return [
                'charges_enabled' => (bool) $account->charges_enabled,
                'payouts_enabled' => (bool) $account->payouts_enabled,
                'details_submitted' => (bool) $account->details_submitted,
            ];
        });
    }

    /**
     * A Checkout Session as a DIRECT charge on the connected account: the
     * business is the merchant of record, EzBizi takes `application_fee_amount`.
     *
     * @return array{id: string, url: string}
     */
    public function createCheckoutSession(string $accountId, array $params, string $idempotencyKey): array
    {
        return $this->call(function () use ($accountId, $params, $idempotencyKey) {
            $session = $this->client()->checkout->sessions->create($params, [
                'stripe_account' => $accountId,
                'idempotency_key' => $idempotencyKey,
            ]);

            return ['id' => (string) $session->id, 'url' => (string) $session->url];
        });
    }

    /**
     * What a paid PaymentIntent cost and how it was paid, for the
     * invoice_payments row. `fee_cents` is Stripe's processing fee plus any
     * application fee, from the charge's balance transaction (0 until one
     * exists).
     *
     * @return array{method: string, fee_cents: int}
     */
    public function paymentDetails(string $accountId, string $paymentIntentId): array
    {
        return $this->call(function () use ($accountId, $paymentIntentId) {
            $intent = $this->client()->paymentIntents->retrieve($paymentIntentId,
                ['expand' => ['latest_charge.balance_transaction']], ['stripe_account' => $accountId]);
            $charge = $intent->latest_charge;

            return [
                'method' => ($charge->payment_method_details->type ?? 'card') === 'us_bank_account' ? 'transfer' : 'card',
                'fee_cents' => (int) ($charge->balance_transaction->fee ?? 0),
            ];
        });
    }

    /**
     * Verify a webhook against every configured secret (platform and Connect
     * endpoints are signed separately) and return the event as an array.
     *
     * @throws RuntimeException when no secret signs it
     */
    public function constructEvent(string $payload, string $signature): array
    {
        $secrets = array_filter([
            (string) config('services.stripe.connect_webhook_secret'),
            (string) config('services.stripe.webhook_secret'),
        ]);

        foreach ($secrets as $secret) {
            try {
                return Webhook::constructEvent($payload, $signature, $secret)->toArray();
            } catch (Throwable) {
                continue;
            }
        }

        throw new RuntimeException('The Stripe signature did not verify.');
    }

    private function client(): StripeClient
    {
        if (! $this->configured()) {
            throw new RuntimeException('Card payments are not set up on this server yet.');
        }

        return $this->client ??= new StripeClient((string) config('services.stripe.secret'));
    }

    /** Stripe's own message, never the key, and never an SDK exception type upstream. */
    private function call(callable $fn): mixed
    {
        try {
            return $fn();
        } catch (RuntimeException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new RuntimeException('Stripe refused: '.$e->getMessage(), previous: $e);
        }
    }
}
