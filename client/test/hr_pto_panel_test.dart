import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/core/timestamps.dart';
import 'package:ezbizi_client/screens/hr/pto_tab.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// The person panel overhaul — design 140, Jose's options 1 and 4
/// (2026-10-22, docs/previews/pto-person-panel-layouts.html). Every name,
/// date and number here is invented.
void main() {
  group('the maths', () {
    test('elapsed is today − start over end − start, clamped', () {
      final start = DateTime(2026, 1, 1);
      final end = DateTime(2027, 1, 1);

      expect(
        PtoMaths.elapsed(start: start, end: end, today: DateTime(2026, 7, 2)),
        closeTo(182 / 365, 1e-9),
      );
      expect(
        PtoMaths.elapsed(start: start, end: end, today: DateTime(2025, 6, 1)),
        0,
      );
      expect(
        PtoMaths.elapsed(start: start, end: end, today: DateTime(2027, 3, 1)),
        1,
      );
      expect(
        PtoMaths.elapsed(start: null, end: end, today: DateTime(2026, 7, 2)),
        isNull,
      );
      expect(
        PtoMaths.elapsed(start: end, end: end, today: DateTime(2026, 7, 2)),
        isNull,
        reason: 'A period with no length has nothing to measure.',
      );
    });

    test('whole days across the spring clock change', () {
      expect(
        PtoMaths.daysBetween(DateTime(2026, 3, 7), DateTime(2026, 3, 9)),
        2,
      );
    });

    test('the bar: used, then what would expire, never past the end', () {
      final a = PtoMaths.shares(total: 12, used: 2, wouldExpire: 10);
      expect(a.used, closeTo(2 / 12, 1e-9));
      expect(a.wouldExpire, closeTo(10 / 12, 1e-9));

      final b = PtoMaths.shares(total: 10, used: 8, wouldExpire: 9);
      expect(b.used, closeTo(0.8, 1e-9));
      expect(b.wouldExpire, closeTo(0.2, 1e-9));

      final none = PtoMaths.shares(total: 0, used: 3, wouldExpire: 1);
      expect((none.used, none.wouldExpire), (0.0, 0.0));
    });

    test('a tap cycles full, half, clear', () {
      expect(PtoMaths.cycle(null), 1);
      expect(PtoMaths.cycle(1), 0.5);
      expect(PtoMaths.cycle(0.5), isNull);
    });

    test('consecutive days with one reason and recorder are one run', () {
      final runs = PtoMaths.runs([
        _day('a', '2026-11-06', reason: 'Trip'),
        _day('b', '2026-11-03', reason: 'Trip'),
        _day('c', '2026-11-04', reason: 'Trip'),
        _day('d', '2026-11-07', reason: 'Sick'),
        _day('e', '2026-11-08', reason: 'Sick', portion: 0.5),
        _day('f', '2026-10-31', reason: 'Fair'),
        _day('g', '2026-11-01', reason: 'Fair'),
      ]);

      expect(
        [for (final r in runs) '${r.label} (${r.daysWords})'],
        [
          'Oct 31–Nov 1 (2 days)',
          'Nov 3–4 (2 days)',
          'Nov 6 (1 day)',
          'Nov 7–8 (1.5 days)',
        ],
      );
      expect(runs[1].ids, ['b', 'c']);
      expect(
        PtoMaths.runs([_day('h', '2026-11-09', portion: 0.5)]).single.daysWords,
        'half a day',
      );
    });

    test(
      'the timeline: newest first, resets from the list, the start last',
      () {
        final events = PtoMaths.timeline({
          'started_on': '2024-03-01',
          'period_start': '2026-03-01',
          'days_off': [_day('a', '2026-11-03'), _day('b', '2026-11-04')],
          'used_changes': [
            {
              'at': '2026-10-01T12:00:00+00:00',
              'by_name': 'Ari Bloom',
              'from': 2,
              'to': 0,
              'reason': 'entered by mistake',
            },
          ],
          'resets': [
            {'on': '2026-03-01', 'expired': 2, 'carried': 0},
            {'on': '2025-03-01', 'expired': 0, 'carried': 3},
          ],
        });

        expect(
          [for (final e in events) e.kind],
          [
            PtoEventKind.daysOff,
            PtoEventKind.correction,
            PtoEventKind.reset,
            PtoEventKind.reset,
            PtoEventKind.started,
          ],
        );
        expect(events.first.run!.label, 'Nov 3–4');
      },
    );

    test('days earned are lines of their own, newest first', () {
      final events = PtoMaths.timeline({
        'started_on': '2026-04-01',
        'earned': [
          {'on': '2026-07-01', 'days': 2},
          {'on': '2026-10-01', 'days': 2},
        ],
      });

      expect(
        [for (final e in events) e.kind],
        [PtoEventKind.earned, PtoEventKind.earned, PtoEventKind.started],
      );
      expect(events.first.on, DateTime(2026, 10, 1));
      expect(events.first.days, 2);
    });

    test('an empty resets list adds no reset; an absent one falls back to '
        'period_start unless that is the start', () {
      List<PtoEventKind> kinds(Map<String, dynamic> row) => [
        for (final e in PtoMaths.timeline(row)) e.kind,
      ];

      expect(
        kinds({
          'started_on': '2026-03-01',
          'period_start': '2026-03-01',
          'resets': <Object>[],
        }),
        [PtoEventKind.started],
      );
      expect(
        kinds({'started_on': '2024-03-01', 'period_start': '2026-03-01'}),
        [PtoEventKind.reset, PtoEventKind.started],
      );
      expect(
        kinds({'started_on': '2026-03-01', 'period_start': '2026-03-01'}),
        [PtoEventKind.started],
      );
    });
  });

  testWidgets('four tiles at Expired 0: grey zeros, Left red at 0, Expired in '
      'the warning tone above 0', (tester) async {
    await _pump(tester, _body(), width: 1200);

    // Pat's own panel: used 6, left 0, expired 0.
    for (final k in ['total', 'used', 'left', 'expired']) {
      expect(find.byKey(Key('pto-tile-$k')), findsOneWidget);
    }
    final context = tester.element(find.byKey(const Key('pto-tile-left')));
    final scheme = Theme.of(context).colorScheme;
    Color? colour(String tile, String text) => tester
        .widget<Text>(
          find.descendant(
            of: find.byKey(Key('pto-tile-$tile')),
            matching: find.text(text),
          ),
        )
        .style
        ?.color;

    expect(colour('left', '0'), scheme.error);
    expect(colour('expired', '0'), scheme.outline);
    expect(colour('used', '6'), isNot(scheme.outline));

    final tops = {
      for (final k in ['total', 'used', 'left', 'expired'])
        tester.getTopLeft(find.byKey(Key('pto-tile-$k'))).dy,
    };
    expect(tops, hasLength(1), reason: 'One row on a desktop.');

    await tester.tap(find.text('Sam Ortiz'));
    await tester.pumpAndSettle();
    expect(colour('expired', '3'), ToneColours.of(context, Tone.caution).ink);
    expect(colour('used', '0'), scheme.outline);
  });

  testWidgets('"When they can be used", and no "you" in the card for any '
      'state', (tester) async {
    await _pump(tester, _body(), width: 1200);

    final you = RegExp(r'\byou(r|rs)?\b', caseSensitive: false);
    for (final name in ['Pat Lin (you)', 'Sam Ortiz', 'Rae Moss']) {
      await tester.tap(find.text(name));
      await tester.pumpAndSettle();

      final card = find.byKey(const ValueKey('tour-work_hours-pto-when'));
      expect(
        find.descendant(of: card, matching: find.text('When they can be used')),
        findsOneWidget,
      );
      final words = [
        for (final t in tester.widgetList<Text>(
          find.descendant(of: card, matching: find.byType(Text)),
        ))
          t.data ?? '',
      ];
      expect(words.where(you.hasMatch), isEmpty, reason: '$name: $words');
    }
    expect(find.textContaining('When you can use them'), findsNothing);
    expect(find.text('Not known'), findsOneWidget, reason: 'Rae is open.');
  });

  testWidgets('the Role picker narrows the list by role id, beside the search '
      'and the segment', (tester) async {
    await _pump(tester, _body(), width: 1200);

    expect(find.byKey(const Key('pto-search')), findsOneWidget);
    expect(find.byKey(const Key('pto-filter')), findsOneWidget);

    await tester.tap(find.byKey(const Key('pto-role')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Technician').last);
    await tester.pumpAndSettle();

    expect(find.text('Dana Reyes'), findsOneWidget);
    expect(find.text('Sam Ortiz'), findsOneWidget);
    expect(find.text('Pat Lin (you)'), findsNothing);
    expect(find.text('Rae Moss'), findsNothing, reason: 'Rae has no role.');

    // With the segment too.
    await tester.tap(find.text('Not yet'));
    await tester.pumpAndSettle();
    expect(find.text('Dana Reyes'), findsNothing);
    expect(find.text('Sam Ortiz'), findsOneWidget);

    await tester.tap(find.text('Everyone'));
    await tester.tap(find.byKey(const Key('pto-role')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('All roles').last);
    await tester.pumpAndSettle();
    expect(find.text('Pat Lin (you)'), findsOneWidget);
    expect(find.text('Rae Moss'), findsOneWidget);
  });

  testWidgets('the ring and the bar take their fractions from period_start '
      'and resets_on', (tester) async {
    await _pump(tester, _body(), width: 1200);
    await tester.tap(find.text('Dana Reyes'));
    await tester.pumpAndSettle();

    // 100 days into a 365-day period.
    final ring = tester.widget<CircularProgressIndicator>(
      find.descendant(
        of: find.byKey(const Key('pto-ring')),
        matching: find.byType(CircularProgressIndicator),
      ),
    );
    expect(ring.value, closeTo(100 / 365, 1e-9));
    expect(
      find.descendant(
        of: find.byKey(const Key('pto-ring')),
        matching: find.text('265'),
      ),
      findsOneWidget,
    );

    // The end labels and the policy line with what would expire.
    expect(find.text(_fmt(_inDays(-100))), findsWidgets);
    expect(
      find.textContaining('Resets ${_fmt(_inDays(265))} · '),
      findsOneWidget,
    );
    expect(find.text('10 would expire if none are used'), findsOneWidget);
  });

  testWidgets('the bar paints: a track and an elapsed fill with real height '
      'and the theme colours, and a Today pin on it', (tester) async {
    await _pump(tester, _body(), width: 1200);
    await tester.tap(find.text('Dana Reyes'));
    await tester.pumpAndSettle();

    final scheme = EzBiziTheme.light().colorScheme;
    final track = find.byKey(const Key('pto-bar-track'));
    final fill = find.byKey(const Key('pto-bar-fill'));
    // The bug this guards: segments laid out 0 px tall paint nothing.
    expect(tester.getSize(track).height, 8);
    expect(tester.getSize(fill).height, 8);
    Color colour(Finder f) =>
        (tester.widget<DecoratedBox>(f).decoration as BoxDecoration).color!;
    expect(colour(track), scheme.surfaceContainerHighest);
    expect(colour(fill), scheme.primary);

    // 100 days into a 365-day period.
    final width = tester.getSize(track).width;
    expect(tester.getSize(fill).width, closeTo(width * 100 / 365, 0.01));

    final pin = find.byKey(const Key('pto-bar-today-pin'));
    expect(tester.getSize(pin).height, greaterThan(8));
    expect(
      tester.getCenter(pin).dx - tester.getTopLeft(track).dx,
      closeTo(width * 100 / 365, 0.01),
    );
    expect(
      find.descendant(
        of: find.byKey(const Key('pto-bar-today')),
        matching: find.text('Today'),
      ),
      findsOneWidget,
    );
  });

  testWidgets('earning dots: filled once earned, hollow while expected, the '
      'next one in words and the reset with what carries', (tester) async {
    final q = _quarterly();
    await _pump(tester, q.body, width: 1200);
    await tester.tap(find.text('Lee Park').first);
    await tester.pumpAndSettle();

    final scheme = EzBiziTheme.light().colorScheme;
    BoxDecoration dot(DateTime on) =>
        tester
                .widget<DecoratedBox>(
                  find.descendant(
                    of: find.byKey(Key('pto-earn-${_iso(on)}')),
                    matching: find.byType(DecoratedBox),
                  ),
                )
                .decoration
            as BoxDecoration;

    expect(dot(q.earned).color, scheme.primary);
    for (final on in q.upcoming) {
      expect(dot(on).color, scheme.surface, reason: 'hollow');
      expect((dot(on).border! as Border).top.color, scheme.primary);
    }
    expect(find.byKey(const Key('pto-earn-earned')), findsOneWidget);
    expect(find.byKey(const Key('pto-earn-upcoming')), findsNWidgets(2));
    expect(tester.getSize(find.byKey(const Key('pto-earn-earned'))).height, 10);

    // Hover says +2.
    final tip = tester.widget<Tooltip>(
      find.descendant(
        of: find.byKey(Key('pto-earn-${_iso(q.upcoming.first)}')),
        matching: find.byType(Tooltip),
      ),
    );
    expect(tip.message, startsWith('+2 expected'));

    expect(
      find.text('Next: +2 days on ${_fmt(q.upcoming.first)}'),
      findsOneWidget,
    );
    // 2 left now and 4 still to come, carried.
    expect(
      find.text('Resets ${_fmt(q.end)} · 6 would carry over'),
      findsOneWidget,
    );
  });

  testWidgets('the timeline leads with the expected earnings, hollow, above '
      'the earned history', (tester) async {
    final q = _quarterly();
    await _pump(tester, q.body, width: 1200);
    await tester.tap(find.text('Lee Park').first);
    await tester.pumpAndSettle();

    double top(String text) => tester.getTopLeft(find.text(text)).dy;
    final later = 'Expected · +2 days on ${_fmt(q.upcoming.last)}';
    final next = 'Expected · +2 days on ${_fmt(q.upcoming.first)}';
    final earned = 'Earned · ${_fmt(q.earned)} (2 days)';
    expect(top(later), lessThan(top(next)));
    expect(top(next), lessThan(top(earned)));
  });

  test('expected earnings: a quarterly policy that starts accumulating 3 '
      'months after starting', () {
    final policy = {
      'allowance': 2,
      'allowance_period': 'quarter',
      'accrue_after': 3,
      'accrue_after_unit': 'month',
      'reset': 'anniversary',
    };
    final person = {'started_on': '2026-01-31'};
    List<DateTime> at(DateTime today) => [
      for (final e in PtoMaths.expected(
        policy: policy,
        person: person,
        today: today,
        end: DateTime(2027, 1, 31),
      ))
        e.on,
    ];

    // Accumulating from 30 Apr (no 31st), then every three months; the one on
    // the reset day counts in this period.
    expect(at(DateTime(2026, 2, 1)), [
      DateTime(2026, 7, 30),
      DateTime(2026, 10, 30),
      DateTime(2027, 1, 30),
    ]);
    expect(at(DateTime(2026, 8, 1)), [
      DateTime(2026, 10, 30),
      DateTime(2027, 1, 30),
    ]);
    expect(
      PtoMaths.expected(
        policy: policy,
        person: person,
        today: DateTime(2026, 8, 1),
        end: DateTime(2027, 1, 31),
      ).first.days,
      2,
    );

    // Reset each period: the allowance lands on the period's first day, so
    // the one on the reset belongs to the next period.
    expect(
      PtoMaths.expected(
        policy: {...policy, 'reset': 'period'},
        person: person,
        today: DateTime(2026, 8, 1),
        end: DateTime(2026, 10, 30),
      ),
      isEmpty,
    );
    expect(
      PtoMaths.expected(
        policy: policy,
        person: const {'started_on': null},
        today: DateTime(2026, 8, 1),
        end: DateTime(2027, 1, 31),
      ),
      isEmpty,
    );
  });

  test('upcoming: HR’s upcoming_earned when sent, the policy when absent', () {
    final policy = {
      'allowance': 2,
      'allowance_period': 'quarter',
      'accrue_after': 3,
      'accrue_after_unit': 'month',
      'reset': 'anniversary',
    };
    final row = <String, dynamic>{'started_on': '2026-01-31'};
    List<DateTime> at(Map<String, dynamic> person) => [
      for (final e in PtoMaths.upcoming(
        policy: policy,
        person: person,
        today: DateTime(2026, 8, 1),
        end: DateTime(2027, 1, 31),
      ))
        e.on,
    ];

    // Absent: derived, as PtoMaths.expected.
    expect(at(row), [DateTime(2026, 10, 30), DateTime(2027, 1, 30)]);
    // Present: the server's list wins, even where it differs from the maths.
    expect(
      at({
        ...row,
        'upcoming_earned': [
          {'on': '2026-11-02', 'days': 3},
        ],
      }),
      [DateTime(2026, 11, 2)],
    );
    // Present and empty: nothing to come, not a fallback.
    expect(at({...row, 'upcoming_earned': <Object>[]}), isEmpty);
  });

  testWidgets('the card draws HR’s upcoming_earned when the row has it', (
    tester,
  ) async {
    final q = _quarterly();
    final on = _inDays(9);
    (q.body['data'] as List).first['upcoming_earned'] = [
      {'on': _iso(on), 'days': 3},
    ];
    await _pump(tester, q.body, width: 1200);

    expect(find.byKey(const Key('pto-earn-upcoming')), findsOneWidget);
    expect(find.byKey(Key('pto-earn-${_iso(on)}')), findsOneWidget);
    expect(find.text('Next: +3 days on ${_fmt(on)}'), findsOneWidget);
    expect(find.text('Expected · +3 days on ${_fmt(on)}'), findsOneWidget);
    for (final d in q.upcoming) {
      expect(find.byKey(Key('pto-earn-${_iso(d)}')), findsNothing);
    }
  });

  testWidgets('phone at 390×844: the bar, its dots and labels fit', (
    tester,
  ) async {
    final q = _quarterly();
    await _pump(tester, q.body, width: 390, height: 844);
    // The only person opens on their own; a list would need the tap.
    if (find.byKey(const Key('pto-resets')).evaluate().isEmpty) {
      await tester.tap(find.text('Lee Park').first);
      await tester.pumpAndSettle();
    }
    expect(tester.takeException(), isNull);
    await tester.scrollUntilVisible(
      find.byKey(const Key('pto-next-earning')),
      200,
      scrollable: find.byType(Scrollable).first,
    );
    expect(tester.takeException(), isNull);
    expect(tester.getSize(find.byKey(const Key('pto-bar-fill'))).height, 8);
    final track = tester.getRect(find.byKey(const Key('pto-bar-track')));
    expect(track.right, lessThanOrEqualTo(390));
    for (final on in [q.earned, ...q.upcoming]) {
      final r = tester.getRect(find.byKey(Key('pto-earn-${_iso(on)}')));
      expect(r.left, greaterThanOrEqualTo(track.left));
      expect(r.right, lessThanOrEqualTo(track.right));
    }
  });

  testWidgets('past resets: chips from resets, "No resets yet" for an empty '
      'list, "Last reset" without one', (tester) async {
    await _pump(tester, _body(), width: 1200);

    // Pat: no resets field at all.
    expect(find.text('Last reset ${_fmt(_inDays(-50))}'), findsOneWidget);
    expect(find.byKey(const Key('pto-past-resets')), findsNothing);

    await tester.tap(find.text('Dana Reyes'));
    await tester.pumpAndSettle();
    final chips = find.byKey(const Key('pto-past-resets'));
    expect(
      find.descendant(
        of: chips,
        matching: find.text('${_fmt(_inDays(-100))} · 2 expired'),
      ),
      findsOneWidget,
    );
    expect(
      find.descendant(
        of: chips,
        matching: find.text('${_fmt(_inDays(-465))} · 3 carried'),
      ),
      findsOneWidget,
    );
    expect(find.textContaining('Last reset'), findsNothing);

    await tester.tap(find.text('Sam Ortiz'));
    await tester.pumpAndSettle();
    expect(find.text('No resets yet'), findsOneWidget);
    expect(find.textContaining('Last reset'), findsNothing);
    expect(find.byKey(const Key('pto-event-reset')), findsNothing);
  });

  testWidgets('timeline: newest first, consecutive days as one run, distinct '
      'kinds', (tester) async {
    await _pump(tester, _body(), width: 1200);
    await tester.tap(find.text('Dana Reyes'));
    await tester.pumpAndSettle();

    final run = 'Days off · ${_md(_monthDay(3))}–${_monthDay(4).day} (2 days)';
    final lines = [
      run,
      'Total corrected 2 → 0',
      'Reset · ${_fmt(_inDays(-100))}',
      'Reset · ${_fmt(_inDays(-465))}',
      'Started · ${_fmt(_inDays(-830))}',
    ];
    final tops = [for (final l in lines) tester.getTopLeft(find.text(l)).dy];
    expect(tops, orderedEquals([...tops]..sort()));

    expect(
      find.text('recorded by Ari Bloom · ${_fmt(_inDays(-1))} · “Doctor”'),
      findsOneWidget,
    );
    expect(
      find.text('Ari Bloom · ${_fmt(_inDays(-40))} · “entered by mistake”'),
      findsOneWidget,
    );
  });

  testWidgets('remove on a run DELETEs each of its days and reads again', (
    tester,
  ) async {
    final requests = <http.Request>[];
    await _pump(
      tester,
      _body(),
      width: 1200,
      requests: requests,
      failReload: true,
    );
    await tester.tap(find.text('Dana Reyes'));
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const Key('pto-remove-d-1')));
    await tester.pumpAndSettle();

    final deletes = [
      for (final r in requests)
        if (r.method == 'DELETE') r.url.path,
    ];
    expect(deletes, [
      endsWith('/hr/people/u-2/pto/days/d-1'),
      endsWith('/hr/people/u-2/pto/days/d-2'),
    ]);
    expect(
      requests.where(
        (r) => r.method == 'GET' && r.url.path.endsWith('/hr/pto'),
      ),
      hasLength(2),
    );
    expect(
      find.descendant(
        of: find.byKey(const Key('pto-tile-used')),
        matching: find.text('0'),
      ),
      findsOneWidget,
    );
  });

  testWidgets('remove is not offered without can_edit_used', (tester) async {
    await _pump(tester, _body(), width: 1200);
    await tester.tap(find.text('Sam Ortiz'));
    await tester.pumpAndSettle();

    expect(find.byKey(const Key('pto-event-daysOff')), findsOneWidget);
    expect(find.byKey(const Key('pto-remove-s-1')), findsNothing);
    expect(find.byKey(const Key('pto-record-days')), findsNothing);
  });

  testWidgets('Record days off: inline, tap cycles full → half → clear, '
      'recorded days cannot be picked, and Save POSTs the portions', (
    tester,
  ) async {
    final requests = <http.Request>[];
    await _pump(
      tester,
      _body(),
      width: 1200,
      requests: requests,
      failReload: true,
    );
    await tester.tap(find.text('Dana Reyes'));
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const Key('pto-record-days')));
    await tester.pumpAndSettle();

    expect(find.byKey(const Key('pto-recorder')), findsOneWidget);
    expect(find.byKey(const Key('pto-calendar')), findsOneWidget);
    expect(find.byType(Dialog), findsNothing);
    expect(find.byKey(const Key('pto-record-days')), findsNothing);
    // Under the tiles, above the reset card.
    expect(
      tester.getTopLeft(find.byKey(const Key('pto-recorder'))).dy,
      greaterThan(
        tester.getBottomLeft(find.byKey(const Key('pto-tile-total'))).dy,
      ),
    );
    expect(
      tester.getTopLeft(find.byKey(const Key('pto-recorder'))).dy,
      lessThan(tester.getTopLeft(find.byKey(const Key('pto-resets'))).dy),
    );

    String picked() => tester
        .widget<Text>(find.byKey(const Key('pto-days-picked')))
        .textSpan!
        .toPlainText();

    // Recorded on the 3rd: tinted, and a tap does nothing.
    await tester.tap(find.byKey(Key('pto-day-${_iso(_monthDay(3))}')));
    await tester.pumpAndSettle();
    expect(picked(), startsWith('No days picked yet'));

    Future<void> tap(int day, [int times = 1]) async {
      for (var i = 0; i < times; i++) {
        await tester.tap(find.byKey(Key('pto-day-${_iso(_monthDay(day))}')));
        await tester.pumpAndSettle();
      }
    }

    await tap(10);
    expect(picked(), '1 day picked: ${_md(_monthDay(10))} · leaves 9');
    await tap(11, 2);
    await tap(20, 3);
    await tap(12);
    expect(
      picked(),
      '2.5 days picked: ${_md(_monthDay(10))}, ${_md(_monthDay(11))} (half), '
      '${_md(_monthDay(12))} · leaves 7.5',
    );

    await tester.enterText(
      find.byKey(const Key('pto-days-reason')),
      'Family trip',
    );
    await tester.tap(find.byKey(const Key('pto-days-save')));
    await tester.pumpAndSettle();

    final post = requests.singleWhere((r) => r.method == 'POST');
    expect(post.url.path, endsWith('/hr/people/u-2/pto/days'));
    expect(jsonDecode(post.body), {
      'days': [
        {'date': _iso(_monthDay(10)), 'portion': 1},
        {'date': _iso(_monthDay(11)), 'portion': 0.5},
        {'date': _iso(_monthDay(12)), 'portion': 1},
      ],
      'reason': 'Family trip',
    });

    // Closed, the tiles from the answer, and the list read again.
    expect(find.byKey(const Key('pto-recorder')), findsNothing);
    expect(
      find.descendant(
        of: find.byKey(const Key('pto-tile-used')),
        matching: find.text('4.5'),
      ),
      findsOneWidget,
    );
    expect(
      requests.where(
        (r) => r.method == 'GET' && r.url.path.endsWith('/hr/pto'),
      ),
      hasLength(2),
    );
  });

  testWidgets('a date already recorded shows the server’s sentence and keeps '
      'the editor open', (tester) async {
    const sentence =
        'Already recorded as a day off: 2026-11-10. Nothing was added.';
    await _pump(
      tester,
      _body(),
      width: 1200,
      post: () => _json({
        'message': sentence,
        'errors': {
          'days': ['2026-11-10'],
        },
      }, 422),
    );
    await tester.tap(find.text('Dana Reyes'));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('pto-record-days')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(Key('pto-day-${_iso(_monthDay(10))}')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('pto-days-save')));
    await tester.pumpAndSettle();

    expect(find.text(sentence), findsOneWidget);
    expect(find.text('2026-11-10'), findsNothing);
    expect(find.byKey(const Key('pto-recorder')), findsOneWidget);
  });

  testWidgets('‹ › move the calendar a month, and Cancel closes it', (
    tester,
  ) async {
    await _pump(tester, _body(), width: 1200);
    await tester.tap(find.text('Dana Reyes'));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('pto-record-days')));
    await tester.pumpAndSettle();

    final now = DateTime.now();
    final next = DateTime(now.year, now.month + 1);
    await tester.tap(find.byKey(const Key('pto-cal-next')));
    await tester.pumpAndSettle();
    expect(
      find.byKey(Key('pto-day-${_iso(DateTime(next.year, next.month, 1))}')),
      findsOneWidget,
    );
    await tester.tap(find.byKey(const Key('pto-cal-prev')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('pto-cal-prev')));
    await tester.pumpAndSettle();
    final previous = DateTime(now.year, now.month - 1);
    expect(
      find.byKey(
        Key('pto-day-${_iso(DateTime(previous.year, previous.month, 1))}'),
      ),
      findsOneWidget,
      reason: 'Past months can be recorded too.',
    );

    await tester.tap(find.byKey(const Key('pto-days-cancel')));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('pto-recorder')), findsNothing);
    expect(find.byKey(const Key('pto-record-days')), findsOneWidget);
  });

  testWidgets('phone at 390×844: tiles two by two, a compact ring, the '
      'calendar full screen in place, the timeline below', (tester) async {
    await _pump(tester, _body(), width: 390, height: 844);
    expect(tester.takeException(), isNull);

    Offset at(String k) => tester.getTopLeft(find.byKey(Key('pto-tile-$k')));
    expect(at('total').dy, at('used').dy);
    expect(at('left').dy, greaterThan(at('total').dy));
    expect(at('left').dy, at('expired').dy);
    expect(tester.getSize(find.byKey(const Key('pto-ring'))).width, 60);

    await tester.scrollUntilVisible(find.text('Dana Reyes'), 200);
    await tester.tap(find.text('Dana Reyes'));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('pto-phone-person')), findsOneWidget);
    expect(tester.takeException(), isNull);

    await tester.scrollUntilVisible(
      find.byKey(const Key('pto-timeline')),
      200,
      scrollable: find.byType(Scrollable).first,
    );
    expect(
      tester.getTopLeft(find.byKey(const Key('pto-timeline'))).dy,
      greaterThan(tester.getTopLeft(find.byKey(const Key('pto-resets'))).dy),
    );
    expect(tester.takeException(), isNull);

    await tester.scrollUntilVisible(
      find.byKey(const Key('pto-record-days')),
      -200,
      scrollable: find.byType(Scrollable).first,
    );
    await tester.tap(find.byKey(const Key('pto-record-days')));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('pto-phone-record')), findsOneWidget);
    expect(find.byKey(const Key('pto-calendar')), findsOneWidget);
    expect(find.byKey(const Key('pto-phone-person')), findsNothing);
    expect(find.byType(Navigator).evaluate().length, 1);
    expect(tester.takeException(), isNull);

    await tester.tap(find.byKey(Key('pto-day-${_iso(_monthDay(10))}')));
    await tester.pumpAndSettle();
    expect(tester.takeException(), isNull);

    await tester.tap(find.byIcon(Icons.arrow_back));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('pto-phone-record')), findsNothing);
  });
}

