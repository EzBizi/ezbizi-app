<?php

namespace Tests\Feature\Reports;

use App\Support\Reports\RelationGraph;
use App\Support\Reports\ReportMap;
use Tests\TestCase;

/**
 * The walk over declared edges. No database: this is about the map only.
 */
class RelationGraphTest extends TestCase
{
    public function test_a_resource_reaches_itself_in_no_hops(): void
    {
        $this->assertSame([], RelationGraph::pathBetween('jobs', 'jobs'));
    }

    public function test_one_declared_edge_is_one_hop(): void
    {
        $path = RelationGraph::pathBetween('jobs', 'customers');

        $this->assertCount(1, $path);
        $this->assertSame('jobs', $path[0]['from']);
        $this->assertSame('customers', $path[0]['to']);
        $this->assertSame('customer_id', $path[0]['local']);
        $this->assertSame('id', $path[0]['foreign']);
    }

    /**
     * `users` is in the map and jobs declares no edge to it, which makes it the
     * control: unreachable because of the EDGES, not because the target is
     * unknown. Without it, a graph that returned null for everything would pass
     * the unknown-target test below and look correct.
     *
     * When somebody later declares that edge, this assertion goes red. That is
     * the point — change it in the same commit as the edge, deliberately.
     */
    public function test_a_mapped_resource_with_no_edge_to_it_is_unreachable(): void
    {
        $this->assertNotNull(ReportMap::get('users'), 'users must be mapped for this to be a control');
        $this->assertNull(RelationGraph::pathBetween('jobs', 'users'));
    }

    public function test_an_unknown_target_is_unreachable_rather_than_guessed(): void
    {
        $this->assertNull(RelationGraph::pathBetween('jobs', 'not_a_resource'));
    }

    public function test_an_unknown_root_is_unreachable(): void
    {
        $this->assertNull(RelationGraph::pathBetween('not_a_resource', 'jobs'));
    }

    /**
     * Every mapped resource must reach itself. Cheap, and it fails if somebody
     * adds a resource whose key disagrees with how it is registered.
     */
    public function test_every_mapped_resource_reaches_itself(): void
    {
        foreach (array_keys(ReportMap::all()) as $key) {
            $this->assertSame([], RelationGraph::pathBetween($key, $key), "{$key} cannot reach itself");
        }
    }
}
