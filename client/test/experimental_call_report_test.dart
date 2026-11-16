import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/core/timestamps.dart';
import 'package:ezbizi_client/screens/phone/recording_playback_bar.dart';
import 'package:ezbizi_client/screens/reports/experimental_call_report.dart';
import 'package:ezbizi_client/widgets/ai_mark.dart';
import 'package:ezbizi_client/widgets/side_panel.dart';
import 'package:ezbizi_client/widgets/status_pill.dart';
import 'package:flutter/material.dart';
import 'package:flutter/rendering.dart';
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

  // Design 170, option 8 (Jose, 2026-11-03): a booking is credited to who
  // answered the call, never to everyone it rang.
  // Trey, 2026-11-04: only new-client calls are bookings to be had.
  testWidgets('booking rate is booked out of answered new-client calls', (
    tester,
  ) async {
    await _pump(tester, width: 1400, view: 'People');

    // Bo answered 9, one of them new, and booked it: 1 of 1, not 1 of 9.
    expect(find.text('11%'), findsNothing);
    expect(find.text('100%'), findsOneWidget);
    // Ada answered one new call and did not book it; Bo's booking is Bo's.
    // (The other 0% is Cy's answer rate: rung twice, answered none.)
    expect(find.text('0%'), findsNWidgets(2));
  });

  // Jose, 2026-10-30 (design A1): the counts say what they count and add up.
  testWidgets(
    'the Client chips add up to All, and say which they count within',
    (tester) async {
      final mixed = jsonDecode(jsonEncode(_report)) as Map<String, dynamic>;
      (mixed['calls'] as List).add({
        ...((mixed['calls'] as List).first as Map),
        'id': 'x1',
        'queue': 'Somebody - Ext 101',
      });
      await _pump(tester, width: 1400, view: 'Calls', report: () => mixed);

      int count(String key) {
        final chip = tester.widget<ChoiceChip>(
          find.byKey(ValueKey('calls-filter-$key')),
        );
        return int.parse(
          RegExp(r'(\d+)$').firstMatch((chip.label as Text).data!)!.group(1)!,
        );
      }

      expect(
        count('new') + count('existing') + count('other'),
        count('every-client'),
      );
      expect(count('other'), 1);

      await tester.tap(find.byKey(const ValueKey('calls-filter-existing')));
      await tester.pumpAndSettle();
      expect(
        find.byKey(const ValueKey('calls-results-within')),
        findsOneWidget,
      );
      expect(find.textContaining('Results within Existing'), findsOneWidget);
    },
  );

  // UI-UX review of 0a52fe906: the Missed tile counts what its card lists,
  // voicemail included, even when the server's total says otherwise.
  testWidgets('the Missed tile equals the Missed list, voicemail included', (
    tester,
  ) async {
    final stale = jsonDecode(jsonEncode(_report)) as Map<String, dynamic>;
    (stale['totals'] as Map)['reached_nobody'] = 0;
    await _pump(tester, width: 1400, report: () => stale);

    final tile = find.descendant(
      of: find.byKey(const ValueKey('stat-reached-nobody')),
      matching: find.text('2'),
    );
    expect(tile, findsOneWidget);
    expect(
      find.descendant(
        of: find.byKey(const ValueKey('overview-missed')),
        matching: find.text('2'),
      ),
      findsOneWidget,
    );
    expect(find.text('Nobody picked up, incl. voicemail'), findsOneWidget);
  });

  // Design B1: New | Existing | Both on every row, saved on the tap.
  testWidgets('Handles is set from the People table, Both being no tag', (
    tester,
  ) async {
    final put = <String>[];
    var gets = 0;
    final api = ApiClient(
      client: MockClient((request) async {
        if (request.url.path.endsWith('/reports/experimental/calls')) {
          gets++;
          return _json(_report, 200);
        }
        if (request.method == 'PUT') {
          put.add(request.body);
          return _json({'data': {}}, 200);
        }
        return _json({'message': 'Nothing stubbed.'}, 500);
      }),
    );
    tester.view.physicalSize = const Size(1400, 2400);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);
    await tester.pumpWidget(
      MaterialApp(
        theme: EzBiziTheme.light(),
        home: Scaffold(body: ExperimentalCallReport(api: api)),
      ),
    );
    await tester.pumpAndSettle();
    await tester.tap(find.textContaining('People ·'));
    await tester.pumpAndSettle();

    expect(find.byKey(const ValueKey('handles-Ada')), findsOneWidget);
    await tester.tap(
      find.descendant(
        of: find.byKey(const ValueKey('handles-Ada')),
        matching: find.text('Existing'),
      ),
    );
    await tester.pumpAndSettle();
    expect(put.single, contains('"tag":"existing"'));
    // Jose, 2026-11-02: only the row changes; the report is not read again.
    expect(gets, 1);
    expect(
      tester
          .widget<SegmentedButton<String>>(
            find.byKey(const ValueKey('handles-Ada')),
          )
          .selected,
      {'existing'},
    );
  });

  testWidgets('Handles is read-only without edit rights', (tester) async {
    final api = ApiClient(
      client: MockClient((request) async => _json(_report, 200)),
    );
    tester.view.physicalSize = const Size(1400, 2400);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);
    await tester.pumpWidget(
      MaterialApp(
        theme: EzBiziTheme.light(),
        home: Scaffold(body: ExperimentalCallReport(api: api, canEdit: false)),
      ),
    );
    await tester.pumpAndSettle();
    await tester.tap(find.textContaining('People ·'));
    await tester.pumpAndSettle();

    expect(
      tester.widget<Text>(find.byKey(const ValueKey('handles-Ada'))).data,
      'Both',
    );
  });

  // Jose, 2026-10-30: "would be good to have search bars as well".
  testWidgets('People and the Overview lists each have a search', (
    tester,
  ) async {
    await _pump(tester, width: 1400);

    await tester.enterText(
      find.byKey(const ValueKey('overview-not-booked-search')),
      'nobody by this name',
    );
    await tester.pumpAndSettle();
    expect(
      find.descendant(
        of: find.byKey(const ValueKey('overview-not-booked')),
        matching: find.text('No caller matches.'),
      ),
      findsOneWidget,
    );

    await tester.enterText(
      find.byKey(const ValueKey('overview-missed-search')),
      'nobody by this name',
    );
    await tester.pumpAndSettle();
    expect(
      find.descendant(
        of: find.byKey(const ValueKey('overview-missed')),
        matching: find.text('No caller matches.'),
      ),
      findsOneWidget,
    );

    await tester.tap(find.textContaining('People ·'));
    await tester.pumpAndSettle();
    await tester.enterText(find.byKey(const ValueKey('people-search')), 'bo');
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('handles-Bo')), findsOneWidget);
    expect(find.byKey(const ValueKey('handles-Ada')), findsNothing);
  });

  // UI queue 78: two columns worked out from the counts beside them.
  testWidgets('People says who was rung and did not pick up, and how often '
      'they answer', (tester) async {
    await _pump(tester, width: 1400, view: 'People');

    // Review 2026-10-30, item 6: a call a teammate took is not one missed.
    final header = find.byType(PinnedHeaderSliver);
    for (final label in ['PHONE', 'SALES', 'Not answered', 'Missed']) {
      expect(
        find.descendant(of: header, matching: find.text(label)),
        findsOneWidget,
      );
    }
    expect(
      find.byTooltip(
        'Booking rate: booked out of the new-client calls they answered.',
      ),
      findsOneWidget,
    );
  });

  // UI queue 78: Calls is a table, and a search narrows it.
  testWidgets('Calls shows each call\'s AI score, a dash when unscored', (
    tester,
  ) async {
    final scored = jsonDecode(jsonEncode(_report)) as Map<String, dynamic>;
    (scored['calls'] as List).first['score'] = {'score': 7};
    await _pump(tester, width: 1400, view: 'Calls', report: () => scored);

    expect(find.text('SCORE'), findsOneWidget);
    final c1 = find.byKey(const ValueKey('score-cell-c1'));
    // One way to write a score in Reports (UI queue 87).
    expect(tester.widget<Text>(c1).data, '7 / 10');
    expect(
      tester.widget<Text>(find.byKey(const ValueKey('score-cell-c2'))).data,
      '',
    );
    // Nobody answered c3: "Missed" where the score sits (Jose, 2026-10-14).
    expect(
      tester.widget<Text>(find.byKey(const ValueKey('score-cell-c3'))).data,
      '',
    );
  });

  // 2026-10-16: a caller who pressed the other key, re-classified by Leads.
  testWidgets('a re-classified call shows its type marked, with what was '
      'pressed', (tester) async {
    final fixed = jsonDecode(jsonEncode(_report)) as Map<String, dynamic>;
    (fixed['calls'] as List).first['reclassified'] = {
      'pressed': 'A1 - Existing Client',
      'reason': 'No customer with this number',
      'evidence': 'customer_match',
      'by_person': false,
    };
    await _pump(tester, width: 1400, view: 'Calls', report: () => fixed);

    final tip = tester.widget<Tooltip>(
      find.byKey(const ValueKey('reclassified-c1')),
    );
    expect(
      tip.message,
      'Re-classified: pressed existing · No customer with this number',
    );
    expect(find.textContaining('↺'), findsOneWidget);
  });

  testWidgets('a caller with an open earlier job reads Existing job, not '
      'Not booked', (tester) async {
    final existing = jsonDecode(jsonEncode(_report)) as Map<String, dynamic>;
    (existing['calls'] as List).first['existing_job'] = {
      'created_at': '2026-10-05 10:00:00',
      'scheduled_for': '2026-10-19 09:00:00',
      'status': 'Submitted',
    };
    await _pump(tester, width: 1400, view: 'Calls', report: () => existing);

    expect(find.text('Existing job'), findsOneWidget);
    expect(
      find.byKey(const ValueKey('calls-filter-not_booked')),
      findsOneWidget,
    );
    expect(find.text('Not booked 0'), findsOneWidget);
  });

  // Jose, 2026-11-12: Needs Attention has its place in a person's Booking
  // filter too.
  testWidgets(
    "a person's Booking filter has Needs Attention beside Existing job",
    (tester) async {
      final heard = jsonDecode(jsonEncode(_report)) as Map<String, dynamic>;
      (heard['calls'] as List).first['needs_attention'] = true;
      await _pump(tester, width: 1400, view: 'People', report: () => heard);

      await tester.tap(find.text('Ada'));
      await tester.pumpAndSettle();
      expect(find.text('Existing job 0'), findsOneWidget);
      expect(find.text('Needs Attention 1'), findsOneWidget);
      expect(find.text('Not booked 0'), findsOneWidget);
    },
  );

  testWidgets('an existing job AI read as a reschedule says Rescheduled', (
    tester,
  ) async {
    final moved = jsonDecode(jsonEncode(_report)) as Map<String, dynamic>;
    final first = (moved['calls'] as List).first as Map;
    first['existing_job'] = {'created_at': '2026-10-05 10:00:00'};
    first['labels'] = {'grok': 'reschedule'};
    await _pump(tester, width: 1400, view: 'Calls', report: () => moved);

    expect(find.text('Rescheduled'), findsOneWidget);
    expect(find.text('Existing job'), findsNothing);
  });

  testWidgets('a number in the search finds outbound calls that connected '
      'too, with their recording', (tester) async {
    final withOut = jsonDecode(jsonEncode(_report)) as Map<String, dynamic>;
    (withOut['calls'] as List).add({
      'id': 'o9',
      'direction': 'Outbound',
      'outcome': 'connected',
      'started_at': '2026-09-16T18:00:00Z',
      'from': '+19524572594',
      'placed_by': 'Ada',
      'answered_by': null,
      'recording_id': 'rec-9',
      'talk_seconds': 60,
      'rang': [],
      'booked': false,
    });
    await _pump(tester, width: 1400, view: 'Calls', report: () => withOut);

    expect(find.text('Called by Ada'), findsNothing);

    await tester.enterText(
      find.byKey(const ValueKey('calls-search')),
      '952-457-2594',
    );
    await tester.pumpAndSettle();

    expect(find.text('Called by Ada'), findsOneWidget);
    // Jose, 2026-11-11: the Call column says Answered for ours out that connected.
    expect(find.widgetWithText(StatusPill, 'Answered'), findsOneWidget);
    expect(find.byKey(const ValueKey('listen-rec-9')), findsOneWidget);
  });

  testWidgets('no Booked per AI list while no call is one', (tester) async {
    await _pump(tester, width: 1400, view: 'Calls');
    expect(
      find.byKey(const ValueKey('calls-filter-ai_booked_no_job')),
      findsNothing,
    );
  });

  // Jose, 2026-11-12: "make a new filter for the AI heard a booking, no job
  // yet, call it Needs Attention": the server's call, and not Not booked.
  testWidgets('Booked per AI with no job is its own list, only when it has '
      'calls', (tester) async {
    final aiBooked = jsonDecode(jsonEncode(_report)) as Map<String, dynamic>;
    (aiBooked['calls'] as List).first['needs_attention'] = true;
    await _pump(tester, width: 1400, view: 'Calls', report: () => aiBooked);

    await tester.tap(
      find.byKey(const ValueKey('calls-filter-ai_booked_no_job')),
    );
    await tester.pumpAndSettle();
    expect(find.text('Needs Attention 1'), findsOneWidget);
    expect(find.text('Not booked 0'), findsOneWidget);
    expect(find.textContaining('Kitchen tap'), findsOneWidget);
    expect(find.textContaining('Blocked drain'), findsNothing);
    expect(find.byTooltip('AI heard a booking, no job yet'), findsOneWidget);
  });

  // Trey, 2026-11-13: "jobs that are existing, booked and show ai heard a
  // booking no job yet are also jobs i manually have to double check".
  testWidgets('an existing client the AI heard book shows Needs Attention, '
      'in the list and in their person', (tester) async {
    final heard = jsonDecode(jsonEncode(_report)) as Map<String, dynamic>;
    final first = (heard['calls'] as List).first as Map;
    first['queue'] = 'A1 - Existing Client';
    first['needs_attention'] = true;
    await _pump(tester, width: 1400, view: 'Calls', report: () => heard);

    expect(find.widgetWithText(StatusPill, 'Needs Attention'), findsOneWidget);
    await tester.tap(
      find.byKey(const ValueKey('calls-filter-ai_booked_no_job')),
    );
    await tester.pumpAndSettle();
    expect(find.textContaining('Kitchen tap'), findsOneWidget);

    await _pump(tester, width: 1400, view: 'People', report: () => heard);
    await tester.tap(find.text('Ada'));
    await tester.pumpAndSettle();
    expect(find.text('Needs Attention 1'), findsOneWidget);
  });

  testWidgets('Calls has headings, and the search narrows the calls', (
    tester,
  ) async {
    await _pump(tester, width: 1400, view: 'Calls');

    expect(find.text('ANSWERED BY'), findsOneWidget);
    expect(find.textContaining('Burst pipe'), findsOneWidget);

    await tester.enterText(
      find.byKey(const ValueKey('calls-search')),
      'kitchen',
    );
    await tester.pumpAndSettle();
    expect(find.textContaining('Kitchen tap'), findsOneWidget);
    expect(find.textContaining('Burst pipe'), findsNothing);
  });

  testWidgets('somebody who answered no new-client calls gets a dash, not 0%', (
    tester,
  ) async {
    await _pump(tester, width: 1400, view: 'People');

    // Cy answered nothing and booked nothing. 0% would read as a verdict on
    // somebody who was never given the chance, and 0/0 is not zero.
    // Ada's booking rate and Cy's answer rate (0 of 2 rang) are the 0%s.
    expect(find.text('0%'), findsNWidgets(2));
    expect(find.text('—'), findsWidgets);
  });

  testWidgets('the column says what it does not claim', (tester) async {
    await _pump(tester, width: 1400, view: 'People');

    // The word "Conversion" beside a name will be read as a verdict, so the
    // narrower true reading is on the screen rather than in a docblock.
    expect(
      find.textContaining('Sales counts only the calls a person answered'),
      findsOneWidget,
    );
  });

  // ── sorting ────────────────────────────────────────────────────

  testWidgets('the table opens on answered, most first', (tester) async {
    await _pump(tester, width: 1400, view: 'People');

    // Bo 9, Ada 6, Cy 0 — not alphabetical, which is what the server sends.
    expect(_names(tester), ['Bo', 'Ada', 'Cy']);
  });

  testWidgets('tapping a column sorts by it, and again reverses it', (
    tester,
  ) async {
    await _pump(tester, width: 1400, view: 'People');

    await tester.tap(_header('Rang'));
    await tester.pumpAndSettle();

    // Bo 10, Ada 7, Cy 0.
    expect(_names(tester), ['Bo', 'Ada', 'Cy']);

    await tester.tap(_header('Rang'));
    await tester.pumpAndSettle();

    expect(_names(tester), ['Cy', 'Ada', 'Bo']);
  });

  testWidgets('a person with no average talk sorts last both ways', (
    tester,
  ) async {
    await _pump(tester, width: 1400, view: 'People');

    await tester.tap(_header('Booking rate'));
    await tester.pumpAndSettle();
    expect(_names(tester).last, 'Cy');

    // The interesting half: reversing must not float the person with NO value
    // to the top, where a dash would read as the longest call of the day.
    await tester.tap(_header('Booking rate'));
    await tester.pumpAndSettle();
    expect(_names(tester).last, 'Cy');
  });

  testWidgets('only the column in force carries an arrow', (tester) async {
    await _pump(tester, width: 1400, view: 'People');

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
    await _pump(tester, width: 1400, view: 'People');

    expect(find.textContaining('Kitchen tap'), findsNothing);

    await tester.tap(find.text('Ada'));
    await tester.pumpAndSettle();

    expect(find.textContaining('Kitchen tap'), findsOneWidget);
  });

  testWidgets('AI call scores add a column and a coaching panel, only once '
      'a call has one', (tester) async {
    final scored = jsonDecode(jsonEncode(_report)) as Map<String, dynamic>;
    (scored['calls'] as List).first['score'] = {
      'score': 7,
      'checks': {'greeted': true, 'offered_time': false},
      'strength': 'Warm greeting.',
      'improve': 'Offer a time.',
    };
    await _pump(tester, width: 1400, view: 'People', report: () => scored);

    expect(find.text('Avg score'), findsOneWidget);
    expect(find.text('7.0 / 10'), findsOneWidget);

    await tester.tap(find.text('Ada'));
    await tester.pumpAndSettle();

    expect(find.byKey(const ValueKey('coaching-Ada')), findsOneWidget);
    expect(find.textContaining('over 1 scored call'), findsOneWidget);
    expect(find.text('Greeted with the company name 100%'), findsOneWidget);
    expect(find.text('Offered a time 0%'), findsOneWidget);
    expect(find.text('Offer a time.'), findsOneWidget);
    await tester.tap(find.byTooltip('Copy').last);
    await tester.pumpAndSettle();
    expect(find.text('Copied'), findsOneWidget);
    expect(find.byKey(const ValueKey('coaching-Bo')), findsNothing);
  });

  testWidgets('Workiz\'s own score shows beside ours for that person', (
    tester,
  ) async {
    final withWorkiz = jsonDecode(jsonEncode(_report)) as Map<String, dynamic>;
    withWorkiz['workiz_scores'] = {
      'Ada': {
        'calls': 3,
        'score': 72,
        'booked': 33,
        'pricing': 67,
        'positive': 100,
      },
    };
    await _pump(tester, width: 1400, view: 'People', report: () => withWorkiz);

    await tester.tap(find.text('Ada'));
    await tester.pumpAndSettle();

    expect(
      find.text(
        "Workiz's own score · 72 / 100 over 3 calls · booked 33% · "
        'price discussed 67% · positive 100%',
      ),
      findsOneWidget,
    );
  });

  testWidgets('no Call score column while no call is scored', (tester) async {
    await _pump(tester, width: 1400, view: 'People');

    expect(find.text('Avg score'), findsNothing);
  });

  testWidgets('a call that rang somebody but was taken by another says so', (
    tester,
  ) async {
    await _pump(tester, width: 1400, view: 'People');

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
    expect(find.text('Not answered · Bo took it'), findsOneWidget);
    // Design 170: Answer and Booking narrow the person's calls apart.
    await tester.tap(find.byKey(const ValueKey('person-Answer-answered')));
    await tester.pumpAndSettle();
    expect(find.text('Not answered · Bo took it'), findsNothing);
    await tester.tap(find.byKey(const ValueKey('person-Answer-not_answered')));
    await tester.pumpAndSettle();
    expect(find.text('Not answered · Bo took it'), findsOneWidget);
    expect(find.text('5:00'), findsNWidgets(before + 1));
  });

  // ── the Reached nobody card ────────────────────────────────────

  testWidgets('Reached nobody opens onto its calls, narrowed by reason', (
    tester,
  ) async {
    await _pump(tester, width: 1400);

    // The Overview shows the first missed calls (UI queue 60); the reasons
    // are in Calls, which the figure opens.
    expect(find.byKey(const ValueKey('nobody-voicemail')), findsNothing);

    await tester.tap(find.byKey(const ValueKey('stat-reached-nobody')));
    await tester.pumpAndSettle();

    // Both reasons, counted, and neither answered call. Spam is not a
    // reason any more: the server drops it (Jose, 2026-10-05).
    expect(find.text('Missed call 1'), findsOneWidget);
    expect(find.text('Voicemail 1'), findsOneWidget);
    expect(find.byKey(const ValueKey('nobody-spam')), findsNothing);
    expect(find.textContaining('Burst pipe'), findsOneWidget);
    expect(find.textContaining('Silent line'), findsOneWidget);
    expect(find.textContaining('Kitchen tap'), findsNothing);

    await tester.tap(find.byKey(const ValueKey('nobody-voicemail')));
    await tester.pumpAndSettle();

    expect(find.textContaining('Silent line'), findsOneWidget);
    expect(find.textContaining('Burst pipe'), findsNothing);
  });

  testWidgets('Missed tells inbound from outbound calls nobody picked up', (
    tester,
  ) async {
    // Jose, 2026-10-12: "on missed calls i want also to differentiate
    // between inbound and outbound calls".
    final withOutbound = Map<String, dynamic>.from(_report);
    withOutbound['calls'] = [
      ...(_report['calls'] as List),
      {
        'id': 'o1',
        'direction': 'Outbound',
        'started_at': '2026-09-16T17:00:00Z',
        'from': '+15550007777',
        'caller_label': null,
        'queue': null,
        'result': 'No Answer',
        'talk_seconds': null,
        'recording_id': null,
        'answered_by': null,
        'placed_by': 'Ada',
        'rang': [],
        'outcome': 'no_answer',
      },
    ];
    await _pump(tester, width: 1400, report: () => withOutbound);

    await tester.tap(find.byKey(const ValueKey('stat-reached-nobody')));
    await tester.pumpAndSettle();

    // Listed in Missed with who placed it, but not a missed customer. Since
    // UI queue 78 who placed it is the Answered by column; since 2026-11-11
    // "Not answered" is its Call (Jose: Answered - Not Answered - Missed Call).
    // Review item 1: Missed is inbound only; our calls out are their own chip.
    expect(find.text('Called by Ada'), findsNothing);
    await tester.tap(
      find.byKey(const ValueKey('calls-filter-outbound_no_answer')),
    );
    await tester.pumpAndSettle();
    expect(find.text('Called by Ada'), findsOneWidget);
    expect(find.widgetWithText(StatusPill, 'Not answered'), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('calls-filter-outbound')));
    await tester.pumpAndSettle();

    expect(find.text('Called by Ada'), findsOneWidget);
    expect(find.textContaining('Burst pipe'), findsNothing);
    expect(find.byKey(const ValueKey('nobody-missed')), findsNothing);

    await tester.tap(find.byKey(const ValueKey('calls-filter-missed')));
    await tester.pumpAndSettle();

    expect(find.text('Called by Ada'), findsNothing);
    expect(find.textContaining('Burst pipe'), findsOneWidget);
  });

  testWidgets('a missed call opens to its caller, its line and its voicemail', (
    tester,
  ) async {
    // Bar, via Jose, 2026-10-12: a missed row opens to all of its info.
    final withLines = Map<String, dynamic>.from(_report);
    withLines['calls'] = [
      for (final call
          in (_report['calls'] as List).cast<Map<String, dynamic>>())
        call['id'] == 'c4'
            ? {
                ...call,
                'recording_id': 'rec-4',
                'to_number': '+15558887777',
                'to_name': 'DFW - LS Ads',
              }
            : call,
    ];
    await _pump(tester, width: 1400, report: () => withLines);

    await tester.tap(find.byKey(const ValueKey('stat-reached-nobody')));
    await tester.pumpAndSettle();

    // Closed: no details, no play button on a missed row, and a voicemail
    // icon that says there is a recording inside (UI/UX).
    expect(find.text('Line called'), findsNothing);
    expect(find.byKey(const ValueKey('listen-rec-4')), findsNothing);
    expect(find.byTooltip('Show details'), findsWidgets);

    await tester.tap(find.byKey(const ValueKey('call-details-c4')));
    await tester.pumpAndSettle();

    expect(find.text('+15550004444'), findsWidgets);
    expect(find.text('DFW · LS Ads'), findsOneWidget);
    expect(find.text('Line called'), findsOneWidget);
    expect(find.text('+1 (555) 888-7777'), findsWidgets);
    expect(find.byKey(const ValueKey('voicemail-rec-4')), findsOneWidget);

    // A missed call with no voicemail opens to its numbers, no player.
    await tester.tap(find.byKey(const ValueKey('call-details-c3')));
    await tester.pumpAndSettle();

    expect(find.text('Caller'), findsNWidgets(2));
    expect(find.byKey(const ValueKey('voicemail-null')), findsNothing);
  });

  testWidgets('a missed-call notification opens on that call', (tester) async {
    // Bar, via Jose, 2026-10-12: a notification opens "the exact thing".
    await _pump(tester, width: 1400, openCall: 'c3');

    expect(find.text('Caller'), findsOneWidget);
    expect(find.byTooltip('Hide details'), findsOneWidget);
  });

  testWidgets('Missed calls narrow to new or existing clients', (tester) async {
    await _pump(tester, width: 1400);

    await tester.tap(find.byKey(const ValueKey('stat-reached-nobody')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('calls-filter-new')));
    await tester.pumpAndSettle();

    expect(find.textContaining('Burst pipe'), findsOneWidget);
    expect(find.textContaining('Silent line'), findsNothing);
  });

  // 2026-11-04: it was every Workiz job over new-client calls ("1056 jobs ·
  // 1028 calls"); now booked out of answered new-client calls.
  testWidgets('Conv. rate is booked out of answered new-client calls', (
    tester,
  ) async {
    await _pump(tester, width: 1400);

    final tile = find
        .ancestor(of: find.text('Conv. rate'), matching: find.byType(Material))
        .first;
    expect(
      find.descendant(of: tile, matching: find.text('50%')),
      findsOneWidget,
    );
  });

  // Someone the phones never rang (a technician who only books jobs) is an
  // empty row in a phone table (2026-11-04: 18 of 48).
  testWidgets('People leaves out whoever no call rang', (tester) async {
    await _pump(tester, width: 1400, view: 'People');

    expect(find.text('Ada'), findsWidgets);
    expect(find.text('Cy'), findsWidgets);
    expect(find.text('Dee'), findsNothing);
  });

  // Ana, 2026-11-16: the people who only call out (Gino, Tiara, Ara) had no
  // row. They get one, with what they placed, and it opens on those calls.
  testWidgets(
    'someone who only calls out has a row that opens on their calls',
    (tester) async {
      final out = jsonDecode(jsonEncode(_report)) as Map<String, dynamic>;
      (out['people'] as List).add({
        'person': 'Gino Russo',
        'rang': 0,
        'answered': 0,
        'outbound': 1,
        'new_client': 0,
        'existing_client': 0,
        'jobs': 0,
        'avg_talk_seconds': null,
        'in_calls': true,
        'in_jobs': false,
      });
      (out['calls'] as List).add({
        'id': 'ZE6alqhIALPyDUA',
        'direction': 'Outbound',
        'outcome': 'connected',
        'started_at': '2026-09-16T18:00:00Z',
        'from': '+15550002222',
        'placed_by': 'Gino Russo',
        'answered_by': null,
        'recording_id': null,
        'talk_seconds': null,
        'rang': [],
        'booked': false,
      });
      await _pump(tester, width: 1400, view: 'People', report: () => out);

      expect(find.text('Outbound'), findsWidgets);
      await tester.tap(find.text('Gino Russo'));
      await tester.pumpAndSettle();

      expect(find.text('Outbound 1'), findsOneWidget);
      expect(find.text('Called by Gino Russo'), findsOneWidget);
    },
  );

  testWidgets('the cards run in the order Jose set', (tester) async {
    await _pump(tester, width: 1400);

    final labels = [
      'Inbound calls',
      'Missed calls',
      'Existing client',
      'New client',
      'Jobs created',
      'Conv. rate',
      'Not booked',
    ];
    final xs = [
      for (final label in labels)
        tester.getTopLeft(find.text(label).first).dx +
            tester.getTopLeft(find.text(label).first).dy * 10000,
    ];

    expect(xs, [...xs]..sort(), reason: 'cards out of order');
    // 1 job over 3 new-client calls.
    expect(find.text('50%'), findsWidgets);
  });

  testWidgets('Not booked lists the answered new-client call and marks it', (
    tester,
  ) async {
    final posted = <String>[];
    await _pump(tester, width: 1400, posted: posted);

    await tester.tap(find.byKey(const ValueKey('stat-not-booked')));
    await tester.pumpAndSettle();

    // Kitchen tap: answered, new client, no job. Blocked drain was booked.
    expect(find.textContaining('Kitchen tap'), findsOneWidget);
    expect(find.textContaining('Blocked drain'), findsNothing);
    expect(
      find.textContaining('5550001111'),
      findsWidgets,
    ); // the caller's number

    // The words, folded until asked for: the row opens to them (UI queue 78).
    expect(find.text('How much is a sweep?'), findsNothing);
    await tester.tap(find.byKey(const ValueKey('call-details-c1')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('transcript-c1')));
    await tester.pumpAndSettle();
    expect(find.text('How much is a sweep?'), findsOneWidget);

    // Design 179 (Jose, 2026-11-03, for Trey): the details set the booking
    // and the client by hand.
    await tester.tap(
      find.descendant(
        of: find.byKey(const ValueKey('set-booking-c1')),
        matching: find.text('Booked'),
      ),
    );
    await tester.pumpAndSettle();
    await tester.tap(
      find.descendant(
        of: find.byKey(const ValueKey('set-client-c1')),
        matching: find.text('Existing'),
      ),
    );
    await tester.pumpAndSettle();

    expect(posted, [
      'PUT /api/v1/reports/experimental/calls/c1/override '
          '{"field":"booking","value":"booked"}',
      'PUT /api/v1/reports/experimental/calls/c1/override '
          '{"field":"client","value":"existing"}',
    ]);
  });

  testWidgets('a value set by hand says who, and goes back to automatic', (
    tester,
  ) async {
    final set = jsonDecode(jsonEncode(_report)) as Map<String, dynamic>;
    final call = (set['calls'] as List).firstWhere((c) => c['id'] == 'c1');
    call['set_by'] = {'booking': 'Trey Brannan'};
    call['booked'] = true;
    final posted = <String>[];
    await _pump(tester, width: 1400, posted: posted, report: () => set);

    await tester.tap(find.textContaining('Calls').first);
    await tester.pumpAndSettle();
    expect(find.text('Booked ✎'), findsWidgets);
    expect(find.byTooltip('Set by Trey Brannan'), findsWidgets);

    await tester.tap(find.byKey(const ValueKey('call-details-c1')));
    await tester.pumpAndSettle();
    expect(find.text('Set by Trey ·'), findsOneWidget);
    await tester.tap(find.byKey(const ValueKey('automatic-booking-c1')));
    await tester.pumpAndSettle();

    expect(posted, [
      'DELETE /api/v1/reports/experimental/calls/c1/override field=booking',
    ]);
  });

  // Trey, 2026-11-04 ("not a cx since we dont do home inspections"): the AI's
  // Not a customer is a suggestion a person confirms; a marked call keeps its
  // mark in the list and is nobody's lost booking.
  testWidgets('a call marked Not a customer leaves Not booked, with its mark', (
    tester,
  ) async {
    final set = jsonDecode(jsonEncode(_report)) as Map<String, dynamic>;
    final call = (set['calls'] as List).firstWhere((c) => c['id'] == 'c1');
    call['labels'] = {'grok': 'not_a_customer'};
    final posted = <String>[];
    await _pump(tester, width: 1400, posted: posted, report: () => set);

    await tester.tap(find.byKey(const ValueKey('stat-not-booked')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('call-details-c1')));
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('mark-suggested-c1')), findsOneWidget);
    // Marked as the server answers it: by whom, and out of Not booked.
    call['mark'] = 'not_a_customer';
    call['set_by'] = {'mark': 'Trey Brannan'};
    await tester.tap(
      find.descendant(
        of: find.byKey(const ValueKey('set-mark-c1')),
        matching: find.text('Not a customer'),
      ),
    );
    await tester.pumpAndSettle();

    expect(posted, [
      'PUT /api/v1/reports/experimental/calls/c1/override '
          '{"field":"mark","value":"not_a_customer"}',
    ]);
    expect(find.textContaining('Kitchen tap'), findsNothing);
  });

  testWidgets('a marked call is still in Calls, saying who marked it', (
    tester,
  ) async {
    final set = jsonDecode(jsonEncode(_report)) as Map<String, dynamic>;
    final call = (set['calls'] as List).firstWhere((c) => c['id'] == 'c1');
    call['mark'] = 'not_a_customer';
    call['set_by'] = {'mark': 'Trey Brannan'};
    await _pump(tester, width: 1400, report: () => set);

    await tester.tap(find.textContaining('Calls').first);
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('call-details-c1')), findsOneWidget);
    expect(
      find.descendant(
        of: find.byTooltip('Set by Trey Brannan'),
        matching: find.text('Not a customer'),
      ),
      findsOneWidget,
    );
  });

  testWidgets('Not booked counts reasons per AI, and shows both on a call', (
    tester,
  ) async {
    await _pump(tester, width: 1400);

    await tester.tap(find.byKey(const ValueKey('stat-not-booked')));
    await tester.pumpAndSettle();

    // Grok's reading first (Jose, 2026-10-13): Kitchen tap is an
    // availability call. Both readings are in what the row opens to.
    expect(find.text('Price 0'), findsOneWidget);
    expect(find.text('Availability 1'), findsOneWidget);
    await tester.tap(find.byKey(const ValueKey('call-details-c1')));
    await tester.pumpAndSettle();
    // One reason, marked AI (review item 9).
    expect(find.text('AI: Availability'), findsOneWidget);

    // A count narrows the list to its calls.
    await tester.tap(find.byKey(const ValueKey('label-price')));
    await tester.pumpAndSettle();
    expect(find.textContaining('Kitchen tap'), findsNothing);
  });

  // UI queue 77: the reasons are AI's reading, and say so.
  testWidgets('the AI reasons carry the AI mark', (tester) async {
    await _pump(tester, width: 1400);

    await tester.tap(find.byKey(const ValueKey('stat-not-booked')));
    await tester.pumpAndSettle();

    expect(find.byKey(const ValueKey('labels-ai-mark')), findsOneWidget);
    await tester.tap(find.byKey(const ValueKey('call-details-c1')));
    await tester.pumpAndSettle();
    expect(
      find.ancestor(
        of: find.text('AI: Availability'),
        matching: find.byWidgetPredicate(
          (w) => w is Row && w.children.first is AiMark,
        ),
      ),
      findsOneWidget,
    );
  });

  // Was "choosing a week asks the server for a week". Day / Week / Month gave
  // way to the date picker (UI queue 57): a week is its dates now.
  testWidgets('choosing last week asks the server for that week', (
    tester,
  ) async {
    final asked = <Map<String, String>>[];
    await _pump(
      tester,
      width: 1400,
      asked: asked,
      now: () => DateTime(2026, 10, 6, 15),
    );

    await tester.tap(find.byKey(const Key('date-range-button')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('date-preset-last_week')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('date-range-apply')));
    await tester.pumpAndSettle();

    expect(asked.last['from'], '2026-09-28');
    expect(asked.last['to'], '2026-10-04');
  });

  testWidgets('the dates are on the button in words, and asked for as dates', (
    tester,
  ) async {
    final asked = <Map<String, String>>[];
    await _pump(
      tester,
      width: 1400,
      asked: asked,
      now: () => DateTime(2026, 10, 6, 15),
    );

    final button = find.byKey(const Key('date-range-button'));
    expect(
      find.descendant(of: button, matching: find.text('Today')),
      findsOneWidget,
    );
    expect(
      find.descendant(of: button, matching: find.text('Tue, Oct 6')),
      findsOneWidget,
    );
    expect(asked.first, {'from': '2026-10-06', 'to': '2026-10-06'});
    // Nothing after today to step to.
    expect(
      tester
          .widget<IconButton>(find.byKey(const Key('date-range-next')))
          .onPressed,
      isNull,
    );
  });

  testWidgets('the arrows step by the length chosen, a month by the month', (
    tester,
  ) async {
    final asked = <Map<String, String>>[];
    await _pump(
      tester,
      width: 1400,
      asked: asked,
      now: () => DateTime(2026, 10, 6, 15),
    );

    await tester.tap(find.byKey(const Key('date-range-previous')));
    await tester.pumpAndSettle();
    expect(asked.last, {'from': '2026-10-05', 'to': '2026-10-05'});
    expect(find.byKey(const Key('date-range-button')), findsOneWidget);
    expect(
      find.descendant(
        of: find.byKey(const Key('date-range-button')),
        matching: find.text('Yesterday'),
      ),
      findsOneWidget,
    );

    await tester.tap(find.byKey(const Key('date-range-button')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('date-preset-this_month')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('date-range-apply')));
    await tester.pumpAndSettle();
    expect(asked.last, {'from': '2026-10-01', 'to': '2026-10-06'});

    // A whole September, not September 25 to 30.
    await tester.tap(find.byKey(const Key('date-range-previous')));
    await tester.pumpAndSettle();
    expect(asked.last, {'from': '2026-09-01', 'to': '2026-09-30'});
    expect(find.text('Last month'), findsOneWidget);
    expect(find.byType(Dialog), findsNothing);
  });

  testWidgets('on a phone the dates are a page of their own', (tester) async {
    final asked = <Map<String, String>>[];
    await _pump(
      tester,
      width: 390,
      asked: asked,
      now: () => DateTime(2026, 10, 6, 15),
    );

    await tester.tap(find.byKey(const Key('date-range-button')));
    await tester.pumpAndSettle();
    expect(find.text('Dates'), findsOneWidget);
    expect(find.byKey(const Key('date-range-button')), findsNothing);

    await tester.tap(find.byKey(const ValueKey('date-preset-last_7')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Show 7 days'));
    await tester.pumpAndSettle();

    expect(asked.last, {'from': '2026-09-30', 'to': '2026-10-06'});
    expect(find.byKey(const Key('date-range-button')), findsOneWidget);
    expect(tester.takeException(), isNull);
  });

  testWidgets('on a phone the button gives the dates the width it has', (
    tester,
  ) async {
    await _pump(tester, width: 700, now: () => DateTime(2026, 10, 6, 15));

    await tester.tap(find.byKey(const Key('date-range-button')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('date-preset-this_week')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Show 2 days'));
    await tester.pumpAndSettle();

    // Drawn whole, not cut to "Mon, Oct 5 –…" by a second flexible child
    // taking half the row for nothing.
    final words = find.byKey(const Key('date-range-words'));
    final paragraph = tester.renderObject<RenderParagraph>(
      find.descendant(of: words, matching: find.byType(RichText)),
    );
    expect(
      paragraph.getMaxIntrinsicWidth(double.infinity),
      lessThanOrEqualTo(paragraph.size.width + 0.5),
    );
  });

  testWidgets('only one person is open at a time', (tester) async {
    await _pump(tester, width: 1400, view: 'People');

    await tester.tap(find.text('Ada'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Bo'));
    await tester.pumpAndSettle();

    // Ada's own call is gone; Bo's is showing.
    expect(find.textContaining('Kitchen tap'), findsNothing);
    expect(find.textContaining('Blocked drain'), findsOneWidget);
  });

  testWidgets('a call with no recording offers no player', (tester) async {
    await _pump(tester, width: 1400, view: 'People');

    await tester.tap(find.text('Ada'));
    await tester.pumpAndSettle();

    // Ada has two calls and one recording between them. A missing recording is
    // an ordinary state, not a failure, and must not draw a dead transport.
    expect(find.byIcon(Icons.play_arrow), findsOneWidget);
  });

  // ── one row per call (UI queue 71) ────────────────────────────

  testWidgets('a recording opens under its call only when played', (
    tester,
  ) async {
    await _pump(tester, width: 1400, view: 'Calls');

    // It was an empty bar under every call, stretched across the page.
    expect(find.byType(RecordingPlaybackBar), findsNothing);

    await tester.tap(find.byKey(const ValueKey('listen-rec-1')));
    await tester.pump();
    expect(find.byType(RecordingPlaybackBar), findsOneWidget);
  });

  testWidgets('Booked narrows Calls to the calls that became a job', (
    tester,
  ) async {
    await _pump(tester, width: 1400, view: 'Calls');

    await tester.tap(find.byKey(const ValueKey('calls-filter-booked')));
    await tester.pumpAndSettle();

    expect(find.textContaining('Blocked drain'), findsOneWidget);
    expect(find.textContaining('Kitchen tap'), findsNothing);
    // Jose, 2026-11-11: Result says the sale, a Call column what became of
    // the call, so a booked row reads Booked and Answered side by side.
    expect(find.text('CALL'), findsOneWidget);
    expect(find.widgetWithText(StatusPill, 'Booked'), findsOneWidget);
    expect(find.widgetWithText(StatusPill, 'Answered'), findsOneWidget);

    // Missed call sits in the Call group, beside Answered and Not answered.
    await tester.tap(find.byKey(const ValueKey('calls-filter-missed')));
    await tester.pumpAndSettle();
    expect(find.widgetWithText(StatusPill, 'Booked'), findsNothing);
  });

  testWidgets('a line name ending in its queue says the queue once', (
    tester,
  ) async {
    await _pump(
      tester,
      width: 1400,
      view: 'Calls',
      report: () => {
        ..._report,
        'calls': [
          for (final call in _report['calls'] as List)
            if ((call as Map)['id'] == 'c1')
              {...call, 'caller_label': 'NJ - LS Ads - A1 - New Client'}
            else
              call,
        ],
      },
    );

    // "NJ - LS Ads - A1 - New Client · A1 - New Client" was the row; the
    // queue is the New client badge.
    expect(find.text('NJ · LS Ads'), findsOneWidget);
    expect(find.textContaining('A1 - New Client'), findsNothing);
  });

  // ── the sticky header ──────────────────────────────────────────

  testWidgets('the column header is pinned rather than scrolled away', (
    tester,
  ) async {
    await _pump(tester, width: 1400, view: 'People');

    expect(find.byType(PinnedHeaderSliver), findsOneWidget);
  });

  testWidgets('a narrow screen draws cards and still opens the calls', (
    tester,
  ) async {
    await _pump(tester, width: 375, view: 'People');

    expect(find.byType(PinnedHeaderSliver), findsNothing);

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

  // ── UI queue 60: Overview, People, Calls (Jose: 1 and 4) ───────

  testWidgets('it opens on the Overview: the figures, not the table', (
    tester,
  ) async {
    await _pump(tester, width: 1400);

    final tabs = find.byKey(const Key('call-report-tabs'));
    for (final label in ['Overview', 'People · 3', 'Calls · 4 in · 0 out']) {
      expect(
        find.descendant(of: tabs, matching: find.text(label)),
        findsOneWidget,
      );
    }
    expect(find.byKey(const ValueKey('stat-calls')), findsOneWidget);
    expect(find.byType(PinnedHeaderSliver), findsNothing);
  });

  testWidgets('the seven figures are one size', (tester) async {
    await _pump(tester, width: 1400);

    final opening = {
      for (final key in [
        'stat-calls',
        'stat-reached-nobody',
        'stat-existing',
        'stat-new',
        'stat-not-booked',
      ])
        tester.getSize(find.byKey(ValueKey(key))),
    };
    expect(opening, hasLength(1));

    // The two that open nothing are no shorter for it.
    for (final label in ['Jobs created', 'Conv. rate']) {
      final tile = find
          .ancestor(of: find.text(label), matching: find.byType(Material))
          .first;
      expect(tester.getSize(tile), opening.single, reason: label);
    }
  });

  testWidgets('a figure opens Calls narrowed to what it counted', (
    tester,
  ) async {
    await _pump(tester, width: 1400);

    await tester.tap(find.byKey(const ValueKey('stat-existing')));
    await tester.pumpAndSettle();

    expect(_chip(tester, 'existing').selected, isTrue);
    expect(find.textContaining('Silent line'), findsOneWidget);
    expect(find.textContaining('Kitchen tap'), findsNothing);

    // New: every new-client call, answered or not.
    await tester.tap(find.byKey(const ValueKey('calls-filter-new')));
    await tester.pumpAndSettle();
    expect(find.textContaining('Kitchen tap'), findsOneWidget);
    expect(find.textContaining('Burst pipe'), findsOneWidget);
    expect(find.textContaining('Silent line'), findsNothing);
  });

  testWidgets('Not booked and Missed sit side by side, one height', (
    tester,
  ) async {
    final posted = <String>[];
    await _pump(tester, width: 1400, posted: posted);

    final notBooked = find.byKey(const ValueKey('overview-not-booked'));
    final missed = find.byKey(const ValueKey('overview-missed'));
    expect(
      find.descendant(
        of: notBooked,
        matching: find.textContaining('Kitchen tap'),
      ),
      findsOneWidget,
    );
    for (final caller in ['Burst pipe', 'Silent line']) {
      expect(
        find.descendant(of: missed, matching: find.textContaining(caller)),
        findsOneWidget,
      );
    }
    expect(tester.getRect(notBooked).top, tester.getRect(missed).top);
    expect(
      tester.getRect(notBooked).left,
      lessThan(tester.getRect(missed).left),
    );
    expect(tester.getSize(notBooked).height, tester.getSize(missed).height);

    // Marked from the Overview as from the list.
    await tester.tap(find.byKey(const ValueKey('overview-convert-c1')));
    await tester.pumpAndSettle();
    expect(posted, ['/api/v1/reports/experimental/calls/c1/convert']);
  });

  testWidgets('See all opens the whole list, whatever it was narrowed to', (
    tester,
  ) async {
    await _pump(tester, width: 1400);

    await tester.tap(find.byKey(const ValueKey('see-all-missed')));
    await tester.pumpAndSettle();
    expect(_chip(tester, 'missed').selected, isTrue);
    await tester.tap(find.byKey(const ValueKey('nobody-voicemail')));
    await tester.pumpAndSettle();
    expect(find.textContaining('Burst pipe'), findsNothing);

    // Back to the Overview and in again: both missed calls, as counted.
    await tester.tap(
      find.descendant(
        of: find.byKey(const Key('call-report-tabs')),
        matching: find.text('Overview'),
      ),
    );
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('stat-reached-nobody')));
    await tester.pumpAndSettle();
    expect(find.textContaining('Burst pipe'), findsOneWidget);
    expect(find.textContaining('Silent line'), findsOneWidget);
  });

  testWidgets('on a phone the Overview stacks them, Not booked first', (
    tester,
  ) async {
    await _pump(tester, width: 390);

    final notBooked = tester.getRect(
      find.byKey(const ValueKey('overview-not-booked')),
    );
    final missed = tester.getRect(
      find.byKey(const ValueKey('overview-missed')),
    );
    expect(notBooked.bottom, lessThanOrEqualTo(missed.top));
    expect(tester.takeException(), isNull);

    // Sources is at the foot of the Overview, leaving the dates their row.
    await tester.tap(find.byKey(const Key('call-report-sources')));
    await tester.pumpAndSettle();
    expect(find.byType(SidePanel), findsOneWidget);
  });

  testWidgets('a call in Calls shows its first words and opens to the rest', (
    tester,
  ) async {
    await _pump(tester, width: 1400, view: 'Calls');

    // Folded into the row until it is opened (UI queue 78): a list of
    // greetings and "not transcribed" told nobody anything.
    expect(find.text('“How much is a sweep?”'), findsNothing);
    expect(find.text('Not transcribed yet.'), findsNothing);

    await tester.tap(find.byKey(const ValueKey('call-details-c1')));
    await tester.pumpAndSettle();
    expect(find.text('“How much is a sweep?”'), findsOneWidget);

    // Blocked drain was answered and waits for its words.
    await tester.tap(find.byKey(const ValueKey('call-details-c2')));
    await tester.pumpAndSettle();
    expect(find.text('Not transcribed yet.'), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('transcript-c1')));
    await tester.pumpAndSettle();
    expect(find.text('How much is a sweep?'), findsOneWidget);
  });

  // Jose, 2026-11-04: "add date as well to the columns, separate from the
  // time column". Every call, whatever the range.
  testWidgets('a call has its date in a Date column beside the time', (
    tester,
  ) async {
    await _pump(
      tester,
      width: 1400,
      view: 'Calls',
      now: () => DateTime(2026, 10, 6, 15),
    );

    expect(find.text('DATE'), findsOneWidget);
    expect(find.text('TIME'), findsOneWidget);
    final stamp = DateTime.parse('2026-09-16T14:05:00Z').toLocal();
    expect(find.text(DisplayFormats.fallback.formatDate(stamp)), findsWidgets);
  });

  testWidgets('an account that did not answer is one line above the tabs', (
    tester,
  ) async {
    await _pump(tester, width: 1400, report: () => _withAccounts());

    expect(
      find.descendant(
        of: find.byKey(const Key('call-report-problem')),
        matching: find.textContaining('Did not answer: Harbor South.'),
      ),
      findsOneWidget,
    );
    expect(find.text('Sources · 2 Workiz accounts'), findsOneWidget);
    // The accounts themselves are not on the page.
    expect(find.byType(InputChip), findsNothing);
  });

  testWidgets('no line when every account answered', (tester) async {
    await _pump(
      tester,
      width: 1400,
      report: () => _withAccounts(southAnswered: true),
    );

    expect(find.byKey(const Key('call-report-problem')), findsNothing);
  });

  testWidgets('Sources names the accounts and cannot disconnect them', (
    tester,
  ) async {
    final deleted = <String>[];
    await _pump(
      tester,
      width: 1400,
      deleted: deleted,
      report: () => _withAccounts(),
    );

    await tester.tap(find.byKey(const Key('call-report-sources')));
    await tester.pumpAndSettle();
    expect(
      find.text('Jobs cover 1 Workiz account: Harbor North.'),
      findsOneWidget,
    );
    expect(find.byKey(const ValueKey('sources-account-w1')), findsOneWidget);
    expect(
      find.descendant(
        of: find.byKey(const ValueKey('sources-account-w1')),
        matching: find.byIcon(Icons.close),
      ),
      findsNothing,
      reason: 'disconnecting is Settings alone',
    );
    expect(find.byKey(const Key('sources-manage')), findsOneWidget);
    expect(deleted, isEmpty);
  });

  testWidgets('a Workiz account is connected from Sources', (tester) async {
    final posted = <String>[];
    await _pump(tester, width: 1400, posted: posted);

    await tester.tap(find.byKey(const Key('call-report-sources')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('sources-connect')));
    await tester.pumpAndSettle();
    await tester.enterText(find.byKey(const Key('sources-token')), 'example');
    await tester.tap(find.byKey(const Key('sources-connect-save')));
    await tester.pumpAndSettle();

    expect(posted, ['/api/v1/reports/experimental/workiz']);
    expect(find.byKey(const Key('sources-token')), findsNothing);
  });
}

ChoiceChip _chip(WidgetTester tester, String key) =>
    tester.widget<ChoiceChip>(find.byKey(ValueKey('calls-filter-$key')));

/// Two branches' accounts: North answered, South did not unless told to.
Map<String, dynamic> _withAccounts({
  bool southAnswered = false,
  bool northGone = false,
}) => {
  ..._report,
  'workiz_accounts': [
    if (!northGone) {'id': 'w1', 'name': 'Harbor North'},
    {'id': 'w2', 'name': 'Harbor South'},
  ],
  'workiz_failed': southAnswered ? {} : {'Harbor South': 'timed out'},
};

/// A column header, and not the summary stat that shares its wording.
///
/// `find.text('Avg talk')` matches twice: the totals strip above the table
/// carries the same phrase. Tapping by text alone is ambiguous and fails with
/// a wall of style dumps that says nothing about the cause.
Finder _header(String label) => find.descendant(
  of: find.byType(PinnedHeaderSliver),
  matching: find.text(label),
);

/// The people, in the order the table is currently drawing them.
List<String> _names(WidgetTester tester) => tester
    .widgetList<Text>(find.byType(Text))
    .map((text) => text.data)
    .whereType<String>()
    .where((data) => const ['Ada', 'Bo', 'Cy'].contains(data))
    .toList();

Future<void> _pump(
  WidgetTester tester, {
  required double width,
  List<String>? posted,
  List<Map<String, String>>? asked,
  DateTime Function()? now,
  Map<String, dynamic> Function()? report,
  List<String>? deleted,
  String? view,
  String? openCall,
}) async {
  tester.view.physicalSize = Size(width, 2400);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(
    client: MockClient((request) async {
      if (request.url.path.endsWith('/reports/experimental/calls')) {
        asked?.add(request.url.queryParameters);
        return _json(report?.call() ?? _report, 200);
      }
      if (request.url.path.endsWith('/override')) {
        posted?.add(
          '${request.method} ${request.url.path} '
          '${request.method == 'DELETE' ? request.url.query : request.body}',
        );
        return _json({'data': {}}, 200);
      }
      if (request.method == 'POST' &&
          (request.url.path.endsWith('/convert') ||
              request.url.path.endsWith('/reports/experimental/workiz'))) {
        posted?.add(request.url.path);
        return _json({'data': {}}, 200);
      }
      if (request.method == 'DELETE' &&
          request.url.path.contains('/social/connections/')) {
        deleted?.add(request.url.path);
        return _json({'data': {}}, 200);
      }

      return _json({'message': 'Nothing stubbed.'}, 500);
    }),
  );

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(
        body: ExperimentalCallReport(
          api: api,
          now: now ?? DateTime.now,
          openCall: openCall,
        ),
      ),
    ),
  );
  await tester.pumpAndSettle();

  // The tab to start on, by the start of its label (People · 3).
  if (view != null) {
    await tester.tap(
      find.descendant(
        of: find.byKey(const Key('call-report-tabs')),
        matching: find.textContaining(view),
      ),
    );
    await tester.pumpAndSettle();
  }
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
  'queues': {'new': 'A1 - New Client', 'existing': 'A1 - Existing Client'},
  // As the server sends the lists' defaults (CallLabels::names()).
  'labels': {
    'existing': {
      'complaint': 'Complaint',
      'reschedule': 'Reschedule',
      'cancel': 'Cancel',
      'eta': 'ETA',
      'billing': 'Billing',
      'estimate_question': 'Estimate question',
      'job_status': 'Job status',
      'confirmation': 'Confirmation',
      'not_a_customer': 'Not a customer',
      'no_conversation': 'No conversation',
      'other': 'Other',
    },
    'not_booked': {
      'price': 'Price',
      'availability': 'Availability',
      'booked': 'AI heard a booking, no job yet',
      'reschedule': 'Reschedule',
      'cancel': 'Cancel',
      'confirmation': 'Confirmation',
      'service_question': 'Service question',
      'not_a_customer': 'Not a customer',
      'no_conversation': 'No conversation',
      'other': 'Other',
    },
  },
  'timezone': 'America/New_York',
  'day': '2026-09-16',
  'workiz_accounts': [],
  'workiz_failed': {},
  'workiz_unreachable': [],
  'totals': {
    'calls': 4,
    'answered': 2,
    'reached_nobody': 2,
    'new_client': 3,
    'existing_client': 1,
    'jobs': 1,
    'answered_new': 2,
    'booked_new': 1,
    'not_booked': 1,
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
      'rang': 2,
      'answered': 0,
      'new_client': 0,
      'existing_client': 0,
      'jobs': 0,
      'avg_talk_seconds': null,
      'in_calls': true,
      'in_jobs': true,
    },
    // Books jobs, never rung: no row in a phone table.
    {
      'person': 'Dee',
      'rang': 0,
      'answered': 0,
      'new_client': 0,
      'existing_client': 0,
      'jobs': 3,
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
      'queue': 'A1 - New Client',
      'result': 'Accepted',
      'talk_seconds': 100,
      'recording_id': 'rec-1',
      'answered_by': 'Ada',
      'rang': ['Ada'],
      'outcome': 'answered',
      'transcript': 'How much is a sweep?',
      'labels': {'claude': 'price', 'grok': 'availability'},
      'booked': false,
    },
    {
      'id': 'c2',
      'started_at': '2026-09-16T15:30:00Z',
      'from': '+15550002222',
      'caller_label': 'Blocked drain',
      'queue': 'A1 - New Client',
      'result': 'Accepted',
      'talk_seconds': 300,
      'recording_id': null,
      'answered_by': 'Bo',
      'rang': ['Ada', 'Bo'],
      'outcome': 'answered',
      'booked': true,
    },
    {
      'id': 'c3',
      'started_at': '2026-09-16T16:00:00Z',
      'from': '+15550003333',
      'caller_label': 'Burst pipe',
      'queue': 'A1 - New Client',
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
      'queue': 'A1 - Existing Client',
      'result': 'Voicemail',
      'talk_seconds': null,
      'recording_id': null,
      'answered_by': null,
      'rang': [],
      'outcome': 'voicemail',
    },
  ],
};
