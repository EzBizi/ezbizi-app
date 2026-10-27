<?php

namespace App\Support\Automations;

use App\Models\Franchise;
use App\Models\PhoneNumber;
use App\Models\SocialConnection;
use App\Models\WhatsAppNumber;
use App\Support\Social\SocialProvider;
use App\Support\Tenancy\TenantContext;

/**
 * Can a sending step send from this place, and if not, why (Jose, 2026-10-27:
 * a step whose provider is not connected says "not connected" and is skipped,
 * rather than failing the rule). The builder's readiness list reads the same.
 * Inside the tenant.
 */
final class StepReadiness
{
    /** @return array<string, string|null> step type => why it cannot send here, null when it can */
    public static function at(?string $scopeType, ?string $scopeId): array
    {
        $places = [['organization', app(TenantContext::class)->idOrFail()]];
        if ($scopeType === 'company') {
            $places[] = ['company', $scopeId];
        }
        if ($scopeType === 'franchise') {
            $places[] = ['franchise', $scopeId];
            $places[] = ['company', Franchise::query()->whereKey($scopeId)->value('company_id')];
        }
        $here = fn ($q) => $q->where(function ($w) use ($places) {
            foreach ($places as [$t, $i]) {
                $w->orWhere(fn ($x) => $x->where('scope_type', $t)->where('scope_id', $i));
            }
        });

        // ANY number here counts as connected: a line not yet cleared for
        // messaging still fails with its reason, which Phone wants seen.
        $sms = PhoneNumber::query()->where($here)->exists() ? null : 'No phone number is connected here.';
        $workiz = SocialConnection::query()->where('provider', SocialProvider::Workiz->value)->exists() ? null : 'Workiz is not connected.';

        return [
            'sms.send' => $sms,
            'lead.text' => $sms,
            'whatsapp.send' => WhatsAppNumber::query()->exists() ? null : 'WhatsApp is not connected.',
            // EzBizi sends email itself when no address of the business's own is set up.
            'email.send' => null,
            'workiz.job_update' => $workiz,
            'inspection_report.workiz' => $workiz,
            'notify' => null,
        ];
    }
}
