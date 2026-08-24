<?php

namespace Database\Factories;

use App\Models\PhoneConnection;
use App\Models\PhoneNumber;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PhoneNumber>
 */
class PhoneNumberFactory extends Factory
{
    protected $model = PhoneNumber::class;

    public function definition(): array
    {
        return [
            'connection_id' => PhoneConnection::factory(),
            'scope_type' => PhoneNumber::SCOPE_ORGANIZATION,
            'scope_id' => (string) fake()->uuid(),
            'twilio_sid' => 'PN'.bin2hex(random_bytes(16)),
            'e164' => '+1'.fake()->numerify('##########'),
            'label' => 'Main line',
            'flow_id' => null,
            'hours_schedule_id' => null,
            'ring_group_id' => null,
            'record' => true,
            'transcribe' => false,
            'after_hours' => 'voicemail',
            'after_hours_number' => null,
            'language' => 'en-US',
            'messaging_service_sid' => null,
            'sms_outbound_allowed' => false,
            'emergency_address_set' => false,
        ];
    }
}
