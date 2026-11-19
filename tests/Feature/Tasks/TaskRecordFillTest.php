<?php

namespace Tests\Feature\Tasks;

use App\Models\Customer;
use App\Models\Job;
use App\Models\Lead;
use App\Models\TaskBoard;
use App\Models\TaskColumn;
use App\Support\Tasks\TaskRecordFill;
use App\Support\Tasks\TaskWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Jose, 2026-11-19: Area, Reason, Address, Client name and Phone from the job; Resolution never. */
class TaskRecordFillTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_the_boards_own_columns_come_from_the_job_and_reason_from_the_text(): void
    {
        $t = $this->provisionTenant('Alpha');
        $this->inTenant($t['org'], function () use ($t) {
            app(\App\Support\Settings\SettingsResolver::class)->set('leads.line_markets', $t['org'], ['MA' => 'Massachusetts']);
            $board = TaskBoard::query()->where('franchise_id', $t['franchise']->id)->first();
            $col = fn ($name, $labels = null) => TaskColumn::query()->create(['board_id' => $board->id, 'name' => $name, 'type' => $labels ? 'status' : 'text', 'position' => 1,
                'settings' => $labels ? ['options' => array_map(fn ($l) => ['id' => strtolower(str_replace(' ', '_', $l)), 'label' => $l, 'colour' => '#000000'], $labels)] : null]);
            $area = $col('Area', ['Massachusetts', 'Texas']);
            $reason = $col('Reason', ['Complaint', 'Missed Voicemail', 'Estimate Inquiry', 'Other']);
            $address = $col('Address');
            $name = $col('Client name');
            $resolution = $col('Resolution', ['Done']);
            $customer = Customer::factory()->create(['owning_franchise_id' => $t['franchise']->id, 'name' => 'Pat Doe', 'phone' => '+15085550101',
                'address_line1' => '1 Main St', 'city' => 'Worcester', 'state' => 'MA', 'postal_code' => '01601']);
            $job = Job::factory()->create(['franchise_id' => $t['franchise']->id, 'customer_id' => $customer->id]);
            $lead = Lead::create(['organization_id' => $t['org']->id, 'source' => Lead::SOURCE_LOCAL_SERVICES, 'remote_id' => 'x', 'contact_name' => 'P',
                'lead_type' => 'PHONE_CALL', 'status' => Lead::STATUS_NEW, 'received_at' => now(), 'job_id' => $job->id]);
            $task = app(TaskWriter::class)->create($board, ['title' => 'Missed call and voicemail asking for a callback'], $t['admin']);

            app(TaskRecordFill::class)->fill($task->load('board'), $lead, $t['admin']);

            $v = fn ($c) => $task->values()->where('column_id', $c->id)->value('value_text');
            $this->assertSame('massachusetts', $v($area), 'the customer\'s state, not the board\'s branch');
            $this->assertSame('missed_voicemail', $v($reason), 'per task, not Complaint for everything');
            $this->assertSame('1 Main St, Worcester, MA 01601', $v($address));
            $this->assertSame('Pat Doe', $v($name));
            $this->assertNull($v($resolution), 'Resolution is for people');
            $this->assertSame(['Estimate Inquiry'], array_slice(app(TaskRecordFill::class)->reasons('Wants a chimney estimate'), 0, 1));
            $this->assertSame([], app(TaskRecordFill::class)->reasons('Hello there'));
        });
    }

    /** Desk for Jose, 11-19: a rule task with no job link finds the caller's job by phone and gets both job ids. */
    public function test_a_task_with_only_a_phone_finds_the_callers_job(): void
    {
        $t = $this->provisionTenant('Alpha');
        $this->inTenant($t['org'], function () use ($t) {
            $board = TaskBoard::query()->where('franchise_id', $t['franchise']->id)->first();
            $phone = TaskColumn::query()->create(['board_id' => $board->id, 'name' => 'Phone number', 'type' => 'text', 'position' => 1]);
            $jobNo = TaskColumn::query()->create(['board_id' => $board->id, 'name' => 'EzBizi Job #', 'type' => 'text', 'position' => 2]);
            $name = TaskColumn::query()->create(['board_id' => $board->id, 'name' => 'Client name', 'type' => 'text', 'position' => 3]);
            $customer = Customer::factory()->create(['owning_franchise_id' => $t['franchise']->id, 'name' => 'Kerry Falloon', 'phone' => '(732) 555-0199']);
            $job = Job::factory()->create(['franchise_id' => $t['franchise']->id, 'customer_id' => $customer->id]);
            $task = app(TaskWriter::class)->create($board, ['title' => 'Wants a chimney estimate', 'values' => [$phone->id => '+17325550199']], $t['admin']);

            app(TaskRecordFill::class)->fill($task->load('board'), null, $t['admin']);

            $this->assertSame($job->number, $task->values()->where('column_id', $jobNo->id)->value('value_text'));
            $this->assertSame('Kerry Falloon', $task->values()->where('column_id', $name->id)->value('value_text'));
        });
    }
}
