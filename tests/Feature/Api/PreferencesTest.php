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
        // withoutGlobalScopes: the request has ended and SetTenantContext clears
        // the tenant on its way out, so an assertion made afterwards has none to
        // be scoped by.
        $this->assertSame([], User::withoutGlobalScopes()->find($this->alpha['admin']->id)->preferences ?? []);
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

        $this->assertSame([], User::withoutGlobalScopes()->find($other->id)->preferences ?? []);
    }

    // ── the two mixed modes, and a width that has bounds ─────────

    public function test_the_mixed_themes_are_accepted(): void
    {
        foreach (['mixed_dark_nav', 'mixed_light_nav'] as $mode) {
            $this->as()->patchJson('/api/v1/me/preferences', [
                'preferences' => ['appearance.theme' => $mode],
            ])->assertOk();

            $this->assertSame(
                $mode,
                User::withoutGlobalScopes()->find($this->alpha['admin']->id)
                    ->preferences['appearance.theme'],
            );
        }
    }

    public function test_a_theme_that_does_not_exist_is_still_refused(): void
    {
        // The list grew; it did not stop being a list. A value the client
        // cannot render is worse than one that was never saved, because the
        // second tells somebody immediately.
        $this->as()->patchJson('/api/v1/me/preferences', [
            'preferences' => ['appearance.theme' => 'neon'],
        ])->assertStatus(422);
    }

    public function test_the_sidebar_width_is_clamped_rather_than_refused(): void
    {
        $this->as()->patchJson('/api/v1/me/preferences', [
            'preferences' => ['navigation.width' => 9000],
        ])->assertOk();

        // Clamped, not rejected. This is somebody's own preference and an
        // out-of-range value is a stale field or a client bug, not an attack —
        // refusing would leave them looking at an error they cannot act on.
        $this->assertSame(
            420,
            User::withoutGlobalScopes()->find($this->alpha['admin']->id)
                ->preferences['navigation.width'],
        );
    }

    public function test_a_width_below_the_floor_is_lifted_to_it(): void
    {
        $this->as()->patchJson('/api/v1/me/preferences', [
            'preferences' => ['navigation.width' => 10],
        ])->assertOk();

        // 10px of sidebar is an app with no navigation. The floor is what stops
        // a hand-written API call producing one.
        $this->assertSame(
            180,
            User::withoutGlobalScopes()->find($this->alpha['admin']->id)
                ->preferences['navigation.width'],
        );
    }
}
