<?php

namespace Tests\Feature\WhatsApp;

use App\Models\User;
use App\Models\WhatsAppGroup;
use App\Models\WhatsAppMessage;
use App\Models\WhatsAppReportBatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Weekly PDFs go to the approver first; their APPROVE sends every branch its
 * PDF and the consolidated one to the financial group (Rodrigo bot row C).
 */
class WhatsAppPeriodReportsTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private const NUMBER = '1309270845610102';

    private const SECRET = 'app-secret-from-meta';

    private const APPROVER = '13055550111';

    /** @var list<array<string, mixed>> */
    private array $sent = [];

    public function test_the_approver_approves_and_every_group_gets_its_pdf_once(): void
    {
        $merav = $this->approvalSetUp();

        $this->artisan('ezbizi:whatsapp-period-reports', ['period' => 'week'])->assertSuccessful();
        $this->artisan('ezbizi:whatsapp-period-reports', ['period' => 'week'])->assertSuccessful(); // asked once

        $this->assertSame(['document', 'text'], array_column($this->sent, 'type'));
        $this->assertSame(self::APPROVER, $this->sent[0]['to']);
        $this->assertStringContainsString('APPROVE', $this->sent[1]['text']['body']);

        $this->sent = [];
        $this->inbound('Approved');
        $this->inbound('approve'); // a second yes sends nothing more

        $documents = array_values(array_filter($this->sent, fn ($m) => $m['type'] === 'document'));
        $this->assertSame(['GROUP-DAILY', 'GROUP-FIN'], array_column($documents, 'to'));
        $this->assertNotNull(WhatsAppReportBatch::query()->withoutGlobalScopes()->sole()->approved_at);
        $this->assertSame($merav->id, WhatsAppReportBatch::query()->withoutGlobalScopes()->sole()->approved_by);
    }

    /** 2026-10-19: HOLD keeps it back; SEPARATELY sends each PDF privately, nothing to the groups. */
    public function test_hold_keeps_it_back_and_separately_sends_each_pdf_only_to_the_approver(): void
    {
        $this->approvalSetUp();

        $this->artisan('ezbizi:whatsapp-period-reports', ['period' => 'week'])->assertSuccessful();
        $this->assertStringContainsString('SEPARATELY', $this->sent[1]['text']['body']);
        $this->assertStringContainsString('HOLD', $this->sent[1]['text']['body']);

        $this->sent = [];
        $this->inbound('Hold');
        $this->assertNotNull(WhatsAppReportBatch::query()->withoutGlobalScopes()->sole()->held_at);
        $this->assertSame([], array_values(array_filter($this->sent, fn ($m) => $m['type'] === 'document')));
        $this->artisan('ezbizi:whatsapp-period-reports', ['period' => 'week'])->assertSuccessful();
        $this->assertSame([], array_values(array_filter($this->sent, fn ($m) => $m['type'] === 'document')), 'a held batch is not previewed again');

        $this->sent = [];
        $this->inbound('send separately');
        $documents = array_values(array_filter($this->sent, fn ($m) => $m['type'] === 'document'));
        $this->assertCount(2, $documents, 'the branch PDF and the consolidated one');
        $this->assertSame([self::APPROVER, self::APPROVER], array_column($documents, 'to'));
        $this->assertNull(WhatsAppReportBatch::query()->withoutGlobalScopes()->sole()->approved_at, 'still not approved');

        $this->sent = [];
        $this->inbound('APPROVE');
        $documents = array_values(array_filter($this->sent, fn ($m) => $m['type'] === 'document'));
        $this->assertSame(['GROUP-DAILY', 'GROUP-FIN'], array_column($documents, 'to'));
    }

    private function approvalSetUp(): User
    {
        config([
            'services.meta.client_secret' => self::SECRET,
            'services.meta.whatsapp_token' => 'a-token',
            'services.meta.whatsapp_bot_number_id' => self::NUMBER,
        ]);
        Http::fake(function ($request) {
            if (str_ends_with($request->url(), '/media')) {
                return Http::response(['id' => 'MEDIA-'.count($this->sent)]);
            }
            if (str_ends_with($request->url(), '/messages')) {
                $this->sent[] = $request->data();

                return Http::response(['messages' => [['id' => 'wamid.OUT'.Str::random(10)]]]);
            }

            return Http::response([], 404); // Airtable and anything else: nothing there
        });

        $t = $this->provisionTenant('A1-Group');
        DB::table('whatsapp_numbers')->insert([
            'id' => (string) Str::uuid(), 'organization_id' => $t['org']->id, 'phone_number_id' => self::NUMBER,
            'waba_id' => '1090541950244647', 'display_number' => '+19545162455', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $merav = $this->userWithRole($t, 'Bookkeeper', ['leads.view']);
        $this->inTenant($t['org'], function () use ($t, $merav) {
            $merav->forceFill(['phone' => '(305) 555-0111'])->save();
            $approval = WhatsAppGroup::create(['phone_number_id' => self::NUMBER, 'purpose' => 'approval', 'subject' => 'Approval']);
            $approval->members()->attach($merav->id, ['id' => (string) Str::uuid(), 'organization_id' => $t['org']->id]);
            WhatsAppGroup::create(['phone_number_id' => self::NUMBER, 'purpose' => 'daily:'.$t['franchise']->id, 'subject' => 'Daily', 'remote_id' => 'GROUP-DAILY']);
            WhatsAppGroup::create(['phone_number_id' => self::NUMBER, 'purpose' => 'financial', 'subject' => 'Financial', 'remote_id' => 'GROUP-FIN']);
            // She wrote today, so the preview reaches her as a document, not a template.
            WhatsAppMessage::create(['phone_number_id' => self::NUMBER, 'direction' => 'in', 'counterparty' => self::APPROVER,
                'status' => 'received', 'kind' => 'text', 'body' => 'hi']);
        });

        return $merav;
    }

    private function inbound(string $text): void
    {
        $json = (string) json_encode(['entry' => [['changes' => [['value' => [
            'metadata' => ['phone_number_id' => self::NUMBER],
            'messages' => [['id' => 'wamid.IN'.Str::random(6), 'from' => self::APPROVER, 'type' => 'text', 'text' => ['body' => $text]]],
        ]]]]]]);

        $this->call('POST', '/api/v1/social/whatsapp/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $json, self::SECRET),
        ], $json)->assertOk();
    }
}
