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
        $this->assertSame('customer_id', $path[0]['from_column']);
        $this->assertSame('id', $path[0]['to_column']);
        $this->assertFalse($path[0]['reversed']);
    }

    /**
     * EDGES ARE WALKED BOTH WAYS, which is what "anything joined to anything"
     * has to mean. A foreign key points one way — jobs holds customer_id — so
     * only `jobs -> customers` would ever be written down, and a report
     * starting at Customers could reach nothing at all. Jose hit exactly that:
     * "A report starting at 'People' cannot reach 'franchises'."
     */
    public function test_an_edge_is_walked_backwards_too(): void
    {
        $path = RelationGraph::pathBetween('customers', 'jobs');

        $this->assertNotNull($path, 'customers cannot reach jobs');
        $this->assertCount(1, $path);
        $this->assertSame('id', $path[0]['from_column']);
        $this->assertSame('customer_id', $path[0]['to_column']);
        $this->assertTrue($path[0]['reversed'], 'a backwards hop must say so; it multiplies rows');
    }

    /** Two hops, the first backwards and the second forwards. */
    public function test_a_route_can_go_back_out_and_forward_again(): void
    {
        $path = RelationGraph::pathBetween('users', 'franchises');

        $this->assertNotNull($path, 'people cannot reach branches');
        $this->assertCount(2, $path);
        $this->assertSame('jobs', $path[0]['to'], 'the route out of people is through jobs');
        $this->assertTrue($path[0]['reversed']);
        $this->assertSame('franchises', $path[1]['to']);
        $this->assertFalse($path[1]['reversed']);
    }

    /**
     * The control, and it still holds with bidirectional walking: a resource
     * NOBODY declared an edge to or from is unreachable. Without it, a graph
     * that returned a path for everything would look correct.
     *
     * This has been re-pointed twice now — once when `performed_by` landed and
     * once when edges became bidirectional — each time deliberately, in the
     * commit that changed the reach. There is no isolated resource in the map
     * today, so the control is an UNKNOWN key, which is the one pair that can
     * never become reachable by adding an edge.
     */
    public function test_a_resource_outside_the_map_is_unreachable_from_anywhere(): void
    {
        foreach (array_keys(ReportMap::all()) as $key) {
            $this->assertNull(
                RelationGraph::pathBetween($key, 'password_reset_tokens'),
                "{$key} claims a route to a table that is not in the map",
            );
        }
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
