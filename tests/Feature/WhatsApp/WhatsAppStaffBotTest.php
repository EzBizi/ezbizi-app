<?php

namespace Tests\Feature\WhatsApp;

use App\Models\AssistantEntry;
use App\Models\StaffBotItem;
use App\Models\StaffBotMenu;
use App\Models\TrainingGuide;
use App\Models\WebSite;
use App\Models\WebValue;
use App\Models\WhatsAppMessage;
use App\Support\Social\WhatsAppStaffBot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
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

        // The bot's answers are the organization's Library since 2026-09-24.
        // Seeded with what A-1 actually has, through the command that put it
        // there, so these tests keep asking about the answers techs get.
        if ($phoneNumberId === self::BOT) {
            $this->assertSame(0, Artisan::call('assistant:import-a1', ['--organization' => $t['org']->name]));
        }
    }

    /** The row id of a Library section, as the main menu draws it. */
    private function section(string $name): string
    {
        return WhatsAppStaffBot::SECTION.$name;
    }

    /** The row id of one Library answer, as a section's list draws it. */
    private function entry(string $title): string
    {
        return WhatsAppStaffBot::ENTRY.AssistantEntry::query()->withoutGlobalScopes()
            ->where('title', $title)->valueOrFail('id');
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

    /** Make this tenant's admin the person texting in, as a staff member. */
    private function staff(array $t, string $phone = '(786) 776-2666', bool $active = true): void
    {
        $this->inTenant($t['org'], fn () => $t['admin']->forceFill([
            'phone' => $phone,
            'is_active' => $active,
        ])->save());
    }

    private function menuIds(): array
    {
        return collect($this->sent[0]['interactive']['action']['sections'])
            ->flatMap(fn ($s) => $s['rows'])->pluck('id')->all();
    }

    /** @param array<string, mixed> $message */
    private function inbound(string $phoneNumberId, array $message, string $wamid = 'wamid.IN1', string $from = '17867762666')
    {
        // Every sender here has already answered WhatsAppIntake's first
        // question, so these tests are about the Library answers (the question
        // itself is WhatsAppIntakeTest's).
        $org = DB::table('whatsapp_numbers')->where('phone_number_id', $phoneNumberId)->value('organization_id');

        if ($org !== null && ! DB::table('whatsapp_contacts')->where(['phone_number_id' => $phoneNumberId, 'counterparty' => $from])->exists()) {
            DB::table('whatsapp_contacts')->insert([
                'id' => (string) Str::uuid(), 'organization_id' => $org, 'phone_number_id' => $phoneNumberId,
                'counterparty' => $from, 'kind' => 'lead', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $json = (string) json_encode(['entry' => [['changes' => [['value' => [
            'metadata' => ['phone_number_id' => $phoneNumberId],
            'messages' => [['id' => $wamid, 'from' => $from, ...$message]],
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

        // Jose's wording from the first real handset: the header names the
        // bot, and the greeting is not the bare "What do you need?".
        $this->assertSame('A1 Chimney Bot', $this->sent[0]['interactive']['header']['text']);
        $this->assertStringContainsString('Welcome to the A1 Chimney staff assistant',
            $this->sent[0]['interactive']['body']['text']);
        $this->assertStringNotContainsString('What do you need', $this->sent[0]['interactive']['body']['text']);

        $ids = collect($this->sent[0]['interactive']['action']['sections'])
            ->flatMap(fn ($s) => $s['rows'])->pluck('id')->all();
        $this->assertSame(
            [$this->section('NFPA 211'), $this->section('CSIA'), WhatsAppStaffBot::FIND_LOCATION],
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

    public function test_tapping_nfpa_211_opens_its_topics(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $this->configured();
        $this->number($t, self::BOT);

        $this->inbound(self::BOT, $this->tap($this->section('NFPA 211'), 'NFPA 211'))->assertOk();

        $this->assertSame('list', $this->sent[0]['interactive']['type']);
        $ids = collect($this->sent[0]['interactive']['action']['sections'])
            ->flatMap(fn ($s) => $s['rows'])->pluck('id')->all();
        $this->assertContains($this->entry('When a Level 2 is needed'), $ids);
        $this->assertContains($this->entry('Flue sizing'), $ids);
    }

    public function test_a_code_topic_gives_the_requirement_itself_and_no_link(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $this->configured();
        $this->number($t, self::BOT);

        $this->inbound(self::BOT, $this->tap($this->entry('When a Level 2 is needed'), 'When a Level 2 is needed'))->assertOk();

        $body = $this->sent[0]['text']['body'];

        // Jose: "I don't want for the bot to offer links, i want the bot to be
        // able to give out the information for the codes." The requirement is
        // in the reply...
        $this->assertStringContainsString('the property is being sold', $body);
        $this->assertStringContainsString('lined or relined', $body);

        // ...and nothing sends the tech elsewhere to read it.
        $this->assertStringNotContainsString('http', $body);

        // Every code answer says what it is: a summary, to be confirmed
        // against the edition in force. A fire-code bot must not read as the
        // standard itself.
        $this->assertStringContainsString('Our summary of NFPA 211', $body);
    }

    public function test_every_code_answer_is_link_free_and_carries_the_footer(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $this->configured();
        $this->number($t, self::BOT);

        $topics = [...array_map(fn (string $title) => $this->entry($title), ['How often to inspect',
            'Inspection levels', 'When a Level 2 is needed', 'What a Level 2 covers', 'Flue sizing',
            'Crown bond break']), $this->section('CSIA')];

        foreach ($topics as $i => $id) {
            $this->inbound(self::BOT, $this->tap($id, $id), 'wamid.T'.$i)->assertOk();
        }

        $this->assertCount(count($topics), $this->sent);

        foreach ($this->sent as $i => $reply) {
            $body = $reply['text']['body'];
            $this->assertStringNotContainsString('http', $body, "{$topics[$i]} offers a link");
            $this->assertStringContainsString('Our summary of NFPA 211', $body, "{$topics[$i]} has no footer");
            // No preview card for an answer that has no link to draw one from.
            $this->assertFalse($reply['text']['preview_url'], "{$topics[$i]} asks for a preview");
        }
    }

    public function test_every_list_the_bot_sends_fits_whatsapps_limits(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $this->configured();
        $this->number($t, self::BOT);

        /*
         * META REFUSES A LIST THAT BREAKS ONE LIMIT, AND THE TECH SEES NOTHING.
         *
         * The reply throws, our catch logs it, and the person who tapped a
         * button gets no answer at all. The first draft of the NFPA topics
         * named its section "Inspections and construction" - 28 characters
         * against a limit of 24 - and every test above would have passed.
         * These are Meta's published limits for an interactive list.
         */
        // As staff, so the menu includes the finance row and it is measured
        // too - a stranger's menu would leave it untested.
        $this->staff($t);
        $this->inbound(self::BOT, $this->text('hi'), 'wamid.L1')->assertOk();
        $this->inbound(self::BOT, $this->tap($this->section('NFPA 211'), 'NFPA 211'), 'wamid.L2')->assertOk();

        $lists = array_filter($this->sent, fn ($m) => ($m['type'] ?? '') === 'interactive');
        $this->assertCount(2, $lists, 'expected the main menu and the NFPA topics');

        foreach ($lists as $m) {
            $i = $m['interactive'];
            $this->assertLessThanOrEqual(60, mb_strlen($i['header']['text']), 'header over 60');
            $this->assertLessThanOrEqual(1024, mb_strlen($i['body']['text']), 'body over 1024');
            $this->assertLessThanOrEqual(20, mb_strlen($i['action']['button']), 'button over 20');

            $rows = 0;
            foreach ($i['action']['sections'] as $section) {
                $this->assertLessThanOrEqual(24, mb_strlen($section['title']),
                    "section title \"{$section['title']}\" is over 24");

                foreach ($section['rows'] as $row) {
                    $rows++;
                    $this->assertLessThanOrEqual(24, mb_strlen($row['title']),
                        "row title \"{$row['title']}\" is over 24");
                    $this->assertLessThanOrEqual(72, mb_strlen($row['description'] ?? ''),
                        "row description \"{$row['title']}\" is over 72");
                }
            }
            $this->assertLessThanOrEqual(10, $rows, 'a list may hold at most 10 rows');
        }
    }

    public function test_staff_see_finance_and_get_the_lenders_in_order(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $this->configured();
        $this->number($t, self::BOT);
        // Stored the way a person types it; WhatsApp sends 17867762666.
        $this->staff($t, '(786) 776-2666');

        $this->inbound(self::BOT, $this->text('hi'), 'wamid.S1')->assertOk();
        $this->assertContains($this->section('Finance options'), $this->menuIds());

        $this->inbound(self::BOT, $this->tap($this->section('Finance options'), 'Finance options'), 'wamid.S2')->assertOk();
        $body = $this->sent[1]['text']['body'];

        // The order is the point of the message: cheapest to the company first.
        $this->assertLessThan(strpos($body, 'Enhancify'), strpos($body, 'Acorn'));
        $this->assertLessThan(strpos($body, 'Wisetack'), strpos($body, 'Enhancify'));
        // And here the links ARE the content - they are what a tech sends.
        $this->assertStringContainsString('acornfinance.com/pre-qualify', $body);
        $this->assertStringContainsString('3.9%', $body);
    }

    public function test_a_stranger_is_not_shown_finance_and_is_refused_if_they_tap_it(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $this->configured();
        $this->number($t, self::BOT);
        // Nobody on staff has this number.

        $this->inbound(self::BOT, $this->text('hi'), 'wamid.X1')->assertOk();

        // Not even drawn: a row they would be refused still advertises that
        // the company has internal guidance to hide.
        $this->assertNotContains($this->section('Finance options'), $this->menuIds());

        // And a tap is refused anyway - the check is on the ANSWER, not only
        // on the menu, because a row id is only a string.
        $this->inbound(self::BOT, $this->tap($this->section('Finance options'), 'Finance options'), 'wamid.X2')->assertOk();
        $body = $this->sent[1]['text']['body'];

        $this->assertStringContainsString('for staff only', $body);
        // What must never leak to a customer who found the number.
        $this->assertStringNotContainsString('3.9%', $body);
        $this->assertStringNotContainsString('acornfinance', $body);
    }

    public function test_a_deactivated_employee_is_no_longer_staff(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $this->configured();
        $this->number($t, self::BOT);

        // Their phone is still on the record. That must not be enough: a
        // former employee keeps reading what A1 pays each lender otherwise.
        $this->staff($t, '(786) 776-2666', active: false);

        $this->inbound(self::BOT, $this->tap($this->section('Finance options'), 'Finance options'))->assertOk();

        $this->assertStringNotContainsString('3.9%', $this->sent[0]['text']['body']);
    }

    public function test_a_foreign_number_is_not_mistaken_for_a_us_staff_member(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $this->configured();
        $this->number($t, self::BOT);

        // A real staff member, on a Dallas 469 line, typed into their profile.
        $this->staff($t, '(469) 555-1234');

        /*
         * FOUND BY THE DEPLOY DESK. A WhatsApp wa_id always carries its country
         * code, so ten digits is a FOREIGN number - e.g. +46 and eight digits.
         * The normaliser adds a 1 to any ten digits (right for a typed profile),
         * which turned "4695551234" into +14695551234: the Dallas staff member.
         * Whoever owned that foreign number read what A1 pays each lender.
         */
        $this->inbound(self::BOT, $this->tap($this->section('Finance options'), 'Finance options'),
            'wamid.FOREIGN', from: '4695551234')->assertOk();

        $this->assertStringNotContainsString('3.9%', $this->sent[0]['text']['body'],
            'a foreign 10-digit number was treated as a US staff member');
    }

    public function test_the_same_staff_member_writing_from_their_real_number_is_let_in(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $this->configured();
        $this->number($t, self::BOT);
        $this->staff($t, '(469) 555-1234');

        // The positive control: the guard must not lock out the US number the
        // profile actually means. Without this, refusing everybody would pass.
        $this->inbound(self::BOT, $this->tap($this->section('Finance options'), 'Finance options'),
            'wamid.US', from: '14695551234')->assertOk();

        $this->assertStringContainsString('3.9%', $this->sent[0]['text']['body']);
    }

    public function test_staff_of_another_organization_are_not_staff_here(): void
    {
        $mine = $this->provisionTenant('A1-Group');
        $other = $this->provisionTenant('Premier-Garage');
        $this->configured();
        $this->number($mine, self::BOT);

        // Active, with this exact phone - but on somebody else's books.
        $this->staff($other, '(786) 776-2666');

        $this->inbound(self::BOT, $this->tap($this->section('Finance options'), 'Finance options'))->assertOk();

        $this->assertStringNotContainsString('3.9%', $this->sent[0]['text']['body']);
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
        $this->assertStringContainsString('Nothing matched', $this->sent[0]['interactive']['body']['text']);
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
        $this->assertStringContainsString('Nothing matched', $this->sent[0]['interactive']['body']['text']);
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
        $body = $this->sent[0]['interactive']['body']['text'];
        $this->assertStringContainsString('Nothing matched *Atlantis*', $body);
        // No cheerful welcome straight after a failed search.
        $this->assertStringNotContainsString('Welcome', $body);
    }

    public function test_a_typed_question_gets_the_answer_it_names(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $this->configured();
        $this->number($t, self::BOT);

        // Free, no AI (Jose, 2026-09-25): the words are looked up in the
        // Library. One answer clearly wins, so it is sent, not a list.
        $this->inbound(self::BOT, $this->text('what flue size do I need'))->assertOk();

        $this->assertCount(1, $this->sent);
        $this->assertSame('text', $this->sent[0]['type']);
        $this->assertStringContainsString('1/12 of the opening area', $this->sent[0]['text']['body']);
    }

    public function test_a_question_several_answers_fit_offers_them(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $this->configured();
        $this->number($t, self::BOT);

        $this->inbound(self::BOT, $this->text('level 2'))->assertOk();

        $this->assertSame('list', $this->sent[0]['interactive']['type']);
        $ids = collect($this->sent[0]['interactive']['action']['sections'])
            ->flatMap(fn ($s) => $s['rows'])->pluck('id')->all();
        $this->assertContains($this->entry('When a Level 2 is needed'), $ids);
        $this->assertContains($this->entry('What a Level 2 covers'), $ids);
    }

    public function test_typing_the_name_of_a_staff_answer_does_not_reveal_it_to_a_stranger(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $this->configured();
        $this->number($t, self::BOT);

        // The search runs over what THIS sender may read, not over the Library.
        $this->inbound(self::BOT, $this->text('finance options'), 'wamid.F1')->assertOk();
        $this->assertStringNotContainsString('3.9%', json_encode($this->sent));

        // The control: the same words from staff do find it.
        $this->staff($t);
        $this->sent = [];
        $this->inbound(self::BOT, $this->text('finance options'), 'wamid.F2')->assertOk();
        $this->assertStringContainsString('3.9%', $this->sent[0]['text']['body']);
    }

    public function test_the_replies_are_recorded_as_a_conversation(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $this->configured();
        $this->number($t, self::BOT);

        $this->inbound(self::BOT, $this->tap($this->section('CSIA'), 'CSIA'))->assertOk();

        $rows = WhatsAppMessage::query()->withoutGlobalScopes()->orderBy('created_at')->get();

        // The tap is recorded as what the person SAW, not as an opaque id, and
        // our answer sits beside it - so the history reads as a conversation.
        $this->assertSame('CSIA', $rows->firstWhere('direction', 'in')->body);
        $this->assertStringContainsString('Chimney & Venting Essentials', $rows->firstWhere('direction', 'out')->body);
    }

    /** Jose, 2026-10-12: Grok answers from the Library what the words did not match. */
    public function test_a_question_the_words_miss_is_answered_by_ai_off_the_webhook(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $this->configured();
        $this->number($t, self::BOT);
        config(['services.grok_cli.home' => '/var/lib/ezbizi-grok']);
        \Illuminate\Support\Facades\Process::fake(['*' => \Illuminate\Support\Facades\Process::result(json_encode(['structuredOutput' => [
            'answered' => true, 'reply' => 'Use the flue sizing chart in the Library.', 'entry_ids' => ['made-up-id'],
        ]]))]);

        $this->staff($t);
        $this->inbound(self::BOT, $this->text('who approves holiday requests'))->assertOk();

        $this->assertSame('text', $this->sent[0]['type']);
        $this->assertStringContainsString('Use the flue sizing chart', $this->sent[0]['text']['body']);
        $this->assertStringContainsString('Answered by AI', $this->sent[0]['text']['body']);
    }

    public function test_when_ai_has_no_answer_the_usual_nothing_matched_menu_follows(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $this->configured();
        $this->number($t, self::BOT);
        config(['services.grok_cli.home' => '/var/lib/ezbizi-grok']);
        \Illuminate\Support\Facades\Process::fake(['*' => \Illuminate\Support\Facades\Process::result(json_encode(['structuredOutput' => [
            'answered' => false, 'reply' => '', 'entry_ids' => [],
        ]]))]);

        $this->staff($t);
        $this->inbound(self::BOT, $this->text('what is the wifi password'))->assertOk();

        $this->assertStringContainsString('Nothing matched', $this->sent[0]['interactive']['body']['text']);
    }

    /** Meta review, 2026-10-13: a stranger has the intake; no xAI call per message from the public. */
    public function test_a_stranger_gets_the_menu_and_no_ai_call(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $this->configured();
        $this->number($t, self::BOT);
        config(['services.grok_cli.home' => '/var/lib/ezbizi-grok']);
        \Illuminate\Support\Facades\Process::fake();

        $this->inbound(self::BOT, $this->text('who approves holiday requests'))->assertOk();

        \Illuminate\Support\Facades\Process::assertNothingRan();
        $this->assertStringContainsString('Nothing matched', $this->sent[0]['interactive']['body']['text']);
    }

    /** Jose, 2026-10-13: Rodrigo's report-on-request, answered from Reports' figures. */
    public function test_a_figures_question_from_someone_on_reports_is_answered_from_the_figures(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $this->configured();
        $this->number($t, self::BOT);
        config(['services.grok_cli.home' => '/var/lib/ezbizi-grok']);
        \Illuminate\Support\Facades\Process::fake(['*' => \Illuminate\Support\Facades\Process::sequence()
            ->push(\Illuminate\Support\Facades\Process::result(json_encode(['structuredOutput' => ['is_report' => true, 'from' => '2026-10-05', 'to' => '2026-10-11', 'branch' => null]])))
            ->push(\Illuminate\Support\Facades\Process::result(json_encode(['structuredOutput' => ['reply' => 'Oct 5-11: 12 jobs done, $4,310 gross.']])))]);

        $this->staff($t);
        $this->inbound(self::BOT, $this->text('how much did we sell last week'))->assertOk();

        $this->assertStringContainsString('12 jobs done', $this->sent[0]['text']['body']);
        $this->assertStringContainsString('From EzBizi Reports', $this->sent[0]['text']['body']);
    }

    public function test_a_figures_question_from_someone_without_reports_goes_to_the_library(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $this->configured();
        $this->number($t, self::BOT);
        config(['services.grok_cli.home' => '/var/lib/ezbizi-grok']);
        \Illuminate\Support\Facades\Process::fake(['*' => \Illuminate\Support\Facades\Process::result(json_encode(['structuredOutput' => [
            'answered' => false, 'reply' => '', 'entry_ids' => [],
        ]]))]);
        $tech = $this->userWithRole($t, 'technician');
        $this->inTenant($t['org'], fn () => $tech->forceFill(['phone' => '(786) 776-2666', 'is_active' => true])->save());
        $this->assertFalse($this->inTenant($t['org'], fn () => $tech->hasPermission('reports.view')));

        $this->inbound(self::BOT, $this->text('how much did we sell last week'))->assertOk();

        // The Library's own answer, never the figures.
        $this->assertStringNotContainsString('From EzBizi Reports', json_encode($this->sent));
    }

    /** Jose, 2026-10-12: no Google Ads or LSA figures go to xAI. */
    public function test_google_local_services_leads_are_not_in_the_figures_sent_to_ai(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $branch = $this->inTenant($t['org'], fn () => \App\Models\Franchise::query()->firstOrFail());
        $this->inTenant($t['org'], function () use ($t, $branch) {
            foreach ([\App\Models\Lead::SOURCE_LOCAL_SERVICES, \App\Models\Lead::SOURCE_THUMBTACK] as $source) {
                \App\Models\Lead::create([
                    'organization_id' => $t['org']->id, 'franchise_id' => $branch->id, 'source' => $source,
                    'remote_id' => uniqid(), 'status' => 'new', 'received_at' => now(),
                ]);
            }
        });

        $counts = \App\Support\Ai\ReportAsk::leads($branch, now()->subDay()->toDateString(), now()->addDay()->toDateString());

        $this->assertSame([\App\Models\Lead::SOURCE_THUMBTACK => 1], $counts);
    }

    public function test_a_misread_period_is_clamped_to_a_year_ending_today(): void
    {
        $today = \Carbon\CarbonImmutable::parse('2026-10-13', 'America/New_York');
        $this->assertSame(['2025-10-13', '2026-10-13'], \App\Support\Ai\ReportAsk::period('1990-01-01', '2030-01-01', $today));
        $this->assertSame(['2026-10-01', '2026-10-13'], \App\Support\Ai\ReportAsk::period('nonsense', 'x', $today));
        $this->assertSame(['2026-10-05', '2026-10-11'], \App\Support\Ai\ReportAsk::period('2026-10-05', '2026-10-11', $today));
    }

    // ── A menu for each role (AI Assistance › Staff bot, UI queue 88) ─────

    /**
     * Office's menu for this tenant's admin role: a guide section, a Library
     * entry and a report nothing writes yet. Returns the three items.
     *
     * @return array{0: StaffBotItem, 1: StaffBotItem, 2: StaffBotItem}
     */
    private function roleMenu(array $t, ?string $roleId = null): array
    {
        return $this->inTenant($t['org'], function () use ($t, $roleId) {
            $guide = TrainingGuide::create([
                'company_id' => null, 'franchise_id' => null, 'title' => 'Dispatcher guide',
                'status' => TrainingGuide::PUBLISHED,
                'screens' => [['title' => 'Answering', 'chapter' => 'Dispatcher Essentials', 'section' => 'Customer Service',
                    'blocks' => [['type' => 'text', 'text' => 'Answer by the third ring, with the company name.']]]],
            ]);
            $menu = StaffBotMenu::create(['name' => 'Office', 'role_ids' => [$roleId ?? $t['admin']->role_id]]);
            $item = fn (int $p, array $a) => StaffBotItem::create(['organization_id' => $t['org']->id,
                'menu_id' => $menu->id, 'position' => $p, ...$a]);

            return [
                $item(1, ['title' => 'Dispatch guidelines', 'line' => 'How we answer', 'source' => 'guide',
                    'training_guide_id' => $guide->id, 'chapter' => 'Dispatcher Essentials', 'section' => 'Customer Service']),
                $item(2, ['title' => 'Codes', 'source' => 'library',
                    'assistant_entry_id' => AssistantEntry::query()->orderBy('position')->value('id')]),
                $item(3, ['title' => 'Daily conversion', 'source' => 'report', 'report' => 'daily_conversion']),
            ];
        });
    }

    public function test_a_role_with_its_own_menu_gets_that_menu(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $this->configured();
        $this->number($t, self::BOT);
        $this->staff($t);
        [$guide, $library, $report] = $this->roleMenu($t);

        $this->inbound(self::BOT, $this->text('hi'))->assertOk();

        $this->assertSame([
            WhatsAppStaffBot::ITEM.$guide->id,
            WhatsAppStaffBot::ITEM.$library->id,
            WhatsAppStaffBot::ITEM.$report->id,
            WhatsAppStaffBot::FIND_LOCATION,
        ], $this->menuIds());
        $this->assertSame('Office', $this->sent[0]['interactive']['action']['sections'][0]['title']);
    }

    public function test_a_row_answers_from_what_it_links_to(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $this->configured();
        $this->number($t, self::BOT);
        $this->staff($t);
        [$guide, $library, $report] = $this->roleMenu($t);
        $entryBody = $this->inTenant($t['org'], fn () => (string) AssistantEntry::query()->orderBy('position')->value('body'));

        // The guide's section, as the guide says it today.
        $this->inbound(self::BOT, $this->tap(WhatsAppStaffBot::ITEM.$guide->id, 'Dispatch guidelines'), 'wamid.IN1')->assertOk();
        $this->assertStringContainsString('*Dispatch guidelines*', $this->sent[0]['text']['body']);
        $this->assertStringContainsString('Answer by the third ring', $this->sent[0]['text']['body']);

        // The Library entry, read through AssistantBrain.
        $this->inbound(self::BOT, $this->tap(WhatsAppStaffBot::ITEM.$library->id, 'Codes'), 'wamid.IN2')->assertOk();
        $this->assertStringContainsString(mb_substr($entryBody, 0, 40), $this->sent[1]['text']['body']);

        // Nothing writes the report yet: it says so rather than going quiet.
        $this->inbound(self::BOT, $this->tap(WhatsAppStaffBot::ITEM.$report->id, 'Daily conversion'), 'wamid.IN3')->assertOk();
        $this->assertStringContainsString('coming soon', $this->sent[2]['text']['body']);
    }

    public function test_another_roles_menu_is_neither_shown_nor_answered(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $this->configured();
        $this->number($t, self::BOT);
        $this->staff($t);
        $technician = $this->userWithRole($t, 'Technician', ['leads.view']);
        [$guide] = $this->roleMenu($t, $technician->role_id);

        // The admin's role is in no menu: the Library sections, as before.
        $this->inbound(self::BOT, $this->text('hi'), 'wamid.IN1')->assertOk();
        $this->assertNotContains(WhatsAppStaffBot::ITEM.$guide->id, $this->menuIds());
        $this->assertContains(WhatsAppStaffBot::FIND_LOCATION, $this->menuIds());

        // And a technician's row, tapped by somebody else, is refused.
        $this->inbound(self::BOT, $this->tap(WhatsAppStaffBot::ITEM.$guide->id, 'Dispatch guidelines'), 'wamid.IN2')->assertOk();
        $this->assertStringContainsString('for staff only', $this->sent[1]['text']['body']);
        $this->assertStringNotContainsString('third ring', $this->sent[1]['text']['body']);
    }
}
