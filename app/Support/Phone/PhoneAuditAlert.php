<?php

namespace App\Support\Phone;

use App\Models\Organization;
use App\Models\User;
use App\Support\Settings\SettingsResolver;
use App\Support\Social\WhatsAppCloud;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Phone audit findings to the people Jose named (2026-11-03): email always,
 * WhatsApp once its utility template is approved. Nothing found, nothing sent.
 */
final class PhoneAuditAlert
{
    /** A phone.* setting of the organization, read in its tenant. */
    public static function setting(Organization $organization, string $key): mixed
    {
        return app(TenantContext::class)->run($organization, fn () => app(SettingsResolver::class)->get($key, $organization));
    }

    /** @return list<string> a comma-separated setting, split */
    public static function values(Organization $organization, string $key): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) self::setting($organization, $key)))));
    }

    /** @param list<string> $issues */
    public static function send(Organization $organization, string $title, array $issues): void
    {
        if ($issues === []) {
            return;
        }

        $text = $title."\n\n- ".implode("\n- ", $issues);
        $template = config('services.twilio.audit_alert_whatsapp_template');
        $users = User::query()->withoutGlobalScopes()->where('organization_id', $organization->id)
            ->whereIn('email', self::values($organization, 'phone.audit_alert_emails'))->get();

        foreach ($users as $user) {
            try {
                Mail::raw($text, fn ($m) => $m->to($user->email)->subject('EzBizi phone check: '.$title));
            } catch (Throwable $e) {
                Log::warning("phone-audit-alert: mail to {$user->id} failed: {$e->getMessage()}");
            }

            if (filled($template) && filled($user->phone)) {
                try {
                    // One {{1}}: Meta refuses newlines, tabs and long space runs in it, and caps its length.
                    app(TenantContext::class)->run(
                        $organization,
                        fn () => app(WhatsAppCloud::class)->send((string) $template, (string) $user->phone, [mb_substr((string) preg_replace('/\s+/', ' ', $title.': '.implode('; ', $issues)), 0, 900)]),
                    );
                } catch (Throwable $e) {
                    Log::warning("phone-audit-alert: WhatsApp to {$user->id} failed: {$e->getMessage()}");
                }
            }
        }
    }
}
