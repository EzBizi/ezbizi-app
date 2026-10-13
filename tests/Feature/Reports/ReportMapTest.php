<?php

namespace Tests\Feature\Reports;

use App\Support\Modules\ModuleRegistry;
use App\Support\Reports\ReportMap;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The map is the only thing that makes a table joinable, so it is also the
 * only place a table can arrive without a scope rule. This test is that gate.
 *
 * RefreshDatabase is here because most of it reads the real schema. Against an
 * unmigrated database `getColumnListing` returns an empty array, which would
 * report every correct column as missing — so each such test asserts the list
 * is non-empty first, and says why, rather than failing misleadingly.
 */
class ReportMapTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_resource_declares_a_model_table_and_permission(): void
    {
        $this->assertNotEmpty(ReportMap::all(), 'the map is empty, so nothing below asserts anything');

        foreach (ReportMap::all() as $key => $resource) {
            $this->assertArrayHasKey('model', $resource, "{$key} has no model");
            $this->assertTrue(class_exists($resource['model']), "{$key} model does not exist");
            $this->assertNotEmpty($resource['table'], "{$key} has no table");
            $this->assertNotEmpty($resource['permission'], "{$key} has no permission");
        }
    }

    /**
     * The scope columns are named rather than derived from a level word.
     *
     * An earlier draft declared a level — 'company', 'franchise' — and the
     * runner turned that into a company_id filter. `jobs` has no company_id
     * column at all, so that would have emitted SQL against a column which
     * does not exist. A level is a story about a table; a column list is the
     * table.
     */
    public function test_every_scope_column_exists_on_its_table(): void
    {
        foreach (ReportMap::all() as $key => $resource) {
            $actual = Schema::getColumnListing($resource['table']);

            $this->assertNotEmpty($actual, "{$resource['table']} is not migrated; this test cannot speak");

            $this->assertContains(
                'organization_id',
                $actual,
                "{$key} is reportable but {$resource['table']} has no organization_id, "
                .'so nothing can confine it to one tenant',
            );

            foreach (array_keys($resource['scope']) as $column) {
                $this->assertContains(
                    $column,
                    $actual,
                    "{$key} declares scope column '{$column}' which {$resource['table']} does not have",
                );
            }
        }
    }

    /**
     * The tree is meant to read like the sidebar, so a module key that matches
     * no registered module would produce a heading pointing at a tab that does
     * not exist.
     */
    public function test_every_resource_names_a_registered_module(): void
    {
        $modules = array_map(
            fn ($m) => $m->key,
            app(ModuleRegistry::class)->all(),
        );

        foreach (ReportMap::all() as $key => $resource) {
            $this->assertNotEmpty($resource['module'], "{$key} names no module");
            $this->assertContains(
                $resource['module'],
                $modules,
                "{$key} says it belongs to module '{$resource['module']}', which is not "
                .'registered. The field tree is meant to read like the sidebar, so a key '
                .'matching no tab points somewhere nobody can go.',
            );
        }
    }

    /**
     * The map names a SOURCE per scope column, and the runner must know every
     * one of them. An unknown source throws at run time; this fails at test
     * time instead, which is the difference between a report nobody can run
     * and a build nobody can merge.
     */
    public function test_every_scope_source_is_one_the_runner_can_resolve(): void
    {
        $known = ['franchise', 'place', 'people', 'task_board'];

        foreach (ReportMap::all() as $key => $resource) {
            foreach ($resource['scope'] as $column => $source) {
                $this->assertContains(
                    $source,
                    $known,
                    "{$key} scopes '{$column}' by '{$source}', which ReportRunner cannot "
                    .'resolve. Add the case to ReportRunner::applyScope and to this list, '
                    .'in that order.',
                );
            }
        }
    }

    /**
     * A table with no scope beyond the tenant is a real thing — customers are
     * shared organization-wide on purpose — but it must be a decision somebody
     * wrote down, not an empty array nobody noticed.
     */
    public function test_a_resource_with_no_scope_says_why(): void
    {
        foreach (ReportMap::all() as $key => $resource) {
            if ($resource['scope'] !== []) {
                $this->assertNull(
                    $resource['shared_because'],
                    "{$key} is scoped AND claims to be shared; one of the two is wrong",
                );

                continue;
            }

            $this->assertNotEmpty(
                $resource['shared_because'],
                "{$key} has no scope columns and does not say why — an absence has to be "
                .'a statement, or the next reader cannot tell it from an oversight',
            );
        }
    }

    public function test_soft_deleting_tables_are_declared_as_such(): void
    {
        foreach (ReportMap::all() as $key => $resource) {
            $actual = Schema::getColumnListing($resource['table']);

            $this->assertNotEmpty($actual, "{$resource['table']} is not migrated; this test cannot speak");

            $this->assertSame(
                in_array('deleted_at', $actual, true),
                $resource['soft_deletes'],
                "{$key} disagrees with {$resource['table']} about soft deletes. A table with "
                .'deleted_at that is not declared will report deleted rows as live, because '
                .'the runner queries the table directly and never loads the model.',
            );
        }
    }

    public function test_every_relation_points_at_a_declared_resource(): void
    {
        $declared = array_keys(ReportMap::all());

        foreach (ReportMap::all() as $key => $resource) {
            foreach ($resource['relations'] as $name => $edge) {
                $this->assertContains(
                    $edge['to'],
                    $declared,
                    "{$key}.{$name} joins to '{$edge['to']}', which is not in the map",
                );
                $this->assertNotEmpty($edge['local'], "{$key}.{$name} has no local column");
                $this->assertNotEmpty($edge['foreign'], "{$key}.{$name} has no foreign column");
            }
        }
    }

    public function test_every_declared_column_exists_on_the_table(): void
    {
        foreach (ReportMap::all() as $key => $resource) {
            $actual = Schema::getColumnListing($resource['table']);

            $this->assertNotEmpty($actual, "{$resource['table']} is not migrated; this test cannot speak");

            foreach ($resource['columns'] as $column => $declared) {
                if (isset($declared['php'])) {
                    // Computed in PHP: no table column, and the method must exist.
                    $this->assertNotContains($column, $actual, "{$key}.{$column} is php AND a table column");
                    $this->assertTrue(
                        method_exists($declared['php'][0], $declared['php'][1]),
                        "{$key}.{$column} names a method that does not exist",
                    );

                    continue;
                }

                if (isset($declared['sql'])) {
                    /*
                     * A DERIVED COLUMN HAS NO TABLE COLUMN, BY DEFINITION — and
                     * it is checked by the test below rather than skipped.
                     *
                     * Exempting it here without asserting anything about it is
                     * how `sql` would become a way to silence this guard: a
                     * mistyped real column with a stray `sql` key would pass
                     * both the exemption and the original assertion.
                     */
                    $this->assertNotContains(
                        $column,
                        $actual,
                        "{$key}.{$column} is declared as derived AND exists on {$resource['table']} — "
                        .'one of the two is a mistake, and a real column read through a subquery '
                        .'is the slower and less obvious of them',
                    );

                    continue;
                }

                $this->assertContains(
                    $column,
                    $actual,
                    "{$key} declares column '{$column}' which {$resource['table']} does not have",
                );
            }
        }
    }

    /**
     * A DERIVED COLUMN'S SQL RUNS, and returns one value per row.
     *
     * `Schema::getColumnListing` cannot check a subquery, so without this a
     * typo in one would reach a customer's report as a database error at the
     * moment they pressed Run — the one place where a report is least able to
     * explain itself.
     *
     * Executed against the real schema rather than pattern-matched: the failure
     * being guarded against is SQL that does not run, and only running it says
     * that. Asserted for every derived column in the map, so a resource adding
     * one later is covered without anybody remembering this test exists.
     */
    public function test_every_derived_column_is_runnable_sql(): void
    {
        $derived = 0;

        foreach (ReportMap::all() as $key => $resource) {
            foreach ($resource['columns'] as $column => $declared) {
                if (! isset($declared['sql'])) {
                    continue;
                }

                $derived++;

                // Resolved through ReportMap's own substitution, not a copy
                // of it: a guard that fills placeholders differently from the
                // runner proves a different string is valid.
                $value = DB::table($resource['table'])
                    ->selectRaw('('.ReportMap::resolve($declared['sql'], CarbonImmutable::parse('2026-09-23')).') as v')
                    ->limit(1)
                    ->value('v');

                // Null is a legitimate answer for a table with no rows; what is
                // being asserted is that the statement PREPARED and ran.
                $this->assertTrue(
                    $value === null || is_numeric($value) || is_string($value),
                    "{$key}.{$column} did not return a scalar",
                );
            }
        }

        $this->assertGreaterThan(
            0,
            $derived,
            'no column in the map is derived, so this test could not distinguish '
            .'working SQL from none at all',
        );
    }

    public function test_every_relation_column_exists_on_both_tables(): void
    {
        foreach (ReportMap::all() as $key => $resource) {
            foreach ($resource['relations'] as $name => $edge) {
                $this->assertContains(
                    $edge['local'],
                    Schema::getColumnListing($resource['table']),
                    "{$key}.{$name} joins from '{$edge['local']}', which {$resource['table']} does not have",
                );

                $target = ReportMap::get($edge['to']);

                $this->assertContains(
                    $edge['foreign'],
                    Schema::getColumnListing($target['table']),
                    "{$key}.{$name} joins to '{$edge['foreign']}', which {$target['table']} does not have",
                );
            }
        }
    }

    /**
     * Nothing that would hand somebody a credential may be reportable. This is
     * a belt on top of the explicit column lists: the lists are the mechanism,
     * and this fails loudly if one ever grows a column it should not.
     */
    public function test_no_secret_column_is_reportable(): void
    {
        $forbidden = [
            'password', 'remember_token', 'totp_secret', 'pending_totp_secret',
            'recovery_codes', 'sms_code_hash', 'last_totp_timestamp',
        ];

        foreach (ReportMap::all() as $key => $resource) {
            foreach (array_keys($resource['columns']) as $column) {
                $this->assertNotContains(
                    $column,
                    $forbidden,
                    "{$key} reports '{$column}', which is a credential",
                );
            }
        }
    }

    /**
     * TWO EDGES FROM ONE RESOURCE TO THE SAME TARGET NEED LABELS.
     *
     * The field picker names a hop by its TARGET — "Jobs › Customers" — because
     * the map declares no human label for an edge, and today it does not need
     * one: there is a single relation in the whole product. The moment a second
     * edge from the same resource reaches the same target (`customer` and
     * `billing_contact` both landing on customers), the tree shows the
     * identical phrase twice and the target stops identifying the edge. That is
     * not a cosmetic gap — it is the picker misreporting which way through the
     * graph a column came from.
     *
     * So the trigger is enforced rather than written down: add the second edge
     * and this goes red, naming what it needs. Condition agreed with UI/UX
     * (7d4a286a) after they measured the map and found the single relation.
     *
     * Watched red by temporarily declaring a second `billing_contact` edge to
     * customers on jobs; the failure named both edges.
     */
    public function test_two_relations_to_the_same_target_would_need_labels(): void
    {
        foreach (ReportMap::all() as $key => $resource) {
            $targets = array_column($resource['relations'], 'to');
            $duplicated = array_keys(array_filter(array_count_values($targets), fn ($n) => $n > 1));

            $this->assertSame(
                [],
                $duplicated,
                "{$key} declares more than one relation to '".implode("', '", $duplicated)."'. "
                .'The field picker names a hop by its target, so two edges to the same place '
                .'draw the identical phrase twice and no longer say which one a column came '
                .'through. Give every relation on this resource a human label, render that '
                .'instead of the target name, and delete this assertion.',
            );
        }
    }

    public function test_get_returns_null_for_an_unknown_resource(): void
    {
        $this->assertNull(ReportMap::get('not_a_resource'));
    }
}
