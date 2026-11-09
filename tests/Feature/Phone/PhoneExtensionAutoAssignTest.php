<?php

namespace Tests\Feature\Phone;

use App\Models\PhoneConnection;
use App\Models\PhoneExtension;
use App\Support\Phone\ExtensionAssigner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Jose, 2026-11-09: "Extensions should be automatically assigned." */
class PhoneExtensionAutoAssignTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_people_without_one_get_the_next_free_extension_alphabetically(): void
    {
        $t = $this->provisionTenant();
        $editor = $this->userWithRole($t, 'Zed Admin', ['phone.view', 'phone.settings.view', 'phone.edit']);
        $kept = $this->userWithRole($t, 'Mia Kept', ['phone.view']);
        $bea = $this->userWithRole($t, 'Bea First', ['phone.view']);
        $carl = $this->userWithRole($t, 'Carl Second', ['phone.view']);
        $gone = $this->userWithRole($t, 'Removed', ['phone.view']);
        $this->inTenant($t["org"], fn () => $gone->forceFill(["is_active" => false])->save());

        $connection = $this->inTenant($t['org'], function () use ($t, $kept) {
            $connection = PhoneConnection::factory()->create([
                'scope_type' => PhoneConnection::SCOPE_ORGANIZATION,
                'scope_id' => $t['org']->id,
                'status' => PhoneConnection::STATUS_CONNECTED,
            ]);
            // 0101 is held and stays; 0102 is a team's, so nobody may get it.
            PhoneExtension::query()->create(['organization_id' => $t['org']->id, 'connection_id' => $connection->id, 'user_id' => $kept->id, 'extension' => '0101']);
            PhoneExtension::query()->create(['organization_id' => $t['org']->id, 'connection_id' => $connection->id, 'extension' => '0102']);

            return $connection;
        });

        // Opening the Users list assigns.
        $rows = collect($this->signedInAs($editor)->getJson('/api/v1/phone/extensions')->assertOk()->json('data'))
            ->pluck('extension', 'user_id');

        $this->assertSame('0101', $rows[$kept->id]);
        // The tenant's own admin is listed too, so order is asserted rather than exact digits.
        $this->assertNotContains(null, $rows->all());
        $this->assertNotContains('0102', $rows->all());
        $this->assertSame($rows->count(), $rows->unique()->count());
        $this->assertMatchesRegularExpression('/^0\d{3}$/', $rows[$bea->id]);
        $this->assertLessThan($rows[$carl->id], $rows[$bea->id]);
        $this->assertLessThan($rows[$editor->id], $rows[$carl->id]);
        $this->assertFalse($rows->has($gone->id));
        $this->inTenant($t['org'], fn () => $this->assertSame(0, PhoneExtension::query()->where('user_id', $gone->id)->count()));

        // Run again: nothing changes. An admin can still change one, and a duplicate is refused.
        $this->assertSame(0, ExtensionAssigner::assignMissing($t['org']));
        $this->signedInAs($editor)->putJson('/api/v1/phone/extensions', ['connection_id' => $connection->id, 'user_id' => $bea->id, 'extension' => '0200'])->assertOk();
        $this->signedInAs($editor)->putJson('/api/v1/phone/extensions', ['connection_id' => $connection->id, 'user_id' => $carl->id, 'extension' => '0200'])->assertStatus(422);
    }
}
