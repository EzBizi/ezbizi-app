<?php

namespace Tests\Feature\Phone;

use App\Models\PhoneConnection;
use App\Models\PhoneNumber;
use App\Models\PhoneVoicemailBox;
use App\Support\Phone\TwimlRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Named voicemail boxes.
 *
 * Every line took a message with the same sentence — "Please leave a message
 * after the tone." — with no way to say who the caller had reached. That
 * greeting is the last thing a customer hears before deciding whether to try
 * somebody else, and it was the one part of the call nobody had any say over.
 */
class PhoneVoicemailBoxTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    /** @return array{0: array<string, mixed>, 1: PhoneConnection} */
    private function connected(): array
    {
        $t = $this->provisionTenant();

        $connection = $this->inTenant($t['org'], fn () => PhoneConnection::factory()->create([
            'scope_type' => PhoneConnection::SCOPE_ORGANIZATION,
            'scope_id' => $t['org']->id,
        ]));

        return [$t, $connection];
    }

    /**
     * The assertion that matters is the TwiML, not the row.
     *
     * A box that saves and displays but never reaches the caller is the exact
     * shape this module has produced four times over — ring groups, the
     * voicemail transcription switch, the IVR menu nothing could attach, the
     * evening calls. A stored greeting proves nothing.
     */
    public function test_a_caller_hears_the_line_s_own_greeting(): void
    {
        [$t, $connection] = $this->connected();
        $editor = $this->userWithRole($t, 'Phone editor', ['phone.view', 'phone.edit']);

        $created = $this->signedInAs($editor)->postJson('/api/v1/phone/voicemail-boxes', [
            'connection_id' => $connection->id,
            'name' => 'A1 Chimney - Voicemail',
            'greeting_text' => 'You have reached A-1 Chimney. Leave a number and we ring back the same day.',
            'max_seconds' => 120,
        ])->assertCreated();

        $boxId = $created->json('data.id');

        // The preview the screen shows is the model's answer, not the screen's.
        $this->assertSame(
            'You have reached A-1 Chimney. Leave a number and we ring back the same day.',
            $created->json('data.spoken_greeting'),
        );

        $number = $this->inTenant($t['org'], fn () => PhoneNumber::query()->create([
            'organization_id' => $t['org']->id,
            'connection_id' => $connection->id,
            'scope_type' => PhoneNumber::SCOPE_ORGANIZATION,
            'scope_id' => $t['org']->id,
            'twilio_sid' => 'PN'.str_repeat('v', 32),
            'e164' => '+15615559100',
            'label' => 'Main',
            'after_hours' => 'voicemail',
            'voicemail_box_id' => $boxId,
        ]));

        $xml = $this->inTenant($t['org'], fn () => (new TwimlRenderer)->inbound($number->fresh(), []));

        $this->assertStringContainsString('You have reached A-1 Chimney.', $xml);
        $this->assertStringNotContainsString('Please leave a message after the tone.', $xml);
        $this->assertStringContainsString('maxLength="120"', $xml);
    }

    public function test_a_line_with_no_box_still_takes_a_message(): void
    {
        [$t, $connection] = $this->connected();

        $number = $this->inTenant($t['org'], fn () => PhoneNumber::query()->create([
            'organization_id' => $t['org']->id,
            'connection_id' => $connection->id,
            'scope_type' => PhoneNumber::SCOPE_ORGANIZATION,
            'scope_id' => $t['org']->id,
            'twilio_sid' => 'PN'.str_repeat('w', 32),
            'e164' => '+15615559101',
            'label' => 'Bare',
            'after_hours' => 'voicemail',
        ]));

        $xml = $this->inTenant($t['org'], fn () => (new TwimlRenderer)->inbound($number, []));

        $this->assertStringContainsString('Please leave a message after the tone.', $xml);
        $this->assertStringContainsString('<Record', $xml);
    }

    /**
     * Deleting a box must not leave lines pointing at nothing — the renderer
     * would look up null and take a message with no greeting at all, which is
     * a silent regression rather than a visible one.
     */
    public function test_deleting_a_box_returns_its_lines_to_the_built_in_greeting(): void
    {
        [$t, $connection] = $this->connected();
        $editor = $this->userWithRole($t, 'Phone editor', ['phone.view', 'phone.edit']);

        $boxId = $this->signedInAs($editor)->postJson('/api/v1/phone/voicemail-boxes', [
            'connection_id' => $connection->id,
            'name' => 'Temporary',
            'greeting_text' => 'This box is going away.',
        ])->assertCreated()->json('data.id');

        $number = $this->inTenant($t['org'], fn () => PhoneNumber::query()->create([
            'organization_id' => $t['org']->id,
            'connection_id' => $connection->id,
            'scope_type' => PhoneNumber::SCOPE_ORGANIZATION,
            'scope_id' => $t['org']->id,
            'twilio_sid' => 'PN'.str_repeat('x', 32),
            'e164' => '+15615559102',
            'label' => 'Main',
            'after_hours' => 'voicemail',
            'voicemail_box_id' => $boxId,
        ]));

        $this->signedInAs($editor)
            ->deleteJson('/api/v1/phone/voicemail-boxes/'.$boxId)
            ->assertOk();

        $this->inTenant($t['org'], function () use ($number) {
            $fresh = $number->fresh();
            $this->assertNull($fresh->voicemail_box_id);

            $xml = (new TwimlRenderer)->inbound($fresh, []);
            $this->assertStringContainsString('Please leave a message after the tone.', $xml);
        });
    }

    public function test_a_message_length_outside_twilio_s_bounds_is_refused(): void
    {
        [$t, $connection] = $this->connected();
        $editor = $this->userWithRole($t, 'Phone editor', ['phone.view', 'phone.edit']);

        $this->signedInAs($editor)->postJson('/api/v1/phone/voicemail-boxes', [
            'connection_id' => $connection->id,
            'name' => 'Too short',
            'max_seconds' => 3,
        ])->assertStatus(422)->assertJsonValidationErrors('max_seconds');
    }
}
