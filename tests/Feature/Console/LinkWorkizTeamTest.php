<?php

namespace Tests\Feature\Console;

use App\Models\WorkizTeamMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

class LinkWorkizTeamTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_links_by_email_then_name_leaves_ambiguous_and_existing_alone(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $byEmail = $this->userWithRole($alpha, 'Tech', ['jobs.view']);
        $byName = $this->userWithRole($alpha, 'Tech2', ['jobs.view']);
        $twinA = $this->userWithRole($alpha, 'Tech3', ['jobs.view']);
        $twinB = $this->userWithRole($alpha, 'Tech4', ['jobs.view']);
        $this->inTenant($alpha['org'], function () use ($byName, $twinA, $twinB) {
            $byName->forceFill(['name' => 'Chaim  Perez'])->save();
            $twinA->forceFill(['name' => 'Same Name'])->save();
            $twinB->forceFill(['name' => 'Same Name'])->save();
        });

        $m = fn (string $r, array $a) => $this->inTenant($alpha['org'], fn () => WorkizTeamMember::create($a + [
            'franchise_id' => $alpha['franchise']->id, 'remote_id' => $r, 'payload' => [], 'synced_at' => now()])->id);
        $e = $m('U1', ['name' => 'X', 'email' => strtoupper($byEmail->email)]);
        $n = $m('U2', ['name' => 'chaim perez', 'email' => 'nobody@else.test']);
        $amb = $m('U3', ['name' => 'Same Name']);
        $kept = $m('U4', ['name' => 'Chaim Perez', 'user_id' => $twinA->id]);
        $unlinked = $m('U5', ['name' => 'Chaim Perez', 'link_confirmed_at' => now()]);

        $read = fn (string $id) => $this->inTenant($alpha['org'], fn () => WorkizTeamMember::query()->find($id)->user_id);

        $this->artisan('workiz:link-team --dry-run')->assertSuccessful();
        $this->assertNull($read($e), 'dry run writes nothing');

        $this->artisan('workiz:link-team')->expectsOutputToContain('(email 1, name 1); ambiguous 1')->assertSuccessful();
        $this->assertSame([$byEmail->id, $byName->id, null, $twinA->id, null],
            [$read($e), $read($n), $read($amb), $read($kept), $read($unlinked)]);
    }
}
