<?php

namespace App\Console\Commands;

use App\Models\Automation;
use App\Models\User;
use App\Models\WhatsAppMessage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * A scheduled report text WhatsApp has not delivered 30 minutes on is told to
 * the person who made its rule, by email (A1 Leads row 52, heartbeat checks):
 * 93 of 107 sat undelivered for five days and nothing said so (2026-11-09).
 * Each message is told once. Rows are the ones ActionRunner::deliverReportText
 * keyed "report.text:{rule}:{kind}:{day}:{person}".
 */
class ReportDeliveryCheck extends Command
{
    protected $signature = 'ezbizi:report-delivery-check';

    protected $description = 'Email a report rule\'s maker when its WhatsApp text was not delivered within 30 minutes';

    public function handle(): int
    {
        $late = WhatsAppMessage::query()->withoutGlobalScopes()
            ->where('idempotency_key', 'like', 'report.text:%')
            ->whereNotIn('status', [WhatsAppMessage::STATUS_DELIVERED, WhatsAppMessage::STATUS_READ])
            ->whereBetween('created_at', [now()->subHours(6), now()->subMinutes(30)])
            ->get()
            ->filter(fn (WhatsAppMessage $m) => Cache::add("report-undelivered:{$m->id}", true, now()->addDay()));

        foreach ($late->groupBy(fn (WhatsAppMessage $m) => explode(':', (string) $m->idempotency_key)[1] ?? '') as $ruleId => $messages) {
            $rule = Automation::query()->withoutGlobalScopes()->find($ruleId);
            $maker = $rule === null ? null : User::query()->withoutGlobalScopes()->find($rule->created_by);
            $lines = $messages->map(function (WhatsAppMessage $m) {
                $to = User::query()->withoutGlobalScopes()->find(explode(':', (string) $m->idempotency_key)[4] ?? '');

                return '- '.($to?->name ?? '+'.$m->counterparty).': '.$m->status.($m->status_reason ? " ({$m->status_reason})" : '')
                    .', sent '.$m->created_at?->diffForHumans();
            })->implode("\n");
            $this->line("rule {$ruleId}:\n{$lines}");

            if ($maker === null || blank($maker->email)) {
                Log::warning("report-delivery-check: rule {$ruleId} has nobody to tell.\n{$lines}");

                continue;
            }

            try {
                Mail::raw("Your rule \"{$rule->name}\" sent a report on WhatsApp that has not been delivered:\n\n{$lines}",
                    fn ($mail) => $mail->to($maker->email)->subject('EzBizi: a WhatsApp report was not delivered'));
            } catch (Throwable $e) {
                Log::warning("report-delivery-check: mail to {$maker->id} failed: {$e->getMessage()}");
            }
        }

        return self::SUCCESS;
    }
}
