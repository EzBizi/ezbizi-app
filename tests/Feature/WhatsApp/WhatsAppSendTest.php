<?php

namespace Tests\Feature\WhatsApp;

use App\Models\WhatsAppMessage;
use App\Support\Social\WhatsAppCloud;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Sending, and what the row says when it does not work.
 *
 * The row is written BEFORE the call and the provider's id written back after.
 * Everything here is about the states between those two moments, because those
 * are the ones that are invisible if the row is only written on success.
 */
class WhatsAppSendTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private const PHONE_ID = '1256610664203381';

    private function queued(array $t): WhatsAppMessage
    {
        config([
            'services.meta.whatsapp_token' => 'system-user-token',
            'services.meta.whatsapp_version' => 'v21.0',
        ]);

        return $this->inTenant($t['org'], fn () => WhatsAppMessage::create([
            'organization_id' => $t['org']->id,
            'phone_number_id' => self::PHONE_ID,
            'direction' => WhatsAppMessage::DIRECTION_OUT,
            'counterparty' => '13057242199',
            'status' => WhatsAppMessage::STATUS_QUEUED,
            'kind' => 'text',
            'body' => 'Your technician is on the way.',
        ]));
    }

    public function test_a_sent_message_records_metas_id(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $row = $this->queued($t);

        Http::fake(['graph.facebook.com/*' => Http::response([
            'messaging_product' => 'whatsapp',
            'messages' => [['id' => 'wamid.HBgLMTMwNTcyNDIxOTk']],
        ], 200)]);

        $this->inTenant($t['org'], fn () => (new WhatsAppCloud)->sendText($row, 'Your technician is on the way.'));

        $fresh = $this->inTenant($t['org'], fn () => $row->fresh());

        // Without the id, no later status can ever find this row - every
        // delivery and read receipt Meta sends is keyed by it and nothing else.
        $this->assertSame('wamid.HBgLMTMwNTcyNDIxOTk', $fresh->wamid);
        $this->assertSame(WhatsAppMessage::STATUS_SENT, $fresh->status);
        $this->assertNotNull($fresh->sent_at);
    }

    public function test_it_posts_to_the_pinned_version_and_the_right_number(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $row = $this->queued($t);

        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'w1']]], 200)]);

        $this->inTenant($t['org'], fn () => (new WhatsAppCloud)->sendText($row, 'hello'));

        Http::assertSent(function ($request) {
            // The version is pinned rather than 'latest' because Meta retires
            // versions and a retired one answers errors to everything.
            $this->assertStringContainsString('/v21.0/'.self::PHONE_ID.'/messages', $request->url());

            $body = $request->data();
            $this->assertSame('whatsapp', $body['messaging_product']);
            $this->assertSame('13057242199', $body['to']);
            $this->assertSame('text', $body['type']);
            $this->assertSame('hello', $body['text']['body']);

            return true;
        });
    }

    public function test_a_template_carries_its_parameters_in_order(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $row = $this->queued($t);

        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'w2']]], 200)]);

        $this->inTenant($t['org'], fn () => (new WhatsAppCloud)->sendTemplate(
            $row,
            'appointment_reminder',
            'en_US',
            ['Thursday', '9am'],
        ));

        Http::assertSent(function ($request) {
            $body = $request->data();

            $this->assertSame('template', $body['type']);
            $this->assertSame('appointment_reminder', $body['template']['name']);
            $this->assertSame('en_US', $body['template']['language']['code']);

            // Order is the whole contract: Meta fills {{1}} and {{2}} by
            // POSITION, so a reordered array silently tells the customer the
            // wrong day.
            $this->assertSame(
                ['Thursday', '9am'],
                array_column($body['template']['components'][0]['parameters'], 'text'),
            );

            return true;
        });
    }

    public function test_a_template_with_no_parameters_sends_no_components(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $row = $this->queued($t);

        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'w3']]], 200)]);

        $this->inTenant($t['org'], fn () => (new WhatsAppCloud)->sendTemplate($row, 'hello_world'));

        Http::assertSent(function ($request) {
            // An empty `components` array is not the same as none: Meta rejects
            // a body component with no parameters for a template that has no
            // variables.
            $this->assertArrayNotHasKey('components', $request->data()['template']);

            return true;
        });
    }

    public function test_metas_refusal_is_kept_in_the_row_in_its_own_words(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $row = $this->queued($t);

        Http::fake(['graph.facebook.com/*' => Http::response([
            'error' => [
                'message' => '(#132001) Template name does not exist in the translation',
                'error_user_msg' => 'The template does not exist in en_US.',
            ],
        ], 400)]);

        try {
            $this->inTenant($t['org'], fn () => (new WhatsAppCloud)->sendTemplate($row, 'no_such_template'));
            $this->fail('a refused send must throw');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('template does not exist', $e->getMessage());
        }

        $fresh = $this->inTenant($t['org'], fn () => $row->fresh());

        $this->assertSame(WhatsAppMessage::STATUS_FAILED, $fresh->status);
        $this->assertStringContainsString(
            'template does not exist',
            (string) $fresh->status_reason,
            "Meta's own sentence, not a code: an unapproved template and a number that is not "
            .'on WhatsApp are different problems and both arrive as a failure',
        );
    }

    public function test_a_missing_token_does_not_blame_the_message(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $row = $this->queued($t);

        config(['services.meta.whatsapp_token' => '']);
        Http::fake();

        try {
            $this->inTenant($t['org'], fn () => (new WhatsAppCloud)->sendText($row, 'hello'));
            $this->fail('an unconfigured token must throw');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('configuration fault', $e->getMessage());
        }

        $fresh = $this->inTenant($t['org'], fn () => $row->fresh());

        // The point of the test. A token EzBizi never configured says nothing
        // about the customer or the message, and marking it FAILED sends
        // somebody to check the recipient's number.
        $this->assertSame(
            WhatsAppMessage::STATUS_QUEUED,
            $fresh->status,
            'our own misconfiguration must not be recorded as the message failing',
        );
        $this->assertNull($fresh->status_reason);

        Http::assertNothingSent();
    }
}
