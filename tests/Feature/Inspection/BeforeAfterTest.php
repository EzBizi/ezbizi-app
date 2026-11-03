<?php

namespace Tests\Feature\Inspection;

use App\Models\Attachment;
use App\Models\Customer;
use App\Models\InspectionDefinition;
use App\Models\InspectionDefinitionVersion;
use App\Models\InspectionReport;
use App\Models\Job;
use App\Support\Inspection\BeforeAfter;
use App\Support\Inspection\ReportDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Jose 2026-11-03: arrival photos pair with the finished-job photos of the same job. */
class BeforeAfterTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_an_after_photo_pairs_with_the_arrival_photo_of_the_same_key_on_the_same_job(): void
    {
        Storage::fake('local');
        $tenant = $this->provisionTenant('Alpha');

        $this->inTenant($tenant['org'], function () use ($tenant) {
            $customer = Customer::create(['owning_franchise_id' => $tenant['franchise']->id, 'number' => 'C-1', 'name' => 'Dana']);
            $job = Job::create(['franchise_id' => $tenant['franchise']->id, 'customer_id' => $customer->id, 'number' => 'J-1', 'title' => 'Sweep', 'status' => 'scheduled']);
            $other = Job::create(['franchise_id' => $tenant['franchise']->id, 'customer_id' => $customer->id, 'number' => 'J-2', 'title' => 'Other', 'status' => 'scheduled']);

            $before = $this->report($tenant, $job, 'system_image', 'System image', '2026-11-03 09:00:00');
            $this->report($tenant, $other, 'system_image', 'System image', '2026-11-03 09:30:00'); // another job: never the pair
            $after = $this->report($tenant, $job, 'after_system_image', 'System after', '2026-11-03 12:00:00');

            $pairs = BeforeAfter::pairs($after->fresh());

            $this->assertCount(1, $pairs);
            $this->assertSame('System after', $pairs[0]['label']);
            $this->assertSame($before->attachments->first()->id, $pairs[0]['before']->id);
            $this->assertStringContainsString('Before &amp; after', app(ReportDocument::class)->html($after->fresh()));
            $this->assertSame([], BeforeAfter::pairs($before->fresh()));

            BeforeAfter::label($after->fresh());
            $this->assertSame('before', $before->attachments->first()->fresh()->label);
            $this->assertSame('after', $after->attachments->first()->fresh()->label);
        });
    }

    private function report(array $tenant, Job $job, string $key, string $label, string $at): InspectionReport
    {
        $screens = [['name' => 'Photos', 'fields' => [['key' => $key, 'label' => $label, 'type' => 'photo']]]];
        $definition = InspectionDefinition::create(['name' => $label.' '.$at, 'created_by' => $tenant['admin']->id, 'screens' => $screens]);
        $version = InspectionDefinitionVersion::create(['inspection_definition_id' => $definition->id, 'version' => 1,
            'layout_preset' => 'step_strip', 'screens' => $screens, 'published_by' => $tenant['admin']->id]);
        $report = InspectionReport::create(['customer_id' => $job->customer_id, 'job_id' => $job->id,
            'inspection_definition_id' => $definition->id, 'inspection_definition_version_id' => $version->id,
            'submitted_by' => $tenant['admin']->id, 'submitted_at' => $at, 'answers' => [],
            'layout_preset' => 'step_strip', 'definition_name' => $label]);

        $path = 'photos/'.$key.$at.'.png';
        Storage::disk('local')->put($path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
        $photo = new Attachment(['attachable_type' => $report->getMorphClass(), 'attachable_id' => $report->id, 'original_name' => 'p.png', 'mime' => 'image/png', 'uploaded_by_user_id' => $tenant['admin']->id]);
        $photo->forceFill(['disk' => 'local', 'path' => $path, 'size_bytes' => 70, 'checksum' => hash('sha256', $path)])->save();

        // Answers are written once; a report is born with them, so recreate the row's answers via the DB.
        \DB::table('inspection_reports')->where('id', $report->id)->update(['answers' => json_encode([$key => [$photo->id]])]);

        return $report->fresh('attachments');
    }
}
