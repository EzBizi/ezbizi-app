<?php

namespace Tests\Feature\Api;

use App\Models\UiLocale;
use App\Models\UiString;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * §8 — strings are data. English and Spanish ship as rows; a new locale is
 * another row, not a PHP enum.
 */
class UiStringsTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_the_public_catalogue_falls_back_to_english(): void
    {
        $body = $this->getJson('/api/v1/ui-strings?locale=xx')
            ->assertOk()
            ->json('data');

        $this->assertSame('en', $body['locale']);
        $this->assertSame('Sign in', $body['strings']['shell.sign_in']);
        $this->assertArrayHasKey('shell.sign_in', $body['strings']);
    }

    public function test_spanish_is_a_row_not_an_enum(): void
    {
        $body = $this->getJson('/api/v1/ui-strings?locale=es')
            ->assertOk()
            ->json('data');

        $this->assertSame('es', $body['locale']);
        $this->assertSame('Iniciar sesión', $body['strings']['shell.sign_in']);
    }

    public function test_a_user_override_beats_the_organization_default(): void
    {
        $alpha = $this->provisionTenant('Alpha');

        $this->inTenant($alpha['org'], function () use ($alpha) {
            app(\App\Support\Settings\SettingsResolver::class)
                ->set('locale', $alpha['org'], 'es');

            $alpha['admin']->forceFill([
                'preferences' => ['locale' => 'en'],
            ])->save();
        });

        $me = $this->signedInAs($alpha['admin'])
            ->getJson('/api/v1/me')
            ->assertOk()
            ->json('data');

        $this->assertSame('en', $me['locale']);
        $this->assertSame('Sign in', $me['strings']['shell.sign_in']);
    }

    public function test_adding_a_locale_does_not_need_a_php_enum(): void
    {
        UiLocale::query()->create(['code' => 'ht', 'name' => 'Haitian Creole']);
        UiString::query()->create([
            'locale' => 'ht',
            'key'    => 'shell.sign_in',
            'text'   => 'Konekte',
        ]);

        $body = $this->getJson('/api/v1/ui-strings?locale=ht')
            ->assertOk()
            ->json('data');

        $this->assertSame('ht', $body['locale']);
        $this->assertSame('Konekte', $body['strings']['shell.sign_in']);
        $this->assertSame('Settings', $body['strings']['shell.settings'],
            'A missing key falls back to English, never to a class name.');
    }
}
