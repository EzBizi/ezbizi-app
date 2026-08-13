/// The preview is a picture of a SHEET, and these are the questions that makes.
///
/// The owner's complaint was that it was not one: *"contract view is sort of
/// wrong atm, as u can see it's not using the a4 or letter format set on
/// settings"*. So the tests here are about geometry — the shape of the frame,
/// where the margins fall, and what happens when the document is longer than a
/// sheet — rather than about what the document says, which is covered where the
/// designer is driven end to end in hr_contracts_test.dart.
///
/// The real dimensions are written out here in millimetres and inches rather
/// than read from [PaperSize]. Asking the widget whether it agrees with the
/// same constant it was built from is a test that cannot fail; asking whether
/// it agrees with the sheet somebody buys is a test.
library;

import 'package:ezbizi_client/core/document_html.dart';
import 'package:ezbizi_client/screens/hr/contract_document_page.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  group('the sheet', () {
    testWidgets('has the shape of the paper it prints on, and a different '
        'paper is a different shape', (tester) async {
      await _pump(
        tester,
        _body('One short clause, and no more.'),
        sheet: PaperSize.a4,
      );
      final a4 = tester.getRect(_sheet);

      expect(
        a4.width / a4.height,
        closeTo(210 / 297, 0.005),
        reason:
            'A4 is 210 by 297mm. A frame that is not that shape is not a '
            'picture of the sheet this contract prints on.',
      );

      await _pump(
        tester,
        _body('One short clause, and no more.'),
        sheet: PaperSize.letter,
      );
      final letter = tester.getRect(_sheet);

      expect(
        letter.width / letter.height,
        closeTo(8.5 / 11, 0.005),
        reason: 'US Letter is 8.5 by 11 inches.',
      );

      // The control. Both halves above would also pass against a box of a
      // single fixed shape if the tolerance were loose enough, and neither
      // says the setting was READ. This does: same pane, same words, and the
      // frame is a different height because the paper is different.
      expect(
        a4.width,
        closeTo(letter.width, 0.5),
        reason:
            'Same pane, so the widths are the same and the shape has to '
            'come out of the height.',
      );
      expect(
        a4.height - letter.height,
        greaterThan(60),
        reason:
            'A4 is the taller sheet for a given width. If the two heights '
            'match, the paper size changed nothing and the frame is hardcoded.',
      );
    });

    testWidgets('is never drawn wider than the sheet really is', (
      tester,
    ) async {
      await _pump(
        tester,
        _body('One short clause.'),
        size: const Size(1400, 1200),
        sheet: PaperSize.a4,
      );
      final a4 = tester.getRect(_sheet);

      // 210mm at 96 CSS pixels to the inch — life size, which is what makes
      // the document's own 14px type land where it lands on the printed page.
      expect(a4.width, closeTo(210 / 25.4 * 96, 1));
      expect(
        a4.center.dx,
        closeTo(1400 / 2, 1),
        reason: 'The rest of a wide pane is desk, either side of the sheet.',
      );

      await _pump(
        tester,
        _body('One short clause.'),
        size: const Size(1400, 1200),
        sheet: PaperSize.letter,
      );
      final letter = tester.getRect(_sheet);

      expect(letter.width, closeTo(8.5 * 96, 1));
      expect(
        letter.width,
        greaterThan(a4.width),
        reason:
            'Letter is the wider sheet. At life size the choice shows in '
            'the width as well as the shape.',
      );
    });

    testWidgets('has margins in proportion to itself, not a fixed padding', (
      tester,
    ) async {
      const clause = 'The first line of the agreement.';

      await _pump(tester, _body(clause), size: const Size(760, 1400));
      final wideSheet = tester.getRect(_sheet);
      final wideMargin =
          tester.getRect(find.text(clause)).left - wideSheet.left;

      await _pump(tester, _body(clause), size: const Size(420, 1400));
      final narrowSheet = tester.getRect(_sheet);
      final narrowMargin =
          tester.getRect(find.text(clause)).left - narrowSheet.left;

      expect(
        narrowMargin,
        lessThan(wideMargin - 10),
        reason:
            'A fixed padding would measure the same on both, and the text '
            'block would sit somewhere other than where it prints.',
      );

      // 20mm of a 210mm sheet, at whatever size the sheet is being drawn.
      expect(wideMargin / wideSheet.width, closeTo(20 / 210, 0.005));
      expect(narrowMargin / narrowSheet.width, closeTo(20 / 210, 0.005));
    });
  });

  group('a contract longer than one sheet', () {
    testWidgets('grows the frame rather than cropping, and every clause can '
        'be scrolled to', (tester) async {
      await _pump(tester, _body(_long), size: const Size(760, 700));

      final sheet = tester.getRect(_sheet);

      expect(
        sheet.height,
        greaterThan(sheet.width / (210 / 297) + 1),
        reason:
            'The frame stayed one sheet tall, so everything past the first '
            'page is inside a box the pane cannot reach.',
      );

      // The control: it is genuinely off screen to begin with, so the scroll
      // below is doing the work rather than the assertion passing on a line
      // that was visible all along.
      expect(
        tester.getRect(find.text(_lastClause)).top,
        greaterThan(700),
        reason:
            'The last clause was already on screen, so this proves nothing '
            'about reaching it.',
      );

      await tester.scrollUntilVisible(find.text(_lastClause), 300);
      await tester.pumpAndSettle();

      final reached = tester.getRect(find.text(_lastClause));

      expect(reached.top, greaterThanOrEqualTo(-1));
      expect(
        reached.bottom,
        lessThanOrEqualTo(701),
        reason:
            'The last clause is reachable by scrolling the pane, which is '
            'the only reason it is allowed to start off screen.',
      );
    });
  });

  group('on a phone', () {
    testWidgets('the page lays out at 375 without overflowing it', (
      tester,
    ) async {
      await _pump(tester, _body(_long), size: const Size(375, 800));

      expect(
        tester.takeException(),
        isNull,
        reason:
            'A fixed-aspect box in a flexible pane is exactly the shape '
            'that overflows on a phone (R7).',
      );

      final page = tester.getRect(find.byType(ContractDocumentPage));
      final sheet = tester.getRect(_sheet);

      expect(page.width, lessThanOrEqualTo(375));
      expect(sheet.left, greaterThanOrEqualTo(0));
      expect(sheet.right, lessThanOrEqualTo(375));
      expect(
        sheet.width,
        greaterThan(375 * 0.85),
        reason:
            'A phone gives the width to the page. A wide grey desk around '
            'a thin sheet is a desktop layout on a phone.',
      );
    });
  });

  /// R7. The pane is a phone, half a tab, and half a wider tab, and the same
  /// widget draws all three. Swept together rather than as one width, because
  /// what breaks a fixed-aspect box in a flexible pane is the width nobody
  /// checked.
  testWidgets('lays out at 375, 700 and 1400 without overflowing any of them', (
    tester,
  ) async {
    for (final width in [375.0, 700.0, 1400.0]) {
      await _pump(tester, _body(_long), size: Size(width, 800));

      expect(tester.takeException(), isNull, reason: 'At ${width}px.');

      final sheet = tester.getRect(_sheet);

      expect(sheet.left, greaterThanOrEqualTo(0), reason: 'At ${width}px.');
      expect(sheet.right, lessThanOrEqualTo(width), reason: 'At ${width}px.');
      expect(
        tester.getRect(find.text(_lastClause)).right,
        lessThanOrEqualTo(width),
        reason: 'A clause is printed past the edge of the pane at ${width}px.',
      );
    }
  });

  group('the caption', () {
    testWidgets('names the sheet, on the desk rather than on the page', (
      tester,
    ) async {
      await _pump(tester, _body('One short clause.'), sheet: PaperSize.letter);

      final caption = find.textContaining('US Letter');

      expect(caption, findsOneWidget);
      expect(find.textContaining('8.5 × 11 in'), findsOneWidget);
      expect(
        tester.getRect(caption).bottom,
        lessThanOrEqualTo(tester.getRect(_sheet).top),
        reason:
            'Which paper it is printed on is not a term of the agreement, '
            'so it does not belong inside the page — the same reason the '
            'version number and the template description come out of it.',
      );
    });

    testWidgets('says so when nothing has told it which sheet to use', (
      tester,
    ) async {
      await _pump(tester, _body('One short clause.'));

      expect(
        find.textContaining('cannot read the contract paper size'),
        findsOneWidget,
        reason:
            'The setting does not reach this screen yet. Drawing A4 and '
            'saying nothing is a preview that looks like it read the setting.',
      );

      final guessed = tester.getRect(_sheet);

      expect(
        guessed.width / guessed.height,
        closeTo(210 / 297, 0.005),
        reason:
            'A4 is the registry default for contract_pdf_paper, so an '
            'organization that has chosen nothing sees what it would get.',
      );

      // And it goes quiet the moment somebody passes a real value.
      await _pump(tester, _body('One short clause.'), sheet: PaperSize.a4);

      expect(find.textContaining('cannot read'), findsNothing);
      expect(find.textContaining('A4'), findsOneWidget);
    });
  });
}

