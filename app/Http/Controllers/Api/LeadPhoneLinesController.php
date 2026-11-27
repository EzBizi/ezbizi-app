<?php

namespace App\Http\Controllers\Api;

use App\Models\Company;
use App\Models\Franchise;
use App\Models\SocialConnection;
use App\Support\Reports\Experimental\RingCentralCallLog;
use App\Support\Settings\SettingsResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Leads › Manage › Phone lines (Jose, 2026-11-17: "should it be able to see
 * where the call came from as in from which ring central number"): every
 * number of every connected RingCentral account, read from RingCentral, each
 * given the company or branch its calls are filed under (leads.line_numbers).
 */
class LeadPhoneLinesController extends Controller
{
    public const SETTING = 'leads.line_numbers';

    public function index(SettingsResolver $settings, RingCentralCallLog $log): JsonResponse
    {
        $org = Auth::user()->organization;
        $assigned = (array) $settings->get(self::SETTING, $org);

        $accounts = [];
        foreach ($log->accounts() as $account) {
            $log->only($account);
            if (! $log->configured()) {
                continue;
            }
            $conn = $account === null ? null : SocialConnection::query()->find($account);
            $names = $log->numberNames();
            asort($names, SORT_NATURAL | SORT_FLAG_CASE);
            // A number filed under a place this reader cannot reach is not theirs to see (audit 11-27).
            $mine = array_values(array_filter(array_keys($names), fn (string $n) => self::reaches($assigned[$n] ?? null)));
            $accounts[] = [
                'id' => $account,
                'name' => self::accountName($conn),
                'numbers' => array_map(fn (string $n) => ['number' => $n, 'label' => $names[$n], 'place' => $assigned[$n] ?? null], $mine),
            ];
        }
        $log->only(null);

        return response()->json(['data' => ['accounts' => $accounts]]);
    }

    public function update(Request $request, SettingsResolver $settings): JsonResponse
    {
        $input = $request->validate([
            'number' => ['required', 'string', 'regex:/^\+\d{8,15}$/'],
            'place' => ['nullable', 'string', 'regex:/^(company|franchise):[0-9a-f-]{36}$/'],
        ]);
        $place = $input['place'] ?? null;
        if ($place !== null) {
            [$type, $id] = explode(':', $place, 2);
            $exists = $type === 'company' ? Company::query()->whereKey($id)->exists() : Franchise::query()->whereKey($id)->exists();
            if (! $exists) {
                throw ValidationException::withMessages(['place' => 'That company or branch is not in this business.']);
            }
        }

        $org = Auth::user()->organization;
        $map = (array) $settings->get(self::SETTING, $org);
        // Audit 11-27: both the place it leaves and the place it goes must be in reach,
        // or a branch manager could file another company's calls under their own branch.
        if (! self::reaches($place) || ! self::reaches($map[$input['number']] ?? null)) {
            abort(404);
        }
        if ($place === null) {
            unset($map[$input['number']]);
        } else {
            $map[$input['number']] = $place;
        }
        $settings->set(self::SETTING, $org, $map);

        return response()->json(['data' => ['number' => $input['number'], 'place' => $place]]);
    }

    /** Null (unfiled) is everyone's; a company or branch only when the user reaches it. */
    private static function reaches(?string $place): bool
    {
        if ($place === null) {
            return true;
        }
        [$type, $id] = explode(':', $place, 2) + [1 => ''];
        // Read once per request: the index asks for every number.
        $reach = request()->attributes->get('lead-lines-reach') ?? tap([
            'company' => Auth::user()->reachableCompanies()->map(fn ($c) => (string) $c->getKey())->flip(),
            'franchise' => Auth::user()->reachableFranchises()->map(fn ($f) => (string) $f->getKey())->flip(),
        ], fn ($r) => request()->attributes->set('lead-lines-reach', $r));

        return isset($reach[$type]) && $reach[$type]->has($id);
    }

    /** "RingCentral · Everdoors Connecticut": whose account, as its connection is scoped. */
    private static function accountName(?SocialConnection $conn): string
    {
        $owner = match ($conn?->scope_type) {
            SocialConnection::SCOPE_COMPANY => Company::query()->whereKey($conn->scope_id)->value('name'),
            SocialConnection::SCOPE_FRANCHISE => ($f = Franchise::query()->with('company')->find($conn->scope_id)) ? trim($f->company?->name.' '.$f->name) : null,
            default => null,
        };

        return 'RingCentral'.($owner ? ' · '.$owner : '').($conn?->remote_id ? ' ('.$conn->remote_id.')' : '');
    }
}
