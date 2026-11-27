<?php

namespace App\Jobs;

use App\Models\Organization;
use App\Models\SocialPostTarget;
use App\Support\Automations\EventEmitter;
use App\Support\Social\Publishing\Publishers;
use App\Support\Social\Publishing\SocialPublishFailed;
use App\Support\Social\Publishing\TikTokPublisher;
use App\Support\Social\SocialProvider;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Hears whether TikTok accepted a video PublishSocialTarget uploaded. The
 * upload only queues TikTok's work, and TikTok can still refuse the video
 * while processing it (codec, length, a guideline). One status check per run,
 * then sent, failed, or look again in EVERY seconds - FinishInstagramPost's
 * shape.
 */
final class FinishTikTokPost implements ShouldQueue
{
    use Queueable;

    public const EVERY = 30;

    /** 20 x 30s: ten minutes of processing before EzBizi stops waiting. */
    public const LOOKS = 20;

    public int $tries = 1;

    public function __construct(public readonly string $targetId, public readonly int $look = 1) {}

    public function handle(TenantContext $tenant, EventEmitter $events): void
    {
        $target = SocialPostTarget::query()->withoutGlobalScopes()->find($this->targetId);
        $organization = $target === null ? null
            : Organization::query()->withoutGlobalScopes()->find($target->organization_id);

        if ($target === null || $organization === null || $target->status !== SocialPostTarget::STATUS_QUEUED) {
            return;
        }

        $tenant->run($organization, fn () => $this->look($target, $events));
    }

    private function look(SocialPostTarget $target, EventEmitter $events): void
    {
        $publishId = (string) (((array) ($target->options ?? []))['tiktok_publish_id'] ?? '');
        $publisher = $target->connection === null ? null : Publishers::for(SocialProvider::TikTok);
        $job = new PublishSocialTarget($target->getKey());

        if ($publishId === '' || ! $publisher instanceof TikTokPublisher) {
            $job->markFailedAndEmit($target, $events, 'TikTok refused it: EzBizi lost track of the video while it was processing. Check TikTok before you try again.');

            return;
        }

        try {
            $status = $publisher->status($target->connection, $publishId);
        } catch (SocialPublishFailed $e) {
            $status = []; // A blip on a status check is worth another look.
        } catch (Throwable $e) {
            report($e);
            $status = [];
        }

        $state = (string) ($status['status'] ?? '');

        if ($state === 'PUBLISH_COMPLETE' || $state === 'SEND_TO_USER_INBOX') {
            $job->succeeded($target, $publisher, $publishId, $events);

            return;
        }

        if ($state === 'FAILED') {
            $reason = (string) ($status['fail_reason'] ?? '');
            $job->markFailedAndEmit($target, $events, 'TikTok refused it'
                .($reason !== '' ? ' ('.$reason.')' : '').'. Nothing was posted.');

            return;
        }

        if ($this->look >= self::LOOKS) {
            $job->markFailedAndEmit($target, $events,
                'TikTok was still processing the video after more than ten minutes, so EzBizi stopped waiting. '
                .'Check TikTok before you try again.');

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
