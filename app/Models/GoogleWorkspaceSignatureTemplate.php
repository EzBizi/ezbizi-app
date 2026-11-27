<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A named Gmail signature on a connected Workspace. `html` is what is filled
 * per person and pushed; `design` is the builder's description of it, kept so
 * the builder can reopen it. A template edited as code has no design.
 */
class GoogleWorkspaceSignatureTemplate extends Model implements StatesRetention
{
    use BelongsToTenant;
    use HasUuids;

    protected $table = 'google_workspace_signature_templates';

    protected $fillable = ['google_workspace_connection_id', 'name', 'design', 'html'];

    protected function casts(): array
    {
        return ['design' => 'array'];
    }

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::forever(
            'A company signature design. Held while the Workspace is connected; disconnecting it deletes these rows.'
        );
    }
}
