<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Models\PhoneConnection;
use App\Support\Phone\TwilioGateway;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Throwable;

/**
 * Give a phone connection the credential that lets Twilio wake a handset.
 *
 * ── The defect this closes ──────────────────────────────────────
 *
 * A browser hears an incoming call down the signalling socket it is holding
 * open. A locked phone holds nothing open, so Twilio sends a VoIP push — and
 * only when the access token names a push credential. Ours named none, so the
 * TestFlight build registered, dialled out, called itself connected, and never
 * rang once. Nothing errored: outgoing needs only the TwiML application.
 *
 * ── Why a command and not a screen ──────────────────────────────
 *
 * What goes in is an Apple VoIP certificate and its private key, or a Firebase
 * server key. Those are EzBizi's own signing material, the same for every
 * tenant, and they are uploaded once per Twilio account and never read back.
 * A settings page for them would be a form nobody in any company should ever
 * see, guarding a value none of them can produce.
 *
 * ── Why per connection ──────────────────────────────────────────
 *
 * A push credential belongs to the Twilio account that mints the token, and
 * each company mints from its own subaccount. A credential created on the
 * parent is invisible to a child's token — accepted, and silently never
 * delivered. So: once per connection, run again when a company is added.
 *
 *   php artisan ezbizi:phone-push-credential --platform=ios \
 *       --certificate=voip-cert.pem --private-key=voip-key.pem --production
 *
 *   php artisan ezbizi:phone-push-credential --platform=android \
 *       --secret=firebase-server-key.json
 */
class PhoneRegisterPush extends Command
{
    protected $signature = 'ezbizi:phone-push-credential
        {--platform= : ios or android.}
        {--connection= : One connection id. Default: every connected one.}
        {--certificate= : [ios] Path to the VoIP certificate, PEM.}
        {--private-key= : [ios] Path to its private key, PEM.}
        {--production : [ios] Production APNs. Without this the credential is sandbox, which only a Debug build off Xcode can use — TestFlight and the App Store are both production.}
        {--secret= : [android] Path to the Firebase server key or service account JSON.}
        {--replace : Overwrite a connection that already has one.}';

    protected $description = 'Register the APNs or FCM push credential a handset needs to ring';

    public function handle(TwilioGateway $twilio, TenantContext $tenant): int
    {
        $platform = (string) $this->option('platform');
        if (! in_array($platform, ['ios', 'android'], true)) {
            $this->error('--platform must be ios or android.');

            return self::FAILURE;
        }

        try {
            $material = $this->material($platform);
        } catch (Throwable $failure) {
            $this->error($failure->getMessage());

            return self::FAILURE;
        }

        $column = $platform === 'ios'
            ? 'push_credential_ios_sid'
            : 'push_credential_android_sid';

        $done = 0;
        $failed = 0;

        foreach (Organization::query()->cursor() as $organization) {
            $connections = $tenant->run($organization, fn () => PhoneConnection::query()
                ->where('status', PhoneConnection::STATUS_CONNECTED)
                ->when(
                    $this->option('connection'),
                    fn ($query, $id) => $query->whereKey($id),
                )
                ->get());

            foreach ($connections as $connection) {
                if ($connection->{$column} && ! $this->option('replace')) {
                    $this->line("skip {$connection->id}: has one already (--replace to overwrite)");

                    continue;
                }

                try {
                    $sid = $twilio->createPushCredential($connection, $platform, $material);
                } catch (Throwable $failure) {
                    $failed++;
                    $this->error("{$connection->id}: {$failure->getMessage()}");

                    continue;
                }

                $tenant->run(
                    $organization,
                    fn () => $connection->forceFill([$column => $sid])->save(),
                );
                $done++;
                $this->info("{$connection->id}: {$sid}");
            }
        }

        $this->line("{$done} registered, {$failed} failed.");

        // A run that reached no connection at all is not a success: it means
        // the id was wrong, or nothing is connected, and reporting 0/0 as OK
        // is how a provisioning step gets ticked off without happening.
        return ($failed > 0 || $done === 0) ? self::FAILURE : self::SUCCESS;
    }

    /** @return array<string, mixed> */
    private function material(string $platform): array
    {
        if ($platform === 'android') {
            return ['secret' => $this->read('secret')];
        }

        return [
            'certificate' => $this->read('certificate'),
            'private_key' => $this->read('private-key'),
            'sandbox' => ! $this->option('production'),
        ];
    }

    private function read(string $option): string
    {
        $path = (string) $this->option($option);
        if ($path === '' || ! is_file($path)) {
            throw new \RuntimeException("--{$option} must be a readable file.");
        }

        $contents = trim((string) file_get_contents($path));
        if ($contents === '') {
            throw new \RuntimeException("--{$option} is empty.");
        }

        return $contents;
    }
}
