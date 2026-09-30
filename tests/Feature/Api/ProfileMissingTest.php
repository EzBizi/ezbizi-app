<?php

namespace Tests\Feature\Api;

use App\Models\User;
use App\Services\Organizations\ProvisionOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `/me` says what the profile still lacks, so the top bar can pulse the
 * picture until it is complete (Jose, 2026-09-30, from A1 Tools). The same
 * six fields HR counts, then the photo; keys only, never values.
 */
class ProfileMissingTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $r = app(ProvisionOrganization::class)(
            organizationName: 'Alpha',
            administrator: ['name' => 'Admin', 'email' => 'a@alpha.test', 'password' => 'a-real-password'],
            companyName: 'Co', franchiseName: 'F1',
        );

        $this->admin = $r['administrator'];
    }

    private function me(): \Illuminate\Testing\TestResponse
    {
        $token = $this->postJson('/api/v1/auth/login', [
            'email' => 'a@alpha.test', 'password' => 'a-real-password',
        ])->assertOk()->json('token');

        $this->app['auth']->forgetGuards();

        return $this->withHeader('Authorization', 'Bearer '.$token)->getJson('/api/v1/me')->assertOk();
    }

    public function test_an_empty_profile_lists_every_field_and_the_photo(): void
    {
        User::withoutGlobalScopes()->whereKey($this->admin->id)->update([
            'first_name' => 'Ana', 'last_name' => 'Ruiz',
            'phone' => null, 'address' => null, 'gender' => null, 'date_of_birth' => null,
            'avatar_path' => null,
        ]);

        $this->assertSame(
            ['phone', 'address', 'gender', 'date_of_birth', 'photo'],
            $this->me()->json('data.profile_missing'),
        );
    }

    public function test_a_complete_profile_lacks_nothing(): void
    {
        User::withoutGlobalScopes()->whereKey($this->admin->id)->update([
            'first_name' => 'Ana', 'last_name' => 'Ruiz', 'middle_name' => null,
            'phone' => '+14045550100', 'address' => '1 Main St', 'gender' => 'Female',
            'date_of_birth' => '1990-01-02', 'avatar_path' => 'avatars/ana.png',
        ]);

        // No middle name and still complete: most people do not have one.
        $this->assertSame([], $this->me()->json('data.profile_missing'));
    }
}
