<?php

namespace Tests\Feature\Fleet;

use App\Support\Fleet\VehicleName;
use Tests\TestCase;

class VehicleNameTest extends TestCase
{
    public function test_name_degrades_through_plate_then_vin(): void
    {
        $this->assertSame(
            '2021 Ford Transit · KGN-4412',
            VehicleName::derive(2021, 'Ford', 'Transit', 'KGN-4412', '1FT…'),
        );
        $this->assertSame('KGN-4412', VehicleName::derive(null, null, null, 'KGN-4412', '1FT'));
        $this->assertSame('1FT', VehicleName::derive(null, null, null, null, '1FT'));
        $this->assertSame('Unidentified vehicle', VehicleName::derive(null, null, null, null, null));
    }
}
