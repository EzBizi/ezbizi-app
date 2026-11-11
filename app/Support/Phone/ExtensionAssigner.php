<?php

namespace App\Support\Phone;

use App\Models\Organization;
use App\Models\PhoneConnection;
use App\Models\PhoneExtension;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Cache;

/**
 * Every active person gets an extension without anybody typing one (Jose,
 * 2026-11-09: "Extensions should be automatically assigned"). Digits are the
 * lowest free four-digit code from 0101 up on the organization's first phone
 * account, so nothing still held, by a person, team or menu, is reused.
 * Existing extensions are never touched; an admin can still change any.
 */
class ExtensionAssigner
{
    public const FIRST = 101;

    /** @return int how many people were given an extension */
    public static function assignMissing(Organization $organization): int
    {
        return app(TenantContext::class)->run($organization, function () use ($organization) {
            // ponytail: the org's oldest connected account, which is where the
            // Users page's extensions live today; per-company numbering if a
            // tenant ever needs separate extension plans per account.
            $connection = PhoneConnection::query()
                ->where('status', PhoneConnection::STATUS_CONNECTED)
                ->orderBy('id')
                ->first();
            if ($connection === null) {
                return 0;
            }

            return Cache::lock('phone-extensions:'.$organization->id, 30)->block(10, function () use ($connection) {
                $rows = PhoneExtension::query()->where('connection_id', $connection->id)->get();
                $taken = $rows->pluck('extension')->filter()->flip();
                $held = $rows->whereNotNull('user_id')->whereNotNull('extension')->pluck('user_id')->flip();

                $people = User::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']);
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
            });
        });
    }
}
