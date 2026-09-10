<?php

namespace Tests\Feature\Reports;

use App\Support\Reports\ReportMap;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

            foreach ($resource['scope'] as $column) {
                $this->assertContains(
                    $column,
                    $actual,
                    "{$key} declares scope column '{$column}' which {$resource['table']} does not have",
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

            foreach (array_keys($resource['columns']) as $column) {
                $this->assertContains(
                    $column,
                    $actual,
                    "{$key} declares column '{$column}' which {$resource['table']} does not have",
                );
            }
        }
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

    public function test_get_returns_null_for_an_unknown_resource(): void
    {
        $this->assertNull(ReportMap::get('not_a_resource'));
    }
}
