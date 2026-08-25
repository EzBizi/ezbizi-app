<?php

namespace Database\Factories;

use App\Models\PhoneConnection;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PhoneConnection>
 */
class PhoneConnectionFactory extends Factory
{
    protected $model = PhoneConnection::class;

    public function definition(): array
    {
        return [
            'scope_type' => PhoneConnection::SCOPE_ORGANIZATION,
            'scope_id' => (string) fake()->uuid(),
            'kind' => 'byo',
            'account_sid' => 'AC'.bin2hex(random_bytes(16)),
            'api_key_sid' => null,
            'credentials' => 'SK'.bin2hex(random_bytes(16)),
            'auth_token' => bin2hex(random_bytes(16)),
            'twiml_app_sid' => null,
            'status' => PhoneConnection::STATUS_CONNECTED,
            'status_reason' => null,
        ];
    }
}
