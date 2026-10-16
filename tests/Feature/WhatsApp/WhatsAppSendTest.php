<?php

namespace Tests\Feature\WhatsApp;

use App\Models\WhatsAppMessage;
use App\Support\Social\WhatsAppCloud;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * `send()` — the call an automation makes, and the row it owns.
 *
 * This exists so no caller has to know three facts about this module:
 * `phone_number_id` is the provider's id and not the display number,
 * `counterparty` is digits with no `+`, and the row is written BEFORE the
 * attempt. Each is pinned here, because each is silent when it goes wrong -
 * a send from a display number is refused by Meta with a message about an
 * unknown id, and a row written after the attempt leaves nothing at all behind
 * when the process dies mid-flight.
 */
class WhatsAppSendTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private const PHONE_ID = '1254820167721699';

    private const REPORTS_PHONE_ID = '1366054836581646';

    private function numberBelongsTo(array $t): void
    {
        DB::table('whatsapp_numbers')->insert([
            'id' => (string) Str::uuid(),
            'organization_id' => $t['org']->id,
            'phone_number_id' => self::PHONE_ID,
            'waba_id' => '1052799931072265',
            'display_number' => '+18778536971',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        config(['services.meta.whatsapp_token' => 'a-token-for-the-fake']);
    }

    private function secondNumberBelongsTo(array $t): void
    {
        DB::table('whatsapp_numbers')->insert([
            'id' => (string) Str::uuid(),
            'organization_id' => $t['org']->id,
            'phone_number_id' => self::REPORTS_PHONE_ID,
            'waba_id' => '2086442795335686',
            'display_number' => '+18774508572',
            'display_name' => 'EzBizi Reports',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function metaAccepts(): void
    {
        // A DIFFERENT wamid per call, because Meta gives a different one per
        // call. A fixed id looks fine until a test sends twice, and then the
        // unique index on wamid throws - which is the index doing its job and
        // the fixture lying about reality.
        $n = 0;

        Http::fake(function () use (&$n) {
            $n++;

            return Http::response(['messages' => [['id' => 'wamid.ACCEPTED'.$n]]]);
        });
    }

    public function test_it_sends_from_the_provider_id_and_never_from_the_display_number(): void
    {
        $t = $this->provisionTenant('Alpha');
        $this->numberBelongsTo($t);
        $this->metaAccepts();

        $row = $this->inTenant($t['org'], fn () => app(WhatsAppCloud::class)
            ->send('job_booked', '(305) 724-2199', ['Thursday']));

        $this->assertSame(self::PHONE_ID, $row->phone_number_id);
        $this->assertNotSame('+18778536971', $row->phone_number_id);

        // And the URL is built from it. Posting to the display number is the
        // mistake this guards, and Meta answers it with "unknown path
        // components" rather than anything that names the real problem.
        Http::assertSent(fn ($request) => str_contains($request->url(), '/'.self::PHONE_ID.'/messages'));
    }

    public function test_it_normalises_however_the_number_was_typed(): void
    {
        $t = $this->provisionTenant('Alpha');
        $this->numberBelongsTo($t);
        $this->metaAccepts();

        foreach (['(305) 724-2199', '305-724-2199', '+1 305 724 2199', '13057242199'] as $typed) {
            $row = $this->inTenant($t['org'], fn () => app(WhatsAppCloud::class)
                ->send('job_booked', $typed));

            // Digits, no `+`. Meta sends every inbound `from` in this shape, so
            // both directions of one conversation key the same way; a stored
            // `+1305...` would thread as a separate conversation.
            $this->assertSame('13057242199', $row->counterparty, "failed for: {$typed}");
        }
    }

    public function test_a_number_it_cannot_be_sure_of_is_refused_rather_than_guessed(): void
    {
        $t = $this->provisionTenant('Alpha');
        $this->numberBelongsTo($t);
        $this->metaAccepts();

        // An extension, not a phone number. Guessing would send a customer's
        // booking confirmation to whoever owns the number it guessed.
        //
        // The SENTENCE is pinned, not just the type, and that applies to every
        // refusal in this file. Automations renders these messages VERBATIM to
        // a rule author, so the sentence is the part a person reads - and the
        // class alone passes on any refusal at all, including one thrown for a
        // completely unrelated reason.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not a phone number WhatsApp can be sent to');

        $this->inTenant($t['org'], fn () => app(WhatsAppCloud::class)
            ->send('job_booked', '2199'));
    }

    public function test_nothing_is_sent_for_a_number_it_refused(): void
    {
        $t = $this->provisionTenant('Alpha');
        $this->numberBelongsTo($t);
        $this->metaAccepts();

        try {
            $this->inTenant($t['org'], fn () => app(WhatsAppCloud::class)
                ->send('job_booked', '2199'));
        } catch (RuntimeException) {
            // expected
        }

        // The half the exception test cannot see: it refused BEFORE calling
        // Meta and before writing a row, rather than after.
        Http::assertNothingSent();
        $this->assertSame(0, WhatsAppMessage::query()->withoutGlobalScopes()->count());
    }

    public function test_a_send_that_dies_in_flight_leaves_a_row_saying_something_was_owed(): void
    {
        $t = $this->provisionTenant('Alpha');
        $this->numberBelongsTo($t);

        // Meta unreachable. The row must already exist by the time this is
        // thrown - that is the whole reason it is created before the attempt.
        Http::fake(fn () => throw new ConnectionException('timed out'));

        try {
            $this->inTenant($t['org'], fn () => app(WhatsAppCloud::class)
                ->send('job_booked', '13057242199'));
        } catch (RuntimeException) {
            // expected
        }

        $row = WhatsAppMessage::query()->withoutGlobalScopes()->first();

        $this->assertNotNull($row, 'a death in flight must leave a record, not silence');
        $this->assertSame(WhatsAppMessage::STATUS_QUEUED, $row->status);
        $this->assertNull($row->wamid);
        $this->assertSame('job_booked', $row->template_name);
    }

    public function test_it_refuses_when_the_organization_has_no_number(): void
    {
        $t = $this->provisionTenant('Alpha');
        config(['services.meta.whatsapp_token' => 'a-token-for-the-fake']);
        $this->metaAccepts();
        // Deliberately no number row.

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No WhatsApp number is attached to this organization');

        $this->inTenant($t['org'], fn () => app(WhatsAppCloud::class)
            ->send('job_booked', '13057242199'));
    }

    public function test_it_sends_from_this_tenants_number_and_never_anothers(): void
    {
        $mine = $this->provisionTenant('Alpha');
        $other = $this->provisionTenant('Beta');
        $this->numberBelongsTo($other);
        $this->metaAccepts();

        // Alpha has no number of its own. If the lookup were not tenant-scoped
        // it would find Beta's and send one organization's message down
        // another's line - which is the failure that cannot be taken back.
        // Pinned to the reason as well. Without it this passes if the lookup
        // finds Beta's number and then throws for some unrelated cause - the
        // leak would be green.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No WhatsApp number is attached to this organization');

        $this->inTenant($mine['org'], fn () => app(WhatsAppCloud::class)
            ->send('job_booked', '13057242199'));
    }

    public function test_the_parameters_reach_meta_in_order(): void
    {
        $t = $this->provisionTenant('Alpha');
        $this->numberBelongsTo($t);
        $this->metaAccepts();

        $this->inTenant($t['org'], fn () => app(WhatsAppCloud::class)
            ->send('invoice_overdue', '13057242199', ['Jose', 4182.60]));

        Http::assertSent(function ($request) {
            $p = $request->data()['template']['components'][0]['parameters'];

            // Positional and stringified: Meta rejects a numeric type here, and
            // an automation filling {{2}} from a money field hands us a float.
            return $p[0] === ['type' => 'text', 'text' => 'Jose']
                && $p[1] === ['type' => 'text', 'text' => '4182.6'];
        });
    }

    public function test_it_refuses_to_choose_when_the_organization_holds_two_numbers(): void
    {
        $t = $this->provisionTenant('Alpha');
        $this->numberBelongsTo($t);
        $this->secondNumberBelongsTo($t);
        $this->metaAccepts();

        /*
         * The defect this replaced. A1-Group really does hold two numbers -
         * "EzBizi" and "EzBizi Reports" - and `->first()` returned whichever
         * sorted first, which is the customer-facing line. A financial report
         * would have gone out from the wrong sender, arrived fine, and been
         * recorded as `sent`. Nothing on our side would ever have said so.
         */
        try {
            $this->inTenant($t['org'], fn () => app(WhatsAppCloud::class)
                ->send('job_booked', '13057242199'));
            $this->fail('sending with two numbers and no choice must refuse, not pick one');
        } catch (RuntimeException $e) {
            // The message has to name the options, or the caller cannot act on it.
            $this->assertStringContainsString('+18778536971', $e->getMessage());
            $this->assertStringContainsString('+18774508572', $e->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_naming_the_number_sends_from_that_one(): void
    {
        $t = $this->provisionTenant('Alpha');
        $this->numberBelongsTo($t);
        $this->secondNumberBelongsTo($t);
        $this->metaAccepts();

        // However it is written. Jose asks for "the EzBizi - Reports one" and
        // a caller should not have to know its provider id to honour that.
        $row = $this->inTenant($t['org'], fn () => app(WhatsAppCloud::class)
            ->send('job_booked', '13057242199', [], 'en_US', '+1 (877) 450-8572'));

        $this->assertSame(self::REPORTS_PHONE_ID, $row->phone_number_id);
        $this->assertNotSame(self::PHONE_ID, $row->phone_number_id);

        Http::assertSent(fn ($r) => str_contains($r->url(), '/'.self::REPORTS_PHONE_ID.'/messages'));
    }

    public function test_a_number_we_do_not_hold_is_refused_rather_than_falling_back(): void
    {
        $t = $this->provisionTenant('Alpha');
        $this->numberBelongsTo($t);
        $this->metaAccepts();

        // A typo in the caller's `from` must not quietly send from the one
        // number we do have - that is the same silent-wrong-sender failure
        // wearing a different hat.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No WhatsApp number matching');

        $this->inTenant($t['org'], fn () => app(WhatsAppCloud::class)
            ->send('job_booked', '13057242199', [], 'en_US', '+18889999999'));
    }

    public function test_one_number_and_no_choice_is_still_unambiguous(): void
    {
        $t = $this->provisionTenant('Alpha');
        $this->numberBelongsTo($t);
        $this->metaAccepts();

        // The refusal is about ambiguity, not about ceremony. With a single
        // number there is nothing to choose, so callers keep working.
        $row = $this->inTenant($t['org'], fn () => app(WhatsAppCloud::class)
            ->send('job_booked', '13057242199'));

        $this->assertSame(self::PHONE_ID, $row->phone_number_id);
    }

    public function test_a_key_already_sent_sends_nothing_the_second_time(): void
    {
        $t = $this->provisionTenant('Alpha');
        $this->numberBelongsTo($t);
        $this->metaAccepts();

        $send = fn () => $this->inTenant($t['org'], fn () => app(WhatsAppCloud::class)
            ->send('job_booked', '13057242199', [], 'en_US', null, 'weekly:alpha:2026-10-12'));

        $first = $send();
        $second = $send();

        $this->assertSame($first->id, $second->id);
        Http::assertSentCount(1);
    }

    public function test_a_key_that_failed_is_sent_again(): void
    {
        $t = $this->provisionTenant('Alpha');
        $this->numberBelongsTo($t);

        Http::fakeSequence()
            ->push(['error' => ['message' => 'nope']], 400)
            ->push(['messages' => [['id' => 'wamid.RETRY']]]);

        $send = fn () => $this->inTenant($t['org'], fn () => app(WhatsAppCloud::class)
            ->send('job_booked', '13057242199', [], 'en_US', null, 'weekly:alpha:2026-10-12'));

        try {
            $send();
        } catch (RuntimeException) {
            // Meta refused the first attempt.
        }

        $retry = $send();

        $this->assertSame('wamid.RETRY', $retry->wamid);
        $this->assertSame('weekly:alpha:2026-10-12', $retry->idempotency_key);
        Http::assertSentCount(2);
    }
}
