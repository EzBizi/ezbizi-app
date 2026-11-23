<?php

namespace App\Support\Web;

use App\Mail\BookingConfirmationMail;
use App\Models\Company;
use App\Models\WebSite;
use App\Models\WebValue;
use App\Models\WebWidget;
use App\Support\Files\FileStore;
use App\Support\Mail\MailDispatcher;
use App\Support\Settings\SettingsResolver;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * The booking confirmation email (Jose, 2026-11-11), in the company's own logo
 * and colour, from EzBizi's noreply with the company's name on it and its own
 * inbox as the reply-to. Off per company with leads.booking_confirmation_email.
 */
final class BookingConfirmations
{
    public function __construct(
        private readonly MailDispatcher $mail,
        private readonly SettingsResolver $settings,
        private readonly FileStore $files,
    ) {}

    /** Everything the email shows, for a booking or a preview. */
    public function details(WebSite $site, string $date, array $window, string $address, array $visitor): array
    {
        $values = (array) ValueResolver::forReaders(
            WebValue::query()->where('site_id', $site->getKey())->get(['path', 'key', 'value']),
            ValueResolver::inheritedRowsFor($site),
        )['values'];
        $style = WebWidget::resolvedFor($site)['style'] ?? [];
        $company = Company::query()->find($site->company_id);
        $fmt = fn (string $t) => CarbonImmutable::parse($date.' '.$t)->format('g:i A');
        [$bytes, $mime] = $this->logo($company);

        return [
            'company' => (string) ($values['company_name'] ?? '') ?: (string) ($company?->name ?? $site->host),
            'accent' => (string) ($style['accent'] ?? '#0b5cad'),
            'on_accent' => (string) ($style['on_accent'] ?? '#ffffff'),
            'logo_bytes' => $bytes,
            'logo_mime' => $mime,
            'date' => CarbonImmutable::parse($date)->format('l, F j, Y'),
            'window' => $fmt($window['start_time']).' - '.$fmt($window['end_time']),
            'service' => $visitor['service'] ?? null,
            'address' => $address,
            'name' => $visitor['name'] ?? null,
            'phone' => (string) ($values['phone'] ?? ''),
            'email' => (string) ($values['email'] ?? ''),
            'site' => $site->host,
        ];
    }

    /** Sent best-effort: a booking never fails because its email did. */
    public function send(WebSite $site, string $to, array $details): bool
    {
        $company = Company::query()->find($site->company_id);

        if ($company === null || ! filter_var($to, FILTER_VALIDATE_EMAIL)
            || ! (bool) $this->settings->get('leads.booking_confirmation_email', $company)) {
            return false;
        }

        try {
            $this->mail->platformForTenant($company, new BookingConfirmationMail($details), $to,
                fromName: $details['company'], replyTo: filter_var($details['email'], FILTER_VALIDATE_EMAIL) ? $details['email'] : null);

            return true;
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }

    /** @return array{0: ?string, 1: ?string} */
    private function logo(?Company $company): array
    {
        if ($company?->logo_path === null) {
            return [null, null];
        }

        try {
            $disk = Storage::disk($this->files->defaultDisk());

            return $disk->exists($company->logo_path)
                ? [(string) $disk->get($company->logo_path), $disk->mimeType($company->logo_path) ?: 'image/png']
                : [null, null];
        } catch (Throwable) {
            return [null, null];
        }
    }
}
