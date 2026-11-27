<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * "Your appointment request is confirmed", to the visitor who booked online
 * (Jose, 2026-11-11: "a confirmation email ... from noreply ... design it
 * well", one per company with its own logo and colour).
 *
 * Tables and inline styles only, so Gmail, Outlook and Apple Mail draw the
 * same thing. The colour is used on borders and the button, never as a page
 * background, so a client's dark mode inverting the white leaves it legible.
 * The logo is embedded (cid), not linked: company logos sit behind sign-in.
 */
class BookingConfirmationMail extends Mailable
{
    /**
     * @param  array{company: string, accent: string, on_accent: string, logo_bytes: ?string, logo_mime: ?string, date: string, window: string, service: ?string, address: string, name: ?string, phone: ?string, email: ?string, site: ?string}  $b
     */
    public function __construct(public readonly array $b) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your appointment request is confirmed - '.$this->b['company']);
    }

    public function content(): Content
    {
        return new Content(htmlString: self::page($this->b, fn () => 'cid:ezbizi-logo'));
    }

    public function build(): static
    {
        if (($this->b['logo_bytes'] ?? null) !== null) {
            $this->withSymfonyMessage(function ($message) {
                $message->embed($this->b['logo_bytes'], 'ezbizi-logo', $this->b['logo_mime'] ?? 'image/png');
            });
        }

        return $this;
    }

    /**
     * The whole email. Static so a preview can render it with no Mailable.
     *
     * @param  callable(array): string|null  $logoSrc  the img src for the logo; a data: URI when null
     */
    public static function page(array $b, ?callable $logoSrc = null): string
    {
        $e = fn (?string $s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $accent = preg_match('/^#[0-9a-fA-F]{6}$/', (string) $b['accent']) ? $b['accent'] : '#0b5cad';
        $on = preg_match('/^#[0-9a-fA-F]{6}$/', (string) ($b['on_accent'] ?? '')) ? $b['on_accent'] : '#ffffff';
        $font = "font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif";
        $src = ($b['logo_bytes'] ?? null) === null ? null
            : ($logoSrc !== null ? $logoSrc($b) : 'data:'.($b['logo_mime'] ?? 'image/png').';base64,'.base64_encode($b['logo_bytes']));

        $head = $src !== null
            ? '<img src="'.$e($src).'" alt="'.$e($b['company']).'" height="56" style="display:block;height:56px;width:auto;max-width:240px;border:0">'
            : '<div style="'.$font.';font-size:22px;font-weight:800;color:#1b1b1b">'.$e($b['company']).'</div>';

        $row = fn (string $label, ?string $value) => $value === null || trim($value) === '' ? '' :
            '<tr><td style="'.$font.';padding:10px 0;border-bottom:1px solid #ececec;color:#6b6b6b;font-size:14px;width:120px;vertical-align:top">'.$e($label).'</td>'
            .'<td style="'.$font.';padding:10px 0;border-bottom:1px solid #ececec;color:#1b1b1b;font-size:15px;font-weight:600">'.nl2br($e($value)).'</td></tr>';

        $phone = trim((string) ($b['phone'] ?? ''));
        $call = $phone === '' ? '' :
            '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:24px 0 8px"><tr><td style="background:'.$accent.';border-radius:8px">'
            .'<a href="tel:'.$e(preg_replace('/[^0-9+]/', '', $phone)).'" style="'.$font.';display:inline-block;padding:13px 26px;color:'.$on.';font-weight:700;font-size:15px;text-decoration:none">Call '.$e($phone).'</a></td></tr></table>';

        $reach = array_filter([
            $phone !== '' ? 'Phone: <a href="tel:'.$e(preg_replace('/[^0-9+]/', '', $phone)).'" style="color:#1b1b1b">'.$e($phone).'</a>' : null,
            ($b['email'] ?? '') !== '' ? 'Email: <a href="mailto:'.$e($b['email']).'" style="color:#1b1b1b">'.$e($b['email']).'</a>' : null,
            ($b['site'] ?? '') !== '' ? '<a href="https://'.$e($b['site']).'" style="color:#1b1b1b">'.$e($b['site']).'</a>' : null,
        ]);

        return '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            .'<meta name="color-scheme" content="light only"><meta name="supported-color-schemes" content="light"><title>'.$e($b['company']).'</title></head>'
            .'<body style="margin:0;padding:0;background:#f4f4f5">'
            .'<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f4f5"><tr><td align="center" style="padding:24px 12px">'
            .'<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border-radius:12px;border-top:6px solid '.$accent.'">'
            .'<tr><td style="padding:28px 32px 8px">'.$head.'</td></tr>'
            .'<tr><td style="padding:8px 32px 0">'
            .'<h1 style="'.$font.';margin:12px 0 6px;font-size:24px;line-height:1.25;color:#1b1b1b">Your appointment request is confirmed</h1>'
            .'<p style="'.$font.';margin:0 0 20px;font-size:15px;line-height:1.55;color:#3a3a3a">'
            .(($b['name'] ?? '') !== '' ? 'Hi '.$e(strtok((string) $b['name'], ' ')).', thank' : 'Thank').' you for booking with '.$e($b['company']).'. Here are the details.</p>'
            .'<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-top:2px solid '.$accent.'">'
            .$row('Day', $b['date']).$row('Arrival', $b['window']).$row('Service', $b['service'] ?? null).$row('Address', $b['address'])
            .'</table>'
            .'<h2 style="'.$font.';margin:26px 0 6px;font-size:17px;color:#1b1b1b">What happens next</h2>'
            .'<p style="'.$font.';margin:0;font-size:15px;line-height:1.55;color:#3a3a3a">Our technician arrives within the window above. '
            .'You will get a message when they are on the way. Need to change the time? Call us and we will sort it out.</p>'
            .$call
            .'</td></tr>'
            .'<tr><td style="padding:20px 32px 28px"><p style="'.$font.';margin:0;font-size:13px;line-height:1.6;color:#6b6b6b">'
            .$e($b['company']).($reach === [] ? '' : '<br>'.implode(' &middot; ', $reach)).'</p></td></tr>'
            .'</table>'
            .'<p style="'.$font.';margin:14px 0 0;font-size:11px;color:#9a9a9a">This message was sent because an appointment was requested online. Reply to this email to reach '.$e($b['company']).'.</p>'
            .'</td></tr></table></body></html>';
    }
}
