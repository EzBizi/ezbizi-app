<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\Strikethrough\StrikethroughExtension;
use League\CommonMark\Extension\Table\TableExtension;
use League\CommonMark\MarkdownConverter;
use TijsVerkoyen\CssToInlineStyles\CssToInlineStyles;

/**
 * An email an automation sends, from EzBizi, carrying words a tenant wrote.
 *
 * Jose reversed the September ruling on 2026-09-16 and drew the line himself:
 *
 *   "actions like send an email or send a whatsapp / Email ( the Ezbizi SES
 *    one, not the company one )"
 *
 * So this is EzBizi's transport and EzBizi's From, with a subject and a body an
 * administrator composed in a rule. That combination is why the envelope lives
 * here rather than in the Automations module: what a message from ezbizi.com
 * says about itself is one decision in one place, and the module that supplies
 * the words is not the module that should decide what the wrapper claims.
 *
 * ── Why no attachments, and why the parameter does not exist ─────
 *
 * An attachment from ezbizi.com on a message a tenant composed is a phishing
 * vector wearing our domain — and our SES reputation is shared with every sign
 * in, password reset and invitation the platform sends. Automations does not
 * offer the parameter at all rather than accepting one and dropping it, because
 * a field that silently ignores what somebody typed is the defect class that
 * module spent a week removing. There is nothing here to attach one with.
 *
 * ── The branded frame, and what keeps a business's words their own ─
 *
 * Until 2026-10-01 this was plain text on purpose: a tenant-authored body in
 * our mail styling could pass as EzBizi speaking. Jose then asked for every
 * automation email in EzBizi's colours ("not just text", UI queue 39), and the
 * reason survives in three places instead of in the absence of HTML:
 *
 *   - The body is Markdown with HTML input ESCAPED and unsafe links dropped
 *     ({@see self::markdownHtml()}), so a business's text is shown as text. A field a
 *     stranger can write (an email's body, a lead's preview) is escaped before
 *     it gets here, by ActionRunner::interpolate, unless its trigger declares
 *     it EzBizi-composed.
 *   - Without a $kind the band is the BUSINESS's — its name, then "via EzBizi"
 *     — and the footer names it again. Only a message EzBizi composed (a kind,
 *     such as the overbooking warning) takes the navy EzBizi band.
 *   - The subject is never Markdown, and the text part is the words with the
 *     Markdown taken out ({@see self::plain()}).
 */
class AutomationMessage extends Mailable
{
    use Queueable;
    use SerializesModels;

    /**
     * Whether the body is rendered as Markdown. ActionRunner escapes the body's
     * {{fields}} only while this is true, and every field not declared
     * EzBizi-composed (TriggerDefinition::composedFields) is escaped so a
     * stranger's text stays text. Turned true with the branded frame (UI queue
     * 39); turning it false again drops the HTML part with it.
     */
    public const RENDERS_MARKDOWN = true;

