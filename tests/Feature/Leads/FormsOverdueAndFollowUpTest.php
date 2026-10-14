<?php

namespace Tests\Feature\Leads;

use App\Models\Lead;
use App\Models\LeadConversation;
use App\Support\Leads\LeadThread;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Jose, 2026-10-14: "forms should turn red as well, and we should have a new tag called Follow Up". */
class FormsOverdueAndFollowUpTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private function form(array $t, array $extra = []): Lead
    {
        return $this->inTenant($t['org'], fn () => Lead::create($extra + [
            'organization_id' => $t['org']->id, 'source' => Lead::SOURCE_WEBSITE_FORM, 'remote_id' => uniqid(),
            'status' => Lead::STATUS_NEW, 'received_at' => now()->subHour(),
            'last_contact_from' => LeadConversation::FROM_CONSUMER, 'last_contact_at' => now()->subHour(),
        ]));
    }

    public function test_a_form_nobody_called_back_is_overdue_until_a_note_or_follow_up(): void
    {
        $t = $this->provisionTenant();
        $waiting = $this->form($t);
        $noted = $this->form($t, ['note' => 'Called, left voicemail', 'note_updated_at' => now()]);
        $following = $this->form($t, ['status' => Lead::STATUS_FOLLOW_UP]);

        $rows = collect($this->signedInAs($t['admin'])->getJson('/api/v1/leads')->assertOk()->json('data'))->keyBy('id');
        $this->assertTrue($rows[$waiting->id]['reply_overdue'], 'a form is owed a call back');
        $this->assertFalse($rows[$noted->id]['reply_overdue'], 'a note since the form says somebody called');
        $this->assertFalse($rows[$following->id]['reply_overdue'], 'Follow Up clears the red');

        $overdue = $this->signedInAs($t['admin'])->getJson('/api/v1/leads?overdue=1')->json('data');
        $this->assertSame([$waiting->id], array_column($overdue, 'id'), 'the Overdue filter agrees with the row');
    }

    public function test_follow_up_is_a_status_a_person_sets_and_the_customer_writing_reopens(): void
    {
        $t = $this->provisionTenant();
        $lead = $this->form($t);

        $this->signedInAs($t['admin'])->patchJson("/api/v1/leads/{$lead->id}", ['status' => Lead::STATUS_FOLLOW_UP])
            ->assertOk()->assertJsonPath('data.status', Lead::STATUS_FOLLOW_UP);

        $this->inTenant($t['org'], fn () => app(LeadThread::class)->store($lead->fresh(), 'again-1', [
            'channel' => 'FORM', 'participant' => LeadConversation::FROM_CONSUMER, 'occurred_at' => now(), 'text' => 'Any update?',
        ]));

        $this->assertSame(Lead::STATUS_NEW, $this->inTenant($t['org'], fn () => $lead->fresh()->status));
    }
}
