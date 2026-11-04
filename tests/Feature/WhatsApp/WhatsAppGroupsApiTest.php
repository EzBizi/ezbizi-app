<?php

namespace Tests\Feature\WhatsApp;

use App\Models\WhatsAppGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** AI Assistance › WhatsApp groups: where each report goes, and who reads it. */
class WhatsAppGroupsApiTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private const ENDPOINT = '/api/v1/ai-assistance/whatsapp-groups';

    private array $t;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.meta.whatsapp_bot_number_id' => '1309270845610102', 'services.meta.whatsapp_token' => 'a-token']);
        $this->t = $this->provisionTenant('A1-Group');

        DB::table('whatsapp_numbers')->insert([
            'id' => (string) Str::uuid(), 'organization_id' => $this->t['org']->id,
            'phone_number_id' => '1309270845610102', 'waba_id' => '1090541950244647',
            'display_number' => '+19545162455', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_a_manager_makes_a_group_of_this_businesss_people_only(): void
    {
        $member = $this->userWithRole($this->t, 'Manager', ['leads.view']);
        $stranger = $this->userWithRole($this->provisionTenant('Premier-Garage'), 'Elsewhere', ['leads.view']);

        $this->signedInAs($this->t['admin'])
            ->postJson(self::ENDPOINT, [
                'purpose' => 'daily:'.$this->t['franchise']->id,
                'subject' => 'A1 NJ - Daily Performance',
                'member_ids' => [$member->id, $stranger->id],
            ])
            ->assertCreated()
            ->assertJsonPath('data.on_whatsapp', false)
            ->assertJsonPath('data.members.*.id', [$member->id]);

        $this->signedInAs($this->t['admin'])->getJson(self::ENDPOINT)
            ->assertOk()
            ->assertJsonPath('data.0.subject', 'A1 NJ - Daily Performance')
            ->assertJsonFragment(['key' => 'daily:'.$this->t['franchise']->id])
            ->assertJsonFragment(['key' => 'messages'])
            ->assertJsonFragment(['id' => $member->id, 'name' => $member->name, 'has_phone' => false])
            ->assertJsonMissing(['id' => $stranger->id]);
    }

    /** Jose, 2026-11-04: every business value editable - the purpose names come from settings. */
    public function test_purpose_names_are_the_orgs_setting(): void
    {
        $this->inTenant($this->t['org'], fn () => app(\App\Support\Settings\SettingsResolver::class)
            ->set('ai_assistance.group_purposes', $this->t['org'], ['dispatch' => 'Dispatch desk']));

        $this->signedInAs($this->t['admin'])->getJson(self::ENDPOINT)
            ->assertOk()
            ->assertJsonFragment(['key' => 'dispatch', 'label' => 'Dispatch desk'])
            ->assertJsonMissing(['key' => 'messages']);
    }

    public function test_reading_and_changing_groups_needs_manage(): void
    {
        $reader = $this->userWithRole($this->t, 'Reader', ['ai_assistance.view', 'ai_assistance.ai.view']);

        $this->signedInAs($reader)->getJson(self::ENDPOINT)->assertForbidden();
        $this->signedInAs($reader)->postJson(self::ENDPOINT, ['purpose' => 'messages', 'subject' => 'x'])->assertForbidden();
    }

    public function test_publishing_before_meta_allows_groups_says_reports_still_go_privately(): void
    {
        Http::fake(['*' => Http::response(['error' => ['message' => 'Official Business Account required']], 403)]);
        $group = $this->inTenant($this->t['org'], fn () => WhatsAppGroup::create([
            'phone_number_id' => '1309270845610102', 'purpose' => 'messages', 'subject' => 'A1 Messages',
        ]));

        $this->signedInAs($this->t['admin'])
            ->postJson(self::ENDPOINT.'/'.$group->id.'/publish')
            ->assertStatus(422)
            ->assertJsonPath('message', fn (string $m) => str_contains($m, 'private chat'));

        $this->assertNull($group->fresh()->remote_id);
    }
}
