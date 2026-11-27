<?php

namespace App\Support\Tasks;

use App\Models\SocialConnection;
use App\Models\User;
use App\Support\Social\SocialProvider;
use App\Support\Tasks\Monday\MondayClient;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Other words a person is found by in a picker (Adeline 11-24: "Chris" is
 * Kristopher Restivo): the start of their email, and the name Monday shows
 * for them, when the organization's Monday connection maps them.
 */
class PeopleAliases
{
    /**
     * @param  Collection<int, User|object>  $people  with id
     * @return array<string, string> id => words to search
     */
    public static function of(Collection $people): array
    {
        if ($people->isEmpty()) {
            return [];
        }
        $users = User::query()->whereKey($people->pluck('id'))->get(['id', 'email', 'organization_id', 'also_known_as']);
        // Kept here (Monday off 11-26); read live from Monday only while a connection still answers.
        $monday = $users->contains(fn ($u) => $u->also_known_as !== null) ? [] : self::mondayNames((string) $users->first()?->organization_id);
        $out = [];
        foreach ($users as $u) {
            $words = array_filter([strstr((string) $u->email, '@', true) ?: null, $u->also_known_as ?? $monday[(string) $u->id] ?? null]);
            if ($words !== []) {
                $out[(string) $u->id] = implode(' ', $words);
            }
        }

        return $out;
    }

    /** @return array<string, string> EzBizi user id => Monday name, read at most every six hours */
    public static function mondayNames(string $org): array
    {
        if ($org === '') {
            return [];
        }

        return Cache::remember("people-aliases:monday:{$org}", now()->addHours(6), function () use ($org) {
            $connection = SocialConnection::query()->withoutGlobalScopes()->where('organization_id', $org)
                ->where('provider', SocialProvider::Monday->value)->where('status', SocialConnection::STATUS_CONNECTED)->first();
            if ($connection === null) {
                return [];
            }
            try {
                $map = array_change_key_case((array) ($connection->setup['monday']['people'] ?? []));
                $byEmail = User::query()->withoutGlobalScopes()->where('organization_id', $org)->pluck('id', 'email')
                    ->mapWithKeys(fn ($id, $e) => [mb_strtolower((string) $e) => (string) $id])->all();
                $out = [];
                foreach (MondayClient::for($connection)->query('{ users(limit:1000, kind:all) { name email } }')['users'] ?? [] as $m) {
                    $email = mb_strtolower((string) ($m['email'] ?? ''));
                    $id = $byEmail[mb_strtolower((string) ($map[$email] ?? $email))] ?? null;
                    if ($id !== null) {
                        $out[$id] = (string) $m['name'];
                    }
                }

                return $out;
            } catch (\Throwable $e) {
                report($e);

                return [];
            }
        });
    }
}
