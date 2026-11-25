<?php
namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * The phone-number normalisation migration, on the shapes production actually
 * holds — measured before the migration was written, not imagined.
 */
class PhoneNumberNormalisationTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private function normalise(): void
    {
        (require base_path('database/migrations/2026_09_03_180000_store_phone_numbers_as_e164.php'))->up();
    }

    private function customerWith(string $phone): string
    {
        $t = $this->provisionTenant('Norm');
        $id = (string) \Illuminate\Support\Str::uuid();
        DB::table('customers')->insert([
            'id' => $id,
            'organization_id' => $t['org']->id,
            'owning_franchise_id' => $t['franchise']->id,
            'number' => 'C-'.substr($id, 0, 8),
            'name' => 'Test',
            'phone' => $phone,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function phoneOf(string $id): ?string
    {
        return DB::table('customers')->where('id', $id)->value('phone');
    }

    public function test_a_number_written_with_dashes_becomes_e164(): void
    {
        $id = $this->customerWith('305-555-0199');
        $this->normalise();
        $this->assertSame('+13055550199', $this->phoneOf($id));
    }

    public function test_bare_ten_digits_become_e164(): void
    {
        $id = $this->customerWith('3055550107');
        $this->normalise();
        $this->assertSame('+13055550107', $this->phoneOf($id));
    }

    public function test_eleven_digits_with_a_country_code_land_the_same(): void
    {
        $id = $this->customerWith('1 305 555 0107');
        $this->normalise();
        $this->assertSame('+13055550107', $this->phoneOf($id));
    }

    public function test_a_row_already_stored_correctly_is_untouched(): void
    {
        $id = $this->customerWith('+13055550118');
        $this->normalise();
        $this->assertSame('+13055550118', $this->phoneOf($id));
    }

    /**
     * The one that matters. Production holds ten of these.
     */
    public function test_an_eight_digit_value_is_left_alone_rather_than_invented(): void
    {
        $id = $this->customerWith('+1 (555) 0120');
        $this->normalise();
        $this->assertSame(
            '+1 (555) 0120',
            $this->phoneOf($id),
            'the migration guessed the two digits it did not have',
        );
    }

    public function test_an_international_number_survives(): void
    {
        $id = $this->customerWith('+442071838750');
        $this->normalise();
        $this->assertSame('+442071838750', $this->phoneOf($id));
    }

    public function test_an_extension_is_not_turned_into_a_phone_number(): void
    {
        $id = $this->customerWith('101');
        $this->normalise();
        $this->assertSame('101', $this->phoneOf($id));
    }

    public function test_an_impossible_area_code_is_left_alone(): void
    {
        $id = $this->customerWith('105-555-0107');
        $this->normalise();
        $this->assertSame('105-555-0107', $this->phoneOf($id));
    }

    public function test_running_it_twice_changes_nothing_the_second_time(): void
    {
        $id = $this->customerWith('305-555-0199');
        $this->normalise();
        $once = $this->phoneOf($id);
        $this->normalise();
        $this->assertSame($once, $this->phoneOf($id));
    }
}
