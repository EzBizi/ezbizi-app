<?php

namespace App\Http\Controllers\Api;

use App\Models\Invitation;
use App\Models\InviteLink;
use App\Models\User;
use App\Services\Auth\EmailVerifications;
use App\Services\Hr\Invitations;
use App\Support\Audit\AuditLogger;
use App\Support\Auth\PasswordPolicy;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * The public side of an invite link: say what it is for, and let somebody make
 * their own account through it (Jose, 2026-11-23).
 *
 * A join is turned into an ordinary Invitation for the address they typed and
 * accepted at once through InvitationAcceptanceController, so the account,
 * role, place, onboarding and session are exactly an invited employee's. The
 * one difference: nobody proved the address, so it is left unverified and a
 * verification email goes out — RequireVerifiedEmail then holds writes until
 * they confirm it.
 *
 * The link token travels as `link` in the body (never in a URL nginx logs),
 * under the same `invitation` rate limit as accepting an invitation.
 */
class InviteLinkJoinController extends Controller
{
    public function __construct(
        private readonly Invitations $invitations,
        private readonly AuditLogger $audit,
        private readonly TenantContext $tenant,
    ) {}

    /** POST /api/v1/invite-links/lookup {link} */
    public function show(Request $request): JsonResponse
    {
        $link = $this->find($request);
        if ($link === null) {
            return $this->gone('This invite link is not valid. Ask whoever sent it for a new one.');
        }

        return $this->tenant->run($link->organization, function () use ($link) {
            $link->load('role');

            return response()->json(['data' => [
                'organization' => $link->organization->name,
                'role' => $link->role?->name,
                'place' => $this->placeName($link),
                'open' => $link->closedBecause() === null,
                'closed_because' => $link->closedBecause(),
                'password_min_length' => PasswordPolicy::minLengthFor($link->organization),
            ]]);
        });
    }

    /** POST /api/v1/invite-links/join {link, first_name, last_name, email, phone?, password} */
    public function join(Request $request): JsonResponse
    {
        $link = $this->find($request);
        if ($link === null) {
            return $this->gone('This invite link is not valid. Ask whoever sent it for a new one.');
        }
        $organization = $link->organization;

        $input = $request->validate([
            'first_name' => ['required', 'string', 'max:60'],
            'last_name' => ['required', 'string', 'max:60'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'password' => PasswordPolicy::rulesFor($organization),
        ]);
        $email = strtolower(trim($input['email']));

        return $this->tenant->run($organization, function () use ($link, $email, $input, $request) {
            DB::beginTransaction();
            try {
                $locked = InviteLink::query()->with('role', 'creator')->whereKey($link->id)->lockForUpdate()->first();
                if ($locked === null || ($why = $locked->closedBecause()) !== null) {
                    DB::rollBack();

                    return $this->gone($why ?? 'This invite link is not valid.');
                }

                // What the maker could hand out when they made it, they must still be able to.
                $maker = $locked->creator;
                if ($maker === null || ! $maker->is_active) {
                    DB::rollBack();

                    return $this->gone('This invite link can no longer be used. Ask for a new one.');
                }
                app(HrInviteLinksController::class)->assertGrantable($locked->role, $maker);

                if (User::query()->where('email', $email)->exists()) {
                    throw ValidationException::withMessages(['email' => 'There is already an account with this '
                        .'address here. Sign in instead — and if you cannot, ask for a password reset.']);
                }

                $issued = $this->invitations->issue([
                    'email' => $email,
                    'first_name' => $input['first_name'],
                    'last_name' => $input['last_name'],
                    'role_id' => $locked->role_id,
                    'invited_by_user_id' => $maker->id,
                    'invite_link_id' => $locked->id,
                    'assigned_organization_id' => $locked->assigned_organization_id,
                    'assigned_company_id' => $locked->assigned_company_id,
                    'assigned_franchise_id' => $locked->assigned_franchise_id,
                ]);

                $request->merge(['token' => $issued['token']]);
                $response = app(InvitationAcceptanceController::class)->accept($request);
                if ($response->getStatusCode() !== Response::HTTP_CREATED) {
                    DB::rollBack();

                    return $response;
                }

                $locked->increment('uses');
                $user = User::query()->findOrFail($issued['invitation']->fresh()->accepted_user_id);
                // Nobody proved this address: confirm it before the account may change anything.
                $user->forceFill(['email_verified_at' => null])->save();
                $this->audit->log('invite_link.used', $locked, metadata: ['user_id' => $user->id], actor: $user);
                DB::commit();
            } catch (Throwable $e) {
                DB::rollBack();

                throw $e;
            }

            try {
                app(EmailVerifications::class)->issueAndSend($user, $request->ip());
            } catch (Throwable $e) {
                report($e);
            }

            return $response;
        });
    }

    private function find(Request $request): ?InviteLink
    {
        $token = (string) $request->validate(['link' => ['required', 'string', 'max:255']])['link'];

        return InviteLink::query()->withoutGlobalScopes()->with('organization')
            ->where('token_hash', HrInviteLinksController::hash($token))->first();
    }

    private function placeName(InviteLink $link): ?string
    {
        return match ($link->scopeType()) {
            Invitation::SCOPE_FRANCHISE => \App\Models\Franchise::query()->find($link->assigned_franchise_id)?->name,
            Invitation::SCOPE_COMPANY => \App\Models\Company::query()->find($link->assigned_company_id)?->name,
            default => $link->organization->name,
        };
    }

    private function gone(string $message): JsonResponse
    {
        return response()->json(['message' => $message, 'code' => 'invite_link_closed'], Response::HTTP_GONE);
    }
}
