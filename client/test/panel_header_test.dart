import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/widgets/async_body.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

/// The heading every screen in the app uses.
///
/// ── Why this has its own test file now ───────────────────────────
///
/// It has been the cause of the same bug twice, and the second time was a
/// FIX for the first one that only covered part of the range.
///
/// Originally it was a plain `Row` of the text and the action. A button keeps
/// its intrinsic width in a Row and `Expanded` takes the remainder, so at
/// 375px the paragraph was laid out into a column a few characters wide:
/// measured at 1270px tall inside a 900px window, overflowing by 370 and
/// taking the screen's list with it.
///
/// The fix stacked the action underneath below 620px, and held — until a
/// screen passed a `Wrap` of three controls as its action. A NON-FLEX child of
/// a Row is given unbounded width, so the Wrap never wrapped: it laid out at
/// its full intrinsic 571px and squeezed the note to 64px, 787px tall inside a
/// 600px window. Same failure, a band higher up, and invisible because every
/// screen with a single button was fine.
///
/// So the property is asserted across the whole range rather than at the one
/// width somebody happened to look at, and with the WIDEST action any screen
/// passes today. A breakpoint that is right for a button and wrong for three
/// controls is not a breakpoint, it is a coincidence.
void main() {
  // The real thing: what the Customers screen passes. Three controls that a
  // Row cannot fit beside a paragraph on a laptop.
  Widget threeControls() => Wrap(
    spacing: 8,
    runSpacing: 8,
    children: [
      OutlinedButton(onPressed: () {}, child: const Text('Import from a file')),
      OutlinedButton(onPressed: () {}, child: const Text('Export everything')),
      FilledButton(onPressed: () {}, child: const Text('New customer')),
    ],
  );

  Future<void> pump(WidgetTester tester, Size size, Widget? action) async {
    tester.view.physicalSize = size;
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);

    await tester.pumpWidget(
      MaterialApp(
        theme: EzBiziTheme.light(),
        home: Scaffold(
          // Column + Expanded is the shape every screen uses it in, and it is
          // the shape that turns an over-tall header into an overflow rather
          // than into a scroll.
          body: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              PanelHeader(
                title: 'Customers',
                note:
                    'Everybody you work for. A customer belongs to the '
                    'organization rather than to one branch, so anybody who '
                    'can see this list can see all of it.',
                action: action,
              ),
              const Expanded(child: SizedBox()),
            ],
          ),
        ),
      ),
    );
    await tester.pumpAndSettle();
  }

  /// The whole range, not the one width somebody tried.
  ///
  /// 700 and 900 are the two that were broken and the two nobody looked at:
  /// a laptop, and a window split down the middle of a desktop.
  for (final width in [375.0, 620.0, 700.0, 900.0, 1000.0, 1400.0]) {
    testWidgets('the header fits at ${width.toInt()}px with a wide action', (
      tester,
    ) async {
      await pump(tester, Size(width, 600), threeControls());

      expect(
        tester.takeException(),
        isNull,
        reason:
            'The header overflowed at ${width.toInt()}px. Every screen in the '
            'app uses this heading, so a width where it breaks is a width '
            'where the whole app breaks.',
      );

      // Not merely "no exception". The defect is the NOTE being squeezed into
      // a column a few characters wide — the overflow is only what that turns
      // into once it is tall enough. Measuring the note says so directly, and
      // says it at widths where the squeeze is bad but not yet fatal.
      //
      // Height would be the wrong measure here: at 375px the action correctly
      // stacks into three rows and a taller header is the right answer, so a
      // height cap would fail the one case that is behaving.
      expect(
        tester.getRect(find.textContaining('Everybody you work for')).width,
        greaterThan(220),
        reason:
            'At ${width.toInt()}px the note is being laid out into a narrow '
            'column beside the action. That is the failure — a paragraph '
            'reading two words a line — and the overflow is just what it '
            'becomes when the column gets tall enough.',
      );
    });
  }

  /// The single-button case, which is most screens and must not regress.
  testWidgets('a one-button header still sits beside the text when there is '
      'room', (tester) async {
    await pump(
      tester,
      const Size(1400, 600),
      FilledButton(onPressed: () {}, child: const Text('New customer')),
    );

    final note = tester.getRect(find.textContaining('Everybody you work for'));
    final button = tester.getRect(find.byType(FilledButton));

    expect(
      button.left,
      greaterThan(note.right),
      reason:
          'The action stacked under the text on a wide screen, which wastes '
          'the axis there is most of.',
    );
  });

  /// R7. On a phone it goes underneath, and the text gets the whole width.
  testWidgets('on a phone the action is under the text, not beside it', (
    tester,
  ) async {
    await pump(
      tester,
      const Size(375, 600),
      FilledButton(onPressed: () {}, child: const Text('New customer')),
    );

    final note = tester.getRect(find.textContaining('Everybody you work for'));
    final button = tester.getRect(find.byType(FilledButton));

    expect(button.top, greaterThan(note.bottom - 1));
    expect(
      note.width,
      greaterThan(300),
      reason: 'The note is still sharing its line with something.',
    );
  });
}
