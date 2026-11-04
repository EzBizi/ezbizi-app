<?php

namespace App\Support\Invoices;

use App\Mail\InvoiceMail;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\Mailbox;
use App\Models\Organization;
use App\Models\PhoneNumber;
use App\Models\WhatsAppNumber;
use App\Support\Email\MailboxReaders;
use App\Support\Mail\CannotSendMail;
use App\Support\Mail\MailDispatcher;
use App\Support\Phone\PhoneTexts;
use App\Support\Settings\SettingsResolver;
use App\Support\Social\WhatsAppCloud;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Throwable;

/**
 * How an invoice reaches whoever it bills (Jose, 2026-10-22: "either texted or
 * emailed depending on the settings set ... a number the company owns (that
 * has texting) or Whatsapp or Email that depends on the company to set up (or
 * multiple)"). Each channel answers whether it is ready and, when not, why in
 * a sentence the Settings pane can show; sending goes through what the company
 * itself connected, never through EzBizi's own address.
 *
 *   email     the company's connected mailbox (Google Workspace, Microsoft…),
 *             else its own mail server
 *   sms       a number the company owns that may text (invoicing.sms_from)
 *   whatsapp  the company's WhatsApp number, with an approved template
 */
final class InvoiceChannels
{
    public const ALL = ['email', 'sms', 'whatsapp'];

    public function __construct(
        private readonly SettingsResolver $settings,
        private readonly MailboxReaders $readers,
        private readonly MailDispatcher $mail,
        private readonly PhoneTexts $texts,
        private readonly WhatsAppCloud $whatsapp,
    ) {}

    /** @return list<string> the channels this issuer sends invoices by */
    public function chosen(Company|Organization $issuer): array
    {
        $chosen = array_values(array_intersect(self::ALL, (array) $this->settings->get('invoicing.channels', $issuer)));

        return $chosen === [] ? ['email'] : $chosen;
    }

    /** @return array{channel: string, ready: bool, why_not: ?string, from: ?string} */
    public function readiness(string $channel, Company|Organization $issuer): array
    {
        return match ($channel) {
            'email' => $this->emailReadiness($issuer),
            'sms' => $this->smsReadiness($issuer),
            'whatsapp' => $this->whatsappReadiness($issuer),
        };
    }

    /** The company's (else the organization's) connected mailbox that can send, if any. */
    public function mailbox(Company|Organization $issuer): ?Mailbox
    {
        $wanted = (string) $this->settings->get('invoicing.mailbox_id', $issuer);
        $candidates = Mailbox::query()->where('status', Mailbox::STATUS_CONNECTED)->get()
            ->filter(fn (Mailbox $m) => ($issuer instanceof Company && $m->scope_type === Mailbox::SCOPE_COMPANY && $m->scope_id === $issuer->id)
                || $m->scope_type === Mailbox::SCOPE_ORGANIZATION);

        return ($wanted !== '' ? $candidates->firstWhere('id', $wanted) : null)
            ?? $candidates->sortBy(fn (Mailbox $m) => $m->scope_type === Mailbox::SCOPE_COMPANY ? 0 : 1)->first();
    }

    /** SMS-capable numbers the issuer owns, for the Settings picker. */
    public function textingNumbers(Company|Organization $issuer)
    {
        return PhoneNumber::query()->where('sms_outbound_allowed', true)->get()
            ->filter(fn (PhoneNumber $n) => $issuer instanceof Organization || ($n->scope_type === 'company' && $n->scope_id === $issuer->id) || $n->scope_type === 'organization')
            ->values();
    }

    /**
     * Sends one email: through the connected mailbox when there is one, else
     * the company's own mail server.
     *
     * @param  list<Invoice>  $invoices  attached as PDFs
     */
    public function email(Company|Organization $issuer, array $to, string $subject, string $html, array $invoices, InvoiceMail $mail): string
    {
        if (($mailbox = $this->mailbox($issuer)) !== null) {
            $attachments = [];
            foreach ($invoices as $invoice) {
                if ($invoice->pdf_path !== null) {
                    $attachments[] = ['name' => $invoice->number.'.pdf', 'mime' => 'application/pdf',
                        'bytes' => (string) Storage::disk($invoice->pdf_disk)->get($invoice->pdf_path)];
                }
            }
            $this->readers->for($mailbox)->send($mailbox, $to, $subject, $html, $attachments);

            return (string) $mailbox->address;
        }

        $this->mail->throughTenantProvider($issuer, $mail, $to);

        return (string) ($this->mail->smtpConfigFor($issuer)['username'] ?? '');
    }

