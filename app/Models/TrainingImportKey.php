<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Which EzBizi row an imported A1 Tools row became.
 *
 * ponytail: the A1 training importer is gone (git history); the table is kept as the import manifest. See the migration.
 */
class TrainingImportKey extends Model implements StatesRetention
{
    use HasUuids;
    use BelongsToTenant;

    public const SOURCE_A1 = 'a1-tools';

    protected $fillable = ['source', 'kind', 'source_id', 'target_id'];

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::forever(
            'Ids only, no content and nothing about a person: which EzBizi row each '
            .'imported A1 row became. It is what keeps a re-run from duplicating and '
            .'the only record of what an import created, so it lives as long as they do.'
        );
    }
}
