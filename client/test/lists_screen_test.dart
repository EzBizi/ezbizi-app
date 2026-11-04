import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/settings/lists_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Settings › Lists (design 181, 2026-11-04).
void main() {
  testWidgets('no lists says so', (tester) async {
    await _pump(tester, lists: const []);
    expect(find.text('No lists yet'), findsOneWidget);
  });

  testWidgets('a cell saves on blur, once, and only when it changed', (
    tester,
  ) async {
    final calls = await _pump(tester);

    await tester.tap(find.byKey(const ValueKey('cell:bar:name')));
    await tester.pump();
    await tester.tap(find.byKey(const ValueKey('cell:shoval:name')));
    await tester.pumpAndSettle();
    expect(
      calls.where((c) => c.method == 'PATCH'),
      isEmpty,
      reason: 'Unchanged: no call.',
    );

    await tester.enterText(
      find.descendant(
        of: find.byKey(const ValueKey('cell:bar:name')),
        matching: find.byType(TextField),
      ),
      'Bar Cohen',
    );
    await tester.tap(find.byKey(const ValueKey('cell:shoval:name')));
    await tester.pumpAndSettle();

    final patch = calls.singleWhere((c) => c.method == 'PATCH');
    expect(patch.url.path, endsWith('/settings/lists/test.aliases/items/bar'));
    expect(jsonDecode(patch.body), {
      'value': {'name': 'Bar Cohen'},
    });
  });

  testWidgets('remove is immediate and Undo puts the row back', (tester) async {
    final calls = await _pump(tester);

    await tester.tap(find.byKey(const ValueKey('remove:bar')));
    await tester.pumpAndSettle();
    expect(calls.last.method, 'DELETE');
    expect(find.byKey(const ValueKey('row:bar')), findsNothing);

    await tester.tap(find.text('Undo'));
    await tester.pumpAndSettle();
    expect(calls.last.method, 'POST');
    expect(jsonDecode(calls.last.body)['key'], 'bar');
    expect(find.byKey(const ValueKey('row:bar')), findsOneWidget);
  });

  testWidgets(
    'a new row is posted once its first column is filled, with no key',
    (tester) async {
      final calls = await _pump(tester);

      await tester.tap(find.byKey(const ValueKey('lists-add')));
      await tester.pumpAndSettle();
      await tester.enterText(
        find.descendant(
          of: find.byKey(const ValueKey('add:name')),
          matching: find.byType(TextField),
        ),
        'Jose',
      );
      await tester.tap(find.byKey(const ValueKey('lists-search')));
      await tester.pumpAndSettle();

      final post = calls.singleWhere((c) => c.method == 'POST');
      expect(jsonDecode(post.body), {
        'value': {'name': 'Jose'},
      });
    },
  );

  testWidgets('a list this person may not edit has no add or remove', (
    tester,
  ) async {
    await _pump(tester, mayEdit: false);

    expect(find.text('Read only'), findsOneWidget);
    expect(find.byKey(const ValueKey('lists-add')), findsNothing);
    expect(find.byKey(const ValueKey('remove:bar')), findsNothing);
  });

  testWidgets(
    'on a phone the list of lists comes first and a list opens in place',
    (tester) async {
      await _pump(tester, width: 400);

      expect(find.byKey(const ValueKey('row:bar')), findsNothing);
      await tester.tap(find.byKey(const ValueKey('list-entry:test.aliases')));
      await tester.pumpAndSettle();
      expect(find.byKey(const ValueKey('row:bar')), findsOneWidget);

      await tester.tap(find.byKey(const ValueKey('lists-back')));
      await tester.pumpAndSettle();
      expect(
        find.byKey(const ValueKey('list-entry:test.aliases')),
        findsOneWidget,
      );
    },
  );
}

Map<String, dynamic> _list({bool mayEdit = true}) => {
  'key': 'test.aliases',
  'label': 'Staff aliases',
  'module': 'phone',
  'help': 'Names people say for each other.',
  'columns': [
    {'key': 'name', 'label': 'Name'},
  ],
  'edited': false,
  'may_edit': mayEdit,
  'items': [
    {
      'key': 'bar',
      'value': {'name': 'Bar'},
      'sort': 0,
    },
    {
      'key': 'shoval',
      'value': {'name': 'Shoval'},
      'sort': 1,
    },
  ],
};

Future<List<http.Request>> _pump(
  WidgetTester tester, {
  List<Map<String, dynamic>>? lists,
  bool mayEdit = true,
  double width = 1400,
}) async {
  tester.view.physicalSize = Size(width, 1000);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final calls = <http.Request>[];
  final api = ApiClient(
    client: MockClient((request) async {
      if (request.method != 'GET') calls.add(request);
      if (request.method == 'POST') {
        final body = jsonDecode(request.body) as Map;
        return _json({
          'data': {
            'key': body['key'] ?? 'new',
            'value': body['value'],
            'sort': 9,
          },
        }, 201);
      }
      if (request.method == 'GET') {
        return _json({
          'data': lists ?? [_list(mayEdit: mayEdit)],
        });
      }
      return _json({'data': null});
    }),
  );

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(body: ListsScreen(api: api)),
    ),
  );
  await tester.pumpAndSettle();

  return calls;
}

http.Response _json(Object body, [int status = 200]) => http.Response(
  jsonEncode(body),
  status,
  headers: const {'content-type': 'application/json'},
);