    /** One text per phone: the summary and a link to the PDF. Returns the number it went from. */
    public function sms(Company|Organization $issuer, Invoice $invoice, array $phones): string
    {
        $number = PhoneNumber::query()->findOrFail((string) $this->settings->get('invoicing.sms_from', $issuer));
        foreach ($phones as $phone) {
            $this->texts->send($number, $phone, $this->shortText($issuer, $invoice), automated: true);
        }

        return (string) $number->e164;
    }

    /** One WhatsApp template message per phone: name, number, total, link. */
    public function whatsapp(Company|Organization $issuer, Invoice $invoice, array $phones): string
    {
        $template = (string) $this->settings->get('invoicing.whatsapp_template', $issuer);
        $from = (string) $this->settings->get('invoicing.whatsapp_from', $issuer) ?: null;
        foreach ($phones as $phone) {
            $this->whatsapp->send($template, $phone, [
                (string) $issuer->name, $invoice->number, '$'.number_format($invoice->total_cents / 100, 2), $this->link($invoice),
            ], from: $from, idempotencyKey: 'invoice:'.$invoice->id.':'.$phone);
        }

        return (string) ($from ?? WhatsAppNumber::query()->value('display_number'));
    }

    /** A link to the PDF that needs no sign-in, good for 60 days. */
    public function link(Invoice $invoice): string
    {
        return URL::temporarySignedRoute('invoice.shared', now()->addDays(60), ['invoice' => $invoice->id]);
    }

    public function shortText(Company|Organization $issuer, Invoice $invoice): string
    {
        $due = $invoice->due_on ? ', due '.$invoice->due_on->format('m/d/Y') : '';

        return "{$issuer->name}: invoice {$invoice->number} for $".number_format($invoice->total_cents / 100, 2)."{$due}. "
            .'View it: '.$this->link($invoice);
    }

    // ── Readiness ─────────────────────────────────────────────

    private function emailReadiness(Company|Organization $issuer): array
    {
        if (($mailbox = $this->mailbox($issuer)) !== null) {
            return ['channel' => 'email', 'ready' => true, 'why_not' => null, 'from' => $mailbox->address];
        }
        try {
            $smtp = $this->mail->smtpConfigFor($issuer);

            return ['channel' => 'email', 'ready' => true, 'why_not' => null, 'from' => $smtp['username'] ?? null];
        } catch (CannotSendMail) {
            return ['channel' => 'email', 'ready' => false, 'from' => null,
                'why_not' => 'Connect the mailbox invoices should come from (Google Workspace, Microsoft 365 or your own mail server) in Integrations.'];
        }
    }

    private function smsReadiness(Company|Organization $issuer): array
    {
        $id = (string) $this->settings->get('invoicing.sms_from', $issuer);
        $number = $id === '' ? null : PhoneNumber::query()->find($id);
        if ($number === null) {
            return ['channel' => 'sms', 'ready' => false, 'from' => null,
                'why_not' => $this->textingNumbers($issuer)->isEmpty()
                    ? 'This company has no number that can send texts yet. Add one in Phone.'
                    : 'Choose the number invoices are texted from.'];
        }
        if (! $number->sms_outbound_allowed) {
            return ['channel' => 'sms', 'ready' => false, 'from' => $number->e164, 'why_not' => PhoneNumber::SMS_REFUSAL];
        }

        return ['channel' => 'sms', 'ready' => true, 'why_not' => null, 'from' => $number->e164];
    }

    private function whatsappReadiness(Company|Organization $issuer): array
    {
        $numbers = WhatsAppNumber::query()->get();
        $from = (string) $this->settings->get('invoicing.whatsapp_from', $issuer);
        $why = match (true) {
            $numbers->isEmpty() => 'Connect a WhatsApp number in Integrations.',
            $numbers->count() > 1 && $from === '' => 'Choose which WhatsApp number invoices go from.',
            (string) $this->settings->get('invoicing.whatsapp_template', $issuer) === '' => 'Name the approved WhatsApp template invoices are sent with (it takes the business name, the invoice number, the total and a link).',
            default => null,
        };

        return ['channel' => 'whatsapp', 'ready' => $why === null, 'why_not' => $why,
            'from' => $from !== '' ? $from : $numbers->first()?->display_number];
    }

    /** Runs a send, turning any refusal into its sentence. */
    public static function attempt(callable $send): ?string
    {
        try {
            $send();

            return null;
        } catch (Throwable $e) {
            return $e->getMessage() !== '' ? $e->getMessage() : 'It could not be sent.';
        }
    }
}
