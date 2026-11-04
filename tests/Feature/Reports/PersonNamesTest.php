<?php

namespace Tests\Feature\Reports;

use App\Support\Reports\Experimental\CallReconciliation;
use App\Support\Reports\Experimental\PersonNames;
use App\Support\Reports\Experimental\RingCentralCallLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * One person's name across the phones and Workiz, from data (Jose, 2026-11-04:
 * "Shouldnt u be able to update them without an update?").
 */
class PersonNamesTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_a_stored_pair_then_workiz_then_one_first_and_last_match(): void
    {
        $names = new PersonNames(
            ['Jay Ticoit' => 'Dieuve Ticoit', 'Rolando De Lara' => 'Rolando De Lara Jr'],
            ['Dieuve Ticoit', 'Cgrey S. Lumacad', 'Angel  F. Mozol', 'Rolando De Lara Jr', 'Christine Malana',
                'Ana Lopez', 'Ana Maria Lopez', 'Rachelle'],
        );

        $this->assertSame('Dieuve Ticoit', $names->canonical('Jay Ticoit'), 'stored pair');
        $this->assertSame('Rolando De Lara Jr', $names->canonical('Rolando De Lara'), 'stored pair');
        $this->assertSame('Christine Malana', $names->canonical('Christine Malana'), 'Workiz as it is');
        $this->assertSame('Cgrey S. Lumacad', $names->canonical('Cgrey Lumacad'), 'an initial aside');
        $this->assertSame('Angel F. Mozol', $names->canonical('Angel Mozol'), "Workiz's double space tidied");
        $this->assertSame('Angel F. Mozol', $names->canonical('Angel  F. Mozol'), 'a Workiz creator, the same person');
        $this->assertNull($names->canonical('  '), 'blank is nobody');

        // Never by resemblance alone: two Workiz people share the first and
        // last word; a suffix is a word; one name is no first-and-last.
        $this->assertSame('Ana Lopez', $names->canonical('Ana Lopez'));
        $this->assertSame('Ana R. Lopez', $names->canonical('Ana R. Lopez'), 'two candidates: neither');
        $this->assertSame('Rachelle Marie Mangasep', $names->canonical('Rachelle Marie Mangasep'));
        $this->assertSame('Rolando Lara', $names->canonical('Rolando Lara'), 'Jr is not dropped');

        $this->assertTrue($names->inWorkiz('Christine Malana'));
        $this->assertFalse($names->inWorkiz('Brian Scheets'));
    }

    /**
     * End to end: a pair added on Settings › Lists (one API call, the app's or
     * the MCP's) changes the report on the next read, and goes when deleted;
     * Workiz's team list says who has an account even when they booked nothing.
     */
    public function test_a_pair_added_as_data_changes_the_next_report(): void
    {
        $t = $this->provisionTenant('Alpha');
        DB::table('workiz_team_members')->insert(['id' => (string) Str::uuid(), 'organization_id' => $t['org']->id,
            'franchise_id' => $t['franchise']->id, 'remote_id' => 'U1', 'name' => 'Christine Malana', 'payload' => '{}', 'synced_at' => now()]);

        $report = fn () => $this->inTenant($t['org'], fn () => app(CallReconciliation::class)->forDay(
            [$this->leg('c1', 'Jay Ticoit'), $this->leg('c2', 'Christine Malana')], [], '2026-09-14'));

        $before = array_column($report()['people'], null, 'person');
        $this->assertArrayHasKey('Jay Ticoit', $before);
        $this->assertTrue($before['Christine Malana']['in_jobs'], 'on the team list, no jobs booked');

        $lists = '/api/v1/settings/lists/reports.staff_names/items';
        $this->signedInAs($t['admin'])->postJson($lists, ['value' => ['phone_name' => 'Jay Ticoit', 'name' => 'Dieuve Ticoit']])
            ->assertSuccessful()->assertJsonPath('data.key', 'Jay Ticoit');
        $this->assertSame(['Christine Malana', 'Dieuve Ticoit'], array_column($report()['people'], 'person'));

        $this->signedInAs($t['admin'])->deleteJson($lists.'/'.rawurlencode('Jay Ticoit'))->assertSuccessful();
        $this->assertContains('Jay Ticoit', array_column($report()['people'], 'person'));

        // Editing it is reports.handles, the list's own permission.
        $viewer = $this->userWithRole($t, 'Watcher', ['reports.view', 'organizations.settings.view']);
        $this->signedInAs($viewer)->postJson($lists, ['value' => ['phone_name' => 'X Y', 'name' => 'Z']])->assertForbidden();
    }

    /** @return array<string, mixed> one call as RingCentralCallLog hands it over */
    private function leg(string $id, string $who): array
    {
        return ['id' => $id, 'direction' => 'Inbound', 'started_at' => '2026-09-14T14:00:00Z', 'from' => '+12125550000',
            'queue' => RingCentralCallLog::EXISTING_CLIENT_QUEUE, 'result' => 'Accepted', 'outcome' => 'answered',
            'rang' => [$who], 'answered_by' => $who, 'talk_seconds' => 60, 'recording_id' => null];
    }
}
