<?php

namespace Tests\Feature\Phone;

use App\Models\PhoneCall;
use App\Models\PhoneCallTag;
use App\Models\PhoneConnection;
use App\Models\PhoneNumber;
use App\Models\PhoneNumberGrant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * What a call was about, so it can be counted.
 */
class PhoneCallTagTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private function callOn(array $t): PhoneCall
    {
        return $this->inTenant($t['org'], function () use ($t) {
            $c = new PhoneConnection([
                'organization_id' => $t['org']->id,
                'scope_type' => PhoneConnection::SCOPE_COMPANY,
                'scope_id' => $t['company']->id,
                'kind' => PhoneConnection::KIND_SUBACCOUNT,
                'account_sid' => 'ACtagtagtagtagtagtagtagtagtagta',
                'api_key_sid' => 'SKtagtagtagtagtagtagtagtagtagta',
                'status' => PhoneConnection::STATUS_CONNECTED,
            ]);
            $c->writeSecrets('s', 't');
            $c->save();

            return PhoneCall::query()->create([
                'organization_id' => $t['org']->id,
                'connection_id' => $c->id,
                'twilio_sid' => 'CAtagtagtagtagtagtagtagtagtagta',
                'direction' => 'inbound',
                'from_e164' => '+12035550111',
                'to_e164' => '+12035550222',
                'status' => 'completed',
            ]);
        });
    }

    /**
     * A colour is chosen from a fixed set, and it reaches the call list.
     *
     * The set is fixed because a tag is read on a dark call list and a light
     * report: a free picker hands somebody pale yellow on white, and the tag
     * they coloured to stand out becomes the one nobody can read.
     *
     * The second half is the half that matters. A colour set in Settings and
     * invisible on the calls themselves would be decoration, which is the
     * shape this module has produced five times over.
     */
    public function test_a_tag_colour_is_from_the_fixed_set_and_reaches_the_call(): void
    {
        $t = $this->provisionTenant();
        $editor = $this->userWithRole($t, 'Phone editor', ['phone.view', 'phone.edit']);
        $call = $this->callOn($t);

        // The helper leaves number_id null, and the call list is scoped by
        // readable NUMBERS — so without a line and a grant this test would
        // assert against an empty list and prove nothing.
        $this->inTenant($t['org'], function () use ($t, $call, $editor) {
            $number = PhoneNumber::query()->create([
                'organization_id' => $t['org']->id,
                'connection_id' => $call->connection_id,
                'scope_type' => PhoneNumber::SCOPE_COMPANY,
                'scope_id' => $t['company']->id,
                'twilio_sid' => 'PN'.str_repeat('t', 32),
                'e164' => '+12035550222',
                'label' => 'Main',
                'after_hours' => 'voicemail',
            ]);

            $call->forceFill(['number_id' => $number->id])->save();

            PhoneNumberGrant::query()->create([
                'number_id' => $number->id,
                'role_id' => $editor->role_id,
            ]);
        });

        $tag = $this->signedInAs($editor)->postJson('/api/v1/phone/tags', [
            'name' => 'Complaint',
            'color' => '#E5484D',
        ])->assertCreated();

        $this->assertSame('#E5484D', $tag->json('data.color'));

        // Anything outside the set is refused rather than coerced: a colour
        // the client cannot draw renders as none, which reads as "it did not
        // save" rather than as "that colour is not offered".
        $this->signedInAs($editor)->postJson('/api/v1/phone/tags', [
            'name' => 'Neon',
            'color' => '#00FF00',
        ])->assertStatus(422)->assertJsonValidationErrors('color');

        $this->signedInAs($editor)
            ->patchJson('/api/v1/phone/calls/'.$call->id.'/tag', [
                'tag_id' => $tag->json('data.id'),
            ])
            ->assertOk();

        $row = collect(
            $this->signedInAs($editor)->getJson('/api/v1/phone/calls')->assertOk()->json('data')
        )->firstWhere('id', $call->id);

        $this->assertNotNull($row, 'the tagged call was not in the list');
        $this->assertSame('Complaint', $row['tag']);
        $this->assertSame(
            '#E5484D',
            $row['tag_color'],
            'the colour never reached the call list, so it is decoration',
        );
    }

    public function test_a_tag_can_be_made_and_put_on_a_call(): void
    {
        $t = $this->provisionTenant();
        $admin = $this->userWithRole($t, 'Phone editor', ['phone.view', 'phone.edit']);
        $call = $this->callOn($t);

        $tag = $this->signedInAs($admin)
            ->postJson('/api/v1/phone/tags', ['name' => 'Warranty'])
            ->assertCreated()
            ->json('data.id');

        $this->signedInAs($admin)
            ->patchJson('/api/v1/phone/calls/'.$call->id.'/tag', ['tag_id' => $tag])
            ->assertOk();

        $fresh = $this->inTenant($t['org'], fn () => PhoneCall::query()->find($call->id));

        $this->assertSame($tag, $fresh->tag_id);
        $this->assertSame($admin->id, $fresh->tagged_by);
        $this->assertNotNull($fresh->tagged_at);
    }

    /**
     * Whoever answers must be able to say what the call was about, and they are
     * usually view-plus-a-line-grant. Requiring phone.edit to tag would mean
     * the people doing the tagging cannot tag.
     */
    public function test_somebody_who_only_answers_calls_may_still_tag_one(): void
    {
        $t = $this->provisionTenant();
        $admin = $this->userWithRole($t, 'Phone editor', ['phone.view', 'phone.edit']);
        $answerer = $this->userWithRole($t, 'Phone answerer', ['phone.view']);
        $call = $this->callOn($t);

        $tag = $this->signedInAs($admin)
            ->postJson('/api/v1/phone/tags', ['name' => 'Callback'])
            ->assertCreated()
            ->json('data.id');

        $this->signedInAs($answerer)
            ->patchJson('/api/v1/phone/calls/'.$call->id.'/tag', ['tag_id' => $tag])
            ->assertOk();

        // ...but they still may not change the list itself.
        $this->signedInAs($answerer)
            ->postJson('/api/v1/phone/tags', ['name' => 'Invented'])
            ->assertForbidden();
    }

    /**
     * Deleting a used tag would blank the label on every call it described and
     * change last month's totals without saying so.
     */
    public function test_deleting_a_used_tag_retires_it_and_keeps_the_history(): void
    {
        $t = $this->provisionTenant();
        $admin = $this->userWithRole($t, 'Phone editor', ['phone.view', 'phone.edit']);
        $call = $this->callOn($t);

        $tag = $this->signedInAs($admin)
            ->postJson('/api/v1/phone/tags', ['name' => 'Warranty'])
            ->assertCreated()
            ->json('data.id');

        $this->signedInAs($admin)
            ->patchJson('/api/v1/phone/calls/'.$call->id.'/tag', ['tag_id' => $tag])
            ->assertOk();

        $response = $this->signedInAs($admin)
            ->deleteJson('/api/v1/phone/tags/'.$tag)
            ->assertOk();

        $this->assertStringContainsString('retired', (string) $response->json('message'));

        $row = $this->inTenant($t['org'], fn () => PhoneCallTag::query()->find($tag));
        $this->assertNotNull($row, 'The tag was deleted and took its history with it.');
        $this->assertFalse($row->is_active);

        $fresh = $this->inTenant($t['org'], fn () => PhoneCall::query()->find($call->id));
        $this->assertSame($tag, $fresh->tag_id);
    }

    public function test_a_retired_tag_cannot_be_put_on_a_call(): void
    {
        $t = $this->provisionTenant();
        $admin = $this->userWithRole($t, 'Phone editor', ['phone.view', 'phone.edit']);
        $call = $this->callOn($t);

        $tag = $this->signedInAs($admin)
            ->postJson('/api/v1/phone/tags', ['name' => 'Old thing'])
            ->assertCreated()
            ->json('data.id');

        $this->signedInAs($admin)
            ->patchJson('/api/v1/phone/tags/'.$tag, ['is_active' => false])
            ->assertOk();

        $this->signedInAs($admin)
            ->patchJson('/api/v1/phone/calls/'.$call->id.'/tag', ['tag_id' => $tag])
            ->assertStatus(422);
    }

    public function test_two_tags_cannot_share_a_name(): void
    {
        $t = $this->provisionTenant();
        $admin = $this->userWithRole($t, 'Phone editor', ['phone.view', 'phone.edit']);

        $this->signedInAs($admin)
            ->postJson('/api/v1/phone/tags', ['name' => 'Warranty'])
            ->assertCreated();

        $this->signedInAs($admin)
            ->postJson('/api/v1/phone/tags', ['name' => 'Warranty'])
            ->assertStatus(422);
    }
}
