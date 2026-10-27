<?php

namespace App\Support\Integrations\Dependents\Counters;

use App\Models\SocialPostTarget;
use App\Support\Integrations\Dependents\Feature;
use App\Support\Integrations\Dependents\IntegrationRef;
use App\Support\Social\Publishing\Publishers;
use App\Support\Social\SocialProvider;
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

    public function features(IntegrationRef $ref): array
    {
        if ($ref->kind !== 'social' || Publishers::for(SocialProvider::tryFrom($ref->provider)) === null) {
            return [];
        }

        return [Feature::lastBreaks($ref, 'Marketing › Posts: can\'t publish to '.Feature::providerLabel($ref))];
    }
}