// ── fixtures ─────────────────────────────────────────────────────────

Map<String, dynamic> _day(
  String id,
  String date, {
  String? reason,
  num portion = 1,
}) => {
  'id': id,
  'date': date,
  'portion': portion,
  'reason': reason,
  'by_name': 'Ari Bloom',
  'at': '2026-10-22T15:00:00+00:00',
};

DateTime _inDays(int days) {
  final now = DateTime.now();
  return DateTime(now.year, now.month, now.day + days);
}

/// A day of the current month.
DateTime _monthDay(int day) {
  final now = DateTime.now();
  return DateTime(now.year, now.month, day);
}

String _iso(DateTime at) =>
    '${at.year}-${at.month.toString().padLeft(2, '0')}-'
    '${at.day.toString().padLeft(2, '0')}';

String _stamp(DateTime at) => '${_iso(at)}T12:00:00';

String _md(DateTime at) => '${PtoMaths.month(at)} ${at.day}';

String _fmt(DateTime at) => DisplayFormats.fallback.formatDate(at);

Future<void> _pump(
  WidgetTester tester,
  Map<String, dynamic> body, {
  required double width,
  double height = 1600,
  List<http.Request>? requests,
  http.Response Function()? post,
  bool failReload = false,
}) async {
  var reads = 0;
  tester.view.physicalSize = Size(width, height);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(
    client: MockClient((request) async {
      requests?.add(request);
      final path = request.url.path;
      if (path.endsWith('/me')) return _json({'data': _me});
      if (request.method == 'POST' && path.endsWith('/pto/days')) {
        return post?.call() ??
            _json({
              'data': {
                'total': 12,
                'used': 4.5,
                'left': 7.5,
                'would_expire': 7.5,
              },
            }, 201);
      }
      if (request.method == 'DELETE' && path.contains('/pto/days/')) {
        return _json({
          'data': {'total': 12, 'used': 0, 'left': 12, 'would_expire': 12},
        });
      }
      if (path.endsWith('/hr/pto')) {
        // The read after a write fails when asked, so what the tiles show is
        // what the write answered rather than the unchanged fixture.
        if (failReload && ++reads > 1) {
          return _json({'message': 'Try again.'}, 503);
        }
        if (!failReload) reads++;
        return _json(body);
      }
      return _json({'message': 'Unexpected $path'}, 500);
    }),
  );

  final session = Session(api);
  await tester.runAsync(() => session.adopt('tok'));

  await tester.pumpWidget(
    MaterialApp(
      key: UniqueKey(),
      theme: EzBiziTheme.light(),
      home: Scaffold(
        body: PtoTab(api: api, session: session),
      ),
    ),
  );
  await tester.pumpAndSettle();
}

