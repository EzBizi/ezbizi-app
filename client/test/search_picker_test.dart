import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/widgets/search_picker.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

/// The picker's own dialog, which had no tests at all.
///
/// Every dropdown in the app became one of these on 2026-09-17, and the only
/// coverage was whatever each call site's screen happened to assert. Nothing
/// tested the control.
///
/// ── What Jose saw ───────────────────────────────────────────────
///
/// 2026-09-24, on the WhatsApp template picker: *"stuff like this is
/// unacceptable, make it look better"* — five approved templates, each one's
/// whole body wrapped over four or five lines, nothing between them, one
/// unbroken wall of `{{1}}` and prose.
///
/// **Two causes and the control owned one.** The call site passed
/// `'$name — $body'` as the label. But a `ListTile` with an unbounded `Text`
/// wraps any label to any height, so the next caller with a long string got
/// the same wall — the closed FIELD has ellipsised since the day it was
/// written and the dialog never did. That asymmetry is the bug this file
/// exists to hold shut.
void main() {
  /// TWO BODIES, ONE TEN TIMES THE OTHER, MUST DRAW THE SAME HEIGHT.
  ///
  /// Not "all rows are equal" — a one-line body and a two-line body honestly
  /// differ by a line, and my first version of this asserted they should not
  /// and went red on correct behaviour. And not "under N points", which is a
  /// threshold somebody re-tunes when a font changes.
  ///
  /// The property is that the height does not SCALE with the body. Both of
  /// these overflow two lines, so a bounded row draws them identically and an
  /// unbounded one draws the second several lines taller.
  testWidgets('row height does not scale with the length of the body', (
    tester,
  ) async {
    const long = 'Here is the {{1}} summary for {{2}}. Spend: {{3}} '
        'Clicks: {{4}} Conversions: {{5}} — open EzBizi for the breakdown.';

    await _open(tester, [
      ('short_body', long),
      ('long_body', '$long $long $long $long $long $long'),
    ]);

    final short = tester.getSize(_rowFor('short_body')).height;
    final long6x = tester.getSize(_rowFor('long_body')).height;

    expect(
      long6x,
      short,
      reason:
          'A body six times longer drew a row ${long6x - short}pt taller, so '
          'it is still wrapping without a limit. That is the wall of text '
          'Jose photographed.',
    );
  });

  testWidgets('the body is still there, just not all of it', (tester) async {
    await _open(tester, _templates);

    // Ellipsised, not dropped: somebody has to be able to tell two templates
    // apart, and the name alone does not always do it.
    expect(find.textContaining('Your'), findsWidgets);
  });

  /// Searching the body is why the body is on the option at all. If `detail`
  /// stopped being searched, the split would have quietly cost a capability —
  /// and nothing about the look of the dialog would have changed.
  testWidgets('a word from the body still finds the template', (tester) async {
    await _open(tester, _templates, searchFrom: 1);

    await tester.enterText(find.byType(TextField).last, 'Conversions');
    await tester.pumpAndSettle();

    expect(find.text('report_summary'), findsOneWidget);
    expect(find.text('hello_world'), findsNothing);
  });

  testWidgets('the dialog fits a 375pt phone', (tester) async {
    await _open(tester, _templates, width: 375);

    expect(
      tester.takeException(),
      isNull,
      reason: 'The picker dialog overflowed at phone width.',
    );
  });
}

Finder _rowFor(String label) => find.ancestor(
  of: find.text(label),
  matching: find.byType(Container),
).first;

/// The five Jose was looking at, bodies shortened only where they repeat.
const _templates = <(String, String)>[
  (
    'automation_alert',
    'Your "{{1}}" automation has just run in EzBizi. What happened: {{2}} — '
        'Open EzBizi or check your email for the full details.',
  ),
  (
    'report_document',
    'Here is the {{1}} report for {{2}}, attached above as a PDF.',
  ),
  (
    'report_summary',
    'Here is the {{1}} summary for {{2}}. Spend: {{3}} Clicks: {{4}} '
        'Conversions: {{5}} — Open EzBizi for the full breakdown.',
  ),
  (
    '3p_direct_integration_test_template',
    'Welcome! This is a test message from the WhatsApp Business Platform. You '
        'have successfully configured your WhatsApp Business account.',
  ),
  ('hello_world', 'Welcome and congratulations!!'),
];

Future<void> _open(
  WidgetTester tester,
  List<(String, String)> options, {
  double width = 900,
  int searchFrom = 8,
}) async {
  tester.view.physicalSize = Size(width, 1600);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(
        body: Builder(
          builder: (context) => SearchPickerField<String>(
            value: null,
            label: 'Template',
            searchFrom: searchFrom,
            options: [
              for (final o in options)
                SearchOption(value: o.$1, label: o.$1, detail: o.$2),
            ],
            onChanged: (_) {},
          ),
        ),
      ),
    ),
  );
  await tester.pumpAndSettle();

  await tester.tap(find.byType(SearchPickerField<String>));
  await tester.pumpAndSettle();
}
