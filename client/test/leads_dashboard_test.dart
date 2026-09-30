import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/widgets/leads_dashboard.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// UI queue 38, Jose's 7 and 10 (2026-09-30): "a new tab called Dashboard
/// which gathers info, reply rates from each user, response time, amounts of
/// leads, graphs". Everything here is invented.
void main() {
  testWidgets('the six numbers, the graphs and a row per person, fastest '
      'first reply first', (tester) async {
    final asked = await _pump(tester);

    expect(asked.single['period'], '7d', reason: 'it opens on the last 7 days');

    String tile(String key) => [
      for (final t in tester.widgetList<Text>(
        find.descendant(
          of: find.byKey(ValueKey('tile-$key')),
          matching: find.byType(Text),
        ),
      ))
        t.data,
    ].join(' | ');
    expect(tile('leads'), 'LEADS | 96 | +20% vs the 7 days before');
    expect(tile('answered'), contains('90%'));
    expect(tile('first-reply'), 'FIRST REPLY | 6 min | median · was 9 min');
    expect(tile('within-10'), contains('75%'));
    expect(tile('booked'), 'BOOKED | 25% | 24 leads became jobs');
    expect(tile('waiting'), contains('3'));

    expect(find.byKey(const ValueKey('speed-under_5m')), findsOneWidget);
    expect(
      tester.widget<Text>(find.byKey(const ValueKey('speed-under_5m'))).data,
      '40%',
    );

    final rows = tester.widgetList(
      find.byWidgetPredicate(
        (w) =>
            w.key is ValueKey &&
            '${(w.key as ValueKey).value}'.startsWith('person-'),
      ),
    );
    expect(
      [for (final r in rows) '${(r.key as ValueKey).value}'],
      ['person-u-maya', 'person-u-luis', 'person-u-tom'],
    );
    expect(find.byKey(const ValueKey('people-automatic')), findsOneWidget);
    expect(find.byKey(const ValueKey('people-outside')), findsOneWidget);
    expect(find.byKey(const ValueKey('dashboard-unnamed')), findsNothing);
    expect(tester.takeException(), isNull);
  });

  testWidgets('a column header sorts the people by it', (tester) async {
    await _pump(tester);

    await tester.tap(find.byKey(const ValueKey('people-sort-replies')));
    await tester.pump();

    final rows = tester.widgetList(
      find.byWidgetPredicate(
        (w) =>
            w.key is ValueKey &&
            '${(w.key as ValueKey).value}'.startsWith('person-'),
      ),
    );
    expect(
      [for (final r in rows) '${(r.key as ValueKey).value}'],
      ['person-u-luis', 'person-u-maya', 'person-u-tom'],
    );
  });

  testWidgets('a period is asked for; Custom waits for both days', (
    tester,
  ) async {
    final asked = await _pump(tester);

    await tester.tap(find.byKey(const ValueKey('dashboard-period-30d')));
    await tester.pumpAndSettle();
    expect(asked.last['period'], '30d');

    await tester.tap(find.byKey(const ValueKey('dashboard-period-custom')));
    await tester.pumpAndSettle();
    expect(asked, hasLength(2), reason: 'no range yet, so nothing is asked');
    expect(find.byKey(const ValueKey('dashboard-from')), findsOneWidget);
  });

  /// Until 2026-09-30 nearly every reply went out from A1 Tools or Google's
  /// own app (261 of 270 to Sep 28): the table says so rather than looking
  /// like nobody works.
  testWidgets('a range mostly answered outside EzBizi says so', (tester) async {
    await _pump(tester, outsideAnswered: 400);

    expect(find.byKey(const ValueKey('dashboard-unnamed')), findsOneWidget);
  });

  testWidgets('on a phone the tiles pair up and the people are a list', (
    tester,
  ) async {
    await _pump(tester, size: const Size(390, 900));

    final leads = tester.getRect(find.byKey(const ValueKey('tile-leads')));
    final answered = tester.getRect(
      find.byKey(const ValueKey('tile-answered')),
    );
    expect(leads.top, answered.top);
    expect(leads.height, answered.height, reason: 'sibling tiles, one height');

    await tester.scrollUntilVisible(
      find.byKey(const ValueKey('person-u-maya')),
      300,
    );
    expect(
      find.ancestor(
        of: find.text('Maya Chen'),
        matching: find.byType(ListTile),
      ),
      findsOneWidget,
    );
    expect(tester.takeException(), isNull);
  });

  testWidgets('a refusal is said, not drawn as zeros', (tester) async {
    await _pump(tester, status: 403);

    expect(find.byKey(const ValueKey('dashboard-error')), findsOneWidget);
    expect(find.byKey(const ValueKey('tile-leads')), findsNothing);
  });

  testWidgets('before the numbers exist on the server it says so', (
    tester,
  ) async {
    await _pump(tester, status: 404);

    expect(
      find.text('The dashboard numbers are not available yet.'),
      findsOneWidget,
    );
  });

  test('times read the way people say them', () {
    expect(replyTime(null), '—');
    expect(replyTime(45), '45 sec');
    expect(replyTime(360), '6 min');
    expect(replyTime(4800), '1 h 20 min');
    expect(replyTime(7200), '2 h');
    expect(percentOf(3, 4), 75);
    expect(percentOf(3, 0), isNull);
    expect(dashboardSourceWord('local_services', 'MESSAGE'), 'LS Text');
    expect(dashboardSourceWord('thumbtack', null), 'Thumbtack');
  });
}

