<?php

namespace Tests\Feature\Phone;

use App\Models\PhoneConnection;
use App\Models\PhoneRingGroup;
use App\Models\PhoneVoicemailBox;
use App\Support\Phone\ConnectionDefaults;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Jose, 2026-11-12: a connection comes out answering calls, without anybody asking. */
class PhoneConnectionDefaultsTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_an_empty_account_gets_a_ring_group_and_voicemail_once(): void
    {
        $t = $this->provisionTenant();
        $this->userWithRole($t, 'Desk', ['phone.view']);

        $this->inTenant($t['org'], function () use ($t) {
            $connection = PhoneConnection::factory()->create([
                'scope_type' => PhoneConnection::SCOPE_ORGANIZATION,
                'scope_id' => $t['org']->id,
                'status' => PhoneConnection::STATUS_CONNECTED,
            ]);

            $this->assertSame(['voicemail box', 'ring group'], ConnectionDefaults::ensure($connection));
            $connection->refresh();
            $group = PhoneRingGroup::query()->find($connection->default_ring_group_id);
            $this->assertSame('Everyone', $group->name);
            $this->assertNotEmpty($group->member_role_ids);
            $this->assertNotNull(PhoneVoicemailBox::query()->find($connection->default_voicemail_box_id));

            // A second run changes nothing: a choice is never replaced.
            $this->assertSame([], ConnectionDefaults::ensure($connection));
            $this->assertSame(1, PhoneRingGroup::query()->where('connection_id', $connection->id)->count());
        });

        $this->artisan('ezbizi:phone-assign-extensions')->assertSuccessful();
    }
}
