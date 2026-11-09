<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What EzBizi decides about one address on a connected Google Workspace: the
 * EzBizi person it belongs to, and a signature written for that person alone.
 *
 * Written only by {@see \App\Support\Email\WorkspaceSignatures}.
 */
class GoogleWorkspacePerson extends Model implements StatesRetention
{
    use BelongsToTenant;
    use HasUuids;

    protected $table = 'google_workspace_people';

    protected $fillable = ['google_workspace_connection_id', 'address', 'user_id', 'signature_html', 'signature_applied_at', 'signature_template_id', 'fields', 'signature_applied_hash'];

    protected function casts(): array
    {
        return ['signature_applied_at' => 'datetime', 'fields' => 'array'];
    }

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::forever(
            'Which EzBizi person a Workspace address belongs to, and the signature written for it. '
            .'Held while the Workspace is connected; disconnecting it deletes these rows, and a '
            .'person removed from EzBizi is unlinked.'
        );
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
