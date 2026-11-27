<?php

namespace App\Support\ViewAs;

use App\Models\Company;
use App\Models\Franchise;
use App\Models\PlatformStaffLink;
use App\Models\Role;
use App\Models\User;
use App\Models\ViewAsSession;
use App\Support\Audit\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * "View as" (Jose, 2026-11-23): a developer tool to see and use the app as one
 * role at one place, or as one person, for debugging permissions.
 *
 * Who: the REAL user is an Administrator, has a platform-staff link, and
 * holds `development.view_as` (Administrators hold every key, so the staff
 * link is the real gate; the key is what the platform's availability switch
 * withdraws). The request swap is ApplyViewAs; the clone is ViewAsUser.
 */
class ViewAs
{
    public const PERMISSION = 'development.view_as';

    /** Request attribute holding the real (signed-in) user while active. */
    public const ACTOR = 'view_as.actor';

    /** Request attribute holding the ViewAsUser the request runs as. */
    public const USER = 'view_as.user';

    public function __construct(private readonly AuditLogger $audit) {}

    public static function allowed(?User $real): bool
    {
        return $real instanceof User
            && ! $real instanceof ViewAsUser
            && $real->is_active
            && $real->isAdministrator()
            && PlatformStaffLink::staffFor($real->getKey()) !== null
            && $real->hasPermission(self::PERMISSION);
    }

    /** The real user behind this request: the actor while active, else whoever signed in. */
    public static function realUser(Request $request): ?User
    {
        $actor = $request->attributes->get(self::ACTOR);

        return $actor instanceof User ? $actor : $request->user();
    }

    /** The active session for a token, or null. */
    public static function activeFor(?PersonalAccessToken $token): ?ViewAsSession
    {
        if ($token === null || ! $token->exists) {
            return null;
        }

        return ViewAsSession::withoutGlobalScopes()
            ->where('personal_access_token_id', $token->getKey())
            ->whereNull('ended_at')
            ->latest()
            ->first();
    }

    // ── options ──────────────────────────────────────────────────

    public function options(User $actor, ?string $q): array
    {
        $org = $actor->organization_id;

        $roles = Role::withoutGlobalScopes()->where('organization_id', $org)->orderBy('name')->get()
            ->reject(fn (Role $r) => $r->isAdministrator())
            ->map(fn (Role $r) => ['id' => $r->getKey(), 'name' => $r->name])
            ->values()->all();

        $people = User::query()->with('role')
            ->where('organization_id', $org)
            ->where('is_active', true)
            ->removed(false)
            ->whereKeyNot($actor->getKey())
            ->when(filled($q), fn ($query) => $query->where(fn ($w) => $w
                ->where('name', 'like', '%'.addcslashes((string) $q, '%_').'%')
                ->orWhere('email', 'like', '%'.addcslashes((string) $q, '%_').'%')))
            ->orderBy('name')->limit(50)->get()
            ->reject(fn (User $u) => $u->isAdministrator())
            ->map(fn (User $u) => [
                'id' => $u->getKey(), 'name' => $u->name, 'email' => $u->email,
                'role_name' => $u->role?->name, 'is_active' => (bool) $u->is_active,
            ])->values()->all();

        $companies = Company::withoutGlobalScopes()->where('organization_id', $org)->orderBy('name')->get();
        $byId = $companies->keyBy('id');
        $companyPath = fn (?string $id) => array_values(array_filter([
            $byId->get($byId->get($id)?->parent_company_id)?->name,
            $byId->get($id)?->name,
        ]));

        $places = [[
            'type' => 'organization', 'id' => $org,
            'label' => $actor->organization->name, 'path' => [$actor->organization->name],
        ]];
        foreach ($companies as $c) {
            $places[] = ['type' => 'company', 'id' => $c->id, 'label' => $c->name, 'path' => $companyPath($c->id)];
        }
        foreach (Franchise::withoutGlobalScopes()->where('organization_id', $org)->orderBy('name')->get() as $f) {
            $places[] = ['type' => 'franchise', 'id' => $f->id, 'label' => $f->name,
                'path' => [...$companyPath($f->company_id), $f->name]];
        }

        return ['roles' => $roles, 'people' => $people, 'places' => $places];
    }

    // ── start and stop ───────────────────────────────────────────

