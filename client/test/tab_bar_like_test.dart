import 'package:ezbizi_client/widgets/tab_bar_like.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

/// The contract a shared tab strip could not state until now.
///
/// `TabBarLike` speaks positions. Measured 2026-09-24: of its 20 call sites,
/// 16 build `labels` with a collection-`if` on a permission or a layout
/// constraint, so the list is a different length for different people and on
/// different window widths — and a stored index means a different tab.
///
/// Six screens had each invented their own defence (clamp, resolve-by-name,
/// `indexWhere + 1`, an index/key conversion, a bare `indexWhere` that answers
/// -1, and an `Offstage` loop). Six answers to one question is the tell that
/// the question belongs to the widget.
///
/// **Nothing threw in any of them.** That is what these tests are for.
void main() {
  /// THE MECHANISM, not a silly number.
  ///
  /// Somebody is on the last tab. A permission goes away — or, on Reports, the
  /// window narrows past `buildAbove` — and the list rebuilds one shorter with
  /// the same index still held in State. That is the whole defect, and before
  /// the assert it drew a strip with no chip lit and a pane with nothing in it.
  testWidgets('a stale index survives its own tab being filtered out', (
    tester,
  ) async {
    await _pump(tester, labels: ['Reports', 'Builder', 'Experimental'], at: 2);
    expect(tester.takeException(), isNull, reason: 'In range to begin with.');

    // The grant is withdrawn. `selected` is still 2; there is no index 2.
    await _pump(tester, labels: ['Reports', 'Builder'], at: 2);

    expect(
      tester.takeException(),
      isA<AssertionError>().having(
        (e) => e.toString(),
        'says which strip and what it was given',
        allOf(contains('selected=2'), contains('Reports, Builder')),
      ),
      reason:
          'A stale index past the end still draws a blank strip silently. The '
          'assert is the only thing standing between that and a bug report '
          'that says "the tab went blank" with no stack to read.',
    );
  });

  /// THE OTHER SIDE, and the reason this is not just `selected < length`.
  ///
  /// `inspection_screen` draws the strip before it knows whether anybody may
  /// open anything, and passes `selected: 0` against no labels at all. That is
  /// correct — an empty strip has nothing to light — and an assert that red it
  /// would be a guard manufacturing its own work.
  testWidgets('no labels and selected 0 is legal', (tester) async {
    await _pump(tester, labels: [], at: 0);

    expect(tester.takeException(), isNull);
  });

  testWidgets('a negative index is caught too', (tester) async {
    // `records_tab` and `automations_screen` reach TabBarLike through a bare
    // `indexWhere`, which answers -1 rather than throwing.
    await _pump(tester, labels: ['Details', 'Onboarding'], at: -1);

    expect(tester.takeException(), isA<AssertionError>());
  });
}

Future<void> _pump(
  WidgetTester tester, {
  required List<String> labels,
  required int at,
}) async {
  await tester.pumpWidget(
    MaterialApp(
      home: Scaffold(
        body: TabBarLike(
          labels: [for (final l in labels) (l, Icons.folder_outlined)],
          selected: at,
          onSelected: (_) {},
        ),
      ),
    ),
  );
}