// ── driving it ─────────────────────────────────────────────────────

/// The page in the pane it actually lives in.
///
/// A [SingleChildScrollView] because that is what ContractPagePane wraps it in,
/// and the whole pagination decision turns on there being exactly one scroller
/// in this column. Mounting it unscrolled would test a widget nothing renders.
Future<void> _pump(
  WidgetTester tester,
  List<DocumentBlock> blocks, {
  PaperSize? sheet,
  Size size = const Size(760, 1400),
}) async {
  tester.view.physicalSize = size;
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  await tester.pumpWidget(
    MaterialApp(
      home: Scaffold(
        body: SingleChildScrollView(
          child: ContractDocumentPage(
            blocks: blocks,
            tagNote: 'Nothing is filled in yet.',
            sheet: sheet,
          ),
        ),
      ),
    ),
  );
  await tester.pumpAndSettle();
}

/// The paper itself — the white, not the desk around it.
final Finder _sheet = find.descendant(
  of: find.byType(ContractDocumentPage),
  matching: find.byWidgetPredicate(
    (widget) =>
        widget is ColoredBox && widget.color == ContractDocumentPage.paper,
  ),
);

/// Blocks the way the editor makes them, rather than built by hand: a draft
/// somebody is typing, read by the reader the pane uses.
List<DocumentBlock> _body(String text) =>
    readDraftText(text, TagDelimiters.none);

const String _lastClause = 'Clause forty, the last one.';

final String _long = [
  for (var n = 1; n < 40; n++) 'Clause $n, which runs on for a line or two.',
  _lastClause,
].join('\n\n');
