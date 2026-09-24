import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/reports/experimental_call_report.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// The four changes Jose asked for on 2026-09-17: a header that stays put,
/// sortable columns, a row that opens onto its calls, and a conversion column.
///
/// The screen had no widget test at all before this, which is why the rewrite
/// that added them starts here rather than ending here.
void main() {
  // ── the conversion column ──────────────────────────────────────

  testWidgets('conversion is jobs over new-client calls, as a percent', (
    tester,
  ) async {
    await _pump(tester, width: 1400);

    // Ada: 4 new-client calls, 1 job.
    expect(find.text('25%'), findsOneWidget);

    // Bo: 2 new-client calls, 2 jobs.
    expect(find.text('100%'), findsOneWidget);
  });

  testWidgets('somebody who answered no new-client calls gets a dash, not 0%', (
    tester,
  ) async {
    await _pump(tester, width: 1400);

    // Cy answered nothing and booked nothing. 0% would read as a verdict on
    // somebody who was never given the chance, and 0/0 is not zero.
    expect(find.text('0%'), findsNothing);
    expect(find.text('—'), findsWidgets);
  });

  testWidgets('the column says what it does not claim', (tester) async {
    await _pump(tester, width: 1400);

    // The word "Conversion" beside a name will be read as a verdict, so the
    // narrower true reading is on the screen rather than in a docblock.
    expect(
      find.textContaining('It is not the share of a person\'s calls'),
      findsOneWidget,
    );
  });

  // ── sorting ────────────────────────────────────────────────────

  testWidgets('the table opens on answered, most first', (tester) async {
    await _pump(tester, width: 1400);

    // Bo 9, Ada 6, Cy 0 — not alphabetical, which is what the server sends.
    expect(_names(tester), ['Bo', 'Ada', 'Cy']);
  });

  testWidgets('tapping a column sorts by it, and again reverses it', (
    tester,
  ) async {
    await _pump(tester, width: 1400);

    await tester.tap(_header('Jobs'));
    await tester.pumpAndSettle();

    // Bo 2, Ada 1, Cy 0.
    expect(_names(tester), ['Bo', 'Ada', 'Cy']);

    await tester.tap(_header('Jobs'));
    await tester.pumpAndSettle();

    expect(_names(tester), ['Cy', 'Ada', 'Bo']);
  });

  testWidgets('a person with no average talk sorts last both ways', (
    tester,
  ) async {
    await _pump(tester, width: 1400);

    await tester.tap(_header('Avg talk'));
    await tester.pumpAndSettle();
    expect(_names(tester).last, 'Cy');

    // The interesting half: reversing must not float the person with NO value
    // to the top, where a dash would read as the longest call of the day.
    await tester.tap(_header('Avg talk'));
    await tester.pumpAndSettle();
    expect(_names(tester).last, 'Cy');
  });

  testWidgets('only the column in force carries an arrow', (tester) async {
    await _pump(tester, width: 1400);

    expect(
      find.descendant(
        of: find.byType(InkWell),
        matching: find.byIcon(Icons.arrow_downward),
      ),
      findsOneWidget,
    );
  });

  // ── the drill-down ─────────────────────────────────────────────

  testWidgets('a row opens onto the calls behind its numbers', (tester) async {
    await _pump(tester, width: 1400);

    expect(find.textContaining('Kitchen tap'), findsNothing);

    await tester.tap(find.text('Ada'));
    await tester.pumpAndSettle();

    expect(find.textContaining('Kitchen tap'), findsOneWidget);
  });

  testWidgets('a call that rang somebody but was taken by another says so', (
    tester,
  ) async {
    await _pump(tester, width: 1400);

    // The call rang Ada and Bo; Bo picked it up. It has to appear under Ada —
    // her Rang column counts it — saying in WORDS who took it, with its talk
    // time. Jose, 2026-09-24: the icons were unreadable and the rows that
    // said "rang" read as a missing time.
    // Bo's own avg-talk cell already reads 5:00, so the call's is counted
    // as the one that APPEARS.
    final before = find.text('5:00').evaluate().length;

    await tester.tap(find.text('Ada'));
    await tester.pumpAndSettle();

    expect(find.textContaining('Blocked drain'), findsOneWidget);
    expect(find.text('Rang · Bo answered'), findsOneWidget);
    expect(find.text('5:00'), findsNWidgets(before + 1));
  });

  // ── the Reached nobody card ────────────────────────────────────

  testWidgets('Reached nobody opens onto its calls, narrowed by reason', (
    tester,
  ) async {
    await _pump(tester, width: 1400);

    expect(find.textContaining('Burst pipe'), findsNothing);

    await tester.tap(find.byKey(const ValueKey('stat-reached-nobody')));
    await tester.pumpAndSettle();

    // Both reasons, counted, and neither answered call.
    expect(find.text('Missed call (1)'), findsOneWidget);
    expect(find.text('Spam call (1)'), findsOneWidget);
    expect(find.textContaining('Burst pipe'), findsOneWidget);
    expect(find.textContaining('Silent line'), findsOneWidget);
    expect(find.textContaining('Kitchen tap'), findsNothing);

    await tester.tap(find.byKey(const ValueKey('nobody-spam')));
    await tester.pumpAndSettle();

    expect(find.textContaining('Silent line'), findsOneWidget);
    expect(find.textContaining('Burst pipe'), findsNothing);
  });

  testWidgets('only one person is open at a time', (tester) async {
    await _pump(tester, width: 1400);

    await tester.tap(find.text('Ada'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Bo'));
    await tester.pumpAndSettle();

    // Ada's own call is gone; Bo's is showing.
    expect(find.textContaining('Kitchen tap'), findsNothing);
    expect(find.textContaining('Blocked drain'), findsOneWidget);
  });

  testWidgets('a call with no recording offers no player', (tester) async {
    await _pump(tester, width: 1400);

    await tester.tap(find.text('Ada'));
    await tester.pumpAndSettle();

    // Ada has two calls and one recording between them. A missing recording is
    // an ordinary state, not a failure, and must not draw a dead transport.
    expect(find.byIcon(Icons.play_arrow), findsOneWidget);
  });

  // ── the sticky header ──────────────────────────────────────────

  testWidgets('the column header is pinned rather than scrolled away', (
    tester,
  ) async {
    await _pump(tester, width: 1400);

    final header = tester.widget<SliverPersistentHeader>(
      find.byType(SliverPersistentHeader),
    );

    expect(header.pinned, isTrue);
  });

  testWidgets('a narrow screen draws cards and still opens the calls', (
    tester,
  ) async {
    await _pump(tester, width: 375);

    expect(find.byType(SliverPersistentHeader), findsNothing);

    // Ada's card specifically: the cards are sorted by answered, so `.first`
    // would open Bo and the assertion below would be about the wrong person.
    await tester.tap(
      find.descendant(
        of: find.ancestor(of: find.text('Ada'), matching: find.byType(Card)),
        matching: find.text('Show calls'),
      ),
    );
    await tester.pumpAndSettle();

    expect(find.textContaining('Kitchen tap'), findsOneWidget);
  });
}

/// A column header, and not the summary stat that shares its wording.
///
/// `find.text('Avg talk')` matches twice: the totals strip above the table
/// carries the same phrase. Tapping by text alone is ambiguous and fails with
/// a wall of style dumps that says nothing about the cause.
Finder _header(String label) => find.descendant(
  of: find.byType(SliverPersistentHeader),
  matching: find.text(label),
);

/// The people, in the order the table is currently drawing them.
List<String> _names(WidgetTester tester) => tester
    .widgetList<Text>(find.byType(Text))
    .map((text) => text.data)
    .whereType<String>()
    .where((data) => const ['Ada', 'Bo', 'Cy'].contains(data))
    .toList();

Future<void> _pump(WidgetTester tester, {required double width}) async {
  tester.view.physicalSize = Size(width, 2400);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(
    client: MockClient((request) async {
      if (request.url.path.endsWith('/reports/experimental/calls')) {
        return _json(_report, 200);
      }

      return _json({'message': 'Nothing stubbed.'}, 500);
    }),
  );

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(body: ExperimentalCallReport(api: api)),
    ),
  );
  await tester.pumpAndSettle();
}

