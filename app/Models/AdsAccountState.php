<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Automations\CarriesAutomationScope;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What EzBizi last learned about one connected Google Ads account: today, its
 * Local Services certification verdict (see `LsaCertifications`).
 *
 * The subject of the Google Ads module's automation triggers. It sits where
 * its connection sits, so a company-level rule covers its own accounts and a
 * notification reaches that company's people, not the whole organization.
 */
class AdsAccountState extends Model implements CarriesAutomationScope, StatesRetention
{
    use BelongsToTenant;
    use HasUuids;

    protected $table = 'ads_account_states';

    protected $fillable = [
        'organization_id', 'connection_id', 'certification_statuses', 'certification_documents',
        'certification_problems', 'certification_problem_count', 'certification_soonest_expiry',
        'certification_checked_at', 'certification_failed', 'campaigns', 'campaigns_checked_at',
    ];

    protected function casts(): array
    {
        return [
            'certification_statuses' => 'array',
            'certification_documents' => 'array',
            'certification_problems' => 'array',
            'certification_problem_count' => 'integer',
            'certification_soonest_expiry' => 'datetime',
            'certification_checked_at' => 'datetime',
            'campaigns' => 'array',
            'campaigns_checked_at' => 'datetime',
        ];
    }

    public function socialConnection(): BelongsTo
    {
        return $this->belongsTo(SocialConnection::class, 'connection_id');
    }

    public function automationScope(): array
    {
        $connection = $this->socialConnection;

        return match ($connection?->scope_type) {
            SocialConnection::SCOPE_COMPANY => ['company', $connection->scope_id],
            SocialConnection::SCOPE_FRANCHISE => ['franchise', $connection->scope_id],
            default => [null, null],
        };
    }

    /**
     * Goes with its connection: one row per account, overwritten by each check,
     * and deleted by the foreign key when the account is disconnected.
     */
    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::withParent('One current row per connected Google Ads account, '
            .'overwritten by each daily check and removed with the connection.');
    }
}
