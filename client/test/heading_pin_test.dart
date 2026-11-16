import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/widgets/heading_pin.dart';
import 'package:ezbizi_client/widgets/list_table.dart';
import 'package:ezbizi_client/widgets/report_grid.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

/// Jose, 2026-11-16: report table headings stay on screen while the rows
/// scroll, or scroll away, by a pin in the headings that each person sets.
void main() {
  final page = ScrollController();

  /// [table] 200px down a page that scrolls; [pinned] null is no scope.
  Future<void> pump(
    WidgetTester tester,
    Widget table, {
    bool? pinned,
    ValueChanged<bool>? onChanged,
  }) async {
    tester.view.physicalSize = const Size(1000, 600);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.reset);
    Widget body = ListView(
      controller: page,
      children: [
        const SizedBox(height: 200),
        table,
        const SizedBox(height: 400),
      ],
    );
    if (pinned != null) {
      body = HeadingPin(
        pinned: pinned,
        onChanged: onChanged ?? (_) {},
        child: body,
      );
    }
    await tester.pumpWidget(
      MaterialApp(
        theme: EzBiziTheme.light(),
        home: Scaffold(body: body),
      ),
    );
    await tester.pumpAndSettle();
  }

  Future<void> scrollTo(WidgetTester tester, double y) async {
    page.jumpTo(y.clamp(0, page.position.maxScrollExtent));
    await tester.pump();
  }

  double top(WidgetTester tester, String text) =>
      tester.getRect(find.text(text)).top;

  final grid = ReportGrid(
    columns: const [
      GridColumn('Source', pinned: true),
      GridColumn('Leads', numeric: true),
    ],
    rows: [
      for (var i = 0; i < 60; i++) [GridCell('Source $i'), GridCell('$i')],
    ],
  );

  final list = ListTable(
    columns: const [ListColumn('Source'), ListColumn('Leads', end: true)],
    rows: [
      for (var i = 0; i < 60; i++) [Text('Source $i'), Text('$i')],
    ],
  );

  group('ReportGrid', () {
    testWidgets('pinned: the headings stay at the top while rows pass', (
      tester,
    ) async {
      await pump(tester, grid, pinned: true);
      expect(find.byKey(const ValueKey('heading-pin')), findsOneWidget);

      await scrollTo(tester, 800);
      expect(top(tester, 'Source'), inInclusiveRange(0, 40));
      expect(top(tester, 'Leads'), inInclusiveRange(0, 40));
      // The rows under it really did scroll.
      expect(top(tester, 'Source 0'), lessThan(0));
    });

    testWidgets('unpinned: the headings scroll away with the rows', (
      tester,
    ) async {
      await pump(tester, grid, pinned: false);
      expect(find.byKey(const ValueKey('heading-pin')), findsOneWidget);

      await scrollTo(tester, 800);
      expect(top(tester, 'Source'), lessThan(0));
    });

    testWidgets('they stop above the last row', (tester) async {
      await pump(tester, grid, pinned: true);
      // Twice: a ListView only estimates its end until it has laid it out.
      await scrollTo(tester, 2000);
      await scrollTo(tester, 1e6);
      expect(top(tester, 'Source'), lessThan(top(tester, 'Source 59')));
    });

    testWidgets('the pin answers a click while it rides down the page', (
      tester,
    ) async {
      bool? asked;
      await pump(tester, grid, pinned: true, onChanged: (v) => asked = v);
      await scrollTo(tester, 800);
      await tester.tap(find.byKey(const ValueKey('heading-pin')));
      expect(asked, isFalse);
    });

    testWidgets('outside Reports: no pin, and the headings scroll as before', (
      tester,
    ) async {
      await pump(tester, grid);
      expect(find.byKey(const ValueKey('heading-pin')), findsNothing);
      await scrollTo(tester, 800);
      expect(top(tester, 'Source'), lessThan(0));
    });
  });

  group('ListTable', () {
    testWidgets('pinned: the headings stay at the top while rows pass', (
      tester,
    ) async {
      await pump(tester, list, pinned: true);
      expect(find.byKey(const ValueKey('heading-pin')), findsOneWidget);

      await scrollTo(tester, 800);
      expect(top(tester, 'SOURCE'), inInclusiveRange(0, 40));
      expect(top(tester, 'Source 0'), lessThan(0));
    });

    testWidgets('unpinned: the headings scroll away with the rows', (
      tester,
    ) async {
      await pump(tester, list, pinned: false);
      await scrollTo(tester, 800);
      expect(top(tester, 'SOURCE'), lessThan(0));
    });

    testWidgets('outside Reports: no pin, and the headings scroll as before', (
      tester,
    ) async {
      await pump(tester, list);
      expect(find.byKey(const ValueKey('heading-pin')), findsNothing);
      await scrollTo(tester, 800);
      expect(top(tester, 'SOURCE'), lessThan(0));
    });
  });

  group('HeadingPin.sliver', () {
    Future<void> pumpSliver(WidgetTester tester, {bool? pinned}) async {
      Widget body = CustomScrollView(
        slivers: [
          Builder(
            builder: (context) =>
                HeadingPin.sliver(context, child: const Text('Heading')),
          ),
          const SliverToBoxAdapter(child: SizedBox(height: 2000)),
        ],
      );
      if (pinned != null) {
        body = HeadingPin(pinned: pinned, onChanged: (_) {}, child: body);
      }
      await tester.pumpWidget(MaterialApp(home: Scaffold(body: body)));
    }

    testWidgets('pinned, unpinned, and pinned as before without a scope', (
      tester,
    ) async {
      await pumpSliver(tester, pinned: true);
      expect(find.byType(PinnedHeaderSliver), findsOneWidget);
      await pumpSliver(tester, pinned: false);
      expect(find.byType(PinnedHeaderSliver), findsNothing);
      await pumpSliver(tester);
      expect(find.byType(PinnedHeaderSliver), findsOneWidget);
    });
  });
}
