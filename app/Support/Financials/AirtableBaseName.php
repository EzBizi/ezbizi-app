<?php

namespace App\Support\Financials;

use App\Models\SocialConnection;

/** An Airtable base named by id (appXXXX) or by its connection's name ("Disputes And Refunds"). In tenant context. */
final class AirtableBaseName
{
    public static function resolve(string $idOrName): string
    {
        if (preg_match('/^app[A-Za-z0-9]+$/', $idOrName)) {
            return $idOrName;
        }

        return SocialConnection::query()->where('provider', 'airtable')
            ->whereRaw('LOWER(display_name) = ?', [mb_strtolower($idOrName)])->value('remote_id') ?? $idOrName;
    }

    public static function of(string $baseId): string
    {
        return SocialConnection::query()->where('provider', 'airtable')->where('remote_id', $baseId)->value('display_name') ?? $baseId;
    }
}
