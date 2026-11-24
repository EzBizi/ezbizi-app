<?php

namespace Tests\Feature\Scheduling;

use App\Models\Notification;
use App\Models\ServiceArea;
use App\Models\ServiceAreaZip;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Nelly Escobar, suggestion 01a20e5c (Jose 11-24): report a wrong ZIP, an admin moves it. */
class ZipReportsTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_a_reported_zip_is_listed_for_area_editors_and_moving_it_closes_the_report(): void
    {
        $t = $this->provisionTenant('Alpha');
        $dispatcher = $this->userWithRole($t, 'Dispatcher', ['scheduling.view']);
        $editor = $this->userWithRole($t, 'Scheduler', ['scheduling.view', 'scheduling.edit', 'scheduling.settings.view']);
        [$wrong, $right] = $this->inTenant($t['org'], function () use ($t) {
            $f = $t['franchise'];
            $wrong = ServiceArea::create(['franchise_id' => $f->id, 'name' => 'New York']);
            $right = ServiceArea::create(['franchise_id' => $f->id, 'name' => 'Massachusetts']);
            ServiceAreaZip::create(['franchise_id' => $f->id, 'service_area_id' => $wrong->id, 'zip' => '01851', 'source' => 'workiz_jobs']);

            return [$wrong, $right];
        });

        $this->signedInAs($dispatcher)->postJson('/api/v1/scheduling/zip-reports', [
            'zip' => '01851', 'reported_area_id' => $wrong->id, 'suggested_area_id' => $right->id, 'note' => 'Lowell is in MA',
        ])->assertCreated();

        $this->inTenant($t['org'], function () use ($editor, $dispatcher) {
            $this->assertSame(['Wrong ZIP reported: 01851 is in New York, should be Massachusetts'],
                Notification::query()->where('user_id', $editor->id)->pluck('title')->all());
            $this->assertSame(0, Notification::query()->where('user_id', $dispatcher->id)->count());
        });

        $list = $this->signedInAs($editor)->getJson('/api/v1/scheduling/zip-reports')->assertOk();
        $list->assertJsonPath('meta.open', 1)->assertJsonPath('data.0.zip', '01851')
            ->assertJsonPath('data.0.suggested_area.name', 'Massachusetts')->assertJsonPath('data.0.note', 'Lowell is in MA');
        $id = $list->json('data.0.id');

        // A reporter without the edit grant cannot move it.
        $this->signedInAs($dispatcher)->postJson("/api/v1/scheduling/zip-reports/{$id}/move", ['area_id' => $right->id])->assertForbidden();

        $this->signedInAs($editor)->postJson("/api/v1/scheduling/zip-reports/{$id}/move", ['area_id' => $right->id])
            ->assertOk()->assertJsonPath('data.area.name', 'Massachusetts');

        $this->inTenant($t['org'], fn () => $this->assertSame([$right->id],
            ServiceAreaZip::query()->where('zip', '01851')->pluck('service_area_id')->all()));
        $this->inTenant($t['org'], fn () => $this->assertSame('Massachusetts', ServiceArea::forZip('01851')?->name));
        $this->signedInAs($editor)->getJson('/api/v1/scheduling/zip-reports')->assertJsonPath('meta.open', 0);
    }

    public function test_an_editor_can_dismiss_a_report_and_a_viewer_cannot(): void
    {
        $t = $this->provisionTenant('Alpha');
        $dispatcher = $this->userWithRole($t, 'Dispatcher', ['scheduling.view']);
        $editor = $this->userWithRole($t, 'Scheduler', ['scheduling.view', 'scheduling.edit', 'scheduling.settings.view']);
        $id = $this->signedInAs($dispatcher)->postJson('/api/v1/scheduling/zip-reports', ['zip' => '12345'])->assertCreated()->json('data.id');

        $this->signedInAs($dispatcher)->postJson("/api/v1/scheduling/zip-reports/{$id}/dismiss")->assertForbidden();
        $this->signedInAs($editor)->postJson("/api/v1/scheduling/zip-reports/{$id}/dismiss")->assertOk();
        $this->signedInAs($editor)->getJson('/api/v1/scheduling/zip-reports')->assertJsonPath('meta.open', 0);
        $this->signedInAs($dispatcher)->postJson('/api/v1/scheduling/zip-reports', ['zip' => '1234'])->assertUnprocessable();
    }
}
