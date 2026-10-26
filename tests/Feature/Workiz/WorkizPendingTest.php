<?php

namespace Tests\Feature\Workiz;

use App\Models\SocialConnection;
use App\Support\Social\SocialProvider;
use App\Support\Workiz\WorkizWebSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Settings › Pending (desk, 2026-10-26): a branch with no Workiz, and a Workiz account no branch matched. */
class WorkizPendingTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private function keysFor(string $placeName): array
    {
        $group = collect($this->getJson('/api/v1/settings/pending')->assertOk()->json('data'))->firstWhere('place.name', $placeName);

        return collect($group['items'] ?? [])->pluck('key')->all();
    }

    private function connection(array $t, string $provider, string $scope, string $id, array $credentials): void
    {
        $this->inTenant($t['org'], function () use ($t, $provider, $scope, $id, $credentials) {
            $c = new SocialConnection;
            $c->forceFill(['organization_id' => $t['org']->id, 'scope_type' => $scope, 'scope_id' => $id,
                'provider' => $provider, 'remote_id' => $id, 'display_name' => 'Workiz']);
            $c->connect($credentials);
            $c->save();
        });
    }

    public function test_a_branch_without_workiz_is_a_gap_only_where_the_organization_uses_workiz(): void
    {
        $t = $this->provisionTenant('Alpha');
        $other = $this->inTenant($t['org'], fn () => $this->extraFranchise($t, 'Second'));
        $this->signedInAs($t['admin']);

        $this->assertNotContains('workiz.branch_connection', $this->keysFor('Second'), 'no Workiz anywhere: nothing asked');

        $this->connection($t, SocialProvider::Workiz->value, SocialConnection::SCOPE_FRANCHISE, $t['franchise']->id, ['api_token' => 'k']);

        $this->assertContains('workiz.branch_connection', $this->keysFor('Second'));
        $this->assertNotContains('workiz.branch_connection', $this->keysFor($t['franchise']->name));
        $this->assertNotNull($other);
    }

    public function test_a_workiz_account_no_branch_matched_is_a_company_gap(): void
    {
        $t = $this->provisionTenant('Alpha');
        $this->signedInAs($t['admin']);
        $this->connection($t, SocialProvider::WorkizWeb->value, SocialConnection::SCOPE_COMPANY, $t['company']->id,
            ['reads' => ['555' => ['error' => WorkizWebSession::UNMATCHED], 'main' => ['payments_at' => now()->toIso8601String()]]]);

        $this->assertContains('workiz.web_account_branch', $this->keysFor('Alpha Co'));
    }
}
