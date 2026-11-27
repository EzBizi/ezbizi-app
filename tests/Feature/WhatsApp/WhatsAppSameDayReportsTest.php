<?php

namespace Tests\Feature\WhatsApp;

use App\Models\WhatsAppGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** The nightly Same-Day Reports, each to its group, once a day. */
class WhatsAppSameDayReportsTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_each_branch_and_the_summary_go_to_their_groups_once(): void
    {
        config(['services.meta.whatsapp_token' => 'a-token']);
        $sent = [];
        Http::fake(function ($request) use (&$sent) {
            $sent[] = $request->data();

            return Http::response(['id' => 'MEDIA-'.count($sent), 'messages' => [['id' => 'wamid.OUT'.count($sent)]]]);
        });

        $t = $this->provisionTenant('A1-Group');
        DB::table('whatsapp_numbers')->insert([
            'id' => (string) Str::uuid(), 'organization_id' => $t['org']->id,
            'phone_number_id' => '1309270845610102', 'waba_id' => '1090541950244647',
            'display_number' => '+19545162455', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->inTenant($t['org'], function () use ($t) {
            WhatsAppGroup::create(['phone_number_id' => '1309270845610102', 'purpose' => 'daily:'.$t['franchise']->id,
                'subject' => 'A1 Daily Performance', 'remote_id' => 'GROUP-DAILY']);
            WhatsAppGroup::create(['phone_number_id' => '1309270845610102', 'purpose' => 'financial',
                'subject' => 'A1 Financial Reports', 'remote_id' => 'GROUP-FIN']);
        });

        $this->artisan('ezbizi:whatsapp-same-day-reports', ['--date' => '2026-10-05'])->assertSuccessful();
        $this->artisan('ezbizi:whatsapp-same-day-reports', ['--date' => '2026-10-05'])->assertSuccessful();

        // Each group ONE message, the PDF under its text (Jose, 2026-11-10); once.
        $posts = array_values(array_filter($sent, fn ($m) => isset($m['to'])));
        $this->assertSame([['GROUP-DAILY', 'document'], ['GROUP-FIN', 'document']], array_map(fn ($m) => [$m['to'], $m['type']], $posts));
        $this->assertStringContainsString('Same-Day Report', $posts[0]['document']['caption']);
        $this->assertStringContainsString('Same-Day Company Summary', $posts[1]['document']['caption']);
    }
}