    public function start(User $actor, PersonalAccessToken $token, array $input): ViewAsSession
    {
        $org = $actor->organization_id;
        $fail = fn (string $field, string $message) => throw ValidationException::withMessages([$field => $message]);

        $attributes = [
            'organization_id' => $org,
            'actor_id' => $actor->getKey(),
            'personal_access_token_id' => $token->getKey(),
            'expires_at' => now()->addMinutes(ViewAsSession::MINUTES),
        ];

        if (filled($input['user_id'] ?? null)) {
            $target = User::query()->with('role')->whereKey($input['user_id'])->first();
            if ($target === null || $target->organization_id !== $org) {
                $fail('user_id', 'That person is not in your organization.');
            }
            if ($target->is($actor)) {
                $fail('user_id', 'You cannot view the app as yourself.');
            }
            if (! $target->is_active) {
                $fail('user_id', 'That person is inactive, so there is nothing to view as them.');
            }
            if ($target->isAdministrator()) {
                $fail('user_id', 'You cannot view the app as an Administrator.');
            }
            $attributes['target_user_id'] = $target->getKey();
        } elseif (filled($input['role_id'] ?? null)) {
            $role = Role::withoutGlobalScopes()->whereKey($input['role_id'])->first();
            if ($role === null || $role->organization_id !== $org) {
                $fail('role_id', 'That role is not in your organization.');
            }
            if ($role->isAdministrator()) {
                $fail('role_id', 'You cannot view the app as the Administrator role.');
            }
            if ($actor->role_id === $role->getKey()) {
                $fail('role_id', 'That is your own role, so you are already seeing it.');
            }
            [$type, $id] = [$input['scope_type'] ?? null, $input['scope_id'] ?? null];
            $placeOk = match ($type) {
                'organization' => $id === null || $id === $org,
                'company' => Company::withoutGlobalScopes()->whereKey($id)->where('organization_id', $org)->exists(),
                'franchise' => Franchise::withoutGlobalScopes()->whereKey($id)->where('organization_id', $org)->exists(),
                default => false,
            };
            if (! $placeOk) {
                $fail('scope_id', 'Choose a place in your organization to view that role at.');
            }
            $attributes += [
                'role_id' => $role->getKey(),
                'scope_type' => $type,
                'scope_id' => $type === 'organization' ? $org : $id,
            ];
        } else {
            $fail('role_id', 'Choose a role and a place, or a person, to view the app as.');
        }

        ViewAsSession::withoutGlobalScopes()
            ->where('personal_access_token_id', $token->getKey())
            ->whereNull('ended_at')
            ->update(['ended_at' => now()]);

        $session = ViewAsSession::withoutGlobalScopes()->create($attributes);

        $this->audit->log('view_as.started', $session->target ?? $session->role, metadata: [
            'viewing_as' => self::describe($session),
            'expires_at' => $session->expires_at->toIso8601String(),
        ], actor: $actor);

        return $session;
    }

    public function end(ViewAsSession $session, string $reason): void
    {
        if ($session->ended_at !== null) {
            return;
        }
        $session->forceFill(['ended_at' => now()])->save();

        $actor = User::query()->whereKey($session->actor_id)->first();
        $this->audit->log('view_as.ended', $session->target ?? $session->role, metadata: [
            'viewing_as' => self::describe($session),
            'reason' => $reason,
        ], actor: $actor);
    }

    // ── describing ───────────────────────────────────────────────

    public static function label(ViewAsSession $session): string
    {
        if ($session->target_user_id !== null) {
            return (string) $session->target?->name;
        }

        $place = match ($session->scope_type) {
            'company' => Company::withoutGlobalScopes()->find($session->scope_id)?->name,
            'franchise' => Franchise::withoutGlobalScopes()->find($session->scope_id)?->name,
            default => 'the whole organization',
        };

        return trim(($session->role?->name ?? 'Role').' at '.$place);
    }

    /** {kind, id, label} — what an audit row records about the target. */
    public static function describe(ViewAsSession $session): array
    {
        return [
            'kind' => $session->kind(),
            'id' => $session->target_user_id ?? $session->role_id,
            'label' => self::label($session),
            'scope_type' => $session->scope_type,
            'scope_id' => $session->scope_id,
            'session_id' => $session->getKey(),
        ];
    }

    /** /me's `viewing_as`, or null. */
    public static function mePayload(User $user): ?array
    {
        if (! $user instanceof ViewAsUser) {
            return null;
        }

        return [
            'kind' => $user->viewAsSession->kind(),
            'label' => self::label($user->viewAsSession),
            'expires_at' => $user->viewAsSession->expires_at->toIso8601String(),
            'real' => ['id' => $user->viewAsActor->getKey(), 'name' => $user->viewAsActor->name],
        ];
    }
}
