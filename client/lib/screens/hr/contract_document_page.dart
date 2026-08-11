import 'package:flutter/material.dart';

import '../../core/document_html.dart';

/// The contract, drawn as a page.
///
/// ══════════════════════════════════════════════════════════════════
///  THE ONE SURFACE IN THIS APP THAT DOES NOT FOLLOW THE THEME.
///
///  Everything else reads its colours from `Theme.of(context)`, because an
///  organization defines its own (§12) and the app has to look like theirs.
///  This does not, and the reason is the owner's:
///
///      "50% showing the actual contract, and how it looks with white
///       background as that's the color of pages"
///
///  It is not a panel showing information about a contract. It is a picture of
///  the printed page, and the page is white with black ink whatever theme the
///  person editing it happens to be running — the same white the finished
///  document is served on, since DocumentRenderer's own stylesheet sets
///  `color:#111` on a default white body and nothing about it is themed either.
///
///  A dark-mode preview would be a preview of a document that does not exist.
///  So the handful of colours below are literals, they are literals ON PURPOSE,
///  and this comment is the reason they are not a lint waiting to be "fixed".
///  Everything AROUND this widget — the pane, the toolbar, the notes — follows
///  the theme normally.
/// ══════════════════════════════════════════════════════════════════
class ContractDocumentPage extends StatelessWidget {
  const ContractDocumentPage({
    super.key,
    required this.blocks,
    required this.tagNote,
    this.placeholder,
  });

  /// The document, as [readDocumentHtml] or [readDraftText] read it.
  final List<DocumentBlock> blocks;

  /// What a merge tag still sitting in the text MEANS here.
  ///
  /// Passed in rather than decided here, because it is two different facts
  /// wearing one appearance: in a document the server rendered, a surviving
  /// `{{tag}}` is one it could not resolve; in a draft of a contract that does
  /// not exist yet, nothing has been merged at all. Drawing them the same and
  /// captioning them the same would tell somebody their contract is broken when
  /// it is merely unsaved.
  final String tagNote;

  /// What to say when there is no document — nothing typed yet, usually.
  final String? placeholder;

  /// Ink, and the paper it sits on. DocumentRenderer's stylesheet, in Dart.
  static const Color paper = Color(0xFFFFFFFF);
  static const Color _ink = Color(0xFF111111);
  static const Color _muted = Color(0xFF555555);
  static const Color _rule = Color(0xFFD8D8D8);

  /// A tag that is still a tag, marked so it cannot be mistaken for prose.
  ///
  /// Amber rather than red: at the moment somebody is typing, an unfilled tag
  /// is normal — they are two keystrokes from finishing it — and red says
  /// "broken". It is a highlighter over a word, which is what it is. No glow,
  /// no gradient, nothing that would read as decoration (R14).
  static const Color _markInk = Color(0xFF8A5300);
  static const Color _markPaper = Color(0xFFFFF2D6);

  /// The document's own face.
  ///
  /// A serif, because the stored document is set in one and this is a picture
  /// of that. Named families with a fallback chain: where Georgia is not
  /// installed the platform's own serif answers, and where nothing does Flutter
  /// falls back to the app font — which is a slightly wrong-looking page rather
  /// than an empty one.
  static const String _face = 'Georgia';
  static const List<String> _faceFallback = ['Times New Roman', 'serif'];

