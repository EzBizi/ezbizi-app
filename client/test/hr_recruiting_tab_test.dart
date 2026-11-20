import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/hr/recruiting_tab.dart';
import 'package:ezbizi_client/widgets/search_picker.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// HR › Recruiting (UI queue 201, options 1 and 4; Shoval 2026-11-20).
/// Every name invented.
void main() {
  testWidgets('applicants with the server\'s labels; a filter goes to the '
      'server; the chips show only what the server counts', (tester) async {
    final s = _Server();
    await _pump(tester, s);

    expect(find.text('Marco Diaz'), findsOneWidget);
    expect(find.text('Field technician'), findsWidgets, reason: 'the label');
    expect(find.text('Interview'), findsOneWidget);
    expect(find.text('40 min'), findsOneWidget);
    expect(find.byKey(const ValueKey('applicant-relevant-a1')), findsOneWidget);
    expect(
      find.byKey(const ValueKey('recruiting-count-total')),
      findsOneWidget,
    );
    // Not sent by the server: not drawn, not counted from one page.
    expect(find.byKey(const ValueKey('recruiting-count-hired')), findsNothing);

    tester
        .widget<SearchPickerField<String?>>(
          find.byKey(const ValueKey('recruiting-filter-position')),
        )
        .onChanged!('tech');
    await tester.pumpAndSettle();
    expect(s.lists.last['position'], 'tech');
  });

  testWidgets('a click docks the applicant; Relevant, stage and an '
      'interview are saved', (tester) async {
    final s = _Server();
    await _pump(tester, s);

    await tester.tap(find.text('Kayla Brooks'));
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('applicant-panel-a2')), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('applicant-relevant')));
    await tester.pumpAndSettle();
    expect(s.writes.last.$2, '/hr/applicants/a2');
    expect(s.writes.last.$3, {'relevant': true});

    tester
        .widget<SearchPickerField<String>>(
          find.byKey(const ValueKey('applicant-stage')),
        )
        .onChanged!('interview');
    await tester.pumpAndSettle();
    expect(s.writes.last.$3, {'stage': 'interview'});

    await tester.tap(find.byKey(const Key('applicant-add-interview')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('In person'));
    await tester.enterText(
      find.descendant(
        of: find.byKey(const Key('interview-when')),
        matching: find.byType(TextField),
      ),
      '2026-11-24 10:00',
    );
    await tester.tap(find.byKey(const Key('interview-save')));
    await tester.pumpAndSettle();
    expect(s.writes.last.$1, 'POST');
    expect(s.writes.last.$2, '/hr/applicants/a2/interviews');
    expect((s.writes.last.$3 as Map)['kind'], 'in_person');
  });

  testWidgets('without hr.edit nothing writes: no Add, no switch', (
    tester,
  ) async {
    final s = _Server();
    await _pump(tester, s, edit: false);
    expect(find.byKey(const Key('recruiting-new')), findsNothing);
    await tester.tap(find.text('Kayla Brooks'));
    await tester.pumpAndSettle();
    expect(
      tester
          .widget<SwitchListTile>(
            find.byKey(const ValueKey('applicant-relevant')),
          )
          .onChanged,
      isNull,
    );
    expect(find.byKey(const Key('applicant-add-interview')), findsNothing);
  });

  testWidgets('Spend: a month typed into a cell is saved', (tester) async {
    final s = _Server();
    await _pump(tester, s);
    await tester.tap(find.text('Spend'));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('spend-table')), findsOneWidget);
    expect(find.byKey(const ValueKey('spend-total-tech')), findsOneWidget);

    final now = DateTime.now();
    final month = '${now.year}-${now.month.toString().padLeft(2, '0')}';
    final cell = find.descendant(
      of: find.byKey(ValueKey('spend-dispatcher-$month')),
      matching: find.byType(TextField),
    );
    await tester.enterText(cell, '250');
    await tester.testTextInput.receiveAction(TextInputAction.done);
    await tester.pumpAndSettle();
    expect(s.writes.last.$2, '/hr/recruiting-spend');
    expect(s.writes.last.$3, {
      'month': month,
      'position': 'dispatcher',
      'amount': 250,
    });
  });

  testWidgets('on a 390 phone: cards, Filters folded, nothing overflows', (
    tester,
  ) async {
    final s = _Server();
    await _pump(tester, s, size: const Size(390, 844));
    expect(tester.takeException(), isNull);
    expect(find.byType(Card), findsWidgets);
    expect(find.byKey(const Key('recruiting-filters')), findsOneWidget);
  });
}

