import 'package:ezbizi_client/widgets/list_filters.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

/// Mobile review 2026-11-13, P5: one filter strip with 40 px pills that
/// starts at the 16 px phone gutter and fades where more lie past the edge.
void main() {
  Future<void> pump(WidgetTester tester, {required int count}) async {
    tester.view.physicalSize = const Size(375, 800);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.reset);
    await tester.pumpWidget(
      MaterialApp(
        home: Scaffold(
          body: FilterPills<int>(
            selected: 0,
            onSelected: (_) {},
            items: [
              for (var i = 0; i < count; i++)
                FilterPill(i, 'S$i', key: ValueKey(i)),
            ],
          ),
        ),
      ),
    );
    await tester.pumpAndSettle();
  }

  testWidgets('pills take taps over 40 px and start at the gutter', (
    tester,
  ) async {
    await pump(tester, count: 2);
    final hit = find.ancestor(
      of: find.byKey(const ValueKey(0)),
      matching: find.byType(GestureDetector),
    );
    expect(tester.getSize(hit.last).height, greaterThanOrEqualTo(40));
    expect(tester.getTopLeft(hit.last).dx, 16);
    expect(find.byKey(const Key('filter-pills-more')), findsNothing);
  });

  testWidgets('a strip that runs past the edge says so', (tester) async {
    await pump(tester, count: 9);
    expect(find.byKey(const Key('filter-pills-more')), findsOneWidget);
  });
}
