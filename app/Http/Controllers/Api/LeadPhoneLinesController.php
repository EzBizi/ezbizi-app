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
            $accounts[] = [
                'id' => $account,
                'name' => self::accountName($conn),
                'numbers' => array_map(fn (string $n) => ['number' => $n, 'label' => $names[$n], 'place' => $assigned[$n] ?? null], array_keys($names)),
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
        if ($place === null) {
            unset($map[$input['number']]);
        } else {
            $map[$input['number']] = $place;
        }
        $settings->set(self::SETTING, $org, $map);

        return response()->json(['data' => ['number' => $input['number'], 'place' => $place]]);
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
