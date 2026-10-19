<?php

namespace Tests\Feature\WhatsApp;

use App\Models\WhatsAppMessage;
use App\Support\Social\WhatsAppCloud;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Meta calling us: the handshake, the signature, and what arrives.
 *
 * Every failure guarded here is SILENT in production. A rejected webhook looks
 * exactly like Meta not sending one; a failed handshake looks like the dashboard
 * refusing to save a URL for no stated reason. None of it appears in a log
 * somebody is watching, which is why each one is pinned rather than left to be
 * noticed.
 */
class WhatsAppWebhookTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private const PHONE_ID = '1256610664203381';

    private const SECRET = 'app-secret-from-meta';

    private function configured(): void
    {
        config([
            'services.meta.client_secret' => self::SECRET,
            'services.meta.whatsapp_verify_token' => 'a-token-we-invented',
        ]);
    }

    private function numberBelongsTo(array $t): void
    {
        // DB::table on purpose: this row is looked up BEFORE any tenant is in
        // context, which is the thing it exists to decide.
        DB::table('whatsapp_numbers')->insert([
            'id' => (string) Str::uuid(),
            'organization_id' => $t['org']->id,
            'phone_number_id' => self::PHONE_ID,
            'waba_id' => '2505196013319752',
            'display_number' => '+15552044911',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** Sign a body the way Meta does, over the exact bytes. */
    private function deliverSigned(string $json, ?string $signature = null)
    {
        return $this->call(
            'POST',
            '/api/v1/social/whatsapp/webhook',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_HUB_SIGNATURE_256' => $signature
                    ?? 'sha256='.hash_hmac('sha256', $json, self::SECRET),
            ],
            $json,
        );
    }

    public function test_the_handshake_echoes_the_challenge_as_plain_text(): void
    {
        $this->configured();

        $response = $this->get('/api/v1/social/whatsapp/webhook?'
            .'hub.mode=subscribe&hub.verify_token=a-token-we-invented&hub.challenge=1158201444');

        $response->assertOk();

        // The body must be the challenge and NOTHING else. A JSON envelope, a
        // trailing newline or a 201 all read as failure to Meta and the
        // callback URL simply cannot be saved.
        $this->assertSame('1158201444', $response->getContent());
        $this->assertStringStartsWith('text/plain', (string) $response->headers->get('Content-Type'));
    }

    public function test_a_wrong_verify_token_is_refused(): void
    {
        $this->configured();

        $this->get('/api/v1/social/whatsapp/webhook?'
            .'hub.mode=subscribe&hub.verify_token=not-ours&hub.challenge=1158201444')
            ->assertForbidden();
    }

    public function test_an_unconfigured_deployment_verifies_nobody(): void
    {
        config(['services.meta.whatsapp_verify_token' => '']);

        // The case that matters: without this guard an install that has not set
        // the token accepts an EMPTY one, and anybody can point their own Meta
        // app's webhook at this deployment.
        $this->get('/api/v1/social/whatsapp/webhook?'
            .'hub.mode=subscribe&hub.verify_token=&hub.challenge=1158201444')
            ->assertForbidden();
    }

    public function test_an_unsigned_delivery_is_refused(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $this->configured();
        $this->numberBelongsTo($t);

        $json = json_encode(['entry' => [['changes' => [['value' => [
            'metadata' => ['phone_number_id' => self::PHONE_ID],
            'messages' => [['id' => 'wamid.FORGED', 'from' => '13057242199', 'type' => 'text',
                'text' => ['body' => 'let me in']]],
        ]]]]]]);

        $this->deliverSigned($json, 'sha256=deadbeef')->assertUnauthorized();

        $this->assertSame(0, WhatsAppMessage::query()->withoutGlobalScopes()->count());
    }

    public function test_a_signature_over_a_reencoded_body_is_refused(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $this->configured();
        $this->numberBelongsTo($t);

        // The mistake this is really about. Signing `json_encode(decoded)`
        // instead of the raw bytes gives a different digest for the SAME
        // message - different key order, different escaping - and every real
        // delivery is then rejected as forged. Here the raw body has spacing
        // that a re-encode would not reproduce.
        $raw = '{ "entry" : [ ] }';
        $reencoded = json_encode(json_decode($raw, true));

        $this->assertNotSame($raw, $reencoded, 'the fixture must actually differ, or this proves nothing');

        $this->deliverSigned($raw, 'sha256='.hash_hmac('sha256', (string) $reencoded, self::SECRET))
            ->assertUnauthorized();

        // Positive control: the same body, signed over the raw bytes, is fine.
        $this->deliverSigned($raw)->assertOk();
    }

    public function test_an_inbound_message_is_recorded_against_the_right_tenant(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $other = $this->provisionTenant('Premier-Garage');
        $this->configured();
        $this->numberBelongsTo($t);

        $json = json_encode(['entry' => [['changes' => [['value' => [
            'metadata' => ['phone_number_id' => self::PHONE_ID],
            'messages' => [[
                'id' => 'wamid.HBgLMTMwNTcyNDIxOTkVAgASGBQz',
                'from' => '13057242199',
                'type' => 'text',
                'text' => ['body' => 'Can you come out Thursday?'],
            ]],
        ]]]]]]);

        $this->deliverSigned($json)->assertOk()->assertJsonPath('data.handled', 1);

        $row = WhatsAppMessage::query()->withoutGlobalScopes()->firstOrFail();

        $this->assertSame($t['org']->id, $row->organization_id);
        $this->assertNotSame(
            $other['org']->id,
            $row->organization_id,
            'the tenant comes from the phone number id we registered, never from the body',
        );
        $this->assertSame(WhatsAppMessage::DIRECTION_IN, $row->direction);
        $this->assertSame('13057242199', $row->counterparty);
        $this->assertSame('Can you come out Thursday?', $row->body);
    }

    public function test_a_retried_delivery_does_not_duplicate_the_message(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $this->configured();
        $this->numberBelongsTo($t);

        $json = json_encode(['entry' => [['changes' => [['value' => [
            'metadata' => ['phone_number_id' => self::PHONE_ID],
            'messages' => [['id' => 'wamid.SAME', 'from' => '13057242199', 'type' => 'text',
                'text' => ['body' => 'hello']]],
        ]]]]]]);

        /*
         * Meta retries anything it did not get a 200 for, carrying the same id.
         *
         * `handled` is asserted on EVERY attempt, not just the row count at the
         * end. The count alone passes for the wrong reason: with a plain
         * create() the second delivery hits the unique index, throws, and is
         * caught and logged - so exactly one row survives and the assertion is
         * green while every retry is quietly failing. handled:1 is what
         * separates "absorbed again" from "threw and was swallowed".
         */
        $this->deliverSigned($json)->assertOk()->assertJsonPath('data.handled', 1);
        $this->deliverSigned($json)->assertOk()->assertJsonPath('data.handled', 1);
        $this->deliverSigned($json)->assertOk()->assertJsonPath('data.handled', 1);

        $this->assertSame(
            1,
            WhatsAppMessage::query()->withoutGlobalScopes()->count(),
            'three retries of one customer message must not be three messages in the list',
        );
    }

    public function test_a_number_we_do_not_own_is_acknowledged_and_dropped(): void
    {
        $this->provisionTenant('A1-Group');
        $this->configured();
        // Deliberately NOT registering the number.

        $json = json_encode(['entry' => [['changes' => [['value' => [
            'metadata' => ['phone_number_id' => '9999999999'],
            'messages' => [['id' => 'wamid.X', 'from' => '13057242199', 'type' => 'text',
                'text' => ['body' => 'hi']]],
        ]]]]]]);

        // 200, not 4xx. Meta disables a subscription that keeps failing, which
        // would stop delivery for the numbers that DO work.
        $this->deliverSigned($json)->assertOk()->assertJsonPath('data.handled', 0);

        $this->assertSame(0, WhatsAppMessage::query()->withoutGlobalScopes()->count());
    }

    public function test_a_status_updates_the_message_it_names(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $this->configured();
        $this->numberBelongsTo($t);

        $sent = $this->inTenant($t['org'], fn () => WhatsAppMessage::create([
            'organization_id' => $t['org']->id,
            'phone_number_id' => self::PHONE_ID,
            'direction' => WhatsAppMessage::DIRECTION_OUT,
            'counterparty' => '13057242199',
            'wamid' => 'wamid.OUT1',
            'status' => WhatsAppMessage::STATUS_SENT,
            'body' => 'Your technician is on the way.',
        ]));

        $json = json_encode(['entry' => [['changes' => [['value' => [
            'metadata' => ['phone_number_id' => self::PHONE_ID],
            'statuses' => [['id' => 'wamid.OUT1', 'status' => 'delivered']],
        ]]]]]]);

        $this->deliverSigned($json)->assertOk()->assertJsonPath('data.handled', 1);

        $fresh = $this->inTenant($t['org'], fn () => $sent->fresh());

        $this->assertSame(WhatsAppMessage::STATUS_DELIVERED, $fresh->status);
        $this->assertNotNull($fresh->delivered_at);
    }

    public function test_a_failure_records_metas_reason_and_not_just_the_status(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $this->configured();
        $this->numberBelongsTo($t);

        $sent = $this->inTenant($t['org'], fn () => WhatsAppMessage::create([
            'organization_id' => $t['org']->id,
            'phone_number_id' => self::PHONE_ID,
            'direction' => WhatsAppMessage::DIRECTION_OUT,
            'counterparty' => '13057242199',
            'wamid' => 'wamid.OUTFREE',
            'status' => WhatsAppMessage::STATUS_SENT,
            'body' => 'Free text, sent after the window closed.',
        ]));

        /*
         * The real payload, from production on 2026-09-16.
         *
         * A free-text message sent 75 minutes after the 24-hour window closed
         * came back HTTP 200 WITH A MESSAGE ID, so the row was written `sent`
         * and the send returned normally. Meta failed it asynchronously and
         * said why only here. Without the reason the row says `failed` and
         * nothing distinguishes "outside the window" from "the number is
         * wrong" or "we are out of credit" - three problems with three
         * different fixes.
         */
        $json = json_encode(['entry' => [['changes' => [['value' => [
            'metadata' => ['phone_number_id' => self::PHONE_ID],
            'statuses' => [[
                'id' => 'wamid.OUTFREE',
                'status' => 'failed',
                'errors' => [['code' => 131047, 'title' => 'Re-engagement message']],
            ]],
        ]]]]]]);

        $this->deliverSigned($json)->assertOk()->assertJsonPath('data.handled', 1);

        $fresh = $this->inTenant($t['org'], fn () => $sent->fresh());

        $this->assertSame(WhatsAppMessage::STATUS_FAILED, $fresh->status);
        $this->assertSame('Re-engagement message', $fresh->status_reason);

        // A failure must not be stamped as delivered on the way past. The
        // writer filters nulls, so a careless change that stamped now() on
        // every status would leave a failed message looking delivered.
        $this->assertNull($fresh->delivered_at);
        $this->assertNull($fresh->read_at);
    }

    /** Deliver a status for a known outbound message. */
    private function statusArrives(string $wamid, string $state): void
    {
        $this->deliverSigned(json_encode(['entry' => [['changes' => [['value' => [
            'metadata' => ['phone_number_id' => self::PHONE_ID],
            'statuses' => [['id' => $wamid, 'status' => $state]],
        ]]]]]]))->assertOk();
    }

    private function outbound(array $t, string $wamid): WhatsAppMessage
    {
        return $this->inTenant($t['org'], fn () => WhatsAppMessage::create([
            'organization_id' => $t['org']->id,
            'phone_number_id' => self::PHONE_ID,
            'direction' => WhatsAppMessage::DIRECTION_OUT,
            'counterparty' => '17867762666',
            'wamid' => $wamid,
            'status' => WhatsAppMessage::STATUS_SENT,
        ]));
    }

    public function test_a_late_sent_does_not_undo_a_delivered(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $this->configured();
        $this->numberBelongsTo($t);
        $row = $this->outbound($t, 'wamid.ORDER');

        /*
         * THE ORDER MEASURED ON PRODUCTION, 2026-09-24.
         *
         * A real message to +1 786 776-2666 came to rest with `delivered_at`
         * set and `status` = `sent`. `delivered_at` is only written when a
         * `delivered` status is absorbed, so `delivered` arrived FIRST and a
         * late `sent` then overwrote it. Meta does not promise order.
         *
         * Fed in the order that arrived rather than the order that would be
         * convenient - in the natural order this test passes against the old
         * code and proves nothing.
         */
        $this->statusArrives('wamid.ORDER', 'delivered');
        $this->statusArrives('wamid.ORDER', 'sent');

        $fresh = $this->inTenant($t['org'], fn () => $row->fresh());

        $this->assertSame(WhatsAppMessage::STATUS_DELIVERED, $fresh->status,
            'a late `sent` rolled a delivered message back to undelivered');
        $this->assertNotNull($fresh->delivered_at);
    }

    public function test_a_stray_failed_cannot_unwind_a_message_that_was_read(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $this->configured();
        $this->numberBelongsTo($t);
        $row = $this->outbound($t, 'wamid.READ');

        $this->statusArrives('wamid.READ', 'read');
        $this->statusArrives('wamid.READ', 'failed');

        // The person opened it. Nothing arriving afterwards makes that untrue.
        $this->assertSame(WhatsAppMessage::STATUS_READ, $this->inTenant($t['org'], fn () => $row->fresh())->status);
    }

    public function test_failed_still_ends_a_message_that_never_arrived(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $this->configured();
        $this->numberBelongsTo($t);
        $row = $this->outbound($t, 'wamid.FAIL');

        // The positive control for the ladder. Without it, a rule that
        // refused EVERY later status would pass the two tests above - and
        // would also hide the only failure anybody needs to see.
        $this->statusArrives('wamid.FAIL', 'failed');

        $this->assertSame(WhatsAppMessage::STATUS_FAILED, $this->inTenant($t['org'], fn () => $row->fresh())->status);
    }

    public function test_a_read_arriving_before_its_delivered_still_marks_delivery(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $this->configured();
        $this->numberBelongsTo($t);
        $row = $this->outbound($t, 'wamid.RFIRST');

        // Read implies delivered. If `delivered` never arrives, or arrives
        // late, the row must not claim it was read and never delivered.
        $this->statusArrives('wamid.RFIRST', 'read');

        $fresh = $this->inTenant($t['org'], fn () => $row->fresh());
        $this->assertSame(WhatsAppMessage::STATUS_READ, $fresh->status);
        $this->assertNotNull($fresh->delivered_at, 'read without delivered is a contradiction');
    }

    public function test_a_status_for_a_message_we_never_sent_invents_nothing(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $this->configured();
        $this->numberBelongsTo($t);

        $json = json_encode(['entry' => [['changes' => [['value' => [
            'metadata' => ['phone_number_id' => self::PHONE_ID],
            'statuses' => [['id' => 'wamid.NOT-OURS', 'status' => 'delivered']],
        ]]]]]]);

        $this->deliverSigned($json)->assertOk()->assertJsonPath('data.handled', 0);

        $this->assertSame(
            0,
            WhatsAppMessage::query()->withoutGlobalScopes()->count(),
            'the same number can be used by another tool; a status is not evidence we sent it',
        );
    }

    public function test_the_signature_check_is_not_a_plain_comparison(): void
    {
        // Not a timing measurement - those are flaky. This asserts the shape
        // that makes timing safety possible, and fails loudly if somebody
        // rewrites it as ===.
        $source = file_get_contents(base_path('app/Support/Social/WhatsAppCloud.php'));

        $this->assertStringContainsString('hash_equals(', $source);
        $this->assertFalse(WhatsAppCloud::signed('body', null, 'secret'));
        $this->assertFalse(WhatsAppCloud::signed('body', 'sha256=wrong', 'secret'));
        $this->assertFalse(
            WhatsAppCloud::signed('body', hash_hmac('sha256', 'body', 'secret'), 'secret'),
            'a digest without the sha256= prefix is not the header Meta sends',
        );
        $this->assertTrue(
            WhatsAppCloud::signed('body', 'sha256='.hash_hmac('sha256', 'body', 'secret'), 'secret'),
        );
    }

    public function test_an_image_keeps_its_media_id_so_it_can_be_fetched(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $this->configured();
        $this->numberBelongsTo($t);
        Http::fake();

        $this->deliverSigned(json_encode(['entry' => [['changes' => [['value' => [
            'metadata' => ['phone_number_id' => self::PHONE_ID],
            'messages' => [[
                'id' => 'wamid.IMAGE1',
                'from' => '13057242199',
                'type' => 'image',
                'image' => ['id' => '1234567890', 'mime_type' => 'image/jpeg'],
            ]],
        ]]]]]]))->assertOk();

        $row = WhatsAppMessage::query()->withoutGlobalScopes()->firstOrFail();
        $this->assertSame('image', $row->kind);
        $this->assertSame('1234567890', $row->media_id);
    }

    public function test_media_is_fetched_by_id_then_by_its_url_and_too_large_is_refused(): void
    {
        config(['services.meta.whatsapp_token' => 'tok']);
        Http::fake([
            'graph.facebook.com/*/111' => Http::response(['url' => 'https://lookaside.fbsbx.com/m/111', 'mime_type' => 'image/jpeg', 'file_size' => 3]),
            'graph.facebook.com/*/222' => Http::response(['url' => 'https://lookaside.fbsbx.com/m/222', 'file_size' => 26 * 1024 * 1024]),
            'lookaside.fbsbx.com/*' => Http::response('JPG'),
        ]);

        $file = app(WhatsAppCloud::class)->downloadMedia('111');
        $this->assertSame(['bytes' => 'JPG', 'mime' => 'image/jpeg'], $file);

        $this->expectException(\RuntimeException::class);
        app(WhatsAppCloud::class)->downloadMedia('222');
    }
}
