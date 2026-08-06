<?php

namespace Tests\Feature\Api;

use App\Models\User;
use App\Services\Organizations\ProvisionOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * User preferences — §2.1 category 6.
 *
 * The category exists so it is never merged with the others. What these defend
 * is exactly that: preferences are the person's, not the organization's, and one
 * person's cannot be read or written by another.
 */
class PreferencesTest extends TestCase
{
    use RefreshDatabase;

    private array $alpha;

    protected function setUp(): void
    {
        parent::setUp();

        $r = app(ProvisionOrganization::class)(
            organizationName: 'Alpha',
            administrator: ['name' => 'Admin', 'email' => 'a@alpha.test', 'password' => 'a-real-password'],
            companyName: 'Co', franchiseName: 'F1',
        );

        $this->alpha = ['organization' => $r['organization'], 'admin' => $r['administrator']];
    }

    private function as(string $email = 'a@alpha.test'): static
    {
        $token = $this->postJson('/api/v1/auth/login', [
            'email' => $email, 'password' => 'a-real-password',
        ])->assertOk()->json('token');

        $this->app['auth']->forgetGuards();

        return $this->withHeader('Authorization', "Bearer {$token}");
    }

    public function test_defaults_arrive_complete_so_the_client_never_guesses(): void
    {
        $response = $this->as()->getJson('/api/v1/me/preferences')->assertOk();

        $data = $response->json('data');

        // Read as an array, not by json path: the KEYS contain dots, so dot
        // notation would traverse into a 'navigation' object that does not exist.
        //
        // The list is the default, deliberately — the owner's preference, and the
        // one most people expect from a CRM sidebar.
        $this->assertSame('list', $data['navigation.style']);
        $this->assertSame('system', $data['appearance.theme']);

        // The catalogue travels with the values, so a preference added next year
        // can be rendered by a client that was never told about it.
        $this->assertNotEmpty($response->json('meta.definitions'));
    }

    public function test_a_preference_survives_and_comes_back_on_me(): void
    {
        $this->as()->patchJson('/api/v1/me/preferences', [
            'preferences' => ['appearance.theme' => 'dark'],
        ])->assertOk();

        $this->app['auth']->forgetGuards();

        // Carried on /me as well as its own endpoint, so the app paints the
        // right theme on the first frame rather than flashing the wrong one.
        $me = $this->as()->getJson('/api/v1/me')->assertOk()->json('data');

        $this->assertSame('dark', $me['preferences']['appearance.theme']);
    }

    public function test_a_partial_update_leaves_the_others_alone(): void
    {
        $this->as()->patchJson('/api/v1/me/preferences', [
            'preferences' => ['appearance.theme' => 'dark'],
        ])->assertOk();

        $this->app['auth']->forgetGuards();

        $data = $this->as()->patchJson('/api/v1/me/preferences', [
            'preferences' => ['navigation.style' => 'blocks'],
        ])->assertOk()->json('data');

        // Two screens changing different preferences must not clobber each other.
        $this->assertSame('dark', $data['appearance.theme']);
        $this->assertSame('blocks', $data['navigation.style']);
    }

    public function test_a_value_outside_the_permitted_set_is_refused(): void
    {
        $this->as()->patchJson('/api/v1/me/preferences', [
            'preferences' => ['appearance.theme' => 'neon'],
        ])->assertStatus(422);

        // Refused loudly rather than stored: a preference the client cannot
        // render is worse than one that was never saved, because the second
        // tells somebody immediately.
        $this->assertSame([], User::find($this->alpha['admin']->id)->preferences ?? []);
    }

    public function test_an_unknown_key_is_refused(): void
    {
        // The schema is a platform invariant (§2.1 category 1). If the permitted
        // keys were themselves editable data, nothing would validate anything.
        $this->as()->patchJson('/api/v1/me/preferences', [
            'preferences' => ['appearance.wallpaper' => 'kittens'],
        ])->assertStatus(422);
    }

    public function test_one_persons_preferences_are_their_own(): void
    {
        $other = app(\App\Support\Tenancy\TenantContext::class)->run(
            $this->alpha['organization'],
            fn () => User::create([
                'organization_id' => $this->alpha['organization']->id,
                'role_id'   => $this->alpha['admin']->role_id,
                'name'      => 'Other',
                'email'     => 'other@alpha.test',
                'password'  => 'a-real-password',
                'is_active' => true,
            ]),
        );

        $this->as()->patchJson('/api/v1/me/preferences', [
            'preferences' => ['appearance.theme' => 'dark'],
        ])->assertOk();

        $this->app['auth']->forgetGuards();

        // Same organization, same role — and none of that has anything to do
        // with what theme somebody likes.
        $theirs = $this->as('other@alpha.test')->getJson('/api/v1/me/preferences')
            ->assertOk()->json('data');

        $this->assertSame('system', $theirs['appearance.theme']);

        $this->assertSame([], User::find($other->id)->preferences ?? []);
    }
}
