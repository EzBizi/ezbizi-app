<?php

namespace Tests\Feature\WhatsApp;

use App\Support\Ai\AppAsk;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** One worker process answers several people in a row; each must be read as themselves. */
class InternalRequestIdentityTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_two_people_in_one_process_are_each_read_as_themselves(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $beta = $this->provisionTenant('Beta');

        $first = $this->inTenant($alpha['org'], fn () => AppAsk::fetch($alpha['admin']->fresh(), [['path' => 'me', 'query' => '']]));
        $second = $this->inTenant($beta['org'], fn () => AppAsk::fetch($beta['admin']->fresh(), [['path' => 'me', 'query' => '']]));

        $this->assertSame($alpha['admin']->email, data_get(json_decode(json_encode($first), true), 'me.data.email'));
        $this->assertSame($beta['admin']->email, data_get(json_decode(json_encode($second), true), 'me.data.email'));
    }
}
