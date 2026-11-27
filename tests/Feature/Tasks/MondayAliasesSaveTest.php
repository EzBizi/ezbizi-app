<?php

namespace Tests\Feature\Tasks;

use App\Models\User;
use App\Support\Tasks\PeopleAliases;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Monday off (11-26): "Chris" still finds Kristopher, from the saved Monday user list, with no Monday to ask. */
class MondayAliasesSaveTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_a_saved_monday_name_finds_the_person_without_monday(): void
    {
        $t = $this->provisionTenant('Alpha');
        $kris = $this->userWithRole($t, 'Kristopher Restivo', ['tasks.view'], 'kris@example.com');
        $file = tempnam(sys_get_temp_dir(), 'mu');
        file_put_contents($file, json_encode(['people_map' => ['k@monday.example.com' => 'kris@example.com'],
            'users' => [['id' => '1', 'name' => 'Chris R', 'email' => 'k@monday.example.com'], ['id' => '2', 'name' => 'Nobody', 'email' => 'x@monday.example.com']]]));

        $this->artisan('ezbizi:monday-aliases-save', ['file' => $file, '--org' => $t['org']->id])->expectsOutputToContain('aliases kept: 1')->assertSuccessful();
        @unlink($file);

        $this->inTenant($t['org'], function () use ($kris) {
            $this->assertSame('Chris R', User::query()->find($kris->id)->also_known_as);
            $this->assertStringContainsString('Chris R', PeopleAliases::of(User::query()->whereKey($kris->id)->get())[(string) $kris->id] ?? '');
        });
    }
}
