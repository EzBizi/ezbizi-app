<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * The templates an automation may send, and the count it must fill.
 *
 * An automation fires when a job is booked or an invoice goes unpaid, which is
 * never inside WhatsApp's 24-hour window, so the only thing a rule can send is
 * an approved template. Everything here is about the two ways the picker can
 * offer something that then fails at the moment it matters: a template Meta has
 * not approved yet, and a parameter count that is wrong.
 */
class WhatsAppTemplatesTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private const WABA = '1052799931072265';

    private const REPORTS_WABA = '2086442795335686';

    private function numberBelongsTo(array $t): void
    {
        DB::table('whatsapp_numbers')->insert([
            'id' => (string) Str::uuid(),
            'organization_id' => $t['org']->id,
            'phone_number_id' => '1254820167721699',
            'waba_id' => self::WABA,
            'display_number' => '+18778536971',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function secondNumberBelongsTo(array $t): void
    {
        DB::table('whatsapp_numbers')->insert([
            'id' => (string) Str::uuid(),
            'organization_id' => $t['org']->id,
            'phone_number_id' => '1366054836581646',
            'waba_id' => self::REPORTS_WABA,
            'display_number' => '+18774508572',
            'display_name' => 'EzBizi Reports',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @param array<int, array<string, mixed>> $data */
    private function metaReturns(array $data): void
    {
        // Without a token the controller refuses before it ever calls Meta, so
        // every fake below would go unused and each test would fail as a 409
        // for a reason that has nothing to do with what it is checking.
        config(['services.meta.whatsapp_token' => 'a-token-for-the-fake']);

        Http::fake([
            'graph.facebook.com/*/message_templates*' => Http::response(['data' => $data]),
        ]);
    }

    public function test_the_parameter_count_is_the_highest_placeholder_not_how_many_appear(): void
    {
        $t = $this->provisionTenant('Alpha');
        $this->numberBelongsTo($t);

        /*
         * The fixture that can distinguish. `{{1}}` and `{{3}}` with no `{{2}}`
         * is a THREE-parameter template: Meta fills them positionally and
         * rejects an array of two. Counting occurrences says two and is wrong,
         * and it is wrong in the direction nothing catches - the builder offers
         * two boxes, the rule is saved, and every send is refused later.
         *
         * A template with a contiguous 1..n would pass under either rule, which
         * is why this one has a gap.
         */
        $this->metaReturns([[
            'name' => 'invoice_overdue',
            'status' => 'APPROVED',
            'category' => 'UTILITY',
            'language' => 'en_US',
            'components' => [
                ['type' => 'BODY', 'text' => 'Hi {{1}}, invoice {{3}} is overdue.'],
            ],
        ]]);

        $data = $this->signedInAs($t['admin'])
            ->getJson('/api/v1/automations/whatsapp-templates')
            ->assertOk()
            ->json('data.0.templates');

        $this->assertSame(3, $data[0]['parameters']);
        $this->assertNotSame(2, $data[0]['parameters'], 'counting occurrences would say two');
    }

    public function test_a_header_parameter_shares_the_bodys_numbering(): void
    {
        $t = $this->provisionTenant('Alpha');
        $this->numberBelongsTo($t);

        // Header and body number into ONE sequence. Scanning only the body
        // would report one parameter for a two-parameter template.
        $this->metaReturns([[
            'name' => 'job_booked',
            'status' => 'APPROVED',
            'category' => 'UTILITY',
            'language' => 'en_US',
            'components' => [
                ['type' => 'HEADER', 'format' => 'TEXT', 'text' => '{{1}} booked'],
                ['type' => 'BODY', 'text' => 'We will see you on {{2}}.'],
            ],
        ]]);

        $data = $this->signedInAs($t['admin'])
            ->getJson('/api/v1/automations/whatsapp-templates')
            ->assertOk()
            ->json('data.0.templates');

        $this->assertSame(2, $data[0]['parameters']);
    }

    public function test_a_template_meta_has_not_approved_is_not_offered(): void
    {
        $t = $this->provisionTenant('Alpha');
        $this->numberBelongsTo($t);

        $this->metaReturns([
            ['name' => 'approved_one', 'status' => 'APPROVED', 'category' => 'UTILITY',
                'language' => 'en_US', 'components' => [['type' => 'BODY', 'text' => 'hello']]],
            ['name' => 'still_pending', 'status' => 'PENDING', 'category' => 'UTILITY',
                'language' => 'en_US', 'components' => [['type' => 'BODY', 'text' => 'hello']]],
            ['name' => 'turned_down', 'status' => 'REJECTED', 'category' => 'MARKETING',
                'language' => 'en_US', 'components' => [['type' => 'BODY', 'text' => 'hello']]],
        ]);

        $names = array_column(
            $this->signedInAs($t['admin'])
                ->getJson('/api/v1/automations/whatsapp-templates')
                ->assertOk()
                ->json('data.0.templates'),
            'name',
        );

        // The positive control matters: without it, a filter that drops
        // everything passes this test.
        $this->assertContains('approved_one', $names);
        $this->assertNotContains('still_pending', $names);
        $this->assertNotContains('turned_down', $names);
    }

    public function test_an_organization_with_no_number_gets_an_empty_answer_and_a_reason(): void
    {
        $t = $this->provisionTenant('Alpha');
        // Deliberately no number row.

        $this->signedInAs($t['admin'])
            ->getJson('/api/v1/automations/whatsapp-templates')
            ->assertOk()
            ->assertJsonPath('data', [])
            ->assertJsonStructure(['data', 'because']);
    }

    public function test_it_reads_one_tenants_number_and_never_anothers(): void
    {
        $mine = $this->provisionTenant('Alpha');
        $other = $this->provisionTenant('Beta');
        $this->numberBelongsTo($other);

        // Alpha has no row of its own. The answer must be the empty one, not
        // Beta's WABA - the scope is the whole point of reading through the
        // model rather than DB::table.
        $this->signedInAs($mine['admin'])
            ->getJson('/api/v1/automations/whatsapp-templates')
            ->assertOk()
            ->assertJsonPath('data', []);

        Http::assertNothingSent();
    }

    public function test_a_waba_with_nothing_approved_yet_says_so_rather_than_going_silent(): void
    {
        $t = $this->provisionTenant('Alpha');
        $this->numberBelongsTo($t);

        // Day one, and every day until Meta finishes reviewing: a number is
        // attached and NOTHING is approved on it. Both empty answers used to
        // be `data: []`, told apart only by whether `because` was there, so a
        // builder printing `because` verbatim rendered nothing for this one -
        // which reads as a broken feature rather than as "come back shortly".
        $this->metaReturns([
            ['name' => 'report_summary', 'status' => 'PENDING', 'category' => 'UTILITY',
                'language' => 'en_US', 'components' => [['type' => 'BODY', 'text' => 'hi']]],
        ]);

        $because = $this->signedInAs($t['admin'])
            ->getJson('/api/v1/automations/whatsapp-templates')
            ->assertOk()
            ->assertJsonPath('data.0.templates', [])
            ->json('because');

        $this->assertNotEmpty($because, 'an empty list must say why it is empty');

        // And it must not be the OTHER reason. A number IS attached here, so
        // blaming a missing number would send somebody to configure a thing
        // that is already configured.
        $this->assertStringNotContainsString('no WhatsApp number', strtolower($because));
        $this->assertStringContainsString('approved', strtolower($because));
    }

    public function test_meta_being_unreachable_is_a_conflict_not_a_five_hundred(): void
    {
        $t = $this->provisionTenant('Alpha');
        $this->numberBelongsTo($t);

        config(['services.meta.whatsapp_token' => 'a-token-for-the-fake']);

        Http::fake([
            'graph.facebook.com/*' => Http::response(
                ['error' => ['message' => 'Application request limit reached']], 429,
            ),
        ]);

        $this->signedInAs($t['admin'])
            ->getJson('/api/v1/automations/whatsapp-templates')
            ->assertStatus(409);
    }

    public function test_each_number_carries_its_own_wabas_templates(): void
    {
        $t = $this->provisionTenant('Alpha');
        $this->numberBelongsTo($t);
        $this->secondNumberBelongsTo($t);

        config(['services.meta.whatsapp_token' => 'a-token-for-the-fake']);

        /*
         * The defect this shape replaced. A template is approved per WABA and
         * our two numbers sit on DIFFERENT WABAs, so a flat list built from
         * `->first()` answered with one number's templates and silently hid the
         * other's - which were the ones the reports screen actually needed.
         *
         * The fixture deliberately gives the two WABAs DIFFERENT templates. If
         * both returned the same set, a flat implementation would pass.
         */
        Http::fake([
            'graph.facebook.com/*/'.self::WABA.'/message_templates*' => Http::response(['data' => [
                ['name' => 'hello_world', 'status' => 'APPROVED', 'category' => 'UTILITY',
                    'language' => 'en_US', 'components' => [['type' => 'BODY', 'text' => 'hi']]],
            ]]),
            'graph.facebook.com/*/'.self::REPORTS_WABA.'/message_templates*' => Http::response(['data' => [
                ['name' => 'report_document', 'status' => 'APPROVED', 'category' => 'UTILITY',
                    'language' => 'en_US', 'components' => [['type' => 'BODY', 'text' => 'the {{1}} report']]],
            ]]),
        ]);

        $data = $this->signedInAs($t['admin'])
            ->getJson('/api/v1/automations/whatsapp-templates')
            ->assertOk()
            ->json('data');

        $byNumber = collect($data)->keyBy('from');

        $this->assertSame(
            ['hello_world'],
            array_column($byNumber['+18778536971']['templates'], 'name'),
        );
        $this->assertSame(
            ['report_document'],
            array_column($byNumber['+18774508572']['templates'], 'name'),
            "the reports number must offer its own WABA's templates, not the other number's",
        );

        // `from` is what the caller hands back to send(), and `label` is what a
        // person recognises. Both are needed or the picker cannot be built.
        $this->assertSame('EzBizi Reports', $byNumber['+18774508572']['label']);
    }

    public function test_one_wabas_outage_does_not_blank_the_other_number(): void
    {
        $t = $this->provisionTenant('Alpha');
        $this->numberBelongsTo($t);
        $this->secondNumberBelongsTo($t);

        config(['services.meta.whatsapp_token' => 'a-token-for-the-fake']);

        Http::fake([
            'graph.facebook.com/*/'.self::WABA.'/message_templates*' => Http::response(['data' => [
                ['name' => 'hello_world', 'status' => 'APPROVED', 'category' => 'UTILITY',
                    'language' => 'en_US', 'components' => [['type' => 'BODY', 'text' => 'hi']]],
            ]]),
            'graph.facebook.com/*/'.self::REPORTS_WABA.'/message_templates*' => Http::response(
                ['error' => ['message' => 'Application request limit reached']], 429,
            ),
        ]);

        $body = $this->signedInAs($t['admin'])
            ->getJson('/api/v1/automations/whatsapp-templates')
            ->assertOk()
            ->json();

        // The working number is still offered...
        $this->assertSame(['+18778536971'], array_column($body['data'], 'from'));

        // ...and the broken one is NAMED rather than silently missing. A list
        // that quietly covers fewer numbers than it appears to is the failure
        // this avoids.
        $this->assertSame('+18774508572', $body['refused'][0]['from']);
    }
}
