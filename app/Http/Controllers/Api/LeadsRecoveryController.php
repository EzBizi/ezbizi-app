<?php

namespace App\Http\Controllers\Api;

use App\Models\Automation;
use App\Models\AutomationRun;
use App\Models\Company;
use App\Models\Lead;
use App\Support\Leads\LeadTexts;
use App\Support\Settings\SettingRegistry;
use App\Support\Settings\SettingsResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Leads › Manage › Recovery (Jose, 2026-11-16: "These Messages should be under
 * a new tab in leads called Recovery"): the automatic texts that win back a
 * missed, unbooked or after-hours enquiry, in one place.
 *
 * It only READS rules. Editing and switching go through the automations API
 * (PATCH /automations/{id}) the builder uses, so a rule here is the same rule
 * there; the client sends conditions with every edit (11-12: an actions-only
 * PATCH wiped them).
 */
class LeadsRecoveryController extends Controller
{
    /** Triggers whose customer replies are recovery. */
    public const TRIGGERS = ['lead.missed_call', 'lead.call_not_booked', 'lead.follow_up_due', 'lead.received', 'lead.customer_replied'];

    /** Re-Scheduling's offer texts, sent from Scheduling, edited as settings. */
    public const TEXTS = ['scheduling.overbooked_offer_message', 'scheduling.offer_message'];

    public function index(Request $request): JsonResponse
    {
        $rules = Automation::query()->with('activeVersion')->whereIn('trigger_key', self::TRIGGERS)->orderBy('name')->get()
            ->filter(fn (Automation $a) => self::isRecovery($a))
            ->values();

        $sent = AutomationRun::query()->whereIn('automation_id', $rules->modelKeys())->where('is_test', false)
            ->where('started_at', '>=', now()->subDays(7))->get(['automation_id', 'action_results'])
            // ponytail: reads a week of runs in PHP; a count column if this grows past a few thousand.
            ->filter(fn (AutomationRun $r) => collect($r->action_results)->contains(
                fn ($x) => ($x['type'] ?? null) === 'reply' && ($x['ok'] ?? false) && empty($x['skipped'])))
            ->countBy('automation_id');

        $organization = Auth::user()->organization;
        $registry = app(SettingRegistry::class);

        return response()->json(['data' => [
            'rules' => $rules->map(fn (Automation $a) => [
                'id' => $a->id,
                'name' => $a->name,
                'trigger_key' => $a->trigger_key,
                'is_active' => $a->is_active,
                'delay_minutes' => $a->delay_minutes,
                // As the builder stores it: organization, company:<id>, franchise:<id>.
                'place' => $a->scope_type === null ? 'organization' : "{$a->scope_type}:{$a->scope_id}",
                'version' => $a->activeVersion?->version,
                'conditions' => $a->activeVersion?->conditions,
                'actions' => $a->activeVersion?->actions ?? [],
                'message' => collect($a->activeVersion?->actions)->firstWhere('type', 'reply')['message'] ?? '',
                'sent_7d' => $sent[$a->id] ?? 0,
            ]),
            'texts' => array_map(fn (string $key) => [
                'key' => $key,
                'label' => $registry->get($key)?->label,
                'help' => $registry->get($key)?->help,
                'message' => (string) app(SettingsResolver::class)->get($key, $organization),
            ], self::TEXTS),
            'variables' => [...Lead::automationMessageFields(), 'contact_name', 'phone'],
            'companies' => Company::query()->orderBy('name')->get(['id', 'name']),
        ]]);
    }

    /**
     * The text as one brand's customer would read it, for a made-up "Jane
     * Smith" calling that company. Writes nothing: an unsaved lead, and the
     * booking link shows the page with a sample code instead of minting one.
     */
    public function preview(Request $request): JsonResponse
    {
        $input = $request->validate([
            'text' => ['required', 'string', 'max:2000'],
            'company_id' => ['nullable', 'uuid'],
        ]);
        $company = Company::query()->when($input['company_id'] ?? null, fn ($q, $id) => $q->whereKey($id))
            ->orderBy('name')->firstOrFail();

        $lead = (new Lead)->forceFill([
            'organization_id' => $company->organization_id, 'company_id' => $company->id,
            'contact_name' => 'Jane Smith', 'phone' => '(203) 555-0100',
            'source' => Lead::SOURCE_LOCAL_SERVICES, 'lead_type' => 'PHONE_CALL', 'received_at' => now(),
        ]);
        $page = $lead->bookingPage();
        $host = parse_url($page, PHP_URL_HOST);
        $values = ['booking_link' => $host ? "https://{$host}/b/Ab3dE7x" : ''];
        $value = fn (string $k) => (string) ($values[$k] ?? (is_scalar($v = $lead->getAttribute($k)) ? $v : ''));

        // As ActionRunner::interpolate: a line whose link, slot or phone is blank is dropped.
        $lines = array_filter(explode("\n", $input['text']), fn (string $line) => ! collect(['booking_link', 'offered_slot', 'office_phone'])
            ->contains(fn ($k) => preg_match('/\{\{\s*'.$k.'\s*\}\}/i', $line) && $value($k) === ''));
        $text = preg_replace_callback('/\{\{\s*([a-z0-9_.]+)\s*\}\}/i', fn ($m) => $value($m[1]), implode("\n", $lines));

        // Scheduling's texts spell theirs {name}.
        $text = strtr($text, ['{name}' => 'Jane', '{company}' => $company->name, '{booked}' => 'Friday, Nov 20',
            '{date}' => 'Wednesday, Nov 18', '{window}' => '8-11 AM']);

        return response()->json(['data' => [
            'text' => $text,
            'company' => $company->name,
            'sms_from' => app(LeadTexts::class)->line($lead)?->e164,
        ]]);
    }

    /** A customer reply on a recovery trigger; on lead.received and replies, only the closed-office ones. */
    private static function isRecovery(Automation $a): bool
    {
        if (! collect($a->activeVersion?->actions)->contains('type', 'reply')) {
            return false;
        }
        if (! in_array($a->trigger_key, ['lead.received', 'lead.customer_replied'], true)) {
            return true;
        }

        return collect($a->activeVersion?->conditions['all'] ?? [])->contains(fn ($c) => ($c['field'] ?? null) === 'closed_reply_due'
            || (($c['field'] ?? null) === 'office_open' && ($c['value'] ?? null) === false));
    }
}