Map<String, dynamic> _data({int outsideAnswered = 9}) => {
  'from': '2026-09-24',
  'to': '2026-09-30',
  'totals': {
    'leads': 96,
    'answerable': 80,
    'answered': 72,
    'first_reply_median_seconds': 360,
    'within_10_min': 54,
    'booked': 24,
    'waiting_now': 3,
  },
  'previous': {
    'leads': 80,
    'answerable': 70,
    'answered': 60,
    'first_reply_median_seconds': 540,
    'within_10_min': 40,
    'booked': 18,
  },
  'per_day': [
    for (var d = 24; d <= 30; d++)
      {
        'date': '2026-09-$d',
        'sources': [
          {'source': 'local_services', 'lead_type': 'PHONE_CALL', 'count': 5},
          {'source': 'local_services', 'lead_type': 'MESSAGE', 'count': 4},
          {'source': 'thumbtack', 'lead_type': null, 'count': d % 3},
        ],
      },
  ],
  'first_reply_buckets': {
    'under_5m': 28,
    'm5_10': 20,
    'm10_30': 12,
    'm30_60': 6,
    'h1_4': 4,
    'over_4h': 0,
  },
  'people': [
    {
      'user': {'id': 'u-tom', 'name': 'Tom Becker'},
      'replies': 20,
      'leads_answered': 10,
      'first_reply_median_seconds': 720,
      'within_10_min': 5,
      'booked': 2,
    },
    {
      'user': {'id': 'u-maya', 'name': 'Maya Chen'},
      'replies': 40,
      'leads_answered': 30,
      'first_reply_median_seconds': 180,
      'within_10_min': 27,
      'booked': 10,
    },
    {
      'user': {'id': 'u-luis', 'name': 'Luis Ortega'},
      'replies': 55,
      'leads_answered': 23,
      'first_reply_median_seconds': 300,
      'within_10_min': 18,
      'booked': 8,
    },
  ],
  'automatic': {'replies': 60, 'leads': 60},
  'outside': {
    'replies': 14,
    'leads_answered': outsideAnswered,
    'first_reply_median_seconds': 600,
  },
};

Future<List<Map<String, String>>> _pump(
  WidgetTester tester, {
  Size size = const Size(1400, 1100),
  int outsideAnswered = 9,
  int status = 200,
}) async {
  tester.view.physicalSize = size;
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final asked = <Map<String, String>>[];
  final api = ApiClient(
    client: MockClient((request) async {
      if (request.url.path.endsWith('/me')) {
        return _json({'data': _me});
      }
      if (request.url.path.endsWith('/leads/dashboard')) {
        asked.add(request.url.queryParameters);
        return status == 200
            ? _json({'data': _data(outsideAnswered: outsideAnswered)})
            : _json({'message': 'You cannot see the leads dashboard.'}, status);
      }
      return _json({
        'message': 'Nothing stubbed for ${request.url.path}.',
      }, 500);
    }),
  );
  final session = Session(api);
  await tester.runAsync(() => session.adopt('tok'));

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(
        body: LeadsDashboard(api: api, session: session),
      ),
    ),
  );
  await tester.pumpAndSettle();
  return asked;
}

http.Response _json(Object body, [int status = 200]) => http.Response(
  jsonEncode(body),
  status,
  headers: const {'content-type': 'application/json'},
);

const _me = {
  'id': 'u-1',
  'name': 'Jose',
  'email': 'owner@example.com',
  'role': {'name': 'Administrator'},
  'has_avatar': false,
  'preferences': <String, dynamic>{},
  'permissions': ['leads.view', 'leads.edit'],
  'organization': {
    'id': 'org-1',
    'name': 'Harbor Group',
    'franchise_label': 'Franchise',
    'is_read_only': false,
  },
  'scope': {
    'type': 'organization',
    'id': 'org-1',
    'label': 'Harbor Group',
    'path': ['Harbor Group'],
  },
  'companies': [
    {'id': 'co-1', 'name': 'Harbor Chimney', 'parent_company_id': null},
    {'id': 'co-2', 'name': 'Harbor Garage Doors', 'parent_company_id': null},
  ],
  'franchises': <Map<String, Object?>>[],
};
