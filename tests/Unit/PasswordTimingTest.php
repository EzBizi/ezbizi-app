<?php

namespace Tests\Unit;

use App\Support\Auth\PasswordTiming;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PasswordTimingTest extends TestCase
{
    public function test_the_dummy_hash_is_reused(): void
    {
        $first  = PasswordTiming::hash();
        $second = PasswordTiming::hash();

        $this->assertSame($first, $second);
        $this->assertTrue(Hash::check('timing-equalisation', $first));
    }
}
