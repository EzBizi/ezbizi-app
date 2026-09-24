<?php

namespace Tests\Feature\WhatsApp;

use App\Models\WebSite;
use App\Models\WebValue;
use App\Models\WhatsAppMessage;
use App\Support\Social\WhatsAppStaffBot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * The A-1 Chimney staff line answering a tech.
 *
 * The two tests that matter most are the ones about NOT replying: a robot that
 * answers on the wrong number puts itself in front of a customer expecting a
 * person, and a robot that answers a retry sends every reply twice. Both are
 * invisible until somebody complains.
 */
class WhatsAppStaffBotTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private const BOT = '1309270845610102';

    private const OTHER = '1254820167721699';

    private const SECRET = 'app-secret-from-meta';

    /** @var list<array<string, mixed>> */
    private array $sent = [];

    private function configured(): void
    {
        config([
            'services.meta.client_secret' => self::SECRET,
            'services.meta.whatsapp_token' => 'a-token-for-the-fake',
            'services.meta.whatsapp_bot_number_id' => self::BOT,
        ]);

        $n = 0;
        Http::fake(function ($request) use (&$n) {
            $n++;
            $this->sent[] = $request->data();

            return Http::response(['messages' => [['id' => 'wamid.REPLY'.$n]]]);
        });
    }

    private function number(array $t, string $phoneNumberId): void
    {
        DB::table('whatsapp_numbers')->insert([
            'id' => (string) Str::uuid(),
            'organization_id' => $t['org']->id,
            'phone_number_id' => $phoneNumberId,
            'waba_id' => '1090541950244647',
            'display_number' => $phoneNumberId === self::BOT ? '+19545162455' : '+18778536971',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * A site as Web Management actually stores one. Defaults to the A1 Chimney
     * brand, because that is what the bot serves; pass 'group' => null to seed
     * another brand.
     *
     * @param  array<string, string|null>  $values
     */
    private function site(array $t, array $values): void
    {
        $values = array_filter(['group' => 'A1 Chimney', ...$values], fn ($v) => $v !== null);

        $this->inTenant($t['org'], function () use ($t, $values) {
            // The Web module's own fixture shape: `token_hash` is NOT NULL and
            // deliberately not fillable, so it is set on the instance.
            $site = new WebSite([
                'company_id' => $t['company']->id,
                'host' => Str::random(8).'.example',
                'label' => $values['company_name'] ?? 'site',
            ]);
            $site->token_hash = hash('sha256', $site->host.'-token');
            $site->save();

            foreach ($values as $key => $value) {
                WebValue::create(['site_id' => $site->getKey(), 'path' => '', 'key' => $key, 'value' => $value]);
            }
        });
    }

    /** @param array<string, mixed> $message */
    private function inbound(string $phoneNumberId, array $message, string $wamid = 'wamid.IN1')
    {
        $json = (string) json_encode(['entry' => [['changes' => [['value' => [
            'metadata' => ['phone_number_id' => $phoneNumberId],
            'messages' => [['id' => $wamid, 'from' => '17867762666', ...$message]],
        ]]]]]]);

        return $this->call('POST', '/api/v1/social/whatsapp/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $json, self::SECRET),
        ], $json);
    }

    private function text(string $body): array
    {
        return ['type' => 'text', 'text' => ['body' => $body]];
    }

    private function tap(string $id, string $title): array
    {
        return ['type' => 'interactive', 'interactive' => ['type' => 'list_reply',
            'list_reply' => ['id' => $id, 'title' => $title]]];
    }

    public function test_hello_gets_the_menu(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $this->configured();
        $this->number($t, self::BOT);

        $this->inbound(self::BOT, $this->text('hi'))->assertOk();

        $this->assertCount(1, $this->sent);
        $this->assertSame('interactive', $this->sent[0]['type']);
        $this->assertSame('list', $this->sent[0]['interactive']['type']);

        $ids = collect($this->sent[0]['interactive']['action']['sections'])
            ->flatMap(fn ($s) => $s['rows'])->pluck('id')->all();
        $this->assertSame(
            [WhatsAppStaffBot::NFPA_211, WhatsAppStaffBot::CSIA, WhatsAppStaffBot::FIND_LOCATION],
            $ids,
        );
    }

    public function test_a_message_on_another_number_gets_no_reply(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $this->configured();
        $this->number($t, self::OTHER);

        /*
         * THE GUARD THAT KEEPS A ROBOT OFF LINES WHERE A PERSON IS EXPECTED.
         *
         * EzBizi's own number receives messages from real customers. Without
         * `handles()`, every one of them would be answered with a chimney menu.
         * The message is still RECORDED - only the reply is withheld.
         */
        $this->inbound(self::OTHER, $this->text('hi'))->assertOk();

        $this->assertCount(0, $this->sent, 'the bot replied on a number it does not run');
        $this->assertSame(1, WhatsAppMessage::query()->withoutGlobalScopes()->count());
    }

    public function test_an_unconfigured_bot_answers_nothing(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $this->configured();
        config(['services.meta.whatsapp_bot_number_id' => '']);
        $this->number($t, self::BOT);

        $this->inbound(self::BOT, $this->text('hi'))->assertOk();

        // Unset is OFF, not "every number". The failure it avoids is a deploy
        // that forgets the env key turning every line into a bot.
        $this->assertCount(0, $this->sent);
    }

    public function test_a_retried_delivery_is_answered_once(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $this->configured();
        $this->number($t, self::BOT);

        /*
         * Meta retries anything it did not get a 200 for, carrying the SAME
         * wamid. A reply calls Meta, which makes a slow response - and so a
         * retry - more likely. Without the new-message guard, the person gets
         * every answer twice.
         */
        $this->inbound(self::BOT, $this->text('hi'), 'wamid.SAME')->assertOk();
        $this->inbound(self::BOT, $this->text('hi'), 'wamid.SAME')->assertOk();
        $this->inbound(self::BOT, $this->text('hi'), 'wamid.SAME')->assertOk();

        $this->assertCount(1, $this->sent, 'a retried delivery was answered again');
    }

    public function test_tapping_nfpa_211_sends_the_official_free_access_link(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $this->configured();
        $this->number($t, self::BOT);

        $this->inbound(self::BOT, $this->tap(WhatsAppStaffBot::NFPA_211, 'NFPA 211'))->assertOk();

        $this->assertSame('text', $this->sent[0]['type']);
        // NFPA's OWN free-access page, not a copy of the standard. The
        // standard is copyrighted and sold; the link is legal, free, and
        // always the current edition.
        $this->assertStringContainsString('nfpa.org', $this->sent[0]['text']['body']);
        $this->assertStringContainsString('free-access', $this->sent[0]['text']['body']);
    }

    public function test_a_city_is_found_by_the_area_it_serves_not_its_street_address(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $this->configured();
        $this->number($t, self::BOT);

        /*
         * The REAL Cleveland row, measured on production: its `city` is
         * "Warrensville Heights" and "Cleveland" appears only in
         * `service_area`. A search on `city` alone - the first draft - found
         * nothing for the word a tech would actually type.
         */
        $this->site($t, [
            'company_name' => 'A1 Chimney - CLE',
            'city' => 'Warrensville Heights',
            'state' => 'OH',
            'service_area' => 'Cleveland, Ohio',
            'address' => '4440 Warrensville Center Rd, Warrensville Heights, OH 44128',
            'phone' => '(888) 282-9555',
        ]);
        $this->site($t, [
            'company_name' => 'A1 Chimney - ATL',
            'city' => 'Atlanta',
            'state' => 'GA',
            'service_area' => 'Atlanta, Georgia',
            'phone' => '(888) 283-2999',
        ]);

        $this->inbound(self::BOT, $this->text('Cleveland'))->assertOk();

        $body = $this->sent[0]['text']['body'];
        $this->assertStringContainsString('A1 Chimney - CLE', $body);
        $this->assertStringContainsString('(888) 282-9555', $body);
        // Only the match - a search returning every site passes the above.
        $this->assertStringNotContainsString('(888) 283-2999', $body);
    }

    public function test_a_short_query_is_not_matched_inside_the_brand_name(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $this->configured();
        $this->number($t, self::BOT);

        $this->site($t, ['company_name' => 'A1 Chimney - CLE', 'state' => 'OH', 'phone' => '(888) 282-9555']);
        $this->site($t, ['company_name' => 'A1 Chimney - ATL', 'state' => 'GA', 'phone' => '(888) 283-2999']);

        /*
         * THE TRAP MEASURED ON PRODUCTION: "chi" is inside "Chimney".
         *
         * There is no CHI branch here. A substring search on the name matched
         * EVERY A1 Chimney site, so a tech asking for Chicago was handed the
         * whole list. A short query must match a code whole or not at all.
         */
        $this->inbound(self::BOT, $this->text('chi'))->assertOk();

        $this->assertSame('interactive', $this->sent[0]['type'], '"chi" matched the brand name, not a branch');
        $this->assertStringContainsString('No location matched', $this->sent[0]['interactive']['body']['text']);
    }

    public function test_a_branch_code_finds_exactly_that_branch(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $this->configured();
        $this->number($t, self::BOT);

        $this->site($t, ['company_name' => 'A1 Chimney - CLE', 'state' => 'OH', 'phone' => '(888) 282-9555']);
        $this->site($t, ['company_name' => 'A1 Chimney - ATL', 'state' => 'GA', 'phone' => '(888) 283-2999']);

        // The positive control for the test above: a code DOES match, whole.
        $this->inbound(self::BOT, $this->text('CLE'))->assertOk();

        $body = $this->sent[0]['text']['body'];
        $this->assertStringContainsString('(888) 282-9555', $body);
        $this->assertStringNotContainsString('(888) 283-2999', $body);
    }

    public function test_another_brand_in_the_same_organization_is_left_out(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $this->configured();
        $this->number($t, self::BOT);

        $this->site($t, ['company_name' => 'A1 Chimney - NJ', 'state' => 'NJ', 'phone' => '(888) 657-9555']);

        // Measured alongside it on production: the same organization runs
        // Everdoors. Handed that number, a chimney tech calls a garage-door
        // company.
        $this->site($t, ['company_name' => 'Everdoors - NJ', 'state' => 'NJ',
            'phone' => '(856) 886-4686', 'group' => null]);

        $this->inbound(self::BOT, $this->text('NJ'))->assertOk();

        $body = $this->sent[0]['text']['body'];
        $this->assertStringContainsString('(888) 657-9555', $body);
        $this->assertStringNotContainsString('Everdoors', $body);
    }

    public function test_an_ad_landing_page_does_not_list_its_branch_twice(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $this->configured();
        $this->number($t, self::BOT);

        // Five of the 43 A1 Chimney sites are "- Ads" copies of a branch.
        $this->site($t, ['company_name' => 'A1 Chimney - NJ', 'state' => 'NJ', 'phone' => '(888) 657-9555']);
        $this->site($t, ['company_name' => 'A1 Chimney - NJ - Ads', 'state' => 'NJ', 'phone' => '(888) 657-9555']);

        $this->inbound(self::BOT, $this->text('NJ'))->assertOk();

        $this->assertSame(1, substr_count($this->sent[0]['text']['body'], '(888) 657-9555'),
            'the ad landing page listed its branch a second time');
    }

    public function test_a_search_never_sees_another_organizations_sites(): void
    {
        $mine = $this->provisionTenant('A1-Group');
        $other = $this->provisionTenant('Premier-Garage');
        $this->configured();
        $this->number($mine, self::BOT);

        // The only Chicago site belongs to somebody else.
        $this->site($other, ['company_name' => 'A1 Chimney - CHI', 'service_area' => 'Chicago, Illinois',
            'phone' => '(312) 555-0000']);

        $this->inbound(self::BOT, $this->text('Chicago'))->assertOk();

        // Read under the tenant scope, so it finds nothing and says so -
        // rather than reading out a competitor's number.
        $this->assertSame('interactive', $this->sent[0]['type']);
        $this->assertStringContainsString('No location matched', $this->sent[0]['interactive']['body']['text']);
        $this->assertStringNotContainsString('555-0000', json_encode($this->sent));
    }

    public function test_no_match_says_so_and_offers_the_menu(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $this->configured();
        $this->number($t, self::BOT);

        $this->inbound(self::BOT, $this->text('Atlantis'))->assertOk();

        // One message, not two: the explanation rides in the menu's body.
        $this->assertCount(1, $this->sent);
        $this->assertStringContainsString('No location matched "Atlantis"', $this->sent[0]['interactive']['body']['text']);
    }

    public function test_the_replies_are_recorded_as_a_conversation(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $this->configured();
        $this->number($t, self::BOT);

        $this->inbound(self::BOT, $this->tap(WhatsAppStaffBot::CSIA, 'CSIA'))->assertOk();

        $rows = WhatsAppMessage::query()->withoutGlobalScopes()->orderBy('created_at')->get();

        // The tap is recorded as what the person SAW, not as an opaque id, and
        // our answer sits beside it - so the history reads as a conversation.
        $this->assertSame('CSIA', $rows->firstWhere('direction', 'in')->body);
        $this->assertStringContainsString('csia.org', $rows->firstWhere('direction', 'out')->body);
    }
}
