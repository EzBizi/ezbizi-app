<?php

namespace Tests\Feature\Api;

use App\Services\Organizations\ProvisionOrganization;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class FactorThrottleIsolationTest extends TestCase
{
    use RefreshDatabase;

    public static function resetPaths(): array
    {
        return ['ambiguous login' => [false], 'unprotected login' => [true]];
    }

    #[DataProvider('resetPaths')]
    public function test_other_account_cannot_reset_factor_budget(bool $completeUnprotected): void
    {
        $credentials = ['email' => 'shared@example.test', 'password' => 'same-password'];
        $protected = app(ProvisionOrganization::class)(organizationName: 'Protected',
            administrator: ['name' => 'Admin', ...$credentials], companyName: 'Co', franchiseName: 'F');
        $unprotected = app(ProvisionOrganization::class)(organizationName: 'Unprotected',
            administrator: ['name' => 'Admin', ...$credentials], companyName: 'Co', franchiseName: 'F');
        app(TenantContext::class)->run($protected['organization'], function () use ($protected) {
            $protected['administrator']->forceFill([
                'totp_secret' => app(Google2FA::class)->generateSecretKey(32),
                'totp_confirmed_at' => now(),
            ])->save();
        });
        $attempt = [...$credentials, 'user_id' => $protected['administrator']->id, 'code' => 'not-a-recovery-code'];
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/choose', $attempt)->assertUnauthorized();
            // Both paths currently clear the same email/IP bucket.
            $this->postJson($completeUnprotected ? '/api/v1/auth/choose' : '/api/v1/auth/login', [...$credentials,
                ...($completeUnprotected ? ['user_id' => $unprotected['administrator']->id] : []),
            ])->assertOk();
        }
        $this->postJson('/api/v1/auth/choose', $attempt)->assertStatus(429);
    }
}
