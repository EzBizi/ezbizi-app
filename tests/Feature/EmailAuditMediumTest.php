<?php

namespace Tests\Feature;

use App\Models\Mailbox;
use App\Support\Email\ImapFailure;
use App\Support\Email\MicrosoftMailboxReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** The Email medium findings of the 11-27 audit, each pinned. */
class EmailAuditMediumTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private const ENDPOINT = '/api/v1/email/mailboxes';

    /** A changed mail server must not inherit the password the old one was given. */
    public function test_changing_the_mail_server_forgets_the_password(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $mailbox = $this->inTenant($t['org'], fn () => Mailbox::create([
            'organization_id' => $t['org']->id, 'scope_type' => 'organization', 'scope_id' => $t['org']->id,
            'address' => 'info@a-1chimney.com', 'display_name' => 'Info', 'provider' => 'imap',
            'mail_server' => ['imap_host' => '93.184.216.34', 'imap_port' => 993],
        ]));
        $this->signedInAs($t['admin'])->postJson(self::ENDPOINT."/{$mailbox->id}/connect", ['credential' => 'a-secret'])->assertOk();

        // A name change keeps it.
        $this->signedInAs($t['admin'])->patchJson(self::ENDPOINT."/{$mailbox->id}", ['display_name' => 'Main'])->assertOk();
        $this->assertTrue($this->inTenant($t['org'], fn () => Mailbox::findOrFail($mailbox->id)->hasCredential()));

        // A new host does not.
        $this->signedInAs($t['admin'])->patchJson(self::ENDPOINT."/{$mailbox->id}", [
            'mail_server' => ['imap_host' => '93.184.216.35', 'imap_port' => 993],
        ])->assertOk();
        $after = $this->inTenant($t['org'], fn () => Mailbox::findOrFail($mailbox->id));
        $this->assertFalse($after->hasCredential());
        $this->assertSame(Mailbox::STATUS_UNCONFIGURED, $after->status);
    }

    /** Page 2 of a delta is Graph's nextLink as given, not its token sent back as a deltatoken. */
    public function test_a_second_delta_page_follows_the_next_link(): void
    {
        config(['services.microsoft_mail.client_id' => 'id', 'services.microsoft_mail.client_secret' => 'secret']);
        $t = $this->provisionTenant('A1-Group');
        $mailbox = $this->inTenant($t['org'], function () use ($t) {
            $m = Mailbox::create([
                'organization_id' => $t['org']->id, 'scope_type' => 'organization', 'scope_id' => $t['org']->id,
                'address' => 'info@a-1chimney.com', 'display_name' => 'Info', 'provider' => 'microsoft_oauth',
            ]);
            $m->connect('refresh');
            $m->save();

            return $m;
        });

        $next = 'https://graph.microsoft.com/v1.0/me/mailFolders/inbox/messages/delta?$skiptoken=PAGE2';
        Http::fake([
            'login.microsoftonline.com/*' => Http::response(['access_token' => 'at', 'expires_in' => 3600]),
            'graph.microsoft.com/*' => function ($request) use ($next) {
                return str_contains($request->url(), 'skiptoken=PAGE2')
                    ? Http::response(['value' => [], '@odata.deltaLink' => 'https://graph.microsoft.com/v1.0/x/delta?$deltatoken=FRESH'])
                    : Http::response(['value' => [], '@odata.nextLink' => $next]);
            },
        ]);

        $arrivals = $this->inTenant($t['org'], fn () => app(MicrosoftMailboxReader::class)->arrivalsSince($mailbox, 'MARK', 50));

        $this->assertSame('FRESH', $arrivals->mark);
        Http::assertNotSent(fn ($request) => str_contains(urldecode($request->url()), '$deltatoken=$skiptoken'));
    }

    /** A template picture at an internal address is never fetched by the server. */
    public function test_a_signature_picture_at_an_internal_address_is_not_requested(): void
    {
        Http::fake();
        $html = app(\App\Support\Email\WorkspaceSignatures::class)->render(
            'P{{^photo_url}}<img src="http://169.254.169.254/latest/meta-data/{{email}}">{{/photo_url}}',
            ['address' => 'ana@example.com', 'name' => 'Ana'],
            null,
            'Co',
        );

        $this->assertSame('P', $html);
        Http::assertNothingSent();
    }

    /** A connection that did not open is a blip, not a bad host. */
    public function test_a_connection_that_did_not_open_is_not_answering(): void
    {
        $this->assertSame(ImapFailure::TIMEOUT, ImapFailure::notAnswering('Could not open a connection')->kind);
    }
}
