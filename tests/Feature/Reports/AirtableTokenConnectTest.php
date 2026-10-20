<?php

namespace Tests\Feature\Reports;

use App\Models\SocialConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Jose, 2026-10-20: one token, tick the bases; replacing the token swaps it everywhere. */
class AirtableTokenConnectTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private const PNL = 'appWhbwht0xgB5Yut';

    private const REP = 'appzD9I7nNkALmw4l';

    private const PAY = 'app5aENjfgesVBPLD';

    /** A fake Airtable where `pat_old` reaches PNL and REP, `pat_new` all three, `pat_small` only PNL. */
    private function airtable(): void
    {
        $reach = ['pat_old' => [self::PNL, self::REP], 'pat_new' => [self::PNL, self::REP, self::PAY], 'pat_small' => [self::PNL]];
        $names = [self::PNL => 'Profit And Loss', self::REP => 'Repair Teams', self::PAY => 'Technicians Payrolls'];

        Http::fake(function (Request $request) use ($reach, $names) {
            $token = substr($request->header('Authorization')[0] ?? '', 7);
            $bases = $reach[$token] ?? null;
            if ($bases === null) {
                return Http::response([], 401);
            }
            if (preg_match('#/meta/bases/(app\w+)/tables#', $request->url(), $m)) {
                return in_array($m[1], $bases, true) ? Http::response(['tables' => [['name' => 'Main']]]) : Http::response([], 403);
            }

            return Http::response(['bases' => array_map(fn ($id) => ['id' => $id, 'name' => $names[$id], 'permissionLevel' => 'create'], $bases)]);
        });
    }

    public function test_one_token_lists_its_bases_connects_the_ticked_ones_and_unticking_disconnects(): void
    {
        $this->airtable();
        $t = $this->provisionTenant('Alpha');
        $as = $this->signedInAs($t['admin']);

        $listed = $as->postJson('/api/v1/social/airtable/bases', ['api_token' => 'pat_old'])->assertOk()->json('data');
        $this->assertSame(['Profit And Loss', 'Repair Teams'], array_column($listed, 'name'));
        $this->assertSame([false, false], array_column($listed, 'connected'));

        $as->postJson('/api/v1/social/airtable/connect', ['api_token' => 'pat_old', 'base_ids' => [self::PNL, self::REP]])->assertOk();
        $listed = $as->postJson('/api/v1/social/airtable/bases', ['api_token' => 'pat_old'])->json('data');
        $this->assertSame([true, true], array_column($listed, 'connected'));

        $as->postJson('/api/v1/social/airtable/connect', ['api_token' => 'pat_old', 'base_ids' => [self::PNL]])->assertOk()->assertJsonPath('data.disconnected', 1);
        $this->inTenant($t['org'], fn () => $this->assertSame([self::PNL], SocialConnection::query()->pluck('remote_id')->all()));

        $as->postJson('/api/v1/social/airtable/bases', ['api_token' => 'pat_wrong'])->assertStatus(422)->assertJsonValidationErrors('api_token');
    }

    public function test_replacing_the_token_swaps_every_base_or_none(): void
    {
        $this->airtable();
        $t = $this->provisionTenant('Alpha');
        $as = $this->signedInAs($t['admin']);
        $as->postJson('/api/v1/social/airtable/connect', ['api_token' => 'pat_old', 'base_ids' => [self::PNL, self::REP]])->assertOk();

        // A token that misses one base changes nothing.
        $as->postJson('/api/v1/social/airtable/replace-token', ['api_token' => 'pat_small'])->assertStatus(422)
            ->assertJsonFragment(['api_token' => ['The new token cannot reach: Repair Teams (That token cannot reach that base. Check the base id, and that the token was granted access to it.) Nothing was changed.']]);
        $this->inTenant($t['org'], fn () => $this->assertSame(['pat_old'], SocialConnection::query()->get()->map(fn ($c) => $c->credentials['api_token'])->unique()->values()->all()));

        $as->postJson('/api/v1/social/airtable/replace-token', ['api_token' => 'pat_new'])->assertOk()->assertJsonPath('data.replaced', 2);
        $this->inTenant($t['org'], fn () => $this->assertSame(['pat_new'], SocialConnection::query()->get()->map(fn ($c) => $c->credentials['api_token'])->unique()->values()->all()));
    }
}
