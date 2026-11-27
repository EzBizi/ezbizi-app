<?php

namespace Tests\Feature\Access;

use App\Models\Mailbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Authz sweep, 2026-11-17: refusing a sibling branch's inbox named its address
 * to anyone holding its id. Out of reach is now absent: 404, no address.
 */
class MailboxOutOfReachIsAbsentTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_a_sibling_branch_inbox_is_absent_and_unnamed(): void
    {
        $t = $this->provisionTenant('Alpha');
        $sibling = $this->extraFranchise($t, 'Alpha F2');
        $branch = $this->userWithRole($t, 'Branch',
            ['email.view', 'email.edit', 'email.settings.view', 'email.inbox.view'],
            'branch@alpha.test', onlyAt: $t['franchise']);

        $mailbox = $this->inTenant($t['org'], fn () => Mailbox::create([
            'organization_id' => $t['org']->id,
            'scope_type' => Mailbox::SCOPE_FRANCHISE,
            'scope_id' => $sibling->id,
            'address' => 'secret-branch@alpha.test',
            'display_name' => 'Sibling',
            'provider' => 'imap',
        ]));

        foreach ([
            ['GET', "/api/v1/email/mailboxes/{$mailbox->id}/messages"],
            ['PATCH', "/api/v1/email/mailboxes/{$mailbox->id}"],
            ['GET', "/api/v1/email/mailboxes/{$mailbox->id}/people"],
        ] as [$method, $uri]) {
            $response = $this->signedInAs($branch)->json($method, $uri, []);
            $response->assertNotFound();
            $this->assertStringNotContainsString('secret-branch@alpha.test', $response->getContent(), "$method $uri");
        }
    }
}
