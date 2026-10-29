<?php

namespace Tests\Feature\Social;

use App\Jobs\SubscribeMetaMessages;
use App\Models\AutomationEvent;
use App\Models\Lead;
use App\Models\SocialConnection;
use App\Support\Social\SocialProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * A submitted Facebook lead-ad form becomes a "Meta Form" lead (Jose,
 * 2026-10-06). The webhook carries an id; the answers are read with the Page's
 * token.
 */
class MetaLeadFormsTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private const PAGE_ID = '102938475610293';

    private const SECRET = 'app-secret-from-meta';

    public function test_a_submitted_form_becomes_one_meta_form_lead(): void
    {
        config(['services.meta.client_secret' => self::SECRET]);
        $t = $this->provisionTenant('A1-Group');
        $this->page($t);

        Http::fake(['graph.facebook.com/*/444555666*' => Http::response([
            'created_time' => '2026-10-06T15:00:00+0000',
            'platform' => 'fb',
            'field_data' => [
                ['name' => 'full_name', 'values' => ['Dana Reyes']],
                ['name' => 'phone_number', 'values' => ['+13055550199']],
                ['name' => 'email', 'values' => ['dana@example.com']],
                ['name' => 'zip_code', 'values' => ['33009']],
                ['name' => 'what_service_do_you_need?', 'values' => ['Chimney sweep']],
            ],
        ])]);

        $this->deliver()->assertOk()->assertJsonPath('data.handled', 1);
        $this->deliver()->assertOk()->assertJsonPath('data.handled', 0); // Meta's retry

        $lead = Lead::query()->withoutGlobalScopes()->sole();
        $this->assertSame(Lead::SOURCE_FACEBOOK, $lead->source);
        $this->assertSame('FORM', $lead->lead_type);
        $this->assertSame('New "Meta Form" lead', $lead->lead_headline);
        $this->assertSame('Dana Reyes', $lead->contact_name);
        $this->assertSame('+13055550199', $lead->phone);
        $this->assertSame('dana@example.com', $lead->email);
        $this->assertSame('33009', $lead->postal_code);
        $this->assertSame('What service do you need?: Chimney sweep', $lead->summary);
        $this->assertSame(1, AutomationEvent::query()->withoutGlobalScopes()
            ->where('event_key', 'lead.received')->where('subject_id', $lead->id)->count());
    }

    public function test_a_page_that_refuses_leadgen_still_gets_its_messages(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $page = $this->page($t);

        Http::fake(fn ($request) => str_contains((string) $request['subscribed_fields'], 'leadgen')
            ? Http::response(['error' => ['message' => 'leads_retrieval not granted']], 403)
            : Http::response(['success' => true]));

        (new SubscribeMetaMessages($page->getKey()))->handle();

        Http::assertSent(fn ($request) => $request['subscribed_fields'] === 'messages,messaging_postbacks');
    }

    /** A-1, 2026-10-29: a lead ad that opens Messenger made two leads per person. */
    public function test_a_form_then_the_chat_it_opens_are_one_lead(): void
    {
        $this->formAndChat(formFirst: true);
    }

    public function test_a_chat_then_its_form_are_one_lead(): void
    {
        $this->formAndChat(formFirst: false);
    }

    private function formAndChat(bool $formFirst): void
    {
        config(['services.meta.client_secret' => self::SECRET]);
        $t = $this->provisionTenant('A1-Group');
        $this->page($t);
        Http::fake(['graph.facebook.com/*' => Http::response([
            'platform' => 'fb',
            'field_data' => [
                ['name' => 'full_name', 'values' => ['N Patterson']],
                ['name' => 'phone_number', 'values' => ['+19736782560']],
                ['name' => 'zip_code', 'values' => ['07050']],
            ],
        ])]);
        $chat = fn () => $this->post_('page', ['entry' => [['id' => self::PAGE_ID, 'messaging' => [[
            'sender' => ['id' => 'PSID1'], 'recipient' => ['id' => self::PAGE_ID], 'timestamp' => 1789000000000,
            'message' => ['mid' => 'm_1', 'text' => 'Hello! I filled out your form.
Full name: N Patterson
Phone number: (973) 678-2560
Zip code: 07050'],
        ]]]]]);

        if ($formFirst) {
            $this->deliver()->assertOk();
            $chat();
        } else {
            $chat();
            $this->deliver()->assertOk();
        }

        $lead = Lead::query()->withoutGlobalScopes()->sole();
        $this->assertSame('PSID1', $lead->remote_id);
        $this->assertSame('MESSAGE', $lead->lead_type);
        $this->assertStringEndsWith('9736782560', (string) $lead->phone_digits);
        $this->assertSame('07050', $lead->postal_code);
        $this->assertContains(['label' => 'Zip code', 'value' => '07050'], $lead->form_answers);
        $this->assertNotNull($lead->form_submitted_at);
    }

    private function post_(string $object, array $body)
    {
        $json = (string) json_encode(['object' => $object, ...$body]);

        return $this->call('POST', '/api/v1/social/meta/messages/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $json, self::SECRET),
        ], $json)->assertOk();
    }

    private function page(array $t): SocialConnection
    {
        return $this->inTenant($t['org'], function () use ($t) {
            $row = new SocialConnection;
            $row->forceFill([
                'organization_id' => $t['org']->id,
                'scope_type' => SocialConnection::SCOPE_ORGANIZATION,
                'scope_id' => $t['org']->id,
                'provider' => SocialProvider::FacebookPage->value,
                'remote_id' => self::PAGE_ID,
                'display_name' => 'A-1 Chimney',
            ]);
            $row->connect(['access_token' => 'page-token']);
            $row->save();

            return $row;
        });
    }

    private function deliver()
    {
        $json = (string) json_encode(['object' => 'page', 'entry' => [[
            'id' => self::PAGE_ID,
            'changes' => [['field' => 'leadgen', 'value' => [
                'leadgen_id' => '444555666', 'page_id' => self::PAGE_ID, 'form_id' => '777',
            ]]],
        ]]]);

        return $this->call('POST', '/api/v1/social/meta/messages/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $json, self::SECRET),
        ], $json);
    }
}
