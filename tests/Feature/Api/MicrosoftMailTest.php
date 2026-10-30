<?php

namespace Tests\Feature\Api;

use App\Models\Mailbox;
use App\Support\Email\MailboxProvider;
use App\Support\Email\MicrosoftMailboxReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

class MicrosoftMailTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_the_catalogue_publishes_microsoft_365(): void
    {
        $this->assertContains(MailboxProvider::MicrosoftOauth, MailboxProvider::all());
        $this->assertSame('sign_in', MailboxProvider::MicrosoftOauth->connectsBy());
    }

    public function test_the_reader_refuses_until_azure_is_configured(): void
    {
        $reader = app(MicrosoftMailboxReader::class);

        $this->assertNotNull($reader->notReadyBecause());
        $this->assertStringContainsString('EzBizi', $reader->notReadyBecause());
    }

    public function test_start_refuses_until_azure_is_configured(): void
    {
        $t = $this->provisionTenant('A1-Group');

        $mailbox = $this->inTenant($t['org'], function () use ($t) {
            return Mailbox::create([
                'organization_id' => $t['org']->id,
                'scope_type' => 'organization',
                'scope_id' => $t['org']->id,
                'address' => 'info@a-1chimney.com',
                'display_name' => 'Info',
                'provider' => MailboxProvider::MicrosoftOauth->value,
            ]);
        });

        $this->signedInAs($t['admin'])
            ->postJson("/api/v1/email/mailboxes/{$mailbox->getKey()}/oauth/start")
            ->assertStatus(501)
            ->assertJsonPath('message', fn ($m) => is_string($m) && str_contains($m, 'EzBizi'));
    }

    public function test_the_callback_without_state_refuses_in_a_sentence(): void
    {
        $html = $this->get('/api/v1/email/microsoft/callback')
            ->assertStatus(400)
            ->getContent();

        $this->assertStringContainsString('no longer valid', $html);
        $this->assertStringNotContainsString('Exception', $html);
    }
}