  @override
  Widget build(BuildContext context) {
    final drawn = [
      for (final block in blocks)
        if (block.kind == DocumentBlockKind.rule || block.text.trim().isNotEmpty)
          block,
    ];

    return ColoredBox(
      color: paper,
      child: SizedBox(
        width: double.infinity,
        child: Padding(
          // The margin a page has. Generous, because a wall of text with no
          // edge does not read as a document.
          padding: const EdgeInsets.fromLTRB(28, 30, 28, 40),
          child: Align(
            alignment: Alignment.topCenter,
            child: ConstrainedBox(
              // A measure, not a width: ~44em is what the stored document uses
              // and roughly what a line of prose can be read across.
              constraints: const BoxConstraints(maxWidth: 620),
              // One selection across the whole page, rather than each
              // paragraph being its own island: somebody drafting a contract
              // will want to copy a clause out of it, and a preview you can
              // only look at makes them retype it. It is also what lets the
              // blocks be `Text.rich` — SelectableText cannot carry the
              // WidgetSpan a marked tag is drawn as.
              child: SelectionArea(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: drawn.isEmpty
                      ? [_empty(context)]
                      : [for (final block in drawn) _block(block)],
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }

  Widget _empty(BuildContext context) => Text(
    placeholder ??
        'Nothing written yet. What you type on the left appears here, as the '
            'page it will be.',
    style: const TextStyle(
      fontFamily: _face,
      fontFamilyFallback: _faceFallback,
      fontSize: 14,
      height: 1.6,
      color: _muted,
      fontStyle: FontStyle.italic,
    ),
  );

  Widget _block(DocumentBlock block) {
    if (block.kind == DocumentBlockKind.rule) {
      return const Padding(
        padding: EdgeInsets.symmetric(vertical: 18),
        child: Divider(height: 1, thickness: 1, color: _rule),
      );
    }

    return Padding(
      padding: EdgeInsets.only(
        top: switch (block.kind) {
          DocumentBlockKind.title => 0,
          DocumentBlockKind.heading => 22,
          DocumentBlockKind.term => 12,
          DocumentBlockKind.definition => 2,
          _ => 14,
        },
      ),
      child: Text.rich(
        TextSpan(
          children: [for (final span in block.spans) _span(block.kind, span)],
        ),
        style: _styleOf(block.kind),
      ),
    );
  }

  TextStyle _styleOf(DocumentBlockKind kind) => switch (kind) {
    DocumentBlockKind.title => const TextStyle(
      fontFamily: _face,
      fontFamilyFallback: _faceFallback,
      fontSize: 23,
      height: 1.25,
      fontWeight: FontWeight.w700,
      color: _ink,
    ),
    DocumentBlockKind.heading => const TextStyle(
      fontFamily: _face,
      fontFamilyFallback: _faceFallback,
      fontSize: 16,
      height: 1.35,
      fontWeight: FontWeight.w700,
      color: _ink,
    ),
    DocumentBlockKind.term => const TextStyle(
      fontFamily: _face,
      fontFamilyFallback: _faceFallback,
      fontSize: 11,
      height: 1.4,
      letterSpacing: 0.9,
      fontWeight: FontWeight.w700,
      color: _muted,
    ),
    _ => const TextStyle(
      fontFamily: _face,
      fontFamilyFallback: _faceFallback,
      fontSize: 14,
      height: 1.65,
      color: _ink,
    ),
  };

  InlineSpan _span(DocumentBlockKind kind, DocumentSpan span) {
    final text = kind == DocumentBlockKind.term
        ? span.text.toUpperCase()
        : span.text;

    if (!span.tag) {
      return TextSpan(
        text: text,
        style: TextStyle(
          fontWeight: span.strong ? FontWeight.w700 : null,
          fontStyle: span.emphasis ? FontStyle.italic : null,
        ),
      );
    }

    // The tag itself, highlighted and hoverable. Drawn IN the document rather
    // than only listed underneath it, because the question somebody has is
    // "where in my contract is the thing that did not fill in".
    return WidgetSpan(
      alignment: PlaceholderAlignment.middle,
      child: Tooltip(
        message: tagNote,
        child: DecoratedBox(
          decoration: BoxDecoration(
            color: _markPaper,
            borderRadius: BorderRadius.circular(3),
            border: const Border(
              bottom: BorderSide(color: _markInk, width: 1.5),
            ),
          ),
          child: Padding(
            padding: const EdgeInsets.symmetric(horizontal: 3, vertical: 1),
            child: Text(
              text,
              style: const TextStyle(
                fontFamily: _face,
                fontFamilyFallback: _faceFallback,
                fontSize: 13,
                color: _markInk,
                fontWeight: FontWeight.w600,
              ),
            ),
          ),
        ),
      ),
    );
  }
}