    /**
     * Styles for the rendered body, inlined because mail apps drop <style>.
     * Scoped to the Markdown's own wrapper, so the frame's button keeps its
     * size.
     */
    private const BODY_CSS = <<<'CSS'
        .md p { margin: 0 0 12px; }
        .md h1 { font-size: 20px; font-weight: 800; color: #060808; margin: 0 0 8px; }
        .md h2 { font-size: 11px; font-weight: 800; letter-spacing: 0.07em; text-transform: uppercase; color: #5B6672; margin: 18px 0 6px; }
        .md h3 { font-size: 15px; font-weight: 800; color: #060808; margin: 14px 0 4px; }
        .md ul, .md ol { margin: 0 0 12px; padding-left: 20px; }
        .md li { padding: 5px 0; border-bottom: 1px solid #F0F3F5; }
        .md strong { color: #060808; }
        .md a { color: #00708F; }
        .md table { border-collapse: collapse; width: 100%; margin: 0 0 12px; }
        .md th { text-align: left; font-size: 11px; letter-spacing: 0.06em; text-transform: uppercase; color: #5B6672; border-bottom: 2px solid #E3E9EC; padding: 6px; }
        .md td { border-bottom: 1px solid #F0F3F5; padding: 6px; }
        .md code { background-color: #E8ECF0; border-radius: 4px; padding: 0 4px; font-family: Consolas, Menlo, monospace; font-size: 13px; }
        .md blockquote { margin: 0 0 12px; padding-left: 12px; border-left: 3px solid #00B4D8; color: #5B6672; }
        .md hr { border: 0; border-top: 1px solid #E3E9EC; margin: 16px 0; }
        .md.composed ul { padding-left: 0; }
        .md.composed li { list-style: none; }
        CSS;

    /**
     * @param  string  $subjectLine  what the rule author typed
     * @param  string  $body  what the rule author typed, its {{fields}} already
     *                        filled in (and escaped where a stranger could write
     *                        them), as Markdown
     * @param  string  $businessName  the business the rule belongs to, named in
     *                                the band and the footer so a recipient can
     *                                tell who actually wrote to them
     * @param  string|null  $kind  set only when EzBizi composed the substance
     *                             (the trigger declares composed fields): the
     *                             navy EzBizi band, with this as its label
     * @param  string|null  $automationName  the rule's name, for the footer
     */
    public function __construct(
        private readonly string $subjectLine,
        private readonly string $body,
        private readonly string $businessName,
        private readonly ?string $kind = null,
        private readonly ?string $automationName = null,
        private readonly ?string $pdf = null,
    ) {}

    public function build(): self
    {
        /*
         * The footer is not decoration and it is not removable by the rule
         * author. A message from ezbizi.com carrying a business's words needs to
         * say whose words they are, or every one of these looks like EzBizi
         * writing to a customer it has no relationship with.
         *
         * Appended to the BODY rather than set as a header, because a header is
         * not read by the person the message confuses.
         */
        $footer = "\n\n--\nSent by {$this->businessName} through EzBizi.";
        $mail = $this->subject($this->subjectLine)->text('emails.automation-plain', [
            'body' => (self::RENDERS_MARKDOWN ? self::plain($this->body) : $this->body).$footer,
        ]);

        // A report's formatted copy rides along (Jose, 2026-11-10: every report, its PDF too).
        if ($this->pdf !== null) {
            $mail->attachData($this->pdf, (Str::slug($this->subjectLine) ?: 'report').'.pdf', ['mime' => 'application/pdf']);
        }

        return self::RENDERS_MARKDOWN ? $mail->html($this->framed()) : $mail;
    }

    /** The body in the branded frame, its styles inlined. */
    private function framed(): string
    {
        $page = view('emails.automation', [
            'subject' => $this->subjectLine,
            'bodyHtml' => self::markdownHtml($this->body),
            'business' => $this->businessName,
            'kind' => $this->kind,
            'automation' => $this->automationName,
            'icon' => rtrim((string) config('ezbizi.hosts.customer'), '/').'/icons/Icon-192.png',
            'open' => (string) config('ezbizi.hosts.customer'),
        ])->render();

        return (new CssToInlineStyles)->convert($page, self::BODY_CSS);
    }

    /**
     * Markdown to HTML with the business's HTML shown as text.
     *
     * html_input 'escape', not 'strip': a tag somebody typed reads as what they
     * typed. No autolinker, so a bare address stays text, as it was in plain
     * text; an explicit [link](url) works, javascript: and its kind are dropped.
     */
    public static function markdownHtml(string $markdown): string
    {
        $environment = new Environment([
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
            'max_nesting_level' => 20,
        ]);
        $environment->addExtension(new CommonMarkCoreExtension);
        $environment->addExtension(new TableExtension);
        $environment->addExtension(new StrikethroughExtension);

        return (new MarkdownConverter($environment))->convert($markdown)->getContent();
    }

    /**
     * The Markdown as words, for the text part: headings and bold lose their
     * marks, a link keeps its address in brackets, and the backslashes
     * ActionRunner put in front of a stranger's punctuation come out.
     */
    public static function plain(string $markdown): string
    {
        $text = (string) preg_replace('/^#{1,6}\s+/m', '', $markdown);
        $text = (string) preg_replace('/(\*\*|__)(.+?)\1/s', '$2', $text);
        $text = (string) preg_replace('/\[([^\]]*)\]\(([^)\s]+)\)/', '$1 ($2)', $text);

        return (string) preg_replace('/\\\\([\\\\`*_{}\[\]()#+\-.!|<>~])/', '$1', $text);
    }
}
