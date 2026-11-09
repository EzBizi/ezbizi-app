import 'package:ezbizi_client/widgets/list_filters.dart';
import 'package:ezbizi_client/widgets/list_table.dart';
import 'package:ezbizi_client/widgets/list_toolbar.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

final List<FilterField> _fields = [
  (
    key: 'owner',
    label: 'Owner',
    kind: 'people',
    options: const [('u1', 'Ana'), ('u2', 'Ben')],
    read: (t) => t['owner'],
  ),
  (
    key: 'status',
    label: 'Status',
    kind: 'options',
    options: const [('a', 'Open'), ('b', 'Done')],
    read: (t) => t['status'],
  ),
];

Future<void> _wide(WidgetTester tester, Widget child) async {
  tester.view.physicalSize = const Size(1400, 900);
  tester.view.devicePixelRatio = 1;
  addTearDown(tester.view.reset);
  await tester.pumpWidget(MaterialApp(home: Scaffold(body: child)));
}

void main() {
  testWidgets('only the buttons with an input are drawn', (tester) async {
    await _wide(
      tester,
      ListToolbar(fields: _fields, query: const ListQuery(), onChanged: (_) {}),
    );
    expect(find.byKey(const Key('list-toolbar-search')), findsNothing);
    expect(find.byKey(const Key('list-toolbar-person')), findsOneWidget);
    expect(find.byKey(const Key('list-toolbar-filter')), findsOneWidget);
    expect(find.byKey(const Key('list-toolbar-group')), findsNothing);
    expect(find.byKey(const Key('list-toolbar-views')), findsNothing);
  });

  testWidgets('Person picks people in a side panel', (tester) async {
    ListQuery? got;
    await _wide(
      tester,
      ListToolbar(
        fields: _fields,
        query: const ListQuery(),
        onChanged: (q) => got = q,
        onSearch: (_) {},
      ),
    );
    await tester.tap(find.byKey(const Key('list-toolbar-person')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('list-toolbar-tick-u2')));
    await tester.tap(find.byKey(const Key('list-toolbar-apply')));
    await tester.pumpAndSettle();
    expect(got?.people, ['u2']);
  });

  testWidgets('Hide reports the unticked columns', (tester) async {
    ListQuery? got;
    await _wide(
      tester,
      ListToolbar(
        fields: _fields,
        query: const ListQuery(),
        onChanged: (q) => got = q,
      ),
    );
    await tester.tap(find.byKey(const Key('list-toolbar-hide')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('list-toolbar-tick-status')));
    await tester.tap(find.byKey(const Key('list-toolbar-apply')));
    await tester.pumpAndSettle();
    expect(got?.hidden, ['status']);
  });

  testWidgets('Group by reports the picked field', (tester) async {
    String? got = 'unset';
    await _wide(
      tester,
      ListToolbar(
        fields: _fields,
        query: const ListQuery(),
        onChanged: (_) {},
        onGroupBy: (g) => got = g,
      ),
    );
    await tester.tap(find.byKey(const Key('list-toolbar-group')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('list-toolbar-group-status')));
    await tester.pumpAndSettle();
    expect(got, 'status');
  });

  testWidgets('a dragged heading edge reports a wider column', (tester) async {
    (int, double)? got;
    await _wide(
      tester,
      ListTable(
        columns: const [ListColumn('Name'), ListColumn('Amount')],
        rows: const [
          [Text('A'), Text('1')],
        ],
        onResize: (c, w) => got = (c, w),
      ),
    );
    await tester.drag(
      find.byKey(const Key('list-resize-0')),
      const Offset(40, 0),
    );
    expect(got?.$1, 0);
    expect(got!.$2, greaterThan(120));
  });

  testWidgets('with a height the header stays while rows scroll', (
    tester,
  ) async {
    await _wide(
      tester,
      ListTable(
        height: 300,
        columns: const [ListColumn('Name')],
        rows: [
          for (var i = 0; i < 50; i++) [Text('row $i')],
        ],
      ),
    );
    await tester.drag(find.text('row 0'), const Offset(0, -600));
    await tester.pumpAndSettle();
    expect(find.text('NAME'), findsOneWidget);
    expect(find.text('row 0'), findsNothing);
  });

  testWidgets('wide dragged widths keep the first column in place', (
    tester,
  ) async {
    await _wide(
      tester,
      ListTable(
        stickyFirstColumn: true,
        widths: const {1: 800, 2: 800},
        columns: const [ListColumn('Name'), ListColumn('B'), ListColumn('C')],
        rows: const [
          [Text('Ana'), Text('b'), Text('c')],
        ],
      ),
    );
    final before = tester.getTopLeft(find.text('Ana'));
    await tester.drag(find.text('b'), const Offset(-500, 0));
    await tester.pumpAndSettle();
    expect(tester.getTopLeft(find.text('Ana')), before);
    expect(tester.takeException(), isNull);
  });
}
