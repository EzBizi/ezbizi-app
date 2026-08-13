import 'dart:math' as math;

import 'package:flutter/material.dart';

import '../../core/document_html.dart';

/// A sheet of paper, in the units it is actually sold in.
///
/// ── Why the real dimensions and not a ratio ──────────────────────
///
/// Everything the preview needs is derived here from millimetres, because
/// millimetres are what the sheet HAS. A ratio alone would draw the right shape
/// and then need a second, unrelated number for the margin and a third for how
/// big the sheet is at 1:1 — three constants that can disagree with each other.
/// From the dimensions they cannot: [aspectRatio], [marginFraction] and
/// [naturalWidth] are three views of one fact.
///
/// ── These two, and the margins on them ───────────────────────────
///
/// The same two the organization can choose between: `contract_pdf_paper` in
/// SettingRegistry offers `a4` and `letter` and nothing else, and its own note
/// says why — they are the two the world prints contracts on.
///
/// The margins are the ordinary ones for each sheet rather than a measurement
/// taken from the PDF, and they are close to the stored document's own measure
/// rather than a coincidence: 20mm off A4 leaves 170mm of text, which is 643 CSS
/// pixels, and 1in off Letter leaves 6.5in, which is 624. DocumentRenderer's
/// stylesheet sets `max-width:44em` at 14px — 616 pixels. So a line of this
/// preview breaks at very nearly the width it breaks at in the document, which
/// is the whole point of drawing margins instead of padding.
enum PaperSize {
  /// What `contract_pdf_paper` calls `a4`, and the registry's own default.
  a4('A4', '210 × 297 mm', 210.0, 297.0, 20.0),

  /// What `contract_pdf_paper` calls `letter`. 8.5 × 11in, the US sheet.
  letter('US Letter', '8.5 × 11 in', 215.9, 279.4, 25.4);

  const PaperSize(
    this.label,
    this.measurements,
    this._widthMm,
    this._heightMm,
    this._marginMm,
  );

  /// What to call it out loud.
  final String label;

  /// How big it is, written the way somebody buying it would write it — so
  /// Letter is in inches and A4 is not.
  final String measurements;

  final double _widthMm;
  final double _heightMm;
  final double _marginMm;

  /// The shape, width over height.
  ///
  /// 0.707 against 0.773 — about a nine per cent difference, which is visible
  /// side by side and is the thing the owner said was missing.
  double get aspectRatio => _widthMm / _heightMm;

  /// The print margin as a fraction of the sheet's WIDTH.
  ///
  /// A fraction, so it stays a margin when the sheet is drawn smaller than life.
  /// A fixed pixel padding is what the preview had, and it is why a narrow pane
  /// showed a page with almost no edge and a wide one showed a stripe of text.
  double get marginFraction => _marginMm / _widthMm;

  /// How wide this sheet is in CSS pixels — the width it prints at, 1:1.
  ///
  /// 96 to the inch because that is the CSS reference pixel, and CSS pixels are
  /// the unit the stored document is written in: DocumentRenderer's stylesheet
  /// says `font:14px` and `max-width:44em`, and nothing on either side sets a
  /// different resolution. So a sheet drawn at this width, carrying that same
  /// 14px type, is life size — the preview is not a diagram of the page.
  ///
  /// It is not a claim about the PDF's own conversion. dompdf is not on this
  /// side of the wire and its options are not readable from here; what is
  /// checkable is the unit the document declares, and that is what this uses.
  double get naturalWidth => _widthMm / 25.4 * 96;

