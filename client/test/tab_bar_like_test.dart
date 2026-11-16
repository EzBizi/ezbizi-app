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

  // UI audit, 2026-10-08: on a phone a strip ran past the edge with nothing
  // saying so, a selected tab could sit out of sight, and a screen reader
  // heard every tab as a checkbox.
  const many = [
    'General',
    'Map',
    'PC Specs',
    'Files',
    'Camera Monitoring',
    'Desktop Monitoring',
  ];

  testWidgets('on a phone the strip fades where tabs go on past the edge', (
    tester,
  ) async {
    await _phone(tester);
    await _pump(tester, labels: many, at: 0);
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('tab-bar-more')), findsOneWidget);

    // The last tab selected: brought into view, and nothing lies past it.
    await _pump(tester, labels: many, at: 5);
    await tester.pumpAndSettle();
    expect(
      tester.getRect(find.text('Desktop Monitoring')).right,
      lessThanOrEqualTo(390),
    );
    expect(find.byKey(const Key('tab-bar-more')), findsNothing);
  });

  // Phone audit 2026-11-04: tabs past the edge went unannounced, and a tab
  // only half on screen took two taps.
  testWidgets('a phone strip shows a chevron that pages it, and one tap on a '
      'half-shown tab selects it', (tester) async {
    await _phone(tester);
    int? picked;
    await tester.pumpWidget(
      MaterialApp(
        home: Scaffold(
          body: TabBarLike(
            labels: [for (final l in many) (l, Icons.folder_outlined)],
            selected: 0,
            onSelected: (i) => picked = i,
          ),
        ),
      ),
    );
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('tab-bar-chevron')), findsOneWidget);

    // The first tab whose left edge is in view but whose right runs on past
    // where the strip ends, beside the chevron (tab widths vary with the
    // style, so the edge is measured, not assumed).
    final end = tester.getRect(find.byKey(const Key('tab-bar-chevron'))).left;
    final half = many.indexWhere((l) {
      final r = tester.getRect(find.text(l));
      return r.left < end - 12 && r.right > end;
    });
    if (half >= 0) {
      await tester.tapAt(
        tester.getRect(find.text(many[half])).centerLeft + const Offset(4, 0),
      );
      await tester.pumpAndSettle();
      expect(picked, half);
    }

    await tester.tap(find.byKey(const Key('tab-bar-chevron')));
    final before = tester.getRect(find.text(many.last)).left;
    await tester.pumpAndSettle();
    expect(tester.getRect(find.text(many.last)).left, lessThan(before));
  });

  testWidgets('a strip that fits has no fade, and a desk wraps instead', (
    tester,
  ) async {
    await _phone(tester);
    await _pump(tester, labels: ['Jobs', 'Estimates'], at: 0);
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('tab-bar-more')), findsNothing);

    tester.view.physicalSize = const Size(1200, 800);
    await _pump(tester, labels: many, at: 0);
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('tab-bar-more')), findsNothing);
  });

  testWidgets('a tab is a selected button to a screen reader, not a checkbox', (
    tester,
  ) async {
    final semantics = tester.ensureSemantics();
    await _pump(tester, labels: ['Records', 'Contracts'], at: 1);

    expect(
      tester.getSemantics(find.bySemanticsLabel('Contracts')),
      isSemantics(isButton: true, isSelected: true, hasCheckedState: false),
    );
    expect(
      tester.getSemantics(find.bySemanticsLabel('Records')),
      isSemantics(isButton: true, isSelected: false),
    );
    semantics.dispose();
  });

  /// UI queue 69: a row inside a tab must not look like the row above it.
  /// Pills there, so none here, and no icon: the icon is what marks the
  /// module's own row. Still a selectable tab to a finger and a screen reader.
  for (final level in [TabLevel.secondary, TabLevel.tertiary]) {
    testWidgets('a $level row is not drawn as pills, and still selects', (
      tester,
    ) async {
      final semantics = tester.ensureSemantics();
      var chosen = -1;
      await tester.pumpWidget(
        MaterialApp(
          home: Scaffold(
            body: TabBarLike(
              level: level,
              labels: const [
                ('Posts', Icons.article_outlined),
                ('Accounts', Icons.link),
              ],
              selected: 0,
              onSelected: (i) => chosen = i,
            ),
          ),
        ),
      );

      // A pill is an outlined chip with an icon; these are neither.
      final chip = tester.widget<ChoiceChip>(
        find.widgetWithText(ChoiceChip, 'Posts'),
      );
      expect(chip.avatar, isNull);
      expect(chip.side, BorderSide.none);
      expect(find.byIcon(Icons.article_outlined), findsNothing);

      await tester.tap(find.text('Accounts'));
      expect(chosen, 1);
      expect(
        tester.getSemantics(find.bySemanticsLabel('Posts')),
        isSemantics(isButton: true, isSelected: true),
      );
      semantics.dispose();
    });
  }
}

Future<void> _phone(WidgetTester tester) async {
  tester.view.physicalSize = const Size(390, 844);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);
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
