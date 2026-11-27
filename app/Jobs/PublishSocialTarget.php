<?php

namespace App\Jobs;

use App\Models\Organization;
use App\Models\SocialPostTarget;
use App\Support\Automations\EventEmitter;
use App\Support\Social\Publishing\LinksToPosts;
use App\Support\Social\Publishing\Publishers;
use App\Support\Social\Publishing\SocialPublishFailed;
use App\Support\Social\Publishing\StillProcessing;
use App\Support\Social\Publishing\TikTokPublisher;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Send one post to one account.
 *
 * ── One job per target, and why it must stay that way ────────────
 *
 * A job that looped over the targets would make a partial failure
 * unrepresentable: the third network refusing would either roll back the two
 * that already posted — it cannot, they are on somebody else's servers — or be
 * swallowed. One job per target means each send owns exactly the row it can
 * honestly report on.
 *
 * ── `tries = 1`, deliberately ────────────────────────────────────
 *
 * There is no idempotency key on any of these APIs. A retry after a timeout
 * cannot tell "the post did not land" from "the post landed and the answer did
 * not come back", so the safe reading of an unknown outcome is to stop and say
 * so, rather than to risk publishing the same thing twice to a customer's real
 * audience. The worker already runs `--tries=1`; this states it here so the
 * property survives somebody changing the worker's flags.
 *
 * A failed target keeps its sentence and the composer shows it. Sending it
 * again is a person's decision, not a queue's.
 */
final class PublishSocialTarget implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /*
     * YouTube, LinkedIn and TikTok give an upload up to 120 s, and the default
     * worker kills a job at 60 s. On `database-long` (retry_after 200) a slow
     * upload finishes instead of being cut off mid-send.
     */
    public int $timeout = 150;

    public function __construct(public readonly string $targetId)
    {
        // Only where the long worker exists: under `sync` (tests) the job runs inline.
        if (config('queue.default') === 'database') {
            $this->onConnection('database-long');
        }

        $this->onQueue('long');
    }

    public function handle(TenantContext $tenant, EventEmitter $events): void
    {
        /*
         * Read outside the tenant scope, because a queued job has no tenant yet
         * — the global scope would filter this row to nothing and the send would
         * quietly never happen. Everything after `run()` below is scoped
         * normally.
         */
        $target = SocialPostTarget::query()->withoutGlobalScopes()->find($this->targetId);

        if ($target === null) {
            return; // The post or the account was deleted while this waited.
        }

        $organization = Organization::query()->withoutGlobalScopes()->find($target->organization_id);

        if ($organization === null) {
            return;
        }

        $tenant->run($organization, fn () => $this->send($target, $events));
    }

    private function send(SocialPostTarget $target, EventEmitter $events): void
    {
        $connection = $target->connection;
        $post = $target->post;

        if ($connection === null || $post === null) {
            return;
        }

        // Whoever queued it owns it once. A second job for the same target
        // (two approvals, a retry racing a sweep) finds it no longer queued.
        if ($target->status !== SocialPostTarget::STATUS_QUEUED) {
            return;
        }

        $publisher = Publishers::for($connection->providerEnum());

        if ($publisher === null) {
            // The controller refuses these before a row is written, so reaching
            // here means a publisher was removed after something was queued.
            $this->markFailedAndEmit($target, $events, (string) Publishers::refusalFor($connection->providerEnum()));

            return;
        }

        [$payload, $temp] = $post->payload();
        $payload = $payload->withOptions((array) ($target->options ?? []));

        try {
            $remoteId = $publisher->publish($connection, $payload);
        } catch (StillProcessing $e) {
            // Not failed and not sent: still `queued` while Instagram ingests.
            $target->forceFill(['options' => array_merge((array) ($target->options ?? []), [
                'instagram_container' => $e->container,
                'handoff' => $e->handle,
            ])])->save();

            FinishInstagramPost::dispatch($target->getKey())->delay(now()->addSeconds(FinishInstagramPost::EVERY));

            return;
        } catch (SocialPublishFailed $e) {
            $this->markFailedAndEmit($target, $events, $e->getMessage());

            return;
        } catch (Throwable $e) {
            /*
             * Anything the publisher did not turn into a sentence — a DNS
             * failure, a malformed response, a bug in here. The tenant is told
             * the network could not be reached rather than shown a stack frame
             * (§R8), and the real message goes to the log where it belongs.
             */
            report($e);
            $this->markFailedAndEmit(
                $target,
                $events,
                'EzBizi could not reach '.$connection->providerLabel().'. Nothing was posted. '
                .'Try again, and if it keeps happening the account may need reconnecting.',
            );

            return;
        } finally {
            if ($temp !== null && is_file($temp)) {
                unlink($temp);
            }
        }

        if ($publisher instanceof TikTokPublisher) {
            /*
             * The bytes landed; TikTok has not accepted the video yet. It can
             * still refuse it during processing, so "sent" waits for
             * FinishTikTokPost to hear PUBLISH_COMPLETE (or the inbox).
             */
            $target->forceFill(['options' => array_merge((array) ($target->options ?? []), [
                'tiktok_publish_id' => $remoteId,
            ])])->save();

            FinishTikTokPost::dispatch($target->getKey())->delay(now()->addSeconds(FinishTikTokPost::EVERY));

            return;
        }

        $this->succeeded($target, $publisher, $remoteId, $events);
    }

    /** Shared with FinishInstagramPost, which completes a post this job began. */
    public function succeeded(SocialPostTarget $target, object $publisher, string $remoteId, EventEmitter $events): void
    {
        $connection = $target->connection;
        $post = $target->post;

        $target->markSent($remoteId);

        // Where it can be seen. Best effort: the post IS out, and a network
        // that will not say where must not turn a sent post into a failure.
        if ($publisher instanceof LinksToPosts) {
            try {
                $target->forceFill(['url' => $publisher->permalink($connection, $remoteId)])->save();
            } catch (Throwable $e) {
                report($e);
            }
        }

        $events->emit('social.published', $target, [
            'provider' => $connection->provider,
            'account' => $connection->display_name,
            'remote_id' => $remoteId,
            'has_media' => $post->media_path !== null,
        ]);
    }

    /**
     * Laravel's own failure hook: the worker timed out or something threw
     * outside the try above. The target must not stay `queued`, where nothing
     * can retry, edit or cancel it.
     */
    public function failed(?Throwable $e = null): void
    {
        $target = SocialPostTarget::query()->withoutGlobalScopes()->find($this->targetId);
        $organization = $target === null ? null
            : Organization::query()->withoutGlobalScopes()->find($target->organization_id);

        if ($target === null || $organization === null || $target->status !== SocialPostTarget::STATUS_QUEUED) {
            return;
        }

        app(TenantContext::class)->run($organization, fn () => $this->markFailedAndEmit(
            $target,
            app(EventEmitter::class),
            self::unknownOutcome((string) $target->connection?->providerLabel()),
        ));
    }

    /** The sentence for a send that stopped without an answer. */
    public static function unknownOutcome(string $network): string
    {
        $network = $network !== '' ? $network : 'the network';

        return 'EzBizi stopped waiting for '.$network.' before it answered, so it cannot tell whether the post went out. '
            .'Check '.$network.' before you try again.';
    }

    public function markFailedAndEmit(SocialPostTarget $target, EventEmitter $events, string $because): void
    {
        $target->markFailed($because);

        $events->emit('social.publish_failed', $target, [
            'provider' => $target->connection?->provider,
            'account' => $target->connection?->display_name,
            'because' => $because,
        ]);
    }
}
