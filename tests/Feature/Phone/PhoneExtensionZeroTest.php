<?php

namespace Tests\Feature\Phone;

use App\Models\PhoneConnection;
use App\Models\PhoneExtension;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Every extension begins with a zero.
 *
 * Jose, 2026-09-22: *"all extensions should also be forced to begin with 0 as
 * in 001 002"*.
 *
 * It is not a house style. A menu offers 1 to 9, so an extension starting with
 * one of those digits is indistinguishable from a menu choice at the moment a
 * caller presses it — the leading zero answers that on the first keypress.
 */
class PhoneExtensionZeroTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_an_extension_without_a_leading_zero_is_refused(): void
    {
        [$t, $connection, $editor, $person] = $this->team();

        $this->signedInAs($editor)
            ->putJson('/api/v1/phone/extensions', [
                'connection_id' => $connection->id,
                'user_id' => $person->id,
                'extension' => '101',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('extension');
    }

    /**
     * And one WITH a zero is accepted.
     *
     * The positive control. Without it the refusal above passes on a build
     * that rejects every extension ever offered, which would be the same
     * screen with a different defect.
     */
    public function test_an_extension_with_a_leading_zero_is_accepted(): void
    {
        [$t, $connection, $editor, $person] = $this->team();

        $this->signedInAs($editor)
            ->putJson('/api/v1/phone/extensions', [
                'connection_id' => $connection->id,
                'user_id' => $person->id,
                'extension' => '0101',
            ])
            ->assertOk()
            ->assertJsonPath('data.extension', '0101');
    }

    /**
     * A zero on its own is not an extension.
     *
     * The boundary the pattern has to get right: `0` is the digit that means
     * "an extension follows", so accepting it alone would make the prefix and
     * the extension the same keypress.
     */
    public function test_a_bare_zero_is_refused(): void
    {
        [$t, $connection, $editor, $person] = $this->team();

        $this->signedInAs($editor)
            ->putJson('/api/v1/phone/extensions', [
                'connection_id' => $connection->id,
                'user_id' => $person->id,
                'extension' => '0',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('extension');
    }

    /**
     * THE MIGRATION MOVES THE ROWS THAT PREDATE THE RULE.
     *
     * Nine extensions exist in production and not one starts with a zero, so
     * without this every one of those people would be unsaveable: open the
     * Users tab, change an outbound line, and the save is refused over digits
     * somebody was given weeks ago.
     *
     * Run against rows this test inserts rather than trusting that it ran once
     * — a migration is an artefact you can execute, and asserting on the
     * schema alone cannot tell a migration that ran from one that was skipped.
     */
    public function test_the_migration_prefixes_extensions_that_predate_the_rule(): void
    {
        [$t, $connection, , $person] = $this->team();
        $other = $this->userWithRole($t, 'Bruno Vega', ['phone.view', 'phone.settings.view']);

        $this->inTenant($t['org'], function () use ($t, $connection, $person, $other) {
            // Straight to the table: the endpoint now refuses exactly these.
            DB::table('phone_extensions')->insert([
                [
                    'id' => (string) Str::uuid7(),
                    'organization_id' => $t['org']->id,
                    'connection_id' => $connection->id,
                    'user_id' => $person->id,
                    'extension' => '103',
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                [
                    'id' => (string) Str::uuid7(),
                    'organization_id' => $t['org']->id,
                    'connection_id' => $connection->id,
                    'user_id' => $other->id,

                    // Already compliant. The case that says the migration
                    // prefixes what needs it rather than everything — without
                    // this row, a migration that blindly concatenated would
                    // pass, and re-running it would give somebody `00104`.
                    'extension' => '0104',
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            ]);
        });

        require_once database_path('migrations/2026_09_22_140000_every_extension_begins_with_a_zero.php');
        $migration = include database_path('migrations/2026_09_22_140000_every_extension_begins_with_a_zero.php');
        $migration->up();

        $this->inTenant($t['org'], function () use ($person, $other) {
            $this->assertSame('0103', PhoneExtension::query()->where('user_id', $person->id)->value('extension'));
            $this->assertSame('0104', PhoneExtension::query()->where('user_id', $other->id)->value('extension'));
        });
    }

    /** @return array{0: array<string,mixed>, 1: PhoneConnection, 2: User, 3: User} */
    private function team(): array
    {
        $t = $this->provisionTenant();
        $editor = $this->userWithRole($t, 'Adeline Ruiz', ['phone.view', 'phone.settings.view', 'phone.edit']);
        $person = $this->userWithRole($t, 'Alice Nolan', ['phone.view', 'phone.settings.view']);

        $connection = $this->inTenant($t['org'], fn () => PhoneConnection::factory()->create([
            'scope_type' => PhoneConnection::SCOPE_ORGANIZATION,
            'scope_id' => $t['org']->id,
        ]));

        return [$t, $connection, $editor, $person];
    }
}
