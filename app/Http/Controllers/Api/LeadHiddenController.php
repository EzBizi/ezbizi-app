<?php

namespace App\Http\Controllers\Api;

use App\Models\Lead;
use App\Models\LeadHiddenNumber;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Leads › Manage › Hidden (Jose, 2026-10-29: Bar's WhatsApp carries private
 * financial talk that dispatchers must not see). Every route sits behind
 * `leads.hidden.view`; the hiding itself is enforced in LeadPolicy, the
 * reader scope, the automation engine and the AI and reminder jobs.
 */
class LeadHiddenController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** GET /api/v1/leads/hidden?q= */
    public function index(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));
        $digits = preg_replace('/\D/', '', $q) ?? '';
        $match = fn ($query, string $name, string $phone) => $q === '' ? $query : $query->where(fn ($w) => $w->where($name, 'like', '%'.addcslashes($q, '%_').'%')
            ->when($digits !== '', fn ($w) => $w->orWhere($phone, 'like', '%'.$digits.'%')));

        $leads = $match(Lead::query()->whereNotNull('hidden_at'), 'contact_name', 'phone_digits')
            ->orderByDesc('hidden_at')->limit(200)->get();
        $numbers = $match(LeadHiddenNumber::query(), 'digits', 'digits')->orderByDesc('created_at')->limit(200)->get();
        $always = LeadHiddenNumber::query()->pluck('digits');
        $names = User::query()->whereIn('id', $leads->pluck('hidden_by_user_id')->merge($numbers->pluck('hidden_by_user_id'))->filter()->unique())->pluck('name', 'id');
        $by = fn (?string $id) => $id !== null && isset($names[$id]) ? ['name' => $names[$id]] : null;
        $counts = Lead::query()->whereNotNull('hidden_at')->whereNotNull('phone_digits')->get(['phone_digits'])
            ->countBy(fn (Lead $l) => LeadHiddenNumber::digitsOf($l->phone_digits));

        return response()->json(['data' => [
            'leads' => $leads->map(fn (Lead $l) => [
                'id' => $l->id,
                'source' => $l->source,
                'contact_name' => $l->contact_name,
                'phone' => $l->phone,
                'last_contact_at' => $l->last_contact_at?->toIso8601String(),
                'last_contact_preview' => $l->last_contact_preview,
                'hidden_at' => $l->hidden_at?->toIso8601String(),
                'hidden_by' => $by($l->hidden_by_user_id),
                'reason' => $l->hidden_reason,
                'always' => $always->contains(LeadHiddenNumber::digitsOf($l->phone_digits)),
            ])->all(),
            'numbers' => $numbers->map(fn (LeadHiddenNumber $n) => [
                'id' => $n->id,
                'digits' => $n->digits,
                'leads' => $counts->get($n->digits, 0),
                'hidden_at' => $n->created_at?->toIso8601String(),
                'hidden_by' => $by($n->hidden_by_user_id),
                'reason' => $n->reason,
            ])->all(),
        ]]);
    }

    /** POST /api/v1/leads/{lead}/hide {always?} — always: every lead from this number, now and later. */
    public function hide(Request $request, Lead $lead): JsonResponse
    {
        $this->authorize('view', $lead);
        $always = $request->boolean('always');
        $request->validate(['reason' => ['nullable', 'string', 'max:255']]);
        $digits = LeadHiddenNumber::digitsOf($lead->phone_digits ?? $lead->phone);
        if ($always && $digits === null) {
            throw ValidationException::withMessages(['always' => 'This lead has no phone number to hide.']);
        }

        $always ? $this->hideNumber($digits, $request) : $this->mark(Lead::query()->whereKey($lead->id), $request);
        $this->audit->log('lead.hidden', $lead, ['always' => $always], actor: $request->user());

        return response()->json(['data' => ['id' => $lead->id, 'hidden' => true, 'always' => $always]]);
    }

    /** POST /api/v1/leads/hidden-numbers {phone} — hide a number before it ever writes in. */
    public function hideNumberDirectly(Request $request): JsonResponse
    {
        $digits = LeadHiddenNumber::digitsOf((string) $request->validate(['phone' => ['required', 'string', 'max:40'], 'reason' => ['nullable', 'string', 'max:255']])['phone']);
        if ($digits === null) {
            throw ValidationException::withMessages(['phone' => 'A phone number needs at least ten digits.']);
        }
        $number = $this->hideNumber($digits, $request);
        $this->audit->log('lead.number_hidden', $number, [], actor: $request->user());

        return response()->json(['data' => ['id' => $number->id, 'digits' => $digits]], 201);
    }

    /** POST /api/v1/leads/{lead}/unhide — also stops always hiding its number, or it would vanish again with the next message. */
    public function unhide(Request $request, Lead $lead): JsonResponse
    {
        $this->authorize('view', $lead);
        $digits = LeadHiddenNumber::digitsOf($lead->phone_digits ?? $lead->phone);
        if ($digits !== null) {
            LeadHiddenNumber::query()->where('digits', $digits)->delete();
        }
        $lead->forceFill(['hidden_at' => null, 'hidden_by_user_id' => null, 'hidden_reason' => null])->save();
        $this->audit->log('lead.unhidden', $lead, [], actor: $request->user());

        return response()->json(['data' => ['id' => $lead->id, 'hidden' => false]]);
    }

    /** DELETE /api/v1/leads/hidden-numbers/{number} — unhide the number and every lead from it. */
    public function unhideNumber(Request $request, LeadHiddenNumber $number): JsonResponse
    {
        Lead::query()->whereNotNull('hidden_at')->where('phone_digits', 'like', '%'.$number->digits)
            ->update(['hidden_at' => null, 'hidden_by_user_id' => null, 'hidden_reason' => null]);
        $this->audit->log('lead.number_unhidden', $number, [], actor: $request->user());
        $number->delete();

        return response()->json(['data' => ['id' => $number->id, 'hidden' => false]]);
    }

    private function hideNumber(string $digits, Request $request): LeadHiddenNumber
    {
        $number = LeadHiddenNumber::query()->firstOrCreate(['digits' => $digits], ['hidden_by_user_id' => $request->user()?->id, 'reason' => $request->input('reason')]);
        $this->mark(Lead::query()->where('phone_digits', 'like', '%'.$digits), $request);

        return $number;
    }

    private function mark($leads, Request $request): void
    {
        $leads->whereNull('hidden_at')->update(['hidden_at' => now(), 'hidden_by_user_id' => $request->user()?->id, 'hidden_reason' => $request->input('reason')]);
    }
}
