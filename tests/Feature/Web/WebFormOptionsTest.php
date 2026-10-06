<?php

namespace Tests\Feature\Web;

use App\Models\LeadConversation;
use App\Models\WebSite;
use App\Models\WebWidget;
use App\Support\Web\FormChallenge;
use App\Support\Web\WidgetDefaults;
use App\Support\Web\WidgetPresets;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Toula's remaining widget rows (2026-09-29): the form's file upload,
 * show-only-when questions, notification emails and a real CAPTCHA, and the
 * option lists for more contact numbers, section icons and custom platforms.
 */
class WebFormOptionsTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_a_captcha_form_refuses_an_unsolved_or_reused_answer(): void
    {
        [$site] = $this->siteWithForm(['captcha' => true]);

        $this->postJson('/api/v1/web/form', $this->enquiry($site))->assertStatus(422);

        $solved = $this->solve($this->getJson('/api/v1/web/form/challenge')->assertOk()->json());
        $this->postJson('/api/v1/web/form', $this->enquiry($site) + ['captcha' => $solved])->assertCreated();

        // One solved puzzle is one enquiry, not a thousand.
        $this->postJson('/api/v1/web/form', $this->enquiry($site) + ['captcha' => $solved])->assertStatus(422);
    }

    /** Jose, 2026-10-06: "Activate captcha on all websites" — every form and chat start, no switch. */
    public function test_with_captcha_everywhere_a_form_and_a_chat_need_the_answer(): void
    {
        config(['ezbizi.web_form_captcha' => true]);
        [$site] = $this->siteWithForm();

        $this->postJson('/api/v1/web/form', $this->enquiry($site))->assertStatus(422);
        $this->postJson('/api/v1/web/chat', ['key' => $site->public_key, 'message' => 'Hi'])->assertStatus(422);

        $this->postJson('/api/v1/web/form', $this->enquiry($site)
            + ['captcha' => $this->solve($this->getJson('/api/v1/web/form/challenge')->json())])->assertCreated();
        $this->postJson('/api/v1/web/chat', ['key' => $site->public_key, 'message' => 'Hi',
            'captcha' => $this->solve($this->getJson('/api/v1/web/form/challenge')->json())])->assertCreated();
    }

    public function test_a_forged_challenge_is_refused(): void
    {
        [$site] = $this->siteWithForm(['captcha' => true]);
        $salt = 'abc?expires='.(time() + 60);
        $forged = json_encode(['salt' => $salt, 'number' => 1, 'challenge' => hash('sha256', $salt.'1'), 'signature' => str_repeat('0', 64)]);

        $this->postJson('/api/v1/web/form', $this->enquiry($site) + ['captcha' => $forged])->assertStatus(422);
    }

    public function test_an_uploaded_file_reaches_the_enquiry_behind_a_signed_link(): void
    {
        Storage::fake('local');
        [$site, $tenant] = $this->siteWithForm();

        $this->post('/api/v1/web/form', $this->enquiry($site) + [
            'files' => ['Photo' => UploadedFile::fake()->image('chimney.jpg')],
        ], ['Accept' => 'application/json'])->assertCreated();

        $row = $this->inTenant($tenant['org'], fn () => LeadConversation::query()->firstOrFail());
        $this->assertStringContainsString('Photo: chimney.jpg', $row->text);
        $this->assertCount(1, $row->attachments);

        $this->get($row->attachments[0])->assertOk();
        $this->get(strtok($row->attachments[0], '?'))->assertForbidden();
    }

    public function test_the_form_emails_its_recipients_and_the_website_never_sees_them(): void
    {
        [$site] = $this->siteWithForm(['notify' => 'office@example.com, not-an-email']);

        $this->postJson('/api/v1/web/form', $this->enquiry($site))->assertCreated();

        $sent = app('mailer')->getSymfonyTransport()->messages();
        $this->assertCount(1, $sent);
        $this->assertSame(['office@example.com'], array_map(fn ($a) => $a->getAddress(), $sent[0]->getOriginalMessage()->getTo()));
        $this->assertStringContainsString('Service: Sweep', $sent[0]->getOriginalMessage()->getTextBody());

        $published = WidgetDefaults::published(WidgetDefaults::resolve(['form' => ['notify' => 'office@example.com']]));
        $this->assertArrayNotHasKey('notify', $published['form']);
    }

    public function test_a_question_can_wait_for_another_answer_and_a_file_is_a_question_type(): void
    {
        $form = WidgetDefaults::resolve(['form' => ['fields' => [
            ['label' => 'Service', 'type' => 'select', 'options' => ['Chimney', 'Dryer vent']],
            ['label' => 'Which chimney?', 'type' => 'text', 'show_if' => ['field' => 'Service', 'value' => 'Chimney']],
            ['label' => 'Photo', 'type' => 'file'],
        ]]])['form']['fields'];

        $this->assertSame(['field' => 'Service', 'value' => 'Chimney'], $form[1]['show_if']);
        $this->assertSame('file', $form[2]['type']);
    }

    public function test_contact_and_social_take_their_new_options(): void
    {
        $p = WidgetPresets::resolve(['contact_info' => ['default' => [
            'extra_phones' => 'Fax | 555-0101; Office | 555-0102', 'phone_icon' => 'mobile', 'email_icon' => 'bogus',
        ]], 'social_links' => ['default' => ['custom' => 'Angi | https://angi.com/x | https://x.test/a.png']]]);

        $this->assertSame('Fax | 555-0101; Office | 555-0102', $p['contact_info']['default']['extra_phones']);
        $this->assertSame('mobile', $p['contact_info']['default']['phone_icon']);
        $this->assertSame('auto', $p['contact_info']['default']['email_icon']);
        $this->assertSame('Angi | https://angi.com/x | https://x.test/a.png', $p['social_links']['default']['custom']);
    }

    /** Toula, 2026-10-01: each chat widget's own Send, Start and focus colours. */
    public function test_a_chat_keeps_its_own_button_and_focus_colours(): void
    {
        $chat = WidgetDefaults::resolve(['chat' => [
            'send_bg' => '#123456', 'start_bg' => '#ABCDEF', 'focus_color' => 'red; }',
        ]])['chat'];

        $this->assertSame('#123456', $chat['send_bg']);
        $this->assertSame('#abcdef', strtolower($chat['start_bg']));
        $this->assertSame('', $chat['focus_color'], 'not a colour, so the accent');
    }

    /** @return array{0: WebSite, 1: array} */
    private function siteWithForm(array $form = []): array
    {
        $tenant = $this->provisionTenant('Alpha');
        $editor = $this->userWithRole($tenant, 'Web Editor',
            ['web_management.view', 'web_management.edit', 'web_management.seo.view', 'web_management.widgets.view']);
        $id = $this->signedInAs($editor)->postJson('/api/v1/web/sites', [
            'company_id' => $tenant['company']->getKey(), 'host' => 'a-1chimney.com',
        ])->assertCreated()->json('data.id');
        $this->signedInAs($editor)->putJson("/api/v1/web/sites/{$id}/widgets", ['config' => ['form' => $form]])->assertOk();
        $site = $this->inTenant($tenant['org'], fn () => WebSite::findOrFail($id));
        $this->assertSame((bool) ($form['captcha'] ?? false), (bool) $this->inTenant($tenant['org'], fn () => WebWidget::resolvedFor($site))['form']['captcha']);
        auth()->forgetGuards();

        return [$site, $tenant];
    }

    private function enquiry(WebSite $site): array
    {
        return ['key' => $site->public_key, 'name' => 'Dana', 'fields' => ['Service' => 'Sweep']];
    }

    private function solve(array $c): string
    {
        for ($n = 0; $n <= $c['max']; $n++) {
            if (hash('sha256', $c['salt'].$n) === $c['challenge']) {
                return json_encode(['salt' => $c['salt'], 'number' => $n, 'challenge' => $c['challenge'], 'signature' => $c['signature']]);
            }
        }

        $this->fail('unsolvable');
    }
}
