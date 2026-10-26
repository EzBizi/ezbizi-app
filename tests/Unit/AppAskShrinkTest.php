<?php

namespace Tests\Unit;

use App\Support\Ai\AppAsk;
use PHPUnit\Framework\TestCase;

/** Prod 2026-10-26: a cut list must still say how many per branch, by branch id. */
class AppAskShrinkTest extends TestCase
{
    public function test_a_cut_list_keeps_its_counts_per_branch_id_and_per_short_field(): void
    {
        $rows = [];
        foreach ([['a1-ct', 'Connecticut', 'Sweep'], ['a1-ct', 'Connecticut', 'Sweep'], ['ever-ct', 'Connecticut', 'Estimate']] as [$id, $name, $type]) {
            $rows[] = ['id' => uniqid(), 'franchise_id' => $id, 'franchise_name' => $name, 'job_type' => $type, 'customer_id' => uniqid()];
        }

        $out = AppAsk::shrink(['scheduled' => $rows], 1)['scheduled'];

        $this->assertSame(3, $out['_total']);
        $this->assertSame(['a1-ct' => 2, 'ever-ct' => 1], $out['_counts']['franchise_id'], 'two branches with one name stay apart');
        $this->assertSame(['Sweep' => 2, 'Estimate' => 1], $out['_counts']['job_type']);
        $this->assertArrayNotHasKey('customer_id', $out['_counts'], 'other ids are not groupings');
        $this->assertArrayNotHasKey('franchise_name', $out['_counts'], 'one value is not a grouping');
    }
}
