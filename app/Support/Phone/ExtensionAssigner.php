<?php

namespace App\Support\Phone;

use App\Models\Company;
use App\Models\Organization;
use App\Models\PhoneConnection;
use App\Models\PhoneExtension;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Cache;

/**
 * Every active person gets an extension without anybody typing one (Jose,
 * 2026-11-09: "Extensions should be automatically assigned"). On every
 * connected phone account, each person who works for its company gets the
 * lowest free four-digit code from 0101 up, so nothing still held, by a
 * person, team or menu, is reused (EverDoors had none, 2026-11-12).
 * Existing extensions are never touched; an admin can still change any.
 */
class ExtensionAssigner
{
    public const FIRST = 101;

    /** @return int how many people were given an extension */
    public static function assignMissing(Organization $organization): int
    {
        return app(TenantContext::class)->run($organization, fn () => Cache::lock('phone-extensions:'.$organization->id, 30)
            ->block(10, fn () => PhoneConnection::query()
                ->where('status', PhoneConnection::STATUS_CONNECTED)
                ->orderBy('id')
                ->get()
                ->sum(fn (PhoneConnection $connection) => self::fill($connection))));
    }

    private static function fill(PhoneConnection $connection): int
    {
        // An organization-wide account serves everybody; a company's serves its people.
        $companyIds = $connection->scope_type === PhoneConnection::SCOPE_COMPANY
            ? (Company::query()->find($connection->scope_id)?->subtreeCompanyIds() ?? [])
            : null;

        $rows = PhoneExtension::query()->where('connection_id', $connection->id)->get();
        $taken = $rows->pluck('extension')->filter()->flip();
        $held = $rows->whereNotNull('user_id')->whereNotNull('extension')->pluck('user_id')->flip();

        $people = PhoneScope::peopleIn(User::query(), $companyIds)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']);
        $next = self::FIRST;
        $given = 0;
        foreach ($people as $user) {
            if (isset($held[$user->id])) {
                continue;
            }
            while (isset($taken[$digits = str_pad((string) $next, 4, '0', STR_PAD_LEFT)])) {
                $next++;
            }
            $taken[$digits] = true;
            // A row may already exist carrying only the line they call out from.
            PhoneExtension::query()->updateOrCreate(
                ['connection_id' => $connection->id, 'user_id' => $user->id],
                ['organization_id' => $connection->organization_id, 'extension' => $digits],
            );
            $given++;
        }

        return $given;
    }
}
