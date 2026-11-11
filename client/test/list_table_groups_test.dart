import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/widgets/list_table.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

// Groups and the bulk bar on ListTable (monday port batch 2, desk 2026-11-09).

const _names = ['Ann', 'Bob', 'Cy', 'Di'];
const _stage = ['open', 'done', 'open', 'done'];

Widget _host(ListTable table) => MaterialApp(
  theme: EzBiziTheme.light(),
  home: Scaffold(
    body: SizedBox(width: 1000, child: SingleChildScrollView(child: table)),
  ),
);

ListTable _table({
  bool groups = false,
  bool selectable = false,
  List<BulkAction> actions = const [],
}) => ListTable(
  columns: const [ListColumn('Name')],
  rows: [
    for (final n in _names) [Text(n)],
  ],
  groupBy: groups
      ? (r) => (key: _stage[r], label: _stage[r].toUpperCase(), tone: Tone.info)
      : null,
  rowTone: (r) => r.isEven ? Tone.ok : Tone.caution,
  selectable: selectable,
  bulkActions: actions,
);

void main() {
  testWidgets('groups head their rows and collapse to a count and a split', (
    tester,
  ) async {
    await tester.pumpWidget(_host(_table(groups: true)));

    expect(find.text('OPEN'), findsOneWidget);
    expect(find.text('DONE'), findsOneWidget);
    expect(find.text('Ann'), findsOneWidget);

    await tester.tap(find.byKey(const Key('list-group-open')));
    await tester.pump();

    expect(find.text('Ann'), findsNothing);
    expect(find.text('Cy'), findsNothing);
    expect(find.text('Bob'), findsOneWidget);
    expect(find.text('2'), findsNWidgets(2));
    expect(find.byKey(const Key('list-group-split-open')), findsOneWidget);
    expect(find.byKey(const Key('list-group-split-done')), findsNothing);
  });

  testWidgets('ticking rows shows the bar; Clear hides it', (tester) async {
    List<int>? ran;
    await tester.pumpWidget(
      _host(
        _table(
          selectable: true,
          actions: [BulkAction('Archive', Icons.archive, (r) => ran = r)],
        ),
      ),
    );

    expect(find.byKey(const Key('list-bulk-bar')), findsNothing);
    await tester.tap(find.byKey(const Key('list-select-2')));
    await tester.tap(find.byKey(const Key('list-select-0')));
    await tester.pump();
    expect(find.text('2 selected'), findsOneWidget);

    await tester.tap(find.byKey(const Key('list-bulk-Archive')));
    await tester.pumpAndSettle();
    expect(ran, [0, 2]);
    expect(find.byKey(const Key('list-bulk-bar')), findsNothing);

    await tester.tap(find.byKey(const Key('list-select-all')));
    await tester.pump();
    expect(find.text('4 selected'), findsOneWidget);
    await tester.tap(find.byKey(const Key('list-bulk-clear')));
    await tester.pump();
    expect(find.byKey(const Key('list-bulk-bar')), findsNothing);
  });

  testWidgets('a destructive action asks in a side panel first', (
    tester,
  ) async {
    var runs = 0;
    await tester.pumpWidget(
      _host(
        _table(
          selectable: true,
          actions: [
            BulkAction(
              'Delete',
              Icons.delete,
              (_) => runs++,
              destructive: true,
            ),
          ],
        ),
      ),
    );

    await tester.tap(find.byKey(const Key('list-select-1')));
    await tester.pump();
    await tester.tap(find.byKey(const Key('list-bulk-Delete')));
    await tester.pumpAndSettle();
    expect(find.text('Delete 1 selected?'), findsOneWidget);
    expect(runs, 0);

    await tester.tap(find.byKey(const Key('list-bulk-confirm')));
    await tester.pumpAndSettle();
    expect(runs, 1);
  });
}
