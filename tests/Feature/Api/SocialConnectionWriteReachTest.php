<?php

namespace Tests\Feature\Api;

use App\Models\SocialConnection;
use App\Support\Telegram\TelegramApi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsTenants;
use Tests\Concerns\GivesAuthenticatorCodes;
use Tests\TestCase;

/**
 * Audit 2026-11-27: a branch user SEES org-level connections (inView), which
 * must not let them delete or re-file them. Each refusal has its control: the
 * same request from someone who reaches the row succeeds.
 */
class SocialConnectionWriteReachTest extends TestCase
{
    use BuildsTenants;
    use GivesAuthenticatorCodes;
    use RefreshDatabase;

    private function connection(array $t, string $scopeType, string $scopeId, string $remoteId): SocialConnection
    {
        return $this->inTenant($t['org'], function () use ($t, $scopeType, $scopeId, $remoteId) {
            $row = new SocialConnection;
            $row->forceFill([
                'organization_id' => $t['org']->id,
                'scope_type' => $scopeType,
                'scope_id' => $scopeId,
                'provider' => 'pinterest',
                'remote_id' => $remoteId,
                'display_name' => 'Pins '.$remoteId,
                'status' => SocialConnection::STATUS_CONNECTED,
            ])->save();

            return $row;
        });
    }

    private function branchUser(array $t)
    {
        return $this->userWithRole($t, 'Branch', ['marketing.view', 'marketing.edit'], onlyAt: $t['franchise']);
    }

    public function test_a_branch_user_cannot_delete_an_org_level_connection_but_an_admin_can(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $org = $this->connection($t, 'organization', $t['org']->id, 'org-1');
        $branch = $this->branchUser($t);

        $this->signedInAs($branch)
            ->deleteJson('/api/v1/social/connections/'.$org->getKey(), $this->authenticatorCode($branch))
            ->assertForbidden();
        $this->assertTrue($this->inTenant($t['org'], fn () => SocialConnection::query()->whereKey($org->getKey())->exists()));

        $this->signedInAs($t['admin'])
            ->deleteJson('/api/v1/social/connections/'.$org->getKey(), $this->authenticatorCode($t['admin']))
            ->assertOk();
    }

    public function test_family_disconnect_by_a_branch_user_leaves_org_level_rows(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $org = $this->connection($t, 'organization', $t['org']->id, 'org-1');
        $own = $this->connection($t, 'franchise', $t['franchise']->id, 'own-1');
        $branch = $this->branchUser($t);

        $this->signedInAs($branch)
            ->deleteJson('/api/v1/social/providers/pinterest/connections', $this->authenticatorCode($branch))
            ->assertOk();

        $left = $this->inTenant($t['org'], fn () => SocialConnection::query()->pluck('remote_id')->all());
        $this->assertSame(['org-1'], $left, 'the branch row goes, the org row stays');
    }

    public function test_a_branch_user_cannot_refile_an_org_connections_leads_nor_send_their_own_to_a_sibling(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $sibling = $this->extraFranchise($t, 'Illinois');
        $org = $this->connection($t, 'organization', $t['org']->id, 'org-1');
        $own = $this->connection($t, 'franchise', $t['franchise']->id, 'own-1');
        $branch = $this->branchUser($t);

        $this->signedInAs($branch)
            ->patchJson('/api/v1/social/connections/'.$org->getKey().'/leads-franchise', ['leads_franchise_id' => $t['franchise']->id])
            ->assertForbidden();

        $this->signedInAs($branch)
            ->patchJson('/api/v1/social/connections/'.$own->getKey().'/leads-franchise', ['leads_franchise_id' => $sibling->id])
            ->assertForbidden();

        // Control: their own branch is allowed.
        $this->signedInAs($branch)
            ->patchJson('/api/v1/social/connections/'.$own->getKey().'/leads-franchise', ['leads_franchise_id' => $t['franchise']->id])
            ->assertOk();
    }

    public function test_a_telegram_connection_error_does_not_carry_the_bot_token(): void
    {
        $token = '123456:SECRET-bot-token';
        Http::fake(fn () => throw new ConnectionException('cURL error 28 for https://api.telegram.org/bot'.$token.'/getMe'));

        try {
            TelegramApi::withToken($token)->call('getMe');
            $this->fail('expected a refusal');
        } catch (\RuntimeException $e) {
            $this->assertStringNotContainsString($token, $e->getMessage());
            $this->assertNull($e->getPrevious());
        }
    }
}
