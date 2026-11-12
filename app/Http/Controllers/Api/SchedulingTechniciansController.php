<?php

namespace App\Http\Controllers\Api;

use App\Models\Franchise;
use App\Models\ServiceArea;
use App\Models\User;
use App\Models\WorkizTeamMember;
use App\Support\Scoping\ViewScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Scheduling › Settings › Schedule: every Workiz technician, and which EzBizi
 * user they are.
 *
 * Jose, 2026-09-25: "we also need Schedule sub tab inside settings, in there,
 * it should list all the technicians from Workiz, so we can link them to the
 * EzBizi user counterpart."
 *
 * ── Whose column this writes ────────────────────────────────────
 *
 * `workiz_team_members` is the Workiz module's mirror, and its sweep overwrites
 * everything Workiz says — except `user_id`, which the model's own docblock
 * calls "EzBizi's own ... the link somebody confirmed, which a sweep must never
 * revert". This screen is where somebody confirms it. The sweep proposes by
 * exact email and marks those `auto_matched`; a person's save clears that
 * mark and stamps `link_confirmed_at`, because from then on it is a decision,
 * not a guess — "not linked" included, which SyncWorkizTeam then leaves empty.
 *
 * Measured 2026-09-25: 430 members on A-1's accounts, 150 of them active field
 * technicians, 3 linked — all three by email. One person on two branches'
 * accounts is two rows here, because Workiz gives them two ids; each is linked
 * on its own.
 */
class SchedulingTechniciansController extends Controller
{
    /** GET /api/v1/scheduling/technicians[?all=1] */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', ServiceArea::class);

        // A switched-off branch's team is not listed (Jose, 2026-11-10).
        $franchiseIds = ViewScope::forUser($request->user())->switchedOnFranchiseIds();
        $franchises = Franchise::whereIn('id', $franchiseIds)->pluck('name', 'id');

        $members = WorkizTeamMember::query()
            ->with('user:id,name,email')
            ->whereIn('franchise_id', $franchiseIds)
            // Leavers are kept by the mirror for the work they did, and hidden
            // here unless asked for — a list of 430 names, two thirds of them
            // gone, is not a list anybody can work through.
            ->when(! $request->boolean('all'), fn ($q) => $q->where('is_active', true))
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $members->map(fn (WorkizTeamMember $m) => [
                'id' => $m->id,
                'name' => trim((string) $m->name),
                'email' => $m->email,
                'role' => $m->role,
                'is_field_tech' => (bool) $m->is_field_tech,
                'is_active' => (bool) $m->is_active,
                'franchise_name' => $franchises[$m->franchise_id] ?? null,
                'user' => $m->user === null ? null : [
                    'id' => $m->user->id,
                    'name' => $m->user->name,
                    'email' => $m->user->email,
                ],
                'auto_matched' => (bool) $m->auto_matched,
            ])->values()->all(),
            // Who a technician can be linked to: the organization's active
            // people, by name.
            'users' => User::query()
                ->where('organization_id', $request->user()->organization_id)
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name', 'email'])
                ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name, 'email' => $u->email])
                ->all(),
        ]);
    }

    /** PUT /api/v1/scheduling/technicians/{member} {user_id: uuid|null} */
    public function update(Request $request, string $member): JsonResponse
    {
        $this->authorize('viewAny', ServiceArea::class);

        $row = WorkizTeamMember::query()
            ->whereIn('franchise_id', ViewScope::forUser($request->user())->franchiseIds())
            ->findOrFail($member);

        $data = $request->validate(['user_id' => ['present', 'nullable', 'uuid']]);

        if ($data['user_id'] !== null) {
            // Only somebody in this organization. The tenant scope on User
            // already hides everyone else, so an id from outside is simply
            // not found.
            User::query()
                ->where('organization_id', $request->user()->organization_id)
                ->findOrFail($data['user_id']);
        }

        // A person decided, so it is no longer the sweep's guess — and the
        // stamp keeps the sync from re-proposing a match somebody removed.
        $row->forceFill([
            'user_id' => $data['user_id'],
            'auto_matched' => false,
            'link_confirmed_at' => now(),
        ])->save();

        return response()->json(['data' => ['id' => $row->id, 'user_id' => $row->user_id]]);
    }
}