  /// What to draw on when nothing told this screen which sheet to use.
  ///
  /// A4 because that is `contract_pdf_paper`'s own default in SettingRegistry,
  /// so a preview that has not been told agrees with the PDF an organization
  /// that has not chosen would get. It is one named constant and the page SAYS
  /// it is guessing — see the caption in [ContractDocumentPage] — because a
  /// preview quietly drawing the wrong sheet is the bug being fixed here.
  static const PaperSize standard = a4;
}

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
///
///  The desk the sheet lies on is a literal too, and it is the one colour here
///  that is not part of the printed page. It exists because white paper on a
///  white background has no edges, and edges are the entire point of this
///  change: a sheet you cannot see the outline of has no shape to compare. It
///  does not follow the theme either, because the pane painting behind this
///  widget paints it in [paper] — a themed desk would show as a band of dark
///  ending in white wherever the document stops.
/// ══════════════════════════════════════════════════════════════════
///
/// ── The sheet, and what it does about being longer than one ──────
///
/// The frame is one sheet at its smallest and grows downward with the document.
/// It draws no page breaks, and both halves of that are decided rather than
/// left over:
///
/// It does not scroll INSIDE a fixed sheet. The pane this sits in already
/// scrolls, so a second scrolling box would put two vertical scrollers in one
/// column — on a phone the inner one eats the gesture and the pane underneath
/// cannot be moved past it, and this app has shipped that bug.
///
/// It does not draw page breaks either, and that is the harder call. Drawing
/// one means claiming to know where the PDF breaks, and this end cannot: the
/// copy is laid out by dompdf with its own font metrics and its own rules about
/// stranded lines, and below life size the type here is deliberately bigger
/// than print so it stays readable, which changes how much of a sheet a
/// paragraph fills. A break drawn in the wrong place is a worse answer than no
/// break at all, and nobody asked how many pages it is.
///
/// What is lost is that a long contract stops showing the sheet's SHAPE, since
/// the frame is by then taller than a sheet. What is not lost is everything the
/// shape was for: the width is the sheet's, the margins are the sheet's, the
/// type is the size it prints at wherever the pane is wide enough to draw the
/// sheet life size, and the caption says which sheet it is.
class ContractDocumentPage extends StatelessWidget {
  const ContractDocumentPage({
    super.key,
    required this.blocks,
    required this.tagNote,
    this.placeholder,
    this.sheet,
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

  /// Which sheet this contract prints on — the organization's own choice.
  ///
  /// ── Null is a state, not a default ───────────────────────────────
  ///
  /// The choice is `contract_pdf_paper` in SettingRegistry, it cascades like
  /// every other setting, and PdfRenderer already reads it for the copy. It
  /// does NOT reach the client: /me carries `DisplaySettings::KEYS`, which is
  /// `date_format` and `time_format` and deliberately nothing else, and the
  /// full settings endpoint is the settings screen's, behind a permission an
  /// HR user designing a contract need not hold.
  ///
  /// So this is nullable and nobody passes it yet. Null draws
  /// [PaperSize.standard] AND captions the page to say the size was not read,
  /// which is the difference between a default and a lie. The day the value
  /// reaches this screen, whoever wires it passes it here and the caption goes
  /// quiet on its own.
  final PaperSize? sheet;

  /// Ink, and the paper it sits on. DocumentRenderer's stylesheet, in Dart.
  static const Color paper = Color(0xFFFFFFFF);
  static const Color _ink = Color(0xFF111111);
  static const Color _muted = Color(0xFF555555);
  static const Color _rule = Color(0xFFD8D8D8);

  /// The desk, its lettering, and the shadow that lifts the sheet off it.
  ///
  /// Not the document — see the note on the class for why they are literals
  /// like the rest and why they do not follow the theme.
  static const Color _desk = Color(0xFFE7EAEE);
  static const Color _deskInk = Color(0xFF5B6672);
  static const Color _shadow = Color(0x1F000000);

  /// The gap between the sheet and the edge of the pane.
  ///
  /// Chrome rather than paper, so it is measured in pixels and not in
  /// millimetres. A fraction of the pane with a floor and a ceiling: at 375 it
  /// works out at thirteen pixels, which is enough to see that the sheet ends
  /// and little enough that the sheet still gets the width; on a wide pane it
  /// stops growing, because a page adrift in a field of grey is not tidier.
  /// The floor is what a pane dragged nearly shut falls back to.
  static const double _deskGapFraction = 0.035;
  static const double _deskGapMin = 10;
  static const double _deskGapMax = 28;

  /// The narrowest sheet that is still a width rather than an error.
  ///
  /// The sheet takes the pane less the gap either side, so a pane narrower than
  /// two gaps would ask for a negative width. It is floored here and NOT at
  /// some readable minimum, because a sheet wider than the pane it sits in
  /// overflows sideways — and the pane is a draggable split that can be pulled
  /// shut, and a phone in landscape is narrower than anything this was designed
  /// against. Unreadable is recoverable; overflowing is the bug R7 exists for.
  static const double _sheetMinWidth = 1;

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
        if (block.kind == DocumentBlockKind.rule ||
            block.text.trim().isNotEmpty)
          block,
    ];

    final size = sheet ?? PaperSize.standard;

    // LayoutBuilder rather than a fixed size, because this pane is half of a
    // tab the owner drags, and the whole width of a phone. Everything below is
    // derived from the width it is actually given.
    return LayoutBuilder(
      builder: (context, constraints) {
        final gap = math.min(
          math.max(constraints.maxWidth * _deskGapFraction, _deskGapMin),
          _deskGapMax,
        );

        // Never wider than life size. A sheet stretched across a 1400px pane
        // would put a hundred and fifty characters on a line — further from the
        // printed page than the flowing web page this replaces, not closer.
        // Below life size it shrinks with the pane and the type does not, so a
        // phone shows the shape and the margins at a size somebody can read.
        final width = math.min(
          math.max(constraints.maxWidth - gap * 2, _sheetMinWidth),
          size.naturalWidth,
        );

        final margin = width * size.marginFraction;

        return DecoratedBox(
          decoration: const BoxDecoration(color: _desk),
          child: Padding(
            padding: EdgeInsets.all(gap),
            // The Column is only as wide as the sheet, so centring INSIDE it
            // centres nothing. This is what puts the sheet in the middle of a
            // pane wider than life size, with desk either side of it.
            child: Center(
              child: Column(
                children: [
                  _caption(size),
                  SizedBox(height: gap),
                  SizedBox(
                    width: width,
                    child: DecoratedBox(
                      decoration: const BoxDecoration(
                        boxShadow: [
                          BoxShadow(
                            color: _shadow,
                            blurRadius: 12,
                            offset: Offset(0, 3),
                          ),
                        ],
                      ),
                      child: ColoredBox(
                        color: paper,
                        child: ConstrainedBox(
                          // One whole sheet, at least. An empty template and a
                          // one-clause contract are drawn at exactly the shape
                          // they print on, which is what makes the choice
                          // between the two sheets visible.
                          constraints: BoxConstraints(
                            minHeight: width / size.aspectRatio,
                          ),
                          child: Padding(
                            padding: EdgeInsets.all(margin),
                            // One selection across the whole page, rather than
                            // each paragraph being its own island: somebody
                            // drafting a contract will want to copy a clause
                            // out of it, and a preview you can only look at
                            // makes them retype it. It is also what lets the
                            // blocks be `Text.rich` — SelectableText cannot
                            // carry the WidgetSpan a marked tag is drawn as.
                            child: SelectionArea(
                              child: Column(
                                crossAxisAlignment: CrossAxisAlignment.start,
                                children: drawn.isEmpty
                                    ? [_empty(context)]
                                    : [
                                        for (final block in drawn)
                                          _block(block),
                                      ],
                              ),
                            ),
                          ),
                        ),
                      ),
                    ),
                  ),
                ],
              ),
            ),
          ),
        );
      },
    );
  }

  /// Which sheet this is, written on the desk rather than on the page.
  ///
  /// On the desk because it is not part of the agreement — the same reason the
  /// version line and the template's description come out of the document
  /// itself. It is here at all because the two sheets are only nine per cent
  /// apart, and somebody who has just changed the setting wants to be told, not
  /// to measure.
  ///
  /// The second sentence appears only while nothing has told this screen which
  /// sheet to use. It names the setting so the answer is actionable, and it
  /// disappears the moment a real value is passed — see [sheet].
  Widget _caption(PaperSize size) => Text(
    sheet == null
        ? '${size.label} · ${size.measurements}. This preview cannot read the '
              'contract paper size from Settings yet, so it draws the standard '
              'sheet.'
        : '${size.label} · ${size.measurements}',
    textAlign: TextAlign.center,
    // No fontFamily: the document is set in a serif and this is not the
    // document. It reads as a label because it does not match the page.
    style: const TextStyle(fontSize: 11, height: 1.4, color: _deskInk),
  );

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
