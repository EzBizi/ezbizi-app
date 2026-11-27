import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/scheduling/technician_roster.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Scheduling › Settings › Technicians (Jose, 2026-10-27: "EzBizi is the
/// master").
void main() {
  testWidgets('the list draws every field of a technician', (tester) async {
    await _pump(tester);

    expect(find.text('Ivan Sharkov'), findsOneWidget);
    expect(find.text('Everdoors · Connecticut'), findsWidgets);
    expect(find.text('555-0101'), findsOneWidget);
    expect(find.text('ivan@example.com'), findsOneWidget);
    expect(find.text('Dana Diaz'), findsOneWidget);
    expect(find.text('North'), findsOneWidget);
    expect(find.text('Field tech'), findsOneWidget);
    expect(find.text('Active'), findsNWidgets(2));
    expect(find.text('In Workiz'), findsOneWidget);
    expect(find.text('Add technician'), findsOneWidget);
  });

  testWidgets('search narrows the list', (tester) async {
    await _pump(tester);

    await tester.enterText(find.byKey(const ValueKey('roster-search')), 'jenk');
    await tester.pumpAndSettle();

    expect(find.text('Harry Jenkins'), findsOneWidget);
    expect(find.text('Ivan Sharkov'), findsNothing);
  });

  testWidgets('the branch filter asks for that branch', (tester) async {
    final gets = <Uri>[];
    await _pump(tester, gets: gets);

    await tester.tap(find.byKey(const ValueKey('roster-branch')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Everdoors · Texas').last);
    await tester.pumpAndSettle();

    expect(gets.last.queryParameters['franchise_id'], 'f-tx');
  });

  testWidgets('Show inactive sends all=1', (tester) async {
    final gets = <Uri>[];
    await _pump(tester, gets: gets);
    expect(gets.last.queryParameters.containsKey('all'), isFalse);

    await tester.tap(find.byKey(const ValueKey('roster-inactive')));
    await tester.pumpAndSettle();

    expect(gets.last.queryParameters['all'], '1');
  });

  testWidgets('adding POSTs the new technician', (tester) async {
    final writes = <(String, String, Map<String, dynamic>)>[];
    await _pump(tester, writes: writes);

    await tester.tap(find.byKey(const ValueKey('roster-add')));
    await tester.pumpAndSettle();
    await tester.enterText(find.byKey(const ValueKey('roster-name')), 'Ron');
    await tester.tap(find.byKey(const ValueKey('roster-editor-branch')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Everdoors · Connecticut').last);
    await tester.pumpAndSettle();
    await tester.enterText(
      find.byKey(const ValueKey('roster-phone')),
      '555-0199',
    );
    await tester.tap(find.byKey(const ValueKey('roster-area:a-south')));
    await tester.pump();
    await tester.tap(find.byKey(const ValueKey('roster-save')));
    await tester.pumpAndSettle();

    expect(writes, hasLength(1));
    expect(writes.single.$1, 'POST');
    expect(writes.single.$2, '/api/v1/scheduling/roster');
    expect(writes.single.$3, {
      'franchise_id': 'f-ct',
      'name': 'Ron',
      'phone': '555-0199',
      'is_field_tech': true,
      'area_ids': ['a-south'],
    });
  });

  testWidgets('editing PATCHes, and area_ids is the whole new set', (
    tester,
  ) async {
    final writes = <(String, String, Map<String, dynamic>)>[];
    await _pump(tester, writes: writes);

    await tester.tap(find.text('Ivan Sharkov'));
    await tester.pumpAndSettle();
    // North off, South on.
    await tester.tap(find.byKey(const ValueKey('roster-area:a-north')));
    await tester.tap(find.byKey(const ValueKey('roster-area:a-south')));
    await tester.pump();
    await tester.tap(find.byKey(const ValueKey('roster-save')));
    await tester.pumpAndSettle();

    expect(writes.single.$1, 'PATCH');
    expect(writes.single.$2, '/api/v1/scheduling/roster/t-1');
    expect(writes.single.$3['area_ids'], ['a-south']);
    expect(writes.single.$3['is_active'], true);
    expect(writes.single.$3['user_id'], 'u-2');
  });

  testWidgets('the Active switch retires a technician', (tester) async {
    final writes = <(String, String, Map<String, dynamic>)>[];
    await _pump(tester, writes: writes);

    await tester.tap(find.text('Ivan Sharkov'));
    await tester.pumpAndSettle();
    await tester.ensureVisible(find.byKey(const ValueKey('roster-active')));
    await tester.tap(find.byKey(const ValueKey('roster-active')));
    await tester.pump();
    await tester.tap(find.byKey(const ValueKey('roster-save')));
    await tester.pumpAndSettle();

    expect(writes.single.$1, 'PATCH');
    expect(writes.single.$3['is_active'], false);
  });

  testWidgets('without scheduling.edit there is nothing to change', (
    tester,
  ) async {
    final gets = <Uri>[];
    await _pump(tester, gets: gets, canEdit: false);

    expect(find.text('Ivan Sharkov'), findsOneWidget);
    expect(find.byKey(const ValueKey('roster-add')), findsNothing);

    await tester.tap(find.text('Ivan Sharkov'));
    await tester.pumpAndSettle();

    expect(find.byKey(const ValueKey('roster-save')), findsNothing);
    expect(
      gets.map((u) => u.path),
      isNot(contains('/api/v1/scheduling/areas')),
    );
  });

  testWidgets('a phone draws cards and the editor full screen, no overflow', (
    tester,
  ) async {
    final errors = <FlutterErrorDetails>[];
    final previous = FlutterError.onError;
    FlutterError.onError = errors.add;
    addTearDown(() => FlutterError.onError = previous);

    await _pump(tester, size: const Size(390, 844));

    expect(find.byType(Card), findsNWidgets(2));
    // Mobile review 2026-11-13, P9: every card is the full row, not shrunk
    // to its own text and centred.
    expect(
      {
        for (final c in tester.widgetList<Card>(find.byType(Card)))
          tester.getSize(find.byWidget(c)).width,
      },
      hasLength(1),
    );
    expect(find.text('Linked to Dana Diaz'), findsOneWidget);

    await tester.tap(find.text('Ivan Sharkov'));
    await tester.pumpAndSettle();

    expect(find.byTooltip('Back'), findsOneWidget);
    expect(find.byKey(const ValueKey('roster-save')), findsOneWidget);
    // The linked login comes from the roster response's users.
    expect(find.text('Dana Diaz'), findsOneWidget);
    expect(tester.getSize(find.byType(RosterEditor)).width, 390);

    await tester.tap(find.byTooltip('Back'));
    await tester.pumpAndSettle();
    expect(find.byType(Card), findsNWidgets(2));
    expect(
      errors,
      isEmpty,
      reason: errors.map((e) => e.exceptionAsString()).join(' | '),
    );
  });
}

final _rows = [
  {
    'id': 't-1',
    'franchise_id': 'f-ct',
    'name': 'Ivan Sharkov',
    'email': 'ivan@example.com',
    'phone': '555-0101',
    'is_field_tech': true,
    'is_active': true,
    'user': {'id': 'u-2', 'name': 'Dana Diaz', 'email': 'dana@example.com'},
    'areas': [
      {'id': 'a-north', 'name': 'North'},
    ],
    'in_workiz': true,
  },
  {
    'id': 't-2',
    'franchise_id': 'f-tx',
    'name': 'Harry Jenkins',
    'email': null,
    'phone': null,
    'is_field_tech': false,
    'is_active': true,
    'user': null,
    'areas': <dynamic>[],
    'in_workiz': false,
  },
];

Future<void> _pump(
  WidgetTester tester, {
  List<Uri>? gets,
  List<(String, String, Map<String, dynamic>)>? writes,
  bool canEdit = true,
  Size size = const Size(1400, 1000),
}) async {
  tester.view.physicalSize = size;
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(
    client: MockClient((request) async {
      final path = request.url.path;
      if (path.endsWith('/me')) {
        return _json({
          'data': {
            'id': 'u-1',
            'name': 'Jose Silva',
            'email': 'jose@example.com',
            'has_avatar': false,
            'role': {'id': 'role-1', 'name': 'Administrator'},
            'permissions': [
              'scheduling.view',
              'scheduling.settings.view',
              if (canEdit) 'scheduling.edit',
            ],
            'modules': <dynamic>[],
            'preferences': <String, dynamic>{},
            'organization': {
              'id': 'org-1',
              'name': 'Everdoors',
              'franchise_label': 'Franchise',
              'is_read_only': false,
            },
            'scope': {
              'type': 'organization',
              'id': 'org-1',
              'label': 'Everdoors',
              'path': ['Everdoors'],
            },
            'companies': [
              {'id': 'c-1', 'name': 'Everdoors'},
            ],
            'franchises': [
              {'id': 'f-ct', 'name': 'Connecticut', 'company_id': 'c-1'},
              {'id': 'f-tx', 'name': 'Texas', 'company_id': 'c-1'},
            ],
          },
        });
      }
      if (request.method != 'GET') {
        writes?.add((
          request.method,
          path,
          jsonDecode(request.body) as Map<String, dynamic>,
        ));
        return _json({
          'data': _rows.first,
        }, request.method == 'POST' ? 201 : 200);
      }
      if (path.endsWith('/scheduling/roster')) {
        gets?.add(request.url);
        final branch = request.url.queryParameters['franchise_id'];
        return _json({
          'data': [
            for (final r in _rows)
              if (branch == null || r['franchise_id'] == branch) r,
          ],
          'users': [
            {'id': 'u-2', 'name': 'Dana Diaz', 'email': 'dana@example.com'},
          ],
        });
      }
      gets?.add(request.url);
      if (path.endsWith('/scheduling/areas')) {
        return _json({
          'data': [
            {'id': 'a-north', 'name': 'North', 'franchise_id': 'f-ct'},
            {'id': 'a-south', 'name': 'South', 'franchise_id': 'f-ct'},
            {'id': 'a-dallas', 'name': 'Dallas', 'franchise_id': 'f-tx'},
          ],
        });
      }
      return _json({'data': <dynamic>[]});
    }),
  );
  final session = Session(api);
  await tester.runAsync(() => session.adopt('tok'));

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(
        body: TechnicianRoster(api: api, session: session, visible: true),
      ),
    ),
  );
  await tester.pumpAndSettle();
}

http.Response _json(Object body, [int status = 200]) => http.Response(
  jsonEncode(body),
  status,
  headers: {'content-type': 'application/json'},
);
