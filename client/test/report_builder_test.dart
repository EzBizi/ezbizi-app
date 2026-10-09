import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/reports/report_builder.dart';
import 'package:ezbizi_client/widgets/search_picker.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// The builder shows the report as it is made (UI queue 72, Jose 2026-10-09:
/// "a preview view, as you select the items and be able to order them too").
void main() {
  testWidgets('ticking a field asks for a preview and draws its rows', (
    tester,
  ) async {
    final asked = <Map<String, dynamic>>[];
    await _pump(tester, asked: asked);

    await _tick(tester, 'Title');

    expect(asked.last['columns'], [
      {'resource': 'jobs', 'column': 'title'},
    ]);
    expect(find.byKey(const Key('builder-preview')), findsOneWidget);
    expect(find.text('Chimney sweep'), findsOneWidget);
    expect(find.textContaining('first 2 of 7 rows'), findsOneWidget);
  });

  testWidgets('a column dragged earlier is asked for earlier', (tester) async {
    final asked = <Map<String, dynamic>>[];
    await _pump(tester, asked: asked);

    await _tick(tester, 'Title');
    await _tick(tester, 'Amount');
    expect(
      [for (final c in asked.last['columns'] as List) c['column']],
      ['title', 'amount_minor'],
    );

    // Amount's handle, dragged left past Title.
    await tester.timedDrag(
      find.byIcon(Icons.drag_indicator).last,
      const Offset(-220, 0),
      const Duration(milliseconds: 600),
    );
    // The drop settles first; then the preview waits for changes to stop.
    await tester.pumpAndSettle();
    await tester.pump(const Duration(milliseconds: 400));
    await tester.pumpAndSettle();

    expect(
      [for (final c in asked.last['columns'] as List) c['column']],
      ['amount_minor', 'title'],
    );
  });

  testWidgets('grouped, the preview ends in a total row', (tester) async {
    final asked = <Map<String, dynamic>>[];
    await _pump(tester, asked: asked);

    await _tick(tester, 'Title');
    await tester.tap(find.byKey(const ValueKey('builder-group')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('builder-add-group')));
    await tester.pump(const Duration(milliseconds: 400));
    await tester.pumpAndSettle();

    // Grouping starts with a count, so each group says how many.
    expect(asked.last['group'], [
      {'resource': 'jobs', 'column': 'title'},
    ]);
    expect(asked.last['totals'], [
      {'fn': 'count'},
    ]);
    // The grand total under the groups: 5 and 2 make 7.
    expect(find.text('Total'), findsOneWidget);
    expect(find.text('7'), findsOneWidget);
  });

  testWidgets('money is typed in dollars and asked for in cents', (
    tester,
  ) async {
    final asked = <Map<String, dynamic>>[];
    await _pump(tester, asked: asked);

    await _tick(tester, 'Amount');
    await tester.tap(find.byKey(const Key('builder-add-filter')));
    await tester.pumpAndSettle();

    // The first field offered is Title; Amount is picked from the list.
    await tester.tap(find.widgetWithText(SearchPickerField<String>, 'Field'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Amount').last);
    await tester.pumpAndSettle();
    await tester.tap(
      find.widgetWithText(SearchPickerField<String>, 'Condition'),
    );
    await tester.pumpAndSettle();
    await tester.tap(find.text('between').last);
    await tester.pumpAndSettle();

    await tester.enterText(
      find.byKey(const ValueKey('builder-filter-from')),
      '100',
    );
    await tester.enterText(
      find.byKey(const ValueKey('builder-filter-to')),
      '250.50',
    );
    await tester.pump(const Duration(milliseconds: 400));
    await tester.pumpAndSettle();

    expect(asked.last['filters'], [
      {
        'resource': 'jobs',
        'column': 'amount_minor',
        'op': 'between',
        'value': [10000, 25050],
      },
    ]);
  });

  testWidgets('a phone stacks the preview above the columns', (tester) async {
    await _pump(tester, asked: [], width: 390);

    await tester.tap(find.byKey(const Key('builder-add-field')));
    await tester.pumpAndSettle();
    await _tick(tester, 'Title');

    final preview = tester.getRect(find.byKey(const Key('builder-preview')));
    final columns = tester.getRect(find.byKey(const Key('builder-columns')));
    expect(preview.bottom, lessThan(columns.top));
    expect(tester.takeException(), isNull);
  });
}

Future<void> _tick(WidgetTester tester, String label) async {
  final box = find.widgetWithText(CheckboxListTile, label);
  if (box.evaluate().isEmpty) {
    await tester.tap(find.text('Jobs'));
    await tester.pumpAndSettle();
  }
  await tester.tap(find.widgetWithText(CheckboxListTile, label));
  // The preview waits for the changes to stop.
  await tester.pump(const Duration(milliseconds: 400));
  await tester.pumpAndSettle();
}

Future<void> _pump(
  WidgetTester tester, {
  required List<Map<String, dynamic>> asked,
  double width = 1400,
}) async {
  tester.view.physicalSize = Size(width, 1000);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(
    client: MockClient((request) async {
      if (request.url.path.endsWith('/reports/fields')) {
        return _json({'data': _fields});
      }
      if (request.url.path.endsWith('/reports/preview')) {
        final definition =
            (jsonDecode(request.body) as Map)['definition']
                as Map<String, dynamic>;
        asked.add(definition);
        return _json({'data': _answer(definition)});
      }
      return _json({'message': 'Not stubbed.'}, 500);
    }),
  );

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(
        body: Padding(
          padding: const EdgeInsets.all(16),
          child: ReportBuilder(api: api, onSaved: (_) {}),
        ),
      ),
    ),
  );
  await tester.pumpAndSettle();
}

/// What the server would answer, from the definition it was sent.
Map<String, dynamic> _answer(Map<String, dynamic> definition) {
  final group = definition['group'] as List?;
  if (group != null) {
    return {
      'columns': [
        {'key': 'jobs.title', 'label': 'Title', 'type': 'string'},
        {'key': 'count', 'label': 'Count', 'type': 'integer'},
      ],
      'rows': [
        {'jobs.title': 'Chimney sweep', 'count': 5},
        {'jobs.title': 'Inspection', 'count': 2},
      ],
      'total': {'count': 7},
      'count': 2,
      'capped': false,
    };
  }

  final columns = [
    for (final column in definition['columns'] as List)
      {
        'key': '${column['resource']}.${column['column']}',
        'label': column['column'] == 'title' ? 'Title' : 'Amount',
        'type': column['column'] == 'title' ? 'string' : 'money',
      },
  ];
  return {
    'columns': columns,
    'rows': [
      {'jobs.title': 'Chimney sweep', 'jobs.amount_minor': 18500},
      {'jobs.title': 'Inspection', 'jobs.amount_minor': 9900},
    ],
    'count': 7,
    'capped': false,
  };
}

const _fields = {
  'jobs': {
    'label': 'Jobs',
    'columns': {
      'title': {'label': 'Title', 'type': 'string'},
      'amount_minor': {'label': 'Amount', 'type': 'money'},
      'scheduled_for': {'label': 'Scheduled', 'type': 'datetime'},
    },
    'relations': <String, dynamic>{},
  },
};

http.Response _json(Object body, [int status = 200]) => http.Response(
  jsonEncode(body),
  status,
  headers: {'content-type': 'application/json'},
);