Map<String, dynamic> _body() => {
  'policy': {
    'enabled': true,
    'allowance': 10,
    'allowance_period': 'year',
    'waiting': 90,
    'waiting_unit': 'day',
    'carry_over': false,
    'reset': 'anniversary',
  },
  'me': 'u-1',
  'data': [
    {
      'id': 'u-2',
      'name': 'Dana Reyes',
      'role': {'id': 'r-tech', 'name': 'Technician'},
      'allowance': 10,
      'started_on': _iso(_inDays(-830)),
      'eligible_from': _iso(_inDays(-740)),
      'eligible': true,
      'total': 12,
      'used': 2,
      'left': 10,
      'carried': 2,
      'expired': 2,
      'would_expire': 10,
      'period_start': _iso(_inDays(-100)),
      'resets_on': _iso(_inDays(265)),
      'can_edit_used': true,
      'days_off': [
        {
          'id': 'd-1',
          'date': _iso(_monthDay(3)),
          'portion': 1,
          'reason': 'Doctor',
          'by_name': 'Ari Bloom',
          'at': _stamp(_inDays(-1)),
        },
        {
          'id': 'd-2',
          'date': _iso(_monthDay(4)),
          'portion': 1,
          'reason': 'Doctor',
          'by_name': 'Ari Bloom',
          'at': _stamp(_inDays(-1)),
        },
      ],
      'used_changes': [
        {
          'at': _stamp(_inDays(-40)),
          'by_name': 'Ari Bloom',
          'from': 2,
          'to': 0,
          'reason': 'entered by mistake',
        },
      ],
      'resets': [
        {'on': _iso(_inDays(-100)), 'expired': 2, 'carried': 0},
        {'on': _iso(_inDays(-465)), 'expired': 0, 'carried': 3},
      ],
    },
    {
      'id': 'u-1',
      'name': 'Pat Lin',
      'role': {'id': 'r-office', 'name': 'Office'},
      'allowance': 6,
      'started_on': _iso(_inDays(-415)),
      'eligible_from': _iso(_inDays(-325)),
      'eligible': true,
      'total': 6,
      'used': 6,
      'left': 0,
      'carried': 0,
      'expired': 0,
      'would_expire': 0,
      'period_start': _iso(_inDays(-50)),
      'resets_on': _iso(_inDays(315)),
      'can_edit_used': false,
      'days_off': <Object>[],
      'used_changes': <Object>[],
    },
    {
      'id': 'u-3',
      'name': 'Sam Ortiz',
      'role': {'id': 'r-tech', 'name': 'Technician'},
      'allowance': 10,
      'started_on': _iso(_inDays(-20)),
      'eligible_from': _iso(_inDays(70)),
      'eligible': false,
      'total': 10,
      'used': 0,
      'left': 10,
      'carried': 0,
      'expired': 3,
      'would_expire': 10,
      'period_start': _iso(_inDays(-20)),
      'resets_on': _iso(_inDays(345)),
      'can_edit_used': false,
      'days_off': [
        {
          'id': 's-1',
          'date': _iso(_inDays(-2)),
          'portion': 0.5,
          'reason': null,
          'by_name': 'Ari Bloom',
          'at': _stamp(_inDays(-2)),
        },
      ],
      'used_changes': <Object>[],
      'resets': <Object>[],
    },
    {
      'id': 'u-5',
      'name': 'Rae Moss',
      'role': null,
      'allowance': 10,
      'started_on': null,
      'eligible_from': null,
      'eligible': false,
      'total': 10,
      'used': 0,
      'left': 10,
      'carried': null,
      'expired': null,
      'would_expire': null,
      'period_start': null,
      'resets_on': null,
      'can_edit_used': false,
      'days_off': <Object>[],
      'used_changes': <Object>[],
      'resets': <Object>[],
    },
  ],
};

