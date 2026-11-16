<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One vendor account (Smartcar for Ford, Samsara, Motive...) an organization connected. */
class TelematicsConnection extends Model implements StatesRetention
{
    use BelongsToTenant, HasUuids;

    protected $fillable = ['organization_id', 'provider', 'label', 'credentials', 'status', 'status_detail', 'checked_at', 'created_by'];

    protected $hidden = ['credentials'];

    protected function casts(): array
    {
        return ['credentials' => 'encrypted:array', 'checked_at' => 'datetime'];
    }

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::forever('Kept while connected: it is what lets the vans report. Disconnecting deletes it.');
    }

    public function devices(): HasMany
    {
        return $this->hasMany(TelematicsDevice::class, 'connection_id');
    }
}
