<?php

namespace App\Console\Commands;

use App\Jobs\PublishSocialTarget;
use App\Models\BusinessLocation;
use App\Models\Organization;
use App\Models\SocialPost;
use App\Models\SocialPostTarget;
use App\Support\Ai\GrokCli;
use App\Support\Social\Publishing\GoogleBusinessProfilePublisher;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Throwable;

/**
 * One AI-written Google post waiting per listing (Jose, 2026-11-03): "make a
 * screen that shows what the post will be like and then approve - disapprove ...
 * once im 100% sure it doesnt need my approvals we can automatize it".
 *
 * Each post waits as awaiting_approval for the queue screen, unless the listing
 * has auto_post on, which nobody has yet. No two posts are the same: the AI sees
 * the listing's recent posts and a repeat is thrown away.
 */
class WeeklyBusinessProfilePosts extends Command
{
    protected $signature = 'ezbizi:gbp-weekly-posts';

    protected $description = 'Make sure every listing has one AI Google post waiting for approval';

    public function handle(TenantContext $tenant): int
    {
        if (! GrokCli::configured()) {
            $this->error('AI is not switched on.');

            return self::FAILURE;
        }

        Organization::query()->each(fn (Organization $org) => $tenant->run($org, function () use ($org) {
            $made = BusinessLocation::query()->whereNull('not_listed_since')->whereNotNull('connection_id')
                ->with(['connection', 'company', 'franchise'])->get()
                ->filter(fn (BusinessLocation $place) => self::draftFor($place))->count();
            $this->line("{$org->name}: {$made} posts drafted");
        }));

        return self::SUCCESS;
    }

    /**
     * Keeps one draft waiting for this listing (Jose, 2026-11-04: always something to approve,
     * the next written as soon as one is decided). True when a new one was written.
     */
    public static function draftFor(BusinessLocation $place): bool
    {
        $where = GoogleBusinessProfilePublisher::v4Name($place, $place->connection);
        $recent = SocialPostTarget::query()->where('options->gbp_location', $where)
            ->where('created_at', '>=', now()->subDays(90))->with('post')->latest()->get();
        if ($recent->contains(fn ($t) => $t->status === SocialPostTarget::STATUS_AWAITING_APPROVAL)) {
            return false;
        }
        $before = $recent->map(fn ($t) => (string) $t->post?->body)->filter()->values();
        try {
            $body = self::write($place, $before->take(10)->all());
        } catch (Throwable $e) {
            report($e);

            return false;
        }
        if ($body === '' || $before->contains(fn ($b) => self::norm($b) === self::norm($body))) {
            return false;
        }
        $post = SocialPost::create(['body' => $body]);
        $target = $post->targets()->create([
            'social_connection_id' => $place->connection_id,
            'options' => ['gbp_location' => $where, 'gbp_title' => $place->title, 'weekly' => true],
            'status' => $place->auto_post ? SocialPostTarget::STATUS_QUEUED : SocialPostTarget::STATUS_AWAITING_APPROVAL,
        ]);
        if ($place->auto_post) {
            PublishSocialTarget::dispatch($target->getKey());
        }

        return true;
    }

    /** @param  string[]  $before  this listing's recent posts, so the new one says something else */
    public static function write(BusinessLocation $place, array $before): string
    {
        $business = $place->company?->name ?? $place->title;
        $city = trim(implode(', ', array_filter([$place->city, $place->state])));
        $prompt = implode("\n", array_filter([
            "Write one Google Business Profile update for {$business}".($city !== '' ? " in {$city}" : '').'.',
            $place->primary_category ? "The business is a {$place->primary_category}." : null,
            'Pick one seasonal or practical topic a homeowner would care about this week, give one useful tip, and end with a short invitation to call or book.',
            'Between 60 and 120 words. Plain text, no hashtags, no emojis, no em dashes, no prices, no discounts, no phone numbers, no links. Never invent facts.',
            $before === [] ? null : "Do not repeat the topic or wording of these earlier posts:\n- ".implode("\n- ", array_map(fn ($b) => mb_substr($b, 0, 300), $before)),
        ]));
        $answer = GrokCli::ask($prompt, ['type' => 'object', 'properties' => ['post' => ['type' => 'string']],
            'required' => ['post'], 'additionalProperties' => false]);

        return mb_substr(trim((string) preg_replace('/\s*[\x{2014}\x{2013}]\s*/u', ', ', (string) ($answer['post'] ?? ''))), 0, 1500);
    }

    private static function norm(string $body): string
    {
        return strtolower(preg_replace('/\W+/u', '', $body));
    }
}
