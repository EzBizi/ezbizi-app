<?php

namespace Tests\Feature\Reports;

use App\Models\Automation;
use App\Models\AutomationEvent;
use App\Models\ReportEmail;
use App\Models\WhatsAppMessage;
use App\Support\Automations\ActionRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Reports › Reports lists every report EzBizi sends and opens what each sent
 * (Jose, 2026-11-25: "right now we are sending out a lot of reports on
 * whatsapp, and yet they can't be seen on the app. the reports tab is showing only 2").
 */
class SentReportsTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_a_rules_report_is_listed_and_opens_onto_what_it_sent_on_whatsapp_and_by_email(): void
    {
        config(['services.meta.whatsapp_token' => 'a-token-for-the-fake', 'mail.platform_mailer' => 'array']);
        Http::fake(['*/media' => Http::response(['id' => 'MEDIA-1']), '*' => fn () => Http::response(['messages' => [['id' => 'wamid.'.Str::random(10)]]])]);
        $t = $this->provisionTenant('A1-Group');
        DB::table('whatsapp_numbers')->insert(['id' => (string) Str::uuid(), 'organization_id' => $t['org']->id,
            'phone_number_id' => '1309270845610102', 'waba_id' => '1090541950244647', 'display_number' => '+19545162455',
            'created_at' => now(), 'updated_at' => now()]);

        [$text, $mail] = $this->inTenant($t['org'], function () use ($t) {
            $t['admin']->forceFill(['phone' => '+13055550123'])->save();
            $event = new AutomationEvent(['event_key' => 'automation.schedule_reached', 'payload' => []]);
            $rule = function (string $name, array $step) use ($t, $event) {
                $rule = Automation::create(['name' => $name, 'trigger_key' => 'automation.schedule_reached', 'is_active' => true, 'created_by' => $t['admin']->id]);
                $rule->addVersion(null, [$step], $t['admin']);
                $done = app(ActionRunner::class)->run($step, $event, null, automation: $rule);
                $this->assertTrue($done['ok'], $done['detail']);

                return $rule;
            };

            return [
                $rule('Weekly reviews', ['type' => 'report.text', 'kind' => 'weekly_reviews', 'user_ids' => [$t['admin']->id]]),
                $rule('Office email', ['type' => 'email.send', 'to_field' => 'office@example.test', 'subject' => 'Overbooking', 'body' => 'Two areas are full.']),
                // To the customer: a message, not a report.
                tap(Automation::create(['name' => 'Booking confirmed', 'trigger_key' => 'lead.booking_approved', 'is_active' => true, 'created_by' => $t['admin']->id]),
                    fn (Automation $r) => $r->addVersion(null, [['type' => 'whatsapp.send', 'to_field' => 'customer:', 'template' => 'booking_confirmation']], $t['admin'])),
            ];
        });

        // The message names its rule and keeps what it said.
        $message = WhatsAppMessage::withoutGlobalScopes()->sole();
        $this->assertSame('automation:'.$text->id, $message->report);
        $this->assertStringContainsString('Weekly reviews by branch', (string) $message->body);
        $email = ReportEmail::withoutGlobalScopes()->sole();
        $this->assertSame(['automation:'.$mail->id, ['office@example.test'], 'Overbooking'], [$email->report, $email->recipients, $email->subject]);
        $this->assertStringContainsString('Two areas are full.', (string) $email->body);

        $list = $this->signedInAs($t['admin'])->getJson('/api/v1/reports/sent')->assertOk()->json('data');
        $byKey = collect($list)->keyBy('key');
        $this->assertEqualsCanonicalizing(['automation:'.$mail->id, 'automation:'.$text->id], $byKey->keys()->all(),
            'the two report rules, not the customer message');
        $this->assertSame(['Weekly reviews', 'Weekly reviews by branch', ['whatsapp'], 1], [
            $byKey['automation:'.$text->id]['name'], $byKey['automation:'.$text->id]['about'],
            $byKey['automation:'.$text->id]['channels'], $byKey['automation:'.$text->id]['sent_7d'],
        ]);
        $this->assertSame([$t['admin']->name], $byKey['automation:'.$text->id]['to']);
        $this->assertSame([['email'], ['office@example.test'], 1], [
            $byKey['automation:'.$mail->id]['channels'], $byKey['automation:'.$mail->id]['to'], $byKey['automation:'.$mail->id]['sent_7d'],
        ]);

        $history = $this->signedInAs($t['admin'])->getJson('/api/v1/reports/sent/history?report=automation:'.$text->id)->assertOk();
        $this->assertSame(1, $history->json('total'));
        $this->assertSame([$t['admin']->name, 'whatsapp'], [$history->json('data.0.to'), $history->json('data.0.channel')]);
        $this->assertStringContainsString('Weekly reviews by branch', (string) $history->json('data.0.body'));
        $this->assertStringContainsString('Two areas are full.', (string) $this->signedInAs($t['admin'])
            ->getJson('/api/v1/reports/sent/history?report=automation:'.$mail->id)->json('data.0.body'));
    }

    public function test_only_a_step_to_staff_is_a_report(): void
    {
        $rule = new Automation;
        $rule->id = 'r1';

        foreach ([
            ['report.text', [], true],
            ['whatsapp.group_send', ['group' => 'weekly_summary'], true],
            ['whatsapp.send', ['to_field' => 'user:abc'], true],
            ['whatsapp.send', ['to_field' => 'branch_managers:'], true],
            ['email.send', ['to_field' => 'office@example.test'], true],
            ['whatsapp.send', ['to_field' => 'customer:'], false],
            ['email.send', ['to_field' => '{{lead.email}}'], false],
            ['sms.send', ['to' => 'customer:'], false],
            ['update_field', [], false],
            // A list where one recipient is read: no warning, not a report.
            ['notify', ['to' => ['user:a', 'user:b']], false],
            ['whatsapp.send', ['to' => ['user:a']], false],
        ] as [$type, $step, $report]) {
            $this->assertSame($report ? 'automation:r1' : null, ActionRunner::reportKey($type, $step, $rule), $type.json_encode($step));
        }
        $this->assertNull(ActionRunner::reportKey('report.text', [], null), 'no rule, no report key');
    }

    /** Audit 2026-11-27: a branch read every branch's report texts. */
    public function test_a_branch_view_reads_only_the_reports_filed_on_that_branch(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $other = $this->extraFranchise($t, 'A1 F2');
        [$here, $there, $group] = $this->inTenant($t['org'], fn () => array_map(function (array $place) use ($t) {
            $rule = Automation::create(['name' => 'Report '.($place[1] ?? 'group'), 'trigger_key' => 'automation.schedule_reached',
                'is_active' => true, 'created_by' => $t['admin']->id, 'scope_type' => $place[0], 'scope_id' => $place[1]]);
            $rule->addVersion(null, [['type' => 'email.send', 'to_field' => 'office@example.test', 'subject' => 'Figures', 'body' => 'x']], $t['admin']);

            return 'automation:'.$rule->id;
        }, [['franchise', $t['franchise']->id], ['franchise', $other->id], [null, null]]));
        $as = fn (string $view) => $this->signedInAs($t['admin'])->withHeader('X-EzBizi-View', $view);
        $branch = 'franchise:'.$t['franchise']->id;

        $this->assertSame([$here], array_column($as($branch)->getJson('/api/v1/reports/sent')->assertOk()->json('data'), 'key'));
        $this->assertEqualsCanonicalizing([$here, $there, $group],
            array_column($as('organization')->getJson('/api/v1/reports/sent')->assertOk()->json('data'), 'key'));
        $as($branch)->getJson('/api/v1/reports/sent/history?report='.$here)->assertOk();
        foreach ([$there, $group, 'same-day'] as $key) {
            $as($branch)->getJson('/api/v1/reports/sent/history?report='.$key)->assertNotFound();
        }
    }

    public function test_the_list_and_its_history_are_the_librarys(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $viewer = $this->userWithRole($t, 'Viewer', ['tasks.view']);

        $this->signedInAs($viewer)->getJson('/api/v1/reports/sent')->assertForbidden();
        $this->signedInAs($viewer)->getJson('/api/v1/reports/sent/history?report=same-day')->assertForbidden();
        $this->signedInAs($t['admin'])->getJson('/api/v1/reports/sent/history?report=bad key')->assertUnprocessable();
    }
}
