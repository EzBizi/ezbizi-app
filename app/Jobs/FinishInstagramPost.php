<?php

namespace App\Jobs;

use App\Models\Organization;
use App\Models\SocialPostTarget;
use App\Support\Automations\EventEmitter;
use App\Support\Social\Publishing\InstagramPublisher;
use App\Support\Social\Publishing\MediaHandoff;
use App\Support\Social\Publishing\SocialPublishFailed;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Finishes an Instagram video PublishSocialTarget began but could not wait
 * for (StillProcessing). One status check per run, then either publish, fail,
 * or look again in EVERY seconds - so no worker is held open while Instagram
 * ingests, and one short call per run keeps it far inside the queue's limits.
 *
 * `tries = 1` for PublishSocialTarget's reason: a retried media_publish could
 * post the reel twice.
 */
final class FinishInstagramPost implements ShouldQueue
{
    use Queueable;

    public const EVERY = 30;

    /** 20 x 30s: ten minutes on top of the first job's 36 seconds. */
    public const LOOKS = 20;

    public int $tries = 1;

    public function __construct(public readonly string $targetId, public readonly int $look = 1) {}

    public function handle(TenantContext $tenant, EventEmitter $events, InstagramPublisher $instagram): void
    {
        $target = SocialPostTarget::query()->withoutGlobalScopes()->find($this->targetId);
        $organization = $target === null ? null
            : Organization::query()->withoutGlobalScopes()->find($target->organization_id);

        if ($target === null || $organization === null || $target->status !== SocialPostTarget::STATUS_QUEUED) {
            return;
        }

        $tenant->run($organization, fn () => $this->look($target, $events, $instagram));
    }

    private function look(SocialPostTarget $target, EventEmitter $events, InstagramPublisher $instagram): void
    {
        $options = (array) ($target->options ?? []);
        $container = (string) ($options['instagram_container'] ?? '');
        $handoff = MediaHandoff::forHandle((string) ($options['handoff'] ?? ''));
        $job = new PublishSocialTarget($target->getKey());

        if ($container === '' || $target->connection === null) {
            $handoff->release();
            $job->markFailedAndEmit($target, $events, 'Instagram refused it: EzBizi lost track of the video while it was processing. Nothing was posted. Try again.');

            return;
        }

        try {
            $remoteId = $instagram->finish($target->connection, $container);
        } catch (SocialPublishFailed $e) {
            $handoff->release();
            $job->markFailedAndEmit($target, $events, $e->getMessage());

            return;
        } catch (Throwable $e) {
            report($e);
            $remoteId = null; // A blip on a status check is worth another look.
        }

        if ($remoteId !== null) {
            $handoff->release();
            $job->succeeded($target, $instagram, $remoteId, $events);

            return;
        }

        if ($this->look >= self::LOOKS) {
            $handoff->release();
            $job->markFailedAndEmit($target, $events,
                'Instagram refused it: it was still processing the video after more than ten minutes, '
                .'so EzBizi stopped waiting. Nothing was posted. A shorter or smaller video will go through.');

            return;
        }

        self::dispatch($target->getKey(), $this->look + 1)->delay(now()->addSeconds(self::EVERY));
    }

    /** Laravel's failure hook: a crash here must not leave the target `queued`. */
    public function failed(?Throwable $e = null): void
    {
        (new PublishSocialTarget($this->targetId))->failed($e);
    }
}
