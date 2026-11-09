<?php

namespace Tests\Feature\WhatsApp;

use App\Models\WhatsAppGroup;
use App\Models\WhatsAppNumber;
use App\Support\Automations\ActionRunner;
use App\Support\Social\WhatsAppGroups;
use App\Support\Social\WhatsAppStaffBot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Jose, 2026-11-09: "make all the Reports use the Reports Number, so we can
 * leave the EzBizi - Main for the AI Support Questions and use the EzBizi -
 * Authentication one for well authentications".
 */
class WhatsAppNumberPurposeTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private const MAIN = '1309270845610102';

    private const REPORTS = '1366054836581646';

    /** @var list<array<string, mixed>> */
    private array $sent = [];

    private array $t;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.meta.whatsapp_token' => 'a-token-for-the-fake', 'services.meta.whatsapp_bot_number_id' => null]);
        Http::fake(function ($request) {
            $this->sent[] = ['url' => $request->url(), ...(str_contains($request->url(), '/media') ? [] : $request->data())];

            return str_contains($request->url(), '/media')
                ? Http::response(['id' => 'MEDIA-1'])
                : Http::response(['messages' => [['id' => 'wamid.OUT'.count($this->sent)]]]);
        });

        $this->t = $this->provisionTenant('A1-Group');
        foreach ([[self::MAIN, '+19545162455', 'support'], [self::REPORTS, '+18774508572', 'reports']] as [$id, $display, $purpose]) {
            DB::table('whatsapp_numbers')->insert([
                'id' => (string) Str::uuid(), 'organization_id' => $this->t['org']->id, 'phone_number_id' => $id,
                'waba_id' => 'waba-'.$id, 'display_number' => $display, 'purpose' => $purpose, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function test_a_groups_report_goes_to_each_member_from_the_reports_number(): void
    {
        $group = $this->inTenant($this->t['org'], fn () => WhatsAppGroup::create([
            'phone_number_id' => self::MAIN, 'purpose' => 'daily:NJ', 'subject' => 'A1 NJ - Daily Performance',
        ]));
        $this->member($group);

        $this->inTenant($this->t['org'], fn () => app(WhatsAppGroups::class)->deliver('daily:NJ', 'A1 NJ Same-Day Report', 'NJ: 50 jobs'));

        $this->assertSame([self::REPORTS], $this->senders());
    }

    public function test_a_real_group_stays_on_the_number_that_made_it(): void
    {
        $this->inTenant($this->t['org'], fn () => WhatsAppGroup::create([
            'phone_number_id' => self::MAIN, 'purpose' => 'daily:NJ', 'subject' => 'A1 NJ', 'remote_id' => 'GROUP-1',
        ]));

        $this->inTenant($this->t['org'], fn () => app(WhatsAppGroups::class)->deliver('daily:NJ', 'A1 NJ Same-Day Report', 'NJ: 50 jobs'));

        $this->assertSame([self::MAIN], $this->senders());
    }

    public function test_a_rules_report_text_goes_from_the_reports_number_whatever_the_rule_names(): void
    {
        $user = $this->userWithRole($this->t, 'Manager', ['reports.view']);
        $this->inTenant($this->t['org'], fn () => $user->forceFill(['phone' => '(305) 724-2199'])->save());

        $sent = $this->inTenant($this->t['org'], fn () => ActionRunner::deliverReportText('+19545162455', [$user->id], null, null, 'Daily sales', 'Sales: $1,200'));

        $this->assertSame(1, $sent);
        $this->assertSame([self::REPORTS], $this->senders());
    }

    public function test_a_file_to_one_person_keeps_its_type(): void
    {
        $user = $this->userWithRole($this->t, 'Manager', ['reports.view']);
        $this->inTenant($this->t['org'], fn () => $user->forceFill(['phone' => '(305) 724-2199'])->save());

        $this->inTenant($this->t['org'], fn () => app(WhatsAppGroups::class)->deliverDocumentsToUser(
            WhatsAppNumber::forPurpose('reports'), $user, 'CT October', [[
                'title' => 'CT October', 'pdf' => 'xlsx-bytes', 'filename' => 'ct-october.xlsx',
                'type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ]]));

        Http::assertSent(fn ($r) => str_contains($r->url(), self::REPORTS.'/media')
            && str_contains($r->body(), 'spreadsheetml'));
    }

    public function test_the_staff_bot_answers_on_the_support_number_only(): void
    {
        $bot = app(WhatsAppStaffBot::class);

        $this->assertTrue($bot->handles(self::MAIN));
        $this->assertFalse($bot->handles(self::REPORTS));
    }

    /** @return list<string> the phone number ids that sent a message */
    private function senders(): array
    {
        return collect($this->sent)->pluck('url')->filter(fn ($u) => str_ends_with($u, '/messages'))
            ->map(fn ($u) => explode('/', $u)[4])->unique()->values()->all();
    }

    private function member(WhatsAppGroup $group): void
    {
        $user = $this->userWithRole($this->t, 'Manager '.Str::random(4), ['leads.view']);
        $this->inTenant($this->t['org'], function () use ($group, $user) {
            $user->forceFill(['phone' => '(305) 724-2199'])->save();
            $group->members()->attach($user->id, ['id' => (string) Str::uuid(), 'organization_id' => $this->t['org']->id]);
        });
    }
}
