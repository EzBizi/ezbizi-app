import 'package:ezbizi_client/widgets/list_table.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

/// Desktop review 2026-11-13, D4 (mobile P9): tile notes were cut to one
/// line ("cannot be dialled insi…"); two lines now, and the tiles in a row
/// stay one height (Jose's equal-tile rule).
void main() {
  testWidgets('a long note wraps and every tile in the row is one height', (
    tester,
  ) async {
    tester.view.physicalSize = const Size(420, 800);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.reset);
    await tester.pumpWidget(
      MaterialApp(
        home: Scaffold(
          body: FilterTiles(
            facts: const [SizedBox()],
            prefix: 't',
            picked: null,
            onPick: (_) {},
            tiles: const [
              FilterTile(key: 'a', label: 'Ext', count: 3, note: 'in'),
              FilterTile(
                key: 'b',
                label: 'No ext',
                count: 2,
                note: 'cannot be dialled inside',
              ),
            ],
          ),
        ),
      ),
    );
    final note = tester.widget<Text>(find.text('cannot be dialled inside'));
    expect(note.maxLines, 2);
    final a = tester.getSize(find.byKey(const Key('t-tile-a')));
    final b = tester.getSize(find.byKey(const Key('t-tile-b')));
    expect(a.height, b.height);
    expect(a.width, b.width);
  });

  testWidgets('on a phone the tiles fold into one row of pills', (
    tester,
  ) async {
    tester.view.physicalSize = const Size(375, 800);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.reset);
    String? picked = 'x';
    await tester.pumpWidget(
      MaterialApp(
        home: Scaffold(
          body: Column(
            children: [
              FilterTiles(
                prefix: 't',
                picked: null,
                onPick: (k) => picked = k,
                tiles: const [
                  FilterTile(key: null, label: 'All', count: 5),
                  FilterTile(key: 'b', label: 'Sent', count: 2),
                ],
              ),
            ],
          ),
        ),
      ),
    );
    expect(find.byKey(const Key('t-tiles-folded')), findsOneWidget);
    expect(
      tester.getSize(find.byKey(const Key('filter-pills-strip'))).height,
      lessThan(60),
    );
    await tester.tap(find.byKey(const Key('t-tile-b')));
    expect(picked, 'b');
  });
}
