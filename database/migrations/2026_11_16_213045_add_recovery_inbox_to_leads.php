<?php

use App\Support\Modules\PermissionCatalogue;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Leads › Recovery (Jose, 2026-11-16: "the leads show, for these text messages
 * we are going to send, because clients WILL BE REPLYING"): when a Recovery
 * text last went to the lead, and which rule wrote each automatic message.
 *
 * Backfilled from the last 30 days of Recovery replies that went out (the
 * rules Leads › Manage › Recovery lists) and Re-Scheduling offers. Every role
 * that can open Leads gets the tab (as 2026_11_06_160218, Promotions).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->timestamp('recovery_at')->nullable()->index();
        });
        Schema::table('lead_conversations', function (Blueprint $table) {
            $table->string('automation_name', 150)->nullable();
        });

        // The rules: a reply action on a recovery trigger, closed-office only on new enquiries and replies.
        $rules = DB::table('automations')->join('automation_versions', 'automation_versions.id', '=', 'automations.active_version_id')
            ->whereIn('automations.trigger_key', ['lead.missed_call', 'lead.call_not_booked', 'lead.follow_up_due', 'lead.received', 'lead.customer_replied'])
            ->get(['automations.id', 'automations.name', 'automations.trigger_key', 'automation_versions.actions', 'automation_versions.conditions'])
            ->filter(function ($r) {
                $actions = json_decode((string) $r->actions, true) ?: [];
                if (! collect($actions)->contains('type', 'reply')) {
                    return false;
                }

                return ! in_array($r->trigger_key, ['lead.received', 'lead.customer_replied'], true)
                    || collect((json_decode((string) $r->conditions, true) ?: [])['all'] ?? [])->contains(fn ($c) => ($c['field'] ?? null) === 'closed_reply_due'
                        || (($c['field'] ?? null) === 'office_open' && ($c['value'] ?? null) === false));
            });

        $runs = DB::table('automation_runs')->join('automation_events', 'automation_events.id', '=', 'automation_runs.event_id')
            ->whereIn('automation_runs.automation_id', $rules->pluck('id'))
            ->where('automation_runs.started_at', '>=', now()->subDays(30))
            ->where('automation_runs.is_test', false)
            ->where('automation_events.subject_type', 'App\\Models\\Lead')
            ->get(['automation_events.subject_id', 'automation_runs.started_at', 'automation_runs.action_results']);
        foreach ($runs as $run) {
            $sent = collect(json_decode((string) $run->action_results, true) ?: [])
                ->contains(fn ($x) => ($x['type'] ?? null) === 'reply' && ($x['ok'] ?? false) && empty($x['skipped']));
            if ($sent) {
                DB::table('leads')->where('id', $run->subject_id)
                    ->where(fn ($q) => $q->whereNull('recovery_at')->orWhere('recovery_at', '<', $run->started_at))
                    ->update(['recovery_at' => $run->started_at]);
            }
        }
        DB::table('leads')->where('lead_type', 'Reschedule')->whereNull('recovery_at')
            ->where('received_at', '>=', now()->subDays(30))->update(['recovery_at' => DB::raw('received_at')]);

        $fromId = DB::table('permissions')->where('key', 'leads.view')->value('id');
        if ($fromId !== null) {
            $permission = app(PermissionCatalogue::class)->materialise('leads.recovery.view');
            DB::table('permission_role')->insertOrIgnore(
                DB::table('permission_role')->where('permission_id', $fromId)->pluck('role_id')
                    ->map(fn ($roleId) => ['role_id' => $roleId, 'permission_id' => $permission->id])->all()
            );
        }
    }

    public function down(): void
    {
        Schema::table('lead_conversations', fn (Blueprint $table) => $table->dropColumn('automation_name'));
        Schema::table('leads', fn (Blueprint $table) => $table->dropColumn('recovery_at'));
    }
};