class _Server {
  final lists = <Map<String, String>>[];
  final writes = <(String, String, Object?)>[];
  bool relevant2 = false;
  String stage2 = 'new';

  Map<String, dynamic> applicant(String id) => id == 'a1'
      ? {
          'id': 'a1',
          'name': 'Marco Diaz',
          'phone': '(614) 555-0142',
          'email': null,
          'position': 'tech',
          'source': 'indeed',
          'stage': 'interview',
          'relevant': true,
          'franchise_id': null,
          'applied_at': '2026-11-18T14:00:00Z',
          'first_response_at': '2026-11-18T14:40:00Z',
          'interviews_count': 1,
          'notes': null,
        }
      : {
          'id': 'a2',
          'name': 'Kayla Brooks',
          'phone': null,
          'email': 'kayla@example.test',
          'position': 'dispatcher',
          'source': 'facebook',
          'stage': stage2,
          'relevant': relevant2,
          'franchise_id': null,
          'applied_at': '2026-11-19T14:00:00Z',
          'first_response_at': null,
          'interviews_count': 0,
          'notes': null,
        };

  Future<http.Response> handle(http.Request r) async {
    final path = r.url.path.replaceFirst('/api/v1', '');
    if (r.method != 'GET') {
      final body = r.body.isEmpty ? null : jsonDecode(r.body);
      writes.add((r.method, path, body));
      if (path == '/hr/applicants/a2' && body is Map) {
        if (body['relevant'] is bool) relevant2 = body['relevant'] as bool;
        if (body['stage'] is String) stage2 = body['stage'] as String;
      }
      return _json({
        'data': <String, dynamic>{'id': 'x'},
      });
    }
    if (path == '/hr/applicants') {
      lists.add(r.url.queryParameters);
      return _json({
        'data': [applicant('a1'), applicant('a2')],
        'meta': {'total': 2, 'last_page': 1, 'page': 1},
        'lists': {
          'positions': [
            {'value': 'tech', 'label': 'Field technician'},
            {'value': 'dispatcher', 'label': 'Dispatcher'},
          ],
          'stages': [
            {'value': 'new', 'label': 'New'},
            {'value': 'interview', 'label': 'Interview'},
            {'value': 'hired', 'label': 'Hired'},
          ],
          'sources': [
            {'value': 'indeed', 'label': 'Indeed'},
            {'value': 'facebook', 'label': 'Facebook'},
          ],
        },
      });
    }
    if (path.startsWith('/hr/applicants/')) {
      final id = path.split('/').last;
      return _json({
        'data': {...applicant(id), 'interviews': <dynamic>[]},
      });
    }
    if (path == '/hr/recruiting-spend') {
      return _json({
        'data': [
          {
            'id': 's1',
            'month': _thisMonth(),
            'position': 'tech',
            'source': null,
            'amount': 1100,
            'franchise_id': null,
            'note': null,
          },
        ],
      });
    }
    return _json({'data': <dynamic>[]});
  }
}

String _thisMonth() {
  final now = DateTime.now();
  return '${now.year}-${now.month.toString().padLeft(2, '0')}';
}

Future<void> _pump(
  WidgetTester tester,
  _Server s, {
  bool edit = true,
  Size size = const Size(1400, 900),
}) async {
  tester.view.physicalSize = size;
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);
  final api = ApiClient(
    client: MockClient((r) async {
      if (r.url.path.endsWith('/me')) {
        return _json({
          'data': {
            'id': 'u-1',
            'name': 'Dana Reyes',
            'email': 'dana@example.test',
            'permissions': ['hr.view', if (edit) 'hr.edit'],
            'modules': <dynamic>[],
            'preferences': <String, dynamic>{},
            'organization': {'id': 'org-1', 'name': 'Northside'},
            'companies': <dynamic>[],
            'franchises': <dynamic>[],
          },
        });
      }
      return s.handle(r);
    }),
  );
  final session = Session(api);
  await tester.runAsync(() => session.adopt('tok'));
  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(
        body: RecruitingTab(api: api, session: session),
      ),
    ),
  );
  await tester.pumpAndSettle();
}

http.Response _json(Object body) => http.Response(
  jsonEncode(body),
  200,
  headers: const {'content-type': 'application/json'},
);
