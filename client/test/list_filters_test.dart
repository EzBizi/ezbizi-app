import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/widgets/list_filters.dart';
import 'package:ezbizi_client/widgets/side_panel.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

/// Task Board filter, sort and person filter (Jose, 2026-11-09, monday's
/// toolbar). Invented tasks only.
void main() {
  final tasks = <Map<String, dynamic>>[
    {
      'title': 'Crown repair',
      'status': 'stuck',
      'due': '2026-11-20',
      'who': ['ann'],
      'cost': 300,
    },
    {
      'title': 'Cap install',
      'status': 'done',
      'due': null,
      'who': ['bo'],
      'cost': 90,
    },
    {
      'title': 'Liner quote',
      'status': 'working',
      'due': '2026-11-01',
      'who': ['ann', 'bo'],
      'cost': null,
    },
  ];
  final fields = <String, FilterField>{
    for (final f in <FilterField>[
      (
        key: 'title',
        label: 'Task',
        kind: 'text',
        options: const [],
        read: (t) => t['title'],
      ),
      (
        key: 'status',
        label: 'Status',
        kind: 'options',
        options: const [
          ('working', 'Working'),
          ('stuck', 'Stuck'),
          ('done', 'Done'),
        ],
        read: (t) => t['status'],
      ),
      (
        key: 'due',
        label: 'Due',
        kind: 'date',
        options: const [],
        read: (t) => t['due'],
      ),
      (
        key: 'cost',
        label: 'Cost',
        kind: 'number',
        options: const [],
        read: (t) => t['cost'],
      ),
    ])
      f.key: f,
  };
  List<String> shown(ListQuery v) => [
    for (final t in v.sorted(tasks, fields))
      if (v.matches(t, fields, [for (final w in t['who'] as List) '$w']))
        t['title'] as String,
  ];

  test('all and any, is not, empty, dates and the person filter', () {
    expect(
      shown(
        const ListQuery(
          filters: [
            (field: 'status', op: 'is_not', value: ['done']),
          ],
        ),
      ),
      ['Crown repair', 'Liner quote'],
    );
    expect(
      shown(
        const ListQuery(
          filters: [
            (field: 'status', op: 'is', value: ['stuck']),
            (field: 'due', op: 'before', value: '2026-11-10'),
          ],
        ),
      ),
      isEmpty,
    );
    expect(
      shown(
        const ListQuery(
          any: true,
          filters: [
            (field: 'status', op: 'is', value: ['stuck']),
            (field: 'due', op: 'before', value: '2026-11-10'),
          ],
        ),
      ),
      ['Crown repair', 'Liner quote'],
    );
    expect(
      shown(
        const ListQuery(filters: [(field: 'due', op: 'empty', value: null)]),
      ),
      ['Cap install'],
    );
    expect(
      shown(
        const ListQuery(
          filters: [(field: 'title', op: 'contains', value: 'CAP')],
        ),
      ),
      ['Cap install'],
    );
    expect(shown(const ListQuery(people: ['bo'])), [
      'Cap install',
      'Liner quote',
    ]);
  });

  test(
    'sort by number, by option order, empty last, and a saved view round-trips',
    () {
      expect(shown(const ListQuery(sort: [(field: 'cost', desc: true)])), [
        'Crown repair',
        'Cap install',
        'Liner quote',
      ]);
      expect(shown(const ListQuery(sort: [(field: 'status', desc: false)])), [
        'Liner quote',
        'Crown repair',
        'Cap install',
      ]);
      expect(shown(const ListQuery(sort: [(field: 'due', desc: false)])), [
        'Liner quote',
        'Crown repair',
        'Cap install',
      ]);

      const view = ListQuery(
        any: true,
        filters: [
          (field: 'status', op: 'is', value: ['stuck']),
        ],
        sort: [(field: 'due', desc: true)],
        people: ['ann'],
      );
      expect(ListQuery.fromJson(view.toJson()).toJson(), view.toJson());
    },
  );

  testWidgets('the panel builds a filter and a sort and applies them', (
    tester,
  ) async {
    ListQuery? result;
    tester.view.physicalSize = const Size(1400, 1000);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);
    await tester.pumpWidget(
      MaterialApp(
        theme: EzBiziTheme.light(),
        home: Scaffold(
          body: Builder(
            builder: (context) => FilledButton(
              onPressed: () async => result = await showSidePanel<ListQuery>(
                context,
                builder: (_) => FilterPanel(
                  fields: fields.values.toList(),
                  view: const ListQuery(),
                ),
              ),
              child: const Text('open'),
            ),
          ),
        ),
      ),
    );
    await tester.tap(find.text('open'));
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const Key('filter-add')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('filter-field-0')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Status').last);
    await tester.pumpAndSettle();
    await tester.tap(find.widgetWithText(FilterChip, 'Stuck'));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('sort-add')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('filter-apply')));
    await tester.pumpAndSettle();

    expect(result!.filters.single.field, 'status');
    expect(result!.filters.single.value, ['stuck']);
    expect(result!.sort.single.field, 'title');
  });
}