http.Response _json(Object body, [int status = 200]) => http.Response(
  jsonEncode(body),
  status,
  headers: {'content-type': 'application/json'},
);

const _me = {
  'id': 'u-1',
  'name': 'Pat Lin',
  'email': 'pat@example.test',
  'has_avatar': false,
  'role': {'id': 'r-office', 'name': 'Office'},
  'permissions': ['hr.view'],
  'modules': [
    {
      'key': 'hr',
      'label': 'Human Resources',
      'available': true,
      'reachable': true,
    },
  ],
  'preferences': <String, dynamic>{},
  'organization': {
    'id': 'org-1',
    'name': 'Brightside Home Services',
    'franchise_label': 'Franchise',
    'is_read_only': false,
  },
  'scope': {
    'type': 'organization',
    'id': 'org-1',
    'label': 'Brightside Home Services',
    'path': ['Brightside Home Services'],
  },
  'companies': <Map<String, Object?>>[],
  'franchises': <Map<String, Object?>>[],
};

/// A quarterly policy, 2 days a quarter, accumulating from 3 months after
/// starting, resetting on the anniversary with carryover. Started 8 months
/// ago: one grant has landed this period and two are still to come.
({
  Map<String, dynamic> body,
  DateTime earned,
  List<DateTime> upcoming,
  DateTime end,
})
_quarterly() {
  final today = _inDays(0);
  final started = PtoMaths.addMonths(today, -8);
  final origin = PtoMaths.addMonths(started, 3);
  DateTime grant(int n) => PtoMaths.addMonths(origin, 3 * n);
  final end = PtoMaths.addMonths(started, 12);
  return (
    earned: grant(1),
    upcoming: [grant(2), grant(3)],
    end: end,
    body: {
      'policy': {
        'enabled': true,
        'allowance': 2,
        'allowance_period': 'quarter',
        'waiting': 0,
        'waiting_unit': 'day',
        'accrue_after': 3,
        'accrue_after_unit': 'month',
        'carry_over': true,
        'reset': 'anniversary',
      },
      'me': 'u-0',
      'data': [
        {
          'id': 'u-7',
          'name': 'Lee Park',
          'role': {'id': 'r-tech', 'name': 'Technician'},
          'allowance': 2,
          'started_on': _iso(started),
          'eligible_from': _iso(started),
          'eligible': true,
          'total': 2,
          'used': 0,
          'left': 2,
          'carried': 0,
          'expired': 0,
          'would_expire': 0,
          'resets': <Object>[],
          'earned': [
            {'on': _iso(grant(1)), 'days': 2},
          ],
          'period_start': _iso(started),
          'resets_on': _iso(end),
          'can_edit_used': false,
          'days_off': <Object>[],
          'used_changes': <Object>[],
        },
      ],
    },
  );
}
