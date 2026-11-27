<?php

namespace Tests\Feature\Workiz;

use App\Models\SocialConnection;
use App\Models\WorkizJob;
use App\Support\Social\SocialProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Reports audit, 2026-11-10: "closed" read last_status_update, which moves
 * with every later edit, so a job done Monday and edited Wednesday left
 * Monday's count. closed_at is when the sweep first saw it Done or Canceled.
 */
class WorkizJobClosedAtTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_closed_at_is_the_first_close_kept_through_later_edits_and_cleared_on_reopening(): void
    {
        $t = $this->provisionTenant('Alpha');

        $closedAt = $this->inTenant($t['org'], function () use ($t) {
            $c = new SocialConnection;
            $c->forceFill(['organization_id' => $t['org']->id, 'scope_type' => SocialConnection::SCOPE_FRANCHISE, 'scope_id' => $t['franchise']->id,
                'provider' => SocialProvider::Workiz->value, 'remote_id' => $t['franchise']->id, 'display_name' => 'NJ']);
            $c->connect(['api_token' => 'k']);
            $c->save();
            $land = function (string $status, string $changed) use ($c) {
                WorkizJob::land($c, ['UUID' => 'J1', 'Status' => $status, 'CreatedDate' => '2026-11-01 09:00:00', 'LastStatusUpdate' => $changed]);

                return WorkizJob::query()->sole()->closed_at?->format('Y-m-d H:i');
            };

            return [
                $land('Submitted', '2026-11-01 09:00:00'),
                $land('Done', '2026-11-02 15:00:00'),
                $land('Done', '2026-11-04 11:00:00'),   // an edit after it closed
                $land('In progress', '2026-11-05 08:00:00'),
                $land('Canceled', '2026-11-06 10:00:00'),
            ];
        });

        $this->assertSame([null, '2026-11-02 15:00', '2026-11-02 15:00', null, '2026-11-06 10:00'], $closedAt);
    }
}
