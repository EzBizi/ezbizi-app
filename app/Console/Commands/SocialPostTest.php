<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Models\SocialConnection;
use App\Support\Social\Publishing\PinterestPublisher;
use App\Support\Social\Publishing\SocialPost;
use App\Support\Social\Publishing\SocialPublishFailed;
use App\Support\Social\Publishing\SocialPublisher;
use App\Support\Social\Publishing\TikTokPublisher;
use App\Support\Social\SocialProvider;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;

/**
 * Send one post to one connected account, from the command line.
 *
 * ── Why a command before a composer ─────────────────────────────
 *
 * The publishers are the part that can only be proved against the real network:
 * a token that works for reading may not carry the scope to write, an app that
 * is not audited may only post privately, and TikTok's three-call upload has an
 * off-by-one in the Content-Range that no test double would catch. This exercises
 * exactly that path with nothing else in the way, so when the composer arrives
 * the uncertain half is already known-good.
 *
 * It is not a shortcut around the composer. It posts publicly to a real account,
 * so it names what it is about to do and asks first unless told not to.
 */
class SocialPostTest extends Command
{
    protected $signature = 'social:post-test
                            {connection : Connection id, or a provider like pinterest}
                            {--file= : Image or video to attach}
                            {--body= : The words}
                            {--title= : Title, where the network has one}
                            {--draft : TikTok only — send to drafts instead of the profile}
                            {--force : Do not ask}';

    protected $description = 'Publish one post to one connected social account';

    public function handle(TenantContext $tenant): int
    {
        $row = $this->find();

        if ($row === null) {
            return self::FAILURE;
        }

        [$organization, $connection] = $row;

        $provider = $connection->providerEnum();
        $file     = $this->option('file');

        if ($file !== null && ! is_file($file)) {
            $this->error("No file at {$file}.");

            return self::FAILURE;
        }

        $post = new SocialPost(
            body: (string) ($this->option('body') ?? ''),
            filePath: $file,
            title: $this->option('title'),
        );

        $kind = $post->kind();

        // The network's own rule, checked before anything is sent. A refusal
        // here is the sentence the composer will show.
        if ($provider !== null && ($why = $provider->refuses($kind)) !== null) {
            $this->error($why);

            return self::FAILURE;
        }

        $this->line('  account   '.$connection->display_name.'  ('.$connection->providerLabel().')');
        $this->line('  kind      '.$kind->label());
        $this->line('  file      '.($file ?? '(none)'));
        $this->line('  body      '.($post->body !== '' ? $post->body : '(none)'));

        if (! $this->option('force') && ! $this->confirm('Publish this, for real, to that account?')) {
            return self::FAILURE;
        }

        $publisher = $this->publisherFor($provider);

        if ($publisher === null) {
            $this->error('No publisher for '.$connection->providerLabel().' yet.');

            return self::FAILURE;
        }

        try {
            $id = $tenant->run($organization, fn () => $publisher->publish($connection, $post));
        } catch (SocialPublishFailed $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info('✓ published — remote id '.$id);

        if ($publisher instanceof TikTokPublisher) {
            $this->line($this->option('draft')
                ? '  Sent to drafts. Open TikTok to finish and post it.'
                : '  TikTok processes after the upload. Status:');
            $status = $tenant->run($organization, fn () => $publisher->status($connection, $id));
            $this->line('    '.json_encode($status));
            $this->line('  Posted SELF_ONLY (private) because the app is not audited yet.');
        }

        return self::SUCCESS;
    }

    private function publisherFor(?SocialProvider $provider): ?SocialPublisher
    {
        return match ($provider) {
            SocialProvider::Pinterest => new PinterestPublisher,
            SocialProvider::TikTok    => new TikTokPublisher(toDrafts: (bool) $this->option('draft')),
            default                   => null,
        };
    }

    /** @return array{0: Organization, 1: SocialConnection}|null */
    private function find(): ?array
    {
        $given = (string) $this->argument('connection');

        $query = SocialConnection::query()->withoutGlobalScopes();

        $connection = $query->clone()->where('id', $given)->first()
            ?? $query->clone()->where('provider', $given)->first();

        if ($connection === null) {
            $this->error("No connection matching '{$given}'.");
            $this->line('Connected accounts:');

            foreach (SocialConnection::query()->withoutGlobalScopes()->get() as $row) {
                $this->line(sprintf('  %s  %-24s %s', $row->id, $row->provider, $row->display_name));
            }

            return null;
        }

        $organization = Organization::query()->withoutGlobalScopes()->find($connection->organization_id);

        if ($organization === null) {
            $this->error('That connection belongs to an organization that is gone.');

            return null;
        }

        return [$organization, $connection];
    }
}
