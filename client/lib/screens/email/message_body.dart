import 'package:flutter/material.dart';

import 'message_blocks.dart';

/// The message, drawn from blocks.
///
/// There is no HTML anywhere in this widget and there is no path that could
/// introduce one: it takes [MailBlock]s and returns Text, Divider and a note
/// where a picture was refused. message_blocks.dart says why at length.
///
/// ── It follows the theme, and the contract page does not ────────
///
/// ContractDocumentPage is white with black ink whatever theme is running,
/// because it is a picture of a printed page. This is the opposite: nobody
/// prints an email, and a shared inbox is somewhere people sit all day. It
/// reads its colours from the theme like every other surface in the app, so an
/// organization that runs dark (§12) gets a dark message rather than a sheet of
/// white in the middle of their screen.
class MessageBody extends StatelessWidget {
  const MessageBody({super.key, required this.blocks});

  final List<MailBlock> blocks;

  @override
  Widget build(BuildContext context) {
    if (blocks.isEmpty) {
      return Text(
        // Said rather than left blank. An empty pane after tapping a message
        // reads as a screen that failed, and the two cases it actually is —
        // a message that is only an attachment, and one whose markup came
        // through with no text in it — are both worth knowing.
        'There is nothing in this message to show as text. It may be only an '
        'attachment, or a picture.',
        style: Theme.of(context).textTheme.bodyMedium,
      );
    }

    return SelectionArea(
      // Selectable because the address under a link is only useful if it can be
      // copied, and because half of answering a customer is quoting them back.
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [for (final block in blocks) _block(context, block)],
      ),
    );
  }

  Widget _block(BuildContext context, MailBlock block) {
    return switch (block.kind) {
      MailBlockKind.rule => const Padding(
        padding: EdgeInsets.symmetric(vertical: 16),
        child: Divider(height: 1),
      ),
      MailBlockKind.blockedImage => Padding(
        padding: const EdgeInsets.only(top: 12),
        child: _BlockedImage(alt: block.alt, host: block.host),
      ),
      MailBlockKind.quote => Padding(
        padding: const EdgeInsets.only(top: 12),
        child: _Quote(spans: block.spans),
      ),
      MailBlockKind.heading => Padding(
        padding: const EdgeInsets.only(top: 18),
        child: _text(context, block, Theme.of(context).textTheme.titleMedium),
      ),
      MailBlockKind.paragraph => Padding(
        padding: const EdgeInsets.only(top: 10),
        child: _text(context, block, _prose(context)),
      ),
    };
  }

  /// Body text at a size somebody can read a paragraph in.
  ///
  /// `bodyMedium` is the app's muted note style — right for a caption under a
  /// control, wrong for the thing a person came to the screen to read, which
  /// wants the full-contrast ink.
  static TextStyle _prose(BuildContext context) =>
      Theme.of(context).textTheme.bodyMedium!.copyWith(
        fontSize: 15,
        height: 1.55,
        color: Theme.of(context).colorScheme.onSurface,
      );

  Widget _text(BuildContext context, MailBlock block, TextStyle? style) =>
      Text.rich(
        TextSpan(
          children: [
            for (final span in block.spans) ..._span(context, span),
          ],
        ),
        style: style,
      );

  /// One run, and for a link the address beside it.
  ///
  /// ── Why a link here is not tappable ─────────────────────────────
  ///
  /// This module exists to put strangers' mail in front of staff, and a link in
  /// a stranger's mail is how phishing arrives. "Click here" over an address
  /// nobody can see is exactly the shape of that attack, so the address is
  /// drawn beside the words and the words do not open anything.
  ///
  /// It is not a dead end: the body is selectable, so a legitimate link can be
  /// copied and opened deliberately. That extra second is the point.
  static List<InlineSpan> _span(BuildContext context, MailSpan span) {
    final style = TextStyle(
      fontWeight: span.strong ? FontWeight.w700 : null,
      fontStyle: span.emphasis ? FontStyle.italic : null,
    );

    if (span.link == null) return [TextSpan(text: span.text, style: style)];

    final scheme = Theme.of(context).colorScheme;

    return [
      TextSpan(
        text: span.text,
        style: style.copyWith(
          color: scheme.primary,
          decoration: TextDecoration.underline,
          decorationColor: scheme.primary,
        ),
      ),
      TextSpan(
        text: ' ⟨${span.link}⟩',
        style: TextStyle(
          fontSize: 13,
          color: Theme.of(context).textTheme.bodyMedium?.color,
        ),
      ),
    ];
  }
}

/// The message being replied to, inside the reply.
class _Quote extends StatelessWidget {
  const _Quote({required this.spans});

  final List<MailSpan> spans;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;

    return Container(
      padding: const EdgeInsets.fromLTRB(14, 4, 0, 4),
      decoration: BoxDecoration(
        // A rule down the side, which is what a quoted message looks like
        // everywhere else and needs no legend.
        border: Border(left: BorderSide(color: scheme.outlineVariant, width: 3)),
      ),
      child: Text.rich(
        TextSpan(
          children: [
            for (final span in spans) ...MessageBody._span(context, span),
          ],
        ),
        style: Theme.of(context).textTheme.bodyMedium?.copyWith(height: 1.5),
      ),
    );
  }
}

/// Where a picture was, and why it is not there.
///
/// ── Two reasons, and only one of them is privacy ────────────────
///
/// A remote image in an email is a tracking pixel: fetching it tells whoever
/// sent the message that it was opened, by an address that receives customer
/// mail, at a time. That is the reason most people know.
///
/// The other is that the fetch would be made BY OUR SERVER, to a host a
/// stranger chose, which is server-side request forgery — the same class of
/// problem as a tenant-supplied IMAP host, and the reason the sanitiser strips
/// the image rather than proxying it.
///
/// So there is no "load images" control here, and there is not going to be one.
/// A button offering to fetch would be offering to do the thing the design
/// refuses; this says what happened and stops.
class _BlockedImage extends StatelessWidget {
  const _BlockedImage({required this.alt, required this.host});

  final String? alt;
  final String? host;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final alt = this.alt;
    final host = this.host;

    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
      decoration: BoxDecoration(
        color: scheme.surfaceContainerHighest.withValues(alpha: 0.4),
        borderRadius: BorderRadius.circular(10),
        border: Border.all(color: scheme.outlineVariant),
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Icon(Icons.image_not_supported_outlined, size: 18, color: scheme.outline),
          const SizedBox(width: 10),
          Expanded(
            child: Text(
              // One sentence, in place, saying what was there and why it is not
              // being fetched. The description the sender wrote is included
              // where there is one, because "a picture called Quote summary"
              // is often the whole of what was lost.
              [
                alt == null
                    ? 'A picture here was not loaded.'
                    : 'A picture here was not loaded — “$alt”.',
                host == null
                    ? 'Fetching it would tell whoever sent this that the '
                          'message was opened.'
                    : 'It sits on $host, and fetching it would tell whoever '
                          'sent this that the message was opened.',
              ].join(' '),
              style: Theme.of(context).textTheme.bodyMedium,
            ),
          ),
        ],
      ),
    );
  }
}
