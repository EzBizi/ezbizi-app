<?php

namespace Tests\Feature\Payments;

use App\Support\Payments\StripeGateway;

/**
 * The network half of StripeGateway, recorded instead of sent. Webhook
 * verification is inherited unchanged: tests sign with the configured secret.
 */
class FakeStripeGateway extends StripeGateway
{
    public array $accountsCreated = [];

    public array $sessions = [];

    public array $flags = ['charges_enabled' => false, 'payouts_enabled' => false, 'details_submitted' => false];

    public array $details = ['method' => 'card', 'fee_cents' => 393];

    public function configured(): bool
    {
        return true;
    }

    public function createConnectedAccount(array $metadata, ?string $email, string $displayName): string
    {
        $this->accountsCreated[] = $metadata;

        return 'acct_fake'.count($this->accountsCreated);
    }

    public function accountLink(string $accountId, string $refreshUrl, string $returnUrl): string
    {
        return "https://connect.stripe.test/setup/{$accountId}";
    }

    public function retrieveAccount(string $accountId): array
    {
        return $this->flags;
    }

    public function createCheckoutSession(string $accountId, array $params, string $idempotencyKey): array
    {
        $id = 'cs_test_'.(count($this->sessions) + 1);
        $this->sessions[] = compact('accountId', 'params', 'idempotencyKey');

        return ['id' => $id, 'url' => "https://checkout.stripe.test/{$id}"];
    }

    public function paymentDetails(string $accountId, string $paymentIntentId): array
    {
        return $this->details;
    }
}
