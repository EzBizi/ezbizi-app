<?php

namespace Tests\Feature\Inspection;

use App\Support\Inspection\ScopeKey;
use Tests\TestCase;

class ScopeKeyTest extends TestCase
{
    public function test_a_job_is_named_in_the_key(): void
    {
        $this->assertSame(
            'customer:aaa|job:bbb',
            ScopeKey::for('aaa', 'bbb'),
        );
    }

    public function test_no_job_is_the_word_none_not_a_sql_null(): void
    {
        $this->assertSame(
            'customer:aaa|job:none',
            ScopeKey::for('aaa', null),
        );
    }
}
