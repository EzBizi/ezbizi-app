<?php

namespace App\Http\Controllers\Api;

use App\Models\Invitation;
use App\Models\InviteLink;
use App\Models\Role;
use App\Support\Access\ScopeReach;
use App\Support\Audit\AuditLogger;
use App\Support\Modules\PermissionCatalogue;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * HR › Invitations › Invite links (Jose, 2026-11-23: "I need to invite a lot
 * of users into A-1 Chimney ... an invite link in which they can create their
 * accounts on their own, I would choose which role and where to assign them").
 *
 * Same authority as sending one invitation: `create` on Invitation, a role the
 * maker may assign, a place they reach — and never an Administrator role,
 * because a link is handed to people nobody has looked at yet.
 */
class HrInviteLinksController extends Controller
{
    public const DEFAULT_DAYS = 14;

    public function __construct(
        private readonly ScopeReach $reach,
        private readonly PermissionCatalogue $catalogue,
        private readonly AuditLogger $audit,
        private readonly TenantContext $tenant,
    ) {}

    /** GET /api/v1/hr/invite-links — every link, newest first, with who joined. */
    public function index(): JsonResponse
    {
        $this->authorize('create', Invitation::class);

        $links = InviteLink::query()->with(['role:id,name', 'creator:id,name',
            'invitations' => fn ($q) => $q->whereNotNull('accepted_user_id')->with('acceptedUser:id,name')])
            ->latest()->get()
            ->filter(fn (InviteLink $l) => $this->reaches($l, request()->user()))->values();

        return response()->json(['data' => $links->map(fn (InviteLink $l) => $this->present($l))->all()]);
    }

    /** POST /api/v1/hr/invite-links {role_id, scope_type, scope_id, expires_in_days?, max_uses?} */
    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Invitation::class);
        $actor = $request->user();

        $input = $request->validate([
            'role_id' => ['required', 'uuid', Rule::exists('roles', 'id')->where('organization_id', $this->tenant->idOrFail())],
            'scope_type' => ['required', Rule::in(ScopeReach::TYPES)],
            'scope_id' => ['required', 'uuid'],
            // Null keeps it open until switched off; the default is two weeks.
            'expires_in_days' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:365'],
            'max_uses' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:10000'],
        ]);

        $role = Role::findOrFail($input['role_id']);
        $this->assertGrantable($role, $actor);
        $this->reach->assertCovers($actor, $input['scope_type'], $input['scope_id']);

        $token = Str::random(48);
        $days = array_key_exists('expires_in_days', $input) ? $input['expires_in_days'] : self::DEFAULT_DAYS;

        $link = new InviteLink([
            'role_id' => $role->id,
            'assigned_organization_id' => $input['scope_type'] === Invitation::SCOPE_ORGANIZATION ? $input['scope_id'] : null,
            'assigned_company_id' => $input['scope_type'] === Invitation::SCOPE_COMPANY ? $input['scope_id'] : null,
            'assigned_franchise_id' => $input['scope_type'] === Invitation::SCOPE_FRANCHISE ? $input['scope_id'] : null,
            'expires_at' => $days === null ? null : now()->addDays($days),
            'max_uses' => $input['max_uses'] ?? null,
            'created_by_user_id' => $actor->id,
        ]);
        $link->forceFill(['token_hash' => self::hash($token), 'token_encrypted' => $token])->save();

        $this->audit->log('invite_link.created', $link, metadata: [
            'role' => $role->name, 'scope' => ['type' => $input['scope_type'], 'id' => $input['scope_id']],
            'expires_at' => $link->expires_at?->toIso8601String(), 'max_uses' => $link->max_uses,
        ]);

        return response()->json(['data' => $this->present($link->load('role', 'creator'))], Response::HTTP_CREATED);
    }

    /** PATCH /api/v1/hr/invite-links/{link} {expires_in_days?, max_uses?} — extend or change the cap. */
    public function update(Request $request, InviteLink $link): JsonResponse
    {
        $this->authorize('create', Invitation::class);
        abort_unless($this->reaches($link, $request->user()), Response::HTTP_NOT_FOUND);
        abort_if($link->revoked_at !== null, Response::HTTP_UNPROCESSABLE_ENTITY, 'This link was switched off. Make a new one.');

        $input = $request->validate([
            'expires_in_days' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:365'],
            'max_uses' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:10000'],
        ]);
        if (array_key_exists('expires_in_days', $input)) {
            $link->expires_at = $input['expires_in_days'] === null ? null : now()->addDays($input['expires_in_days']);
        }
        if (array_key_exists('max_uses', $input)) {
            $link->max_uses = $input['max_uses'];
        }
        $link->save();
        $this->audit->log('invite_link.changed', $link, metadata: [
            'expires_at' => $link->expires_at?->toIso8601String(), 'max_uses' => $link->max_uses,
        ]);

        return response()->json(['data' => $this->present($link->load('role', 'creator'))]);
    }

    /** DELETE /api/v1/hr/invite-links/{link} — switch it off; who joined stays. */
    public function destroy(Request $request, InviteLink $link): JsonResponse
    {
        $this->authorize('create', Invitation::class);
        abort_unless($this->reaches($link, $request->user()), Response::HTTP_NOT_FOUND);
        if ($link->revoked_at === null) {
            $link->forceFill(['revoked_at' => now()])->save();
            $this->audit->log('invite_link.revoked', $link);
        }

        return response()->json(['data' => $this->present($link->load('role', 'creator'))]);
    }

    /** A role a link may hand out: never Administrator, and only what the maker could give. */
    public function assertGrantable(Role $role, $actor): void
    {
        if ($role->isAdministrator()) {
            throw ValidationException::withMessages(['role_id' => 'An invite link cannot make Administrators: '
                .'anyone holding the link could take that role. Invite an administrator by name instead.']);
        }
        $this->catalogue->assertRoleAssignable($actor, $role);
    }

    /** A link for a place outside the reader's reach is not theirs to see or change. */
    private function reaches(InviteLink $link, $actor): bool
    {
        return $this->reach->covers($actor, $link->scopeType(), $link->scopeId());
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function url(string $token): string
    {
        return rtrim((string) config('ezbizi.hosts.customer'), '/').'/join?link='.$token;
    }

    private function present(InviteLink $l): array
    {
        $joined = $l->relationLoaded('invitations') ? $l->invitations : collect();

        return [
            'id' => $l->id,
            'url' => self::url((string) $l->token_encrypted),
            'role' => ['id' => $l->role_id, 'name' => $l->role?->name],
            'scope_type' => $l->scopeType(),
            'scope_id' => $l->scopeId(),
            'expires_at' => $l->expires_at?->toIso8601String(),
            'max_uses' => $l->max_uses,
            'uses' => $l->uses,
            'revoked_at' => $l->revoked_at?->toIso8601String(),
            'open' => $l->closedBecause() === null,
            'closed_because' => $l->closedBecause(),
            'created_by' => $l->creator?->name,
            'created_at' => $l->created_at?->toIso8601String(),
            'joined' => $joined->map(fn (Invitation $i) => [
                'user_id' => $i->accepted_user_id, 'name' => $i->acceptedUser?->name, 'joined_at' => $i->accepted_at?->toIso8601String(),
            ])->values()->all(),
        ];
    }
}
