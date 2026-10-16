<?php

namespace Tests\Unit;

use App\Support\Financials\CostSplit;
use PHPUnit\Framework\TestCase;

class CostSplitTest extends TestCase
{
    public function test_largest_remainder_hands_out_every_cent_and_ties_go_alphabetically(): void
    {
        // 100.00 over 3 equal people: 33.34 / 33.33 / 33.33, the extra cent to the first name.
        $this->assertSame([
            ['person' => 'Ann', 'jobs' => 1, 'cents' => 3334],
            ['person' => 'Bob', 'jobs' => 1, 'cents' => 3333],
            ['person' => 'Cy', 'jobs' => 1, 'cents' => 3333],
        ], CostSplit::allocate(10000, ['Cy' => 1, 'Bob' => 1, 'Ann' => 1]));

        // 10.01 over 2:1 jobs — 6.67 / 3.34, remainder to the larger fraction.
        $split = CostSplit::allocate(1001, ['A' => 2, 'B' => 1]);
        $this->assertSame([667, 334], array_column($split, 'cents'));
        $this->assertSame(1001, array_sum(array_column($split, 'cents')));
    }
}
