<?php

namespace App\Services\Reviews;

use App\Models\Review;
use App\Models\SocialConnection;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * eKomi's published reviews into Reviews (A1 Leads takeover #43), read with
 * the shop id and password held encrypted on an `ekomi` connection. eKomi
 * reviews cannot be answered from here, and the sync emits no events, so no
 * reply rule ever reaches them.
 */
class EkomiReviews
{
    private const FEEDBACK = 'https://api.ekomi.de/v3/getFeedback';

    /** @return list<array<string, mixed>> eKomi's own rows. */
    public static function fetch(string $shopId, string $password): array
    {
        $json = Http::timeout(30)->acceptJson()->get(self::FEEDBACK, [
            'auth' => $shopId.'|'.$password, 'version' => 'cms-v3', 'type' => 'json', 'range' => 'all',
        ])->throw()->json();

        // A bare list, or {feedback: [...]}, depending on the account.
        $rows = is_array($json) ? ($json['feedback'] ?? $json['feedbacks'] ?? (array_is_list($json) ? $json : null)) : null;
        if (! is_array($rows)) {
            throw new RuntimeException('eKomi answered without a list of reviews.');
        }

        return array_values($rows);
    }

    /** Upserts every review; returns how many eKomi gave. */
    public function sync(SocialConnection $connection): int
    {
        $c = $connection->credentials ?? [];
        $rows = self::fetch((string) ($c['shop_id'] ?? ''), (string) ($c['password'] ?? ''));

        foreach ($rows as $row) {
            $id = (string) ($row['order_id'] ?? $row['id'] ?? md5(json_encode($row)));
            $at = $row['submitted'] ?? $row['created'] ?? null;
            Review::query()->updateOrCreate(
                ['organization_id' => $connection->organization_id, 'remote_name' => 'ekomi/'.$id],
                [
                    'source' => Review::SOURCE_EKOMI,
                    'connection_id' => $connection->id,
                    'location_name' => 'ekomi',
                    'company_id' => $connection->scope_type === SocialConnection::SCOPE_COMPANY ? $connection->scope_id : null,
                    'franchise_id' => $connection->scope_type === SocialConnection::SCOPE_FRANCHISE ? $connection->scope_id : null,
                    'reviewer_name' => $row['client_name'] ?? $row['name'] ?? null,
                    'rating' => isset($row['rating']) ? max(1, min(5, (int) $row['rating'])) : null,
                    'comment' => $row['review'] ?? $row['feedback'] ?? null,
                    'reviewed_at' => $at === null ? null : (is_numeric($at) ? CarbonImmutable::createFromTimestamp((int) $at) : CarbonImmutable::parse($at)),
                    'synced_at' => now(),
                ],
            );
        }
        $connection->forceFill(['last_reached_at' => now()])->save();

        return count($rows);
    }
}
