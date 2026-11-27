import 'dart:convert';
import 'dart:math' as math;
import 'dart:typed_data';

import 'package:flutter/material.dart';

import '../../core/api_client.dart';
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
  const MessageBody({
    super.key,
    required this.blocks,
    this.api,
    this.picturePath,
  });

  final List<MailBlock> blocks;
  final ApiClient? api;
  final String? picturePath;

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
      MailBlockKind.image => Padding(
        padding: EdgeInsets.only(top: (block.width ?? 99) <= 48 ? 4 : 12),
        child: _MailPicture(
          alt: block.alt,
          src: block.src,
          width: block.width,
          height: block.height,
          api: api,
          picturePath: picturePath,
        ),
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
    // The site, not the whole address: tracking URLs ran to ten lines and
    // buried the message (Jose, 11-04). The host is what tells a lookalike.
    final host = Uri.tryParse(span.link!)?.host.replaceFirst(RegExp(r'^www\.'), '');
    final site = host == null || host.isEmpty ? span.link! : host;
    // A link around nothing (a spacer or a picture) has no words to explain.
    if (span.text.trim().isEmpty) return const [];

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
        text: ' ⟨$site⟩',
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

    // Indented and MUTED, not ruled.
    //
    // The rule down the side is out (Jose, 2026-09-08). It was the only thing
    // marking this as quoted, so it could not simply be deleted: indent alone
    // is 14px, which reads as a paragraph rather than as somebody else's words.
    // The muted foreground carries it instead — the same role the app uses
    // everywhere for text that is context rather than content.
    return Container(
      padding: const EdgeInsets.fromLTRB(14, 4, 0, 4),
      child: Text.rich(
        TextSpan(
          children: [
            for (final span in spans) ...MessageBody._span(context, span),
          ],
        ),
        style: Theme.of(context).textTheme.bodyMedium?.copyWith(
          height: 1.5,
          color: scheme.onSurfaceVariant,
        ),
      ),
    );
  }
}

/// A picture from the message, fetched through our origin.
class _MailPicture extends StatefulWidget {
  const _MailPicture({
    required this.alt,
    required this.src,
    required this.width,
    required this.height,
    required this.api,
    required this.picturePath,
  });

  final String? alt;
  final String? src;
  final int? width;
  final int? height;
  final ApiClient? api;
  final String? picturePath;

  @override
  State<_MailPicture> createState() => _MailPictureState();
}

class _MailPictureState extends State<_MailPicture> {
  Uint8List? _bytes;
  bool _failed = false;

  @override
  void initState() {
    super.initState();
    final src = widget.src;
    if (src != null && src.startsWith('data:image/')) {
      _bytes = _decodeDataUri(src);
      _failed = _bytes == null;
      return;
    }
    _load();
  }

  Uint8List? _decodeDataUri(String src) {
    final comma = src.indexOf(',');
    if (comma < 0) return null;
    try {
      return Uint8List.fromList(base64Decode(src.substring(comma + 1)));
    } catch (_) {
      return null;
    }
  }

  @override
  void didUpdateWidget(_MailPicture old) {
    super.didUpdateWidget(old);
    if (old.src != widget.src || old.picturePath != widget.picturePath) {
      _load();
    }
  }

  Future<void> _load() async {
    final src = widget.src;
    if (src == null || src.isEmpty) {
      setState(() => _failed = true);
      return;
    }

    if (src.startsWith('data:image/')) {
      final bytes = _decodeDataUri(src);
      setState(() {
        _bytes = bytes;
        _failed = bytes == null;
      });
      return;
    }

    final api = widget.api;
    final path = widget.picturePath;
    if (api == null || path == null) {
      setState(() => _failed = true);
      return;
    }

    final bytes = await api.getBytes(
      '$path?src=${Uri.encodeQueryComponent(src)}',
    );
    if (!mounted) return;
    setState(() {
      _bytes = bytes == null ? null : Uint8List.fromList(bytes);
      // EMPTY is a failure, not a message still arriving.
      //
      // This read `_failed = bytes == null`, and `build` draws the spinner
      // whenever there are no bytes and nothing has failed. A 200 carrying
      // zero bytes satisfies both -- not null, so not failed; empty, so not
      // drawn -- and the picture spins for ever in the middle of the message.
      // Nothing times out and nothing is logged.
      _failed = bytes == null || bytes.isEmpty;
    });
  }

  @override
  Widget build(BuildContext context) {
    final bytes = _bytes;
    if (bytes != null && bytes.isNotEmpty) {
      // Gmail draws at the HTML width, capped at the pane — never at the
      // file's pixel size. A 1200px retina logo with width="600" must
      // not fill a 500px column.
      return LayoutBuilder(
        builder: (context, constraints) {
          final maxW = constraints.maxWidth.isFinite
              ? constraints.maxWidth
              : 600.0;
          final asked = widget.width?.toDouble();
          final w = asked == null ? maxW : math.min(asked, maxW);
          final h = (asked != null &&
                  widget.height != null &&
                  asked > 0)
              ? widget.height! * (w / asked)
              : null;

          return Image.memory(
            bytes,
            width: w,
            height: h,
            fit: BoxFit.scaleDown,
            alignment: Alignment.centerLeft,
            filterQuality: FilterQuality.medium,
            semanticLabel: widget.alt,
            // Bytes that are not a picture must not throw out of the message.
            //
            // `Image.memory` had no errorBuilder, so anything that is not a
            // decodable image -- a truncated response, an expired CDN object
            // answering with an HTML error page, a tracking pixel that is not
            // really a PNG -- threw `Exception: Invalid image data` out of the
            // image resource service. A message with seventeen pictures threw
            // it seventeen times.
            //
            // The bytes arrive from a STRANGER'S MAIL over a URL the sender
            // chose, so "it decodes" is the assumption this widget has least
            // right to make. Said in place instead, with the same notice used
            // when a picture is refused outright.
            errorBuilder: (context, error, stack) =>
                _BlockedImage(alt: widget.alt, host: null),
          );
        },
      );
    }

    if (_failed) {
      return _BlockedImage(alt: widget.alt, host: null);
    }

    return const SizedBox(
      height: 48,
      child: Center(child: SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2))),
    );
  }
}

/// Where a picture was, and it could not be retrieved.
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
                    ? 'A picture here could not be shown.'
                    : 'A picture here could not be shown — “$alt”.',
                if (host != null) 'It sits on $host.',
              ].join(' '),
              style: Theme.of(context).textTheme.bodyMedium,
            ),
          ),
        ],
      ),
    );
  }
}
