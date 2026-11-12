<?php

namespace Tests\Feature\Api;

use App\Models\Mailbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * "Can send" on the inbox card, checked without sending (Jose 11-12).
 */
class MailboxSendCheckTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.google_mail.client_id'     => 'a-client-id.apps.googleusercontent.com',
            'services.google_mail.client_secret' => 'a-client-secret',
        ]);
    }

    private function inbox(array $t, string $address): Mailbox
    {
        return $this->inTenant($t['org'], function () use ($t, $address) {
            $mailbox = Mailbox::create([
                'organization_id' => $t['org']->id,
                'scope_type'      => 'organization',
                'scope_id'        => $t['org']->id,
                'address'         => $address,
                'display_name'    => 'Info',
                'provider'        => 'gmail_oauth',
            ]);
            $mailbox->connect('a-refresh-token');
            $mailbox->save();

            return $mailbox;
        });
    }

    public function test_a_token_allowed_to_send_passes_and_one_that_is_not_says_why(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $can = $this->inbox($t, 'info@a-1chimney.com');
        $cannot = $this->inbox($t, 'office@a-1chimney.com');

        $tokens = 0;
        Http::fake([
            'oauth2.googleapis.com/token' => function () use (&$tokens) {
                $tokens++;

                return Http::response(['access_token' => 'tok-'.$tokens, 'expires_in' => 3600]);
            },
            'oauth2.googleapis.com/tokeninfo*' => fn ($request) => Http::response([
                'scope' => str_contains($request->url(), 'tok-1')
                    ? 'https://www.googleapis.com/auth/gmail.readonly https://www.googleapis.com/auth/gmail.send'
                    : 'https://www.googleapis.com/auth/gmail.readonly',
            ]),
            // The receive check reads the inbox; nothing is ever sent.
            'gmail.googleapis.com/gmail/v1/users/me/messages/send*' => fn () => throw new \LogicException('The check sent mail.'),
            'gmail.googleapis.com/*' => Http::response(['threads' => [], 'resultSizeEstimate' => 0]),
        ]);

        $this->artisan('ezbizi:check-mailboxes')->assertSuccessful();

        $rows = $this->inTenant($t['org'], fn () => Mailbox::query()->get()->keyBy('address'));

        $first = $rows[$can->address]->send_problem === null ? $rows[$can->address] : $rows[$cannot->address];
        $other = $first->is($rows[$can->address]) ? $rows[$cannot->address] : $rows[$can->address];
        $this->assertNull($first->send_problem);
        $this->assertStringContainsString('without permission to send', (string) $other->send_problem);
        $this->assertNotNull($other->send_checked_at);
        $this->assertNotNull($other->last_reached_at, 'The receive check did not read the inbox.');

        // Checked once a day, not every minute; a reconnect makes it due again.
        $this->artisan('ezbizi:check-mailboxes')->assertSuccessful();
        $this->assertSame(2, $tokens);

        $this->inTenant($t['org'], function () use ($other) {
            $other->connect('new-token');
            $other->save();
        });
        $this->assertNull($this->inTenant($t['org'], fn () => $other->fresh()->send_checked_at));
    }

    public function test_the_card_carries_the_send_check(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $mailbox = $this->inbox($t, 'info@a-1chimney.com');
        $this->inTenant($t['org'], fn () => $mailbox->forceFill(['send_checked_at' => now(), 'send_problem' => 'No sending.'])->save());

        $row = collect($this->signedInAs($t['admin'])->getJson('/api/v1/email/mailboxes')->assertOk()->json('data'))
            ->firstWhere('address', 'info@a-1chimney.com');

        $this->assertSame('No sending.', $row['send_problem']);
        $this->assertNotNull($row['send_checked_at']);
    }
}
