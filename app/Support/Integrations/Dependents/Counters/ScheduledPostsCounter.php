<?php

namespace App\Support\Integrations\Dependents\Counters;

use App\Models\SocialPostTarget;
use App\Support\Integrations\Dependents\IntegrationRef;
use Illuminate\Database\Eloquent\Builder;

final class ScheduledPostsCounter extends RowCounter
{
    protected function key(): string
    {
        return 'scheduled_posts';
    }

    protected function applies(IntegrationRef $ref): bool
    {
        return $ref->kind === 'social';
    }

    protected function query(IntegrationRef $ref): Builder
    {
        return SocialPostTarget::query()->where('social_connection_id', $ref->id)->whereIn('status', [SocialPostTarget::STATUS_SCHEDULED, SocialPostTarget::STATUS_QUEUED]);
    }

    protected function label(int $count): string
    {
        return self::plural($count, 'scheduled post will not publish', 'scheduled posts will not publish');
    }
}