http.Response _json(Object body, [int status = 200]) => http.Response(
  jsonEncode(body),
  status,
  headers: const {'content-type': 'application/json'},
);

/// Three people chosen so every rule has a case that can distinguish it:
/// Ada converts at 25%, Bo at 100%, and Cy has no new-client calls at all so
/// no rate exists for him — which is the case a 0 would quietly answer wrongly.
const _report = {
  'connected': true,
  'timezone': 'America/New_York',
  'day': '2026-09-16',
  'workiz_accounts': [],
  'workiz_failed': {},
  'workiz_unreachable': [],
  'totals': {
    'calls': 3,
    'answered': 3,
    'reached_nobody': 0,
    'new_client': 6,
    'existing_client': 0,
    'jobs': 3,
    'avg_talk_seconds': 120,
  },
  'people': [
    {
      'person': 'Ada',
      'rang': 7,
      'answered': 6,
      'new_client': 4,
      'existing_client': 1,
      'jobs': 1,
      'avg_talk_seconds': 100,
      'in_calls': true,
      'in_jobs': true,
    },
    {
      'person': 'Bo',
      'rang': 10,
      'answered': 9,
      'new_client': 2,
      'existing_client': 0,
      'jobs': 2,
      'avg_talk_seconds': 300,
      'in_calls': true,
      'in_jobs': true,
    },
    {
      'person': 'Cy',
      'rang': 0,
      'answered': 0,
      'new_client': 0,
      'existing_client': 0,
      'jobs': 0,
      'avg_talk_seconds': null,
      'in_calls': false,
      'in_jobs': true,
    },
  ],
  'calls': [
    {
      'id': 'c1',
      'started_at': '2026-09-16T14:05:00Z',
      'from': '+15550001111',
      'caller_label': 'Kitchen tap',
      'queue': 'New Client',
      'result': 'Accepted',
      'talk_seconds': 100,
      'recording_id': 'rec-1',
      'answered_by': 'Ada',
      'rang': ['Ada'],
      'outcome': 'answered',
    },
    {
      'id': 'c2',
      'started_at': '2026-09-16T15:30:00Z',
      'from': '+15550002222',
      'caller_label': 'Blocked drain',
      'queue': 'New Client',
      'result': 'Accepted',
      'talk_seconds': 300,
      'recording_id': null,
      'answered_by': 'Bo',
      'rang': ['Ada', 'Bo'],
      'outcome': 'answered',
    },
    {
      'id': 'c3',
      'started_at': '2026-09-16T16:00:00Z',
      'from': '+15550003333',
      'caller_label': 'Burst pipe',
      'queue': 'New Client',
      'result': 'Missed',
      'talk_seconds': null,
      'recording_id': null,
      'answered_by': null,
      'rang': [],
      'outcome': 'missed',
    },
    {
      'id': 'c4',
      'started_at': '2026-09-16T16:10:00Z',
      'from': '+15550004444',
      'caller_label': 'Silent line',
      'queue': null,
      'result': 'Accepted',
      'talk_seconds': null,
      'recording_id': null,
      'answered_by': null,
      'rang': [],
      'outcome': 'spam',
    },
  ],
};
