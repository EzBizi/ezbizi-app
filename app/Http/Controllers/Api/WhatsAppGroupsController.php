<?php

namespace App\Http\Controllers\Api;

use App\Models\Franchise;
use App\Models\User;
use App\Models\WhatsAppGroup;
use App\Models\WhatsAppNumber;
use App\Support\Audit\AuditLogger;
use App\Support\Reports\Texts\BranchName;
use App\Support\Settings\SettingsResolver;
use App\Support\Social\WhatsAppGroups;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * AI Assistance › WhatsApp groups: where each report goes, and who reads it
 * (docs/REPLACING-RODRIGO-BOT.md). Organization-level, under
 * `ai_assistance.manage` (the route's middleware).
 */
class WhatsAppGroupsController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly AuditLogger $audit,
        private readonly WhatsAppGroups $groups,
        private readonly SettingsResolver $settings,
    ) {}

    public function index(): JsonResponse
    {
        // "A-1 Chimney · Texas", not "Texas": Jose, 2026-10-09, could not tell
        // which company a branch belonged to. Sorted so a company's branches sit together.
        $daily = Franchise::query()->get()
            ->mapWithKeys(fn (Franchise $f) => ['daily:'.$f->getKey() => BranchName::of($f).' - Same-Day Report'])
            ->sort();

        return response()->json([
            'data' => WhatsAppGroup::query()->with('members:id,name,phone')->orderBy('subject')->get()
                ->map(fn (WhatsAppGroup $g) => $this->present($g)),
            'purposes' => collect((array) $this->settings->get('ai_assistance.group_purposes', $this->tenant->get()))->merge($daily)
                ->map(fn (string $label, string $key) => ['key' => $key, 'label' => $label])->values(),
            // Who can be added: this business's active people. Here rather
            // than GET /users so the picker needs only ai_assistance.manage.
            'people' => User::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'phone'])
                ->map(fn (User $u) => ['id' => $u->getKey(), 'name' => $u->name, 'has_phone' => trim((string) $u->phone) !== ''])
                ->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $input = $request->validate([
            'purpose' => ['required', 'string', 'max:64', Rule::unique('whatsapp_groups')->where('organization_id', $this->tenant->idOrFail())],
            'subject' => ['required', 'string', 'max:128'],
            'member_ids' => ['array', 'max:8'],
            'member_ids.*' => ['uuid'],
        ]);

        $number = WhatsAppNumber::forPurpose('reports')
            ?? WhatsAppNumber::query()->where('phone_number_id', (string) config('services.meta.whatsapp_bot_number_id'))->first()
            ?? WhatsAppNumber::query()->first();

        if ($number === null) {
            throw ValidationException::withMessages(['purpose' => 'This business has no WhatsApp number to send reports from.']);
        }

        $group = DB::transaction(function () use ($input, $number) {
            $group = WhatsAppGroup::create([
                'phone_number_id' => $number->phone_number_id,
                'purpose' => $input['purpose'],
                'subject' => $input['subject'],
            ]);
            $this->syncMembers($group, $input['member_ids'] ?? []);

            return $group;
        });

        $this->audit->log('whatsapp_group.created', $group, metadata: ['subject' => $group->subject]);

        return response()->json(['data' => $this->present($group->load('members'))], Response::HTTP_CREATED);
    }

    public function update(Request $request, WhatsAppGroup $group): JsonResponse
    {
        $input = $request->validate([
            'subject' => ['sometimes', 'string', 'max:128'],
            'member_ids' => ['sometimes', 'array', 'max:8'],
            'member_ids.*' => ['uuid'],
        ]);

        DB::transaction(function () use ($group, $input) {
            if (isset($input['subject'])) {
                $group->forceFill(['subject' => $input['subject']])->save();
            }
            if (array_key_exists('member_ids', $input)) {
                $this->syncMembers($group, $input['member_ids']);
            }
        });

        $this->audit->log('whatsapp_group.updated', $group, metadata: ['subject' => $group->subject]);

        return response()->json(['data' => $this->present($group->load('members'))]);
    }

    public function destroy(WhatsAppGroup $group): JsonResponse
    {
        $this->audit->log('whatsapp_group.deleted', $group, metadata: ['subject' => $group->subject]);
        $group->delete();

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * Make it a real WhatsApp group. Refused by Meta until the number is an
     * Official Business Account; the group keeps working one to one meanwhile.
     */
    public function publish(WhatsAppGroup $group): JsonResponse
    {
        if ($group->remote_id === null) {
            try {
                $this->groups->create($group);
            } catch (RuntimeException $e) {
                return response()->json(['message' => 'WhatsApp would not make this group yet: '.$e->getMessage()
                    .' Until it does, every member gets the reports in a private chat.'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            $this->audit->log('whatsapp_group.published', $group, metadata: ['subject' => $group->subject]);
        }

        return response()->json(['data' => $this->present($group->load('members'))]);
    }

    /** @param list<string> $ids */
    private function syncMembers(WhatsAppGroup $group, array $ids): void
    {
        // Only people of this business: User is tenant-scoped, so a foreign id
        // simply is not found.
        $users = User::query()->whereIn('id', $ids)->pluck('id');

        $group->members()->sync($users->mapWithKeys(fn (string $id) => [$id => [
            'id' => (string) Str::uuid7(),
            'organization_id' => $group->organization_id,
        ]])->all());
    }

    private function present(WhatsAppGroup $group): array
    {
        return [
            'id' => $group->getKey(),
            'purpose' => $group->purpose,
            'subject' => $group->subject,
            // A real WhatsApp group once made; until then members get it privately.
            'on_whatsapp' => $group->remote_id !== null,
            'invite_link' => $group->invite_link,
            'members' => $group->members->map(fn (User $u) => [
                'id' => $u->getKey(),
                'name' => $u->name,
                'has_phone' => trim((string) $u->phone) !== '',
            ])->values(),
        ];
    }
}
