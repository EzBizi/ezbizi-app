import 'dart:convert';
import 'dart:io';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/deep_links.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/work_hours_screen.dart';
import 'package:ezbizi_client/widgets/side_panel.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// HR › Work Hours — the week (Jose: desktop 1, mobile 4, 2026-09-17).
///
/// This file replaces the one-day list's tests rather than starting fresh.
/// Four of its assertions are CARRIED OVER, and finding them is how I learned
/// the old screen showed things my rewrite had silently dropped: `Auto` for a
/// shift the system closed, and the extension count. Neither is on Jose's
/// list, and neither was mine to remove — a screen quietly telling somebody
/// less than it used to is a loss whether or not anybody asked.
void main() {
  testWidgets(
    'the first fetch has no on, and the names, clocks and zone come from the '
    'payload',
    (tester) async {
      final requests = <Uri>[];

      await _pump(
        tester,
        timeClock: _clock(organizationEnabled: true),
        onClock: (uri, _) {
          requests.add(uri);

          return _week(people: [_person()]);
        },
      );

      // General is the landing tab of Work Hours; nothing to tap.

      expect(requests, hasLength(1));
      expect(requests.single.path, '/api/v1/hr/time-clock/week');
      expect(
        requests.single.queryParameters.containsKey('on'),
        isFalse,
        reason: 'The first fetch is the server\'s view-local week, not ?on=.',
      );

      expect(find.text('Bea Okafor'), findsWidgets);
      expect(find.text('8:00 AM'), findsOneWidget);
      expect(find.text('9:05 AM'), findsOneWidget);
      expect(find.text('UTC-5'), findsOneWidget);
    },
  );

  /// Jose, 2026-09-30: "by default it only shows people who have clocked in
  /// on that week … most recent on the top". The server still sends
  /// everybody in reach; somebody who worked nothing is one tap away, not
  /// dropped, and a search finds them too.
  testWidgets('only who clocked in, latest first; the rest one tap away', (
    tester,
  ) async {
    await _pump(
      tester,
      timeClock: _clock(organizationEnabled: true),
      onClock: (_, _) => _week(
        // The server's order: Cal first. Bea clocked in later, so she leads.
        people: [
          _person(
            id: 'u-3',
            name: 'Cal Reyes',
            shifts: [
              {
                ..._bea,
                'id': 's-3',
                'date': '2026-08-24',
                'clocked_in_local': '2026-08-24 09:00',
              },
            ],
          ),
          _person(
            id: 'u-9',
            name: 'Jon Park',
            total: 0,
            days: 0,
            average: 0,
            shifts: const [],
          ),
          _person(),
        ],
      ),
    );

    final list = find.byType(ListView).first;
    Finder row(String name) =>
        find.descendant(of: list, matching: find.text(name));

    expect(row('Jon Park'), findsNothing, reason: 'He clocked nothing.');
    expect(
      tester.getTopLeft(row('Bea Okafor')).dy,
      lessThan(tester.getTopLeft(row('Cal Reyes')).dy),
      reason: 'Tuesday 8:00 is more recent than Monday 9:00.',
    );

    await tester.tap(find.text('Show 1 who did not clock in'));
    await tester.pumpAndSettle();
    expect(row('Jon Park'), findsOneWidget);
    expect(
      tester.getTopLeft(row('Cal Reyes')).dy,
      lessThan(tester.getTopLeft(row('Jon Park')).dy),
      reason: 'Everyone else comes after who clocked in.',
    );

    await tester.tap(find.text('Only who clocked in'));
    await tester.pumpAndSettle();
    expect(row('Jon Park'), findsNothing);

    await tester.enterText(find.byType(TextField).first, 'jon');
    await tester.pumpAndSettle();
    expect(
      row('Jon Park'),
      findsOneWidget,
      reason: 'A search looks at everyone.',
    );
  });

  /// Jose, 2026-09-30: "Also filters to see certain roles only".
  testWidgets('a role filter narrows the list to that role', (tester) async {
    await _pump(
      tester,
      timeClock: _clock(organizationEnabled: true),
      onClock: (_, _) => _week(
        people: [
          {
            ..._person(),
            'role': {'id': 'r-1', 'name': 'Dispatcher'},
          },
          {
            ..._person(id: 'u-3', name: 'Cal Reyes'),
            'role': {'id': 'r-2', 'name': 'Technician'},
          },
        ],
      ),
    );

    final list = find.byType(ListView).first;
    Finder row(String name) =>
        find.descendant(of: list, matching: find.text(name));
    expect(row('Cal Reyes'), findsOneWidget);

    await tester.tap(find.text('All roles'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Dispatcher').last);
    await tester.pumpAndSettle();

    expect(row('Bea Okafor'), findsOneWidget);
    expect(row('Cal Reyes'), findsNothing, reason: 'Technician, filtered out.');
  });

  testWidgets('the three numbers he asked for are on the person', (
    tester,
  ) async {
    await _pump(
      tester,
      timeClock: _clock(organizationEnabled: true),
      onClock: (_, _) =>
          _week(people: [_person(total: 29100, days: 4, average: 7275)]),
    );

    // General is the landing tab of Work Hours; nothing to tap.

    // The total appears twice on purpose -- on the person's row in the list
    // AND in their Total tile -- so the ones that distinguish this assertion
    // are the two the list does not carry.
    expect(find.text('8h 05m'), findsWidgets);
    expect(find.text('4'), findsOneWidget);
    expect(find.text('2h 01m'), findsOneWidget);
  });

  /// CARRIED OVER. The old row showed all three and my rewrite dropped two of
  /// them; this test is how I found out.
  testWidgets('a shift shows Auto, Outside schedule and the extensions', (
    tester,
  ) async {
    await _pump(
      tester,
      timeClock: _clock(organizationEnabled: true),
      onClock: (_, _) => _week(
        people: [
          _person(
            shifts: [
              {
                ..._bea,
                'ended_by': 'schedule',
                'outside_schedule': true,
                'extensions': 2,
              },
            ],
          ),
        ],
      ),
    );

    // General is the landing tab of Work Hours; nothing to tap.

    expect(find.text('Auto'), findsOneWidget);
    expect(find.text('Outside schedule'), findsOneWidget);
    expect(find.text('+2'), findsOneWidget);
  });

  /// Jose, 2026-10-01: on the shift with an Outside schedule chip the total
  /// and its ⋯ sat mid-row, and every day total did too. The chip's Flexible
  /// and the Spacer were splitting the free space. Every total ends at the
  /// same right edge, chip or no chip.
  testWidgets('totals line up at the right, whatever chips a shift has', (
    tester,
  ) async {
    await _pump(
      tester,
      timeClock: _clock(organizationEnabled: true),
      onClock: (_, _) => _week(
        people: [
          _person(
            total: 3900 + 1800,
            shifts: [
              {..._bea, 'outside_schedule': true, 'ended_by': 'correction'},
              {
                ..._bea,
                'id': 's-2',
                'duration_seconds': 1800,
                'clocked_in_local': '2026-08-25 10:00',
              },
            ],
          ),
        ],
      ),
    );

    final chipped = tester.getTopRight(find.text('1h 05m').last).dx;
    final plain = tester.getTopRight(find.text('0h 30m').last).dx;
    expect(
      chipped,
      plain,
      reason: 'The Outside schedule row ends where the plain one does.',
    );

    final day = tester.getTopRight(find.text('1h 35m').last).dx;
    expect(
      day,
      greaterThan(plain - 60),
      reason: 'The day total is at the right, not mid-row.',
    );
  });

  /// An open shift reports the time worked SO FAR rather than null, because
  /// it is summed into the total beside it. So the duration is a number here,
  /// and `open` is what says it is still running.
  testWidgets('an open shift is green, running, and still counts', (
    tester,
  ) async {
    await _pump(
      tester,
      timeClock: _clock(organizationEnabled: true),
      onClock: (_, _) => _week(
        people: [
          _person(
            open: true,
            shifts: [
              {..._bea, 'clocked_out': null, 'open': true},
            ],
          ),
        ],
      ),
    );

    // General is the landing tab of Work Hours; nothing to tap.

    expect(find.text('still clocked in'), findsOneWidget);
    expect(
      find.text('9:05 AM'),
      findsNothing,
      reason: 'There is no clock-out to draw.',
    );
    expect(
      find.text('1h 05m'),
      findsWidgets,
      reason:
          'The time so far is summed into the total, so it is shown '
          'rather than hidden behind an em dash.',
    );
  });

  testWidgets('a week nobody worked says so', (tester) async {
    await _pump(
      tester,
      timeClock: _clock(organizationEnabled: true),
      onClock: (_, _) => _week(people: const []),
    );

    // General is the landing tab of Work Hours; nothing to tap.

    // The real string, not one I invented: UiStrings falls back to its own
    // English map, and asserting my own wording would have passed only
    // against a build where the copy was missing.
    expect(find.text('Nothing clocked this week.'), findsOneWidget);
  });

  // A 409 is the organization's switch, not a failure. It was drawn as a red
  // error with Try again, which no number of tries can change.
  testWidgets('clock in switched off says where it is switched on', (
    tester,
  ) async {
    await _pump(
      tester,
      timeClock: _clock(organizationEnabled: false),
      onWrite: (request) => request.url.path.endsWith('/hr/time-clock/week')
          ? _json({
              'message': 'Clock in is switched off for this organization.',
            }, 409)
          : null,
    );

    expect(
      find.text('Clock in is switched off for this organization.'),
      findsOneWidget,
    );
    expect(find.textContaining('Work Hours › Settings'), findsOneWidget);
    expect(find.text('Try again'), findsNothing);
  });

  testWidgets('the arrows move a WEEK and refetch with on=', (tester) async {
    final ons = <String?>[];

    await _pump(
      tester,
      timeClock: _clock(organizationEnabled: true),
      onClock: (uri, _) {
        final on = uri.queryParameters['on'];
        ons.add(on);

        return _week(
          start: on ?? '2026-08-23',
          end: '2026-08-29',
          people: [_person()],
        );
      },
    );

    // General is the landing tab of Work Hours; nothing to tap.
    expect(ons, [null]);

    await _tap(tester, find.byIcon(Icons.chevron_right));

    expect(
      ons,
      [null, '2026-08-30'],
      reason:
          'Seven days, not one. The tab this replaces had day arrows and '
          'this one is a week, so a one-day step would look like a bug.',
    );
  });

  /// The day headings are the SERVER'S words, in the organization's language.
  ///
  /// This screen used a local English weekday array until Clock In put the
  /// labels on the payload. The assertion uses a label no local formatter
  /// would produce, because "Monday 24 Aug" and "Monday, Aug 24" are both
  /// plausible English and only one of them can have come from the server.
  testWidgets('day headings come from the payload, not from this screen', (
    tester,
  ) async {
    await _pump(
      tester,
      timeClock: _clock(organizationEnabled: true),
      onClock: (_, _) => _week(people: [_person()]),
    );

    // General is the landing tab of Work Hours; nothing to tap.

    expect(find.text('Tuesday, Aug 25'), findsOneWidget);
    expect(
      find.text('Sunday, Aug 23'),
      findsOneWidget,
      reason:
          'A day nobody worked still gets a heading, which is why the '
          'seven days are the loop rather than the shifts.',
    );
  });

  /// Jose, 2026-09-17: *"make it so it's descending / so sep 19 first / then
  /// 18 / then 17 / also do not show the future days"*.
  testWidgets('the newest day is first and the week stops at today', (
    tester,
  ) async {
    await _pump(
      tester,
      timeClock: _clock(organizationEnabled: true),
      onClock: (_, _) => _week(people: [_person()]),
    );

    // General is the landing tab of Work Hours; nothing to tap.

    // Tuesday is today in this fixture, so the week ends there.
    expect(find.text('Wednesday, Aug 26'), findsNothing);
    expect(find.text('Saturday, Aug 29'), findsNothing);

    // Order, read off the screen rather than off the list: Tuesday must sit
    // ABOVE Sunday. Asserting both are present would pass either way round,
    // which is the whole of what changed.
    final tuesday = tester.getTopLeft(find.text('Tuesday, Aug 25')).dy;
    final sunday = tester.getTopLeft(find.text('Sunday, Aug 23')).dy;
    expect(
      tuesday,
      lessThan(sunday),
      reason:
          'Newest first: Tuesday is later than Sunday and must be higher '
          'up the page.',
    );

    // AND THE HEADING STILL SPANS THE WHOLE WEEK. It is built from the first
    // and last labels of `days`, so this is what fails if somebody reverses
    // or filters that list instead of the loop over it -- which is the one
    // way to make the days right and the header silently wrong.
    expect(
      find.text('Sunday, Aug 23 – Saturday, Aug 29'),
      findsOneWidget,
      reason:
          'The week is still Sunday to Saturday even when four of its '
          'days have not happened yet.',
    );
  });

  /// NO USER-FACING STRING IN THIS SCREEN IS WRITTEN HERE.
  ///
  /// Clock In found two hardcoded English tags in this file, reported it
  /// clean on the strength of a grep for `text:` and `Text(`, and that grep
  /// could not see a `tooltip:` -- so there were five. **The fix is not a
  /// better pattern.** A pattern only knows the shapes somebody thought of,
  /// and the next bare string will arrive in a shape neither of us listed.
  ///
  /// So this looks at the STRINGS rather than at the parameters holding them:
  /// any literal that reads as a sentence, wherever it sits. A key has no
  /// space; a duration format starts with a digit or a brace; prose does not.
  test('no sentence is hardcoded in this screen', () {
    final source = File(
      'lib/screens/hr/work_hours_tab.dart',
    ).readAsStringSync();

    // Doc comments and comments are prose by design.
    final code = source
        .split('\n')
        .where((line) {
          final t = line.trimLeft();

          return !t.startsWith('///') && !t.startsWith('//');
        })
        .join('\n');

    final prose = RegExp("'([A-Za-z][A-Za-z]* [a-z][A-Za-z ,.]*)'");
    final found = prose.allMatches(code).map((m) => m.group(1)!).toSet();

    // Empty, and it stays empty. It held three strings until `b166aae1`
    // landed the keys AND the Dart fallbacks -- using a key before its
    // fallback exists renders the key itself in a tooltip, which is worse
    // than the English it replaces. They left this list and the screen in
    // one commit, which is what the exemption said it would do.
    const pending = <String>{};

    expect(
      found.difference(pending),
      isEmpty,
      reason:
          'A sentence written here ships English to a Spanish '
          'organization. It belongs in UiStrings, and Clock In adds the row.',
    );
  });

  test('the tab names no weekday of its own', () {
    final source = File(
      'lib/screens/hr/work_hours_tab.dart',
    ).readAsStringSync();

    expect(
      source,
      isNot(contains("'Wednesday'")),
      reason:
          'A weekday name here is locale text on a screen that ships in '
          'two languages. Three other widgets in this tree each hardcode '
          'their own English list; this one asked for the payload instead.',
    );
  });

  test('the tab does not read the device clock', () {
    final file = File('lib/screens/hr/work_hours_tab.dart');
    expect(file.existsSync(), isTrue);
    expect(file.readAsStringSync(), isNot(contains('DateTime.now()')));
  });

  // ── Corrections: a ⋯ on every shift and every day (2026-09-24) ──────
  //
  // Jose picked layout 1 for both sizes: a menu on a desk, a bottom sheet on
  // a phone, the same items either way. Absent without hr.hours.correct.

  testWidgets('without work_hours.manage there is no ⋯ anywhere', (
    tester,
  ) async {
    await _pump(
      tester,
      timeClock: _clock(organizationEnabled: true),
      onClock: (_, _) => _week(people: [_person()]),
    );
    // General is the landing tab of Work Hours; nothing to tap.

    expect(find.text('8:00 AM'), findsOneWidget, reason: 'the pane rendered');
    expect(find.byTooltip('Shift actions'), findsNothing);
    expect(find.byTooltip('Day actions'), findsNothing);
  });

  testWidgets(
    'deleting a shift asks first, and the week the server answers replaces '
    'the screen',
    (tester) async {
      final writes = <http.Request>[];

      await _pump(
        tester,
        timeClock: _clock(organizationEnabled: true),
        grants: const ['work_hours.manage'],
        onClock: (_, _) => _week(people: [_person()]),
        onWrite: (request) {
          writes.add(request);

          return _json({
            'data': _week(
              people: [
                _person(total: 0, days: 0, average: 0, shifts: const []),
              ],
            ),
          });
        },
      );
      // General is the landing tab of Work Hours; nothing to tap.

      await _tap(tester, find.byTooltip('Shift actions'));
      expect(
        find.text('Clock out now'),
        findsNothing,
        reason: 'a finished shift has nothing to clock out of',
      );
      await _tap(tester, find.text('Delete shift'));

      expect(writes, isEmpty, reason: 'nothing is sent before the confirm');
      await _tap(tester, find.widgetWithText(FilledButton, 'Delete shift'));

      expect(writes.single.method, 'DELETE');
      expect(writes.single.url.path, '/api/v1/hr/time-clock/shifts/s-1');
      expect(
        find.text('8:00 AM'),
        findsNothing,
        reason: 'the answer IS the new week; nothing is recomputed here',
      );
    },
  );

  testWidgets('an open shift offers Clock out now, at the server clock', (
    tester,
  ) async {
    final writes = <http.Request>[];
    final open = <String, Object?>{
      ..._bea,
      'open': true,
      'clocked_out': null,
      'clocked_out_at': null,
      'clocked_out_local': null,
    };

    await _pump(
      tester,
      timeClock: _clock(organizationEnabled: true),
      grants: const ['work_hours.manage'],
      onClock: (_, _) => _week(
        people: [
          _person(open: true, shifts: [open]),
        ],
      ),
      onWrite: (request) {
        writes.add(request);

        return _json({
          'data': _week(people: [_person()]),
        });
      },
    );
    // General is the landing tab of Work Hours; nothing to tap.

    await _tap(tester, find.byTooltip('Shift actions'));
    await _tap(tester, find.text('Clock out now'));

    expect(writes.single.method, 'POST');
    expect(
      writes.single.url.path,
      '/api/v1/hr/time-clock/shifts/s-1/clock-out',
    );
    expect(
      writes.single.body,
      '{}',
      reason: 'no time is sent: the device clock is not the one that counts',
    );
  });

  /// The edit dialog starts from the server's wall clock and sends the same
  /// shape back. Saving without touching anything proves no timezone was
  /// applied on the way through — the error that ran every reminder an hour
  /// late would show here as a shifted hour.
  testWidgets('editing round-trips wall clock without converting it', (
    tester,
  ) async {
    final writes = <http.Request>[];

    await _pump(
      tester,
      timeClock: _clock(organizationEnabled: true),
      grants: const ['work_hours.manage'],
      onClock: (_, _) => _week(people: [_person()]),
      onWrite: (request) {
        writes.add(request);

        return _json({
          'data': _week(people: [_person()]),
        });
      },
    );
    // General is the landing tab of Work Hours; nothing to tap.

    await _tap(tester, find.byTooltip('Shift actions'));
    await _tap(tester, find.text('Edit times'));
    await _tap(tester, find.widgetWithText(FilledButton, 'Save'));

    expect(writes.single.method, 'PATCH');
    expect(jsonDecode(writes.single.body), {
      'clocked_in_at': '2026-08-25 08:00',
      'clocked_out_at': '2026-08-25 09:05',
    });
  });

  testWidgets('a day tag is set from the day menu', (tester) async {
    final writes = <http.Request>[];

    await _pump(
      tester,
      timeClock: _clock(organizationEnabled: true),
      grants: const ['work_hours.manage'],
      onClock: (_, _) => _week(people: [_person()]),
      onWrite: (request) {
        writes.add(request);

        return _json({
          'data': _week(people: [_person()]),
        });
      },
    );
    // General is the landing tab of Work Hours; nothing to tap.

    // Tuesday is the newest past day, so its ⋯ is the first one drawn.
    await _tap(tester, find.byTooltip('Day actions'));
    await _tap(tester, find.text('Set tag'));
    await _tap(tester, find.text('Sick'));

    expect(writes.single.method, 'PUT');
    expect(
      writes.single.url.path,
      '/api/v1/hr/time-clock/people/u-2/days/2026-08-25',
    );
    expect(jsonDecode(writes.single.body), {'tag': 'sick'});
  });

  testWidgets(
    'a day record draws its tag, its extra time and its note, and the day '
    'total counts the extra time',
    (tester) async {
      await _pump(
        tester,
        timeClock: _clock(organizationEnabled: true),
        onClock: (_, _) => _week(
          people: [
            _person(
              records: const [
                {
                  'date': '2026-08-25',
                  'tag': 'home',
                  'extra_minutes': 90,
                  'note': 'Stayed for the Henderson call.',
                },
              ],
            ),
          ],
        ),
      );
      // General is the landing tab of Work Hours; nothing to tap.

      expect(find.text('Home'), findsOneWidget);
      expect(find.text('Extra time'), findsOneWidget);
      expect(find.text('+1h 30m'), findsOneWidget);
      expect(find.text('Stayed for the Henderson call.'), findsOneWidget);
      // 1h 05m clocked plus 90 minutes nobody's clock saw.
      expect(find.text('2h 35m'), findsOneWidget);
    },
  );

  testWidgets('on a phone the ⋯ opens a bottom sheet with the same items', (
    tester,
  ) async {
    await _pump(
      tester,
      timeClock: _clock(organizationEnabled: true),
      grants: const ['work_hours.manage'],
      size: const Size(390, 844),
      onClock: (_, _) => _week(people: [_person()]),
    );
    // General is the landing tab of Work Hours; nothing to tap.
    await _tap(tester, find.text('Bea Okafor'));

    await _tap(tester, find.byTooltip('Shift actions'));

    expect(find.byType(BottomSheet), findsOneWidget);
    expect(
      find.descendant(
        of: find.byType(BottomSheet),
        matching: find.text('Edit times'),
      ),
      findsOneWidget,
    );
    expect(
      find.descendant(
        of: find.byType(BottomSheet),
        matching: find.text('Delete shift'),
      ),
      findsOneWidget,
    );
  });

  /// Jose's containment rule: on a phone a person draws in the tab's own
  /// area with its own way back, never as a pushed full-screen page; and the
  /// edit forms are side panels, not dialogs.
  testWidgets('on a phone a person opens in place, and edits open a panel', (
    tester,
  ) async {
    await _pump(
      tester,
      timeClock: _clock(organizationEnabled: true),
      grants: const ['work_hours.manage'],
      size: const Size(390, 844),
      onClock: (_, _) => _week(people: [_person()]),
    );
    final scaffolds = find.byType(Scaffold).evaluate().length;
    await _tap(tester, find.text('Bea Okafor'));

    expect(find.byType(AppBar), findsNothing, reason: 'no pushed page');
    expect(
      find.byType(Scaffold).evaluate().length,
      scaffolds,
      reason: 'no new route, so no second Scaffold',
    );
    expect(find.byTooltip('Shift actions'), findsWidgets);

    await _tap(tester, find.byTooltip('Shift actions'));
    await _tap(tester, find.text('Edit times').last);
    expect(find.byType(SidePanel), findsOneWidget);
    expect(find.byType(AlertDialog), findsNothing);
    await _tap(tester, find.text('Cancel'));

    await _tap(tester, find.byIcon(Icons.arrow_back));
    expect(
      find.byTooltip('Shift actions'),
      findsNothing,
      reason: 'back on the list',
    );
  });

  // ── Add shift, asking for a day, and the marks (Jose, 2026-10-01) ──

  testWidgets('a requested change reads Shift corrected, an unasked one '
      'Edited, and your own edit nothing', (tester) async {
    await _pump(
      tester,
      timeClock: _clock(organizationEnabled: true),
      onClock: (_, _) => _week(
        people: [
          _person(
            shifts: [
              {..._bea, 'edited': 'request'},
              {..._bea, 'id': 's-2', 'edited': 'hr'},
              // Ended by a correction, but your own: no mark.
              {..._bea, 'id': 's-3', 'ended_by': 'correction'},
            ],
          ),
        ],
      ),
    );

    expect(find.text('Shift corrected'), findsOneWidget);
    expect(find.text('Edited'), findsOneWidget);
  });

  testWidgets('HR adds a shift on a day with nothing, from the day menu', (
    tester,
  ) async {
    final writes = <http.Request>[];
    await _pump(
      tester,
      timeClock: _clock(organizationEnabled: true),
      grants: const ['work_hours.manage'],
      onClock: (_, _) => _week(
        people: [
          {
            ..._person(),
            'schedule': {
              'monday': {'start': '08:00', 'end': '16:00'},
            },
          },
        ],
      ),
      onWrite: (request) {
        writes.add(request);

        return _json({
          'data': _week(people: [_person()]),
        });
      },
    );

    // Newest first: Tuesday, Monday, Sunday.
    await _tap(tester, find.byTooltip('Day actions').at(1));
    await _tap(tester, find.text('Add shift'));
    expect(find.byType(SidePanel), findsOneWidget);
    await _tap(tester, find.widgetWithText(FilledButton, 'Save'));

    expect(writes.single.method, 'POST');
    expect(writes.single.url.path, '/api/v1/hr/time-clock/people/u-2/shifts');
    expect(jsonDecode(writes.single.body), {
      'clocked_in_at': '2026-08-24 08:00',
      'clocked_out_at': '2026-08-24 16:00',
    }, reason: 'their own hours for that weekday');
  });

  testWidgets('without manage, your own day asks for its hours, no shift', (
    tester,
  ) async {
    final writes = <http.Request>[];
    await _pump(
      tester,
      timeClock: _clock(organizationEnabled: true),
      onClock: (_, _) => _week(
        people: [_person(id: 'u-1', name: 'Anisa Telfort')],
      ),
      onWrite: (request) {
        writes.add(request);

        return _json({
          'data': {'id': 'r-1', 'status': 'pending'},
        }, 201);
      },
    );

    await _tap(tester, find.byTooltip('Day actions').at(1));
    expect(find.text('Add shift'), findsNothing);
    await _tap(tester, find.text('Request hours for this day'));
    await tester.enterText(
      find.byKey(const Key('correction-reason')),
      'Worked the switch-over day.',
    );
    await tester.pumpAndSettle();
    await _tap(tester, find.widgetWithText(FilledButton, 'Send for approval'));

    expect(writes.single.url.path, '/api/v1/time-clock/corrections');
    expect(jsonDecode(writes.single.body), {
      'clocked_in_at': '2026-08-24 09:00',
      'clocked_out_at': '2026-08-24 17:00',
      'reason': 'Worked the switch-over day.',
    });
  });

  // ── Work Hours, its own module (Jose, 2026-09-24) ─────────────────

  testWidgets('the module draws only the tabs the role holds', (tester) async {
    await _pump(
      tester,
      timeClock: _clock(organizationEnabled: true),
      onClock: (_, _) => _week(people: [_person()]),
    );

    expect(find.widgetWithText(ChoiceChip, 'General'), findsOneWidget);
    expect(find.widgetWithText(ChoiceChip, 'Paid Time Off'), findsNothing);
    expect(find.widgetWithText(ChoiceChip, 'Settings'), findsNothing);
  });

  testWidgets('General, Paid Time Off and Settings, in that order', (
    tester,
  ) async {
    await _pump(
      tester,
      timeClock: _clock(organizationEnabled: true),
      grants: const ['work_hours.pto.view', 'work_hours.settings.view'],
      onClock: (_, _) => _week(people: [_person()]),
    );

    final labels = tester
        .widgetList<ChoiceChip>(find.byType(ChoiceChip))
        .map((chip) => (chip.label as Text).data)
        .toList();

    expect(labels, ['General', 'Paid Time Off', 'Settings']);
  });

  /// Jose: "right now only users can set their schedule on their own, i need
  /// a panel to be able to set it for them as well". The card is on every
  /// person's week; Edit is there for work_hours.manage and nobody else.
  testWidgets('the schedule card shows the hours, and Edit only with manage', (
    tester,
  ) async {
    final person = {
      ..._person(),
      'schedule': {
        'monday': {'start': '08:00', 'end': '17:00'},
      },
      'schedule_is_personal': false,
    };

    await _pump(
      tester,
      timeClock: _clock(organizationEnabled: true),
      onClock: (_, _) => _week(people: [person]),
    );

    expect(find.text('Work schedule'), findsOneWidget);
    expect(find.text('08:00'), findsOneWidget);
    expect(
      find.text('No schedule of their own — these are the business hours.'),
      findsOneWidget,
    );
    expect(find.widgetWithText(TextButton, 'Edit'), findsNothing);

    await tester.pumpWidget(const SizedBox.shrink());
    await _pump(
      tester,
      timeClock: _clock(organizationEnabled: true),
      grants: const ['work_hours.manage'],
      onClock: (_, _) => _week(people: [person]),
    );

    expect(find.widgetWithText(TextButton, 'Edit'), findsOneWidget);
  });

  // ── Work Hours › Reports (2026-09-28) ─────────────────────────────

  http.Response? reportsServer(
    http.Request request,
    List<http.Request> writes,
  ) {
    final path = request.url.path;
    if (path.endsWith('/hr/time-clock/reports') && request.method == 'GET') {
      return _json({
        'data': [
          {
            'id': 'r-1',
            'name': 'Weekly hours',
            'role_ids': ['role-t'],
            'include_user_ids': <String>[],
            'exclude_user_ids': <String>[],
            'recipients': ['dismanager@a-1chimney.com'],
          },
        ],
        'roles': [
          {'id': 'role-t', 'name': 'Technician'},
        ],
        'people': [
          {
            'id': 'u-2',
            'name': 'Bea Okafor',
            'email': 'bea@x.test',
            'role_id': 'role-t',
          },
        ],
      });
    }
    if (path.endsWith('/reports/r-1/preview')) {
      return _json({
        'data': {
          'week_start': '2026-09-20',
          'week_end': '2026-09-26',
          'range': 'Sunday, Sep 20 – Saturday, Sep 26',
          'rows': [
            {
              'id': 'u-2',
              'name': 'Bea Okafor',
              'seconds': 30600,
              'hours': '8:30',
              'days_worked': 1,
            },
          ],
          'total_seconds': 30600,
          'total': '8:30',
          'recipients': ['dismanager@a-1chimney.com'],
        },
      });
    }
    if (path.endsWith('/reports/r-1/send')) {
      writes.add(request);
      return _json({
        'data': {
          'to': ['dismanager@a-1chimney.com'],
          'rows': 1,
          'range': 'Sunday, Sep 20 – Saturday, Sep 26',
        },
      });
    }

    return null;
  }

  testWidgets('Reports lists a saved report, previews last week and sends it', (
    tester,
  ) async {
    final writes = <http.Request>[];

    await _pump(
      tester,
      timeClock: _clock(organizationEnabled: true),
      grants: const ['work_hours.reports.view', 'work_hours.manage'],
      onClock: (_, _) => _week(people: [_person()]),
      onWrite: (request) => reportsServer(request, writes),
    );

    await tester.tap(find.widgetWithText(ChoiceChip, 'Reports'));
    await tester.pumpAndSettle();

    expect(find.text('Weekly hours'), findsOneWidget);
    expect(find.text('Technician'), findsOneWidget);
    expect(find.text('To: dismanager@a-1chimney.com'), findsOneWidget);

    await tester.tap(find.text('Preview'));
    await tester.pumpAndSettle();

    expect(find.text('Sunday, Sep 20 – Saturday, Sep 26'), findsOneWidget);
    expect(find.text('Total (1 employee)'), findsOneWidget);
    expect(
      find.text('8:30'),
      findsNWidgets(2),
      reason: 'the row and the total',
    );

    await tester.tap(find.text('Send now'));
    await tester.pumpAndSettle();

    expect(writes.single.method, 'POST');
    expect(writes.single.url.queryParameters['week'], 'last');
    expect(find.text('Sent to 1 address.'), findsOneWidget);
  });

  testWidgets('without manage the Reports tab reads and cannot send or edit', (
    tester,
  ) async {
    await _pump(
      tester,
      timeClock: _clock(organizationEnabled: true),
      grants: const ['work_hours.reports.view'],
      onClock: (_, _) => _week(people: [_person()]),
      onWrite: (request) => reportsServer(request, []),
    );

    await tester.tap(find.widgetWithText(ChoiceChip, 'Reports'));
    await tester.pumpAndSettle();

    expect(
      find.text('Weekly hours'),
      findsOneWidget,
      reason: 'the tab rendered',
    );
    expect(find.text('New report'), findsNothing);
    expect(find.byTooltip('Edit report'), findsNothing);
    expect(find.byTooltip('Delete report'), findsNothing);

    await tester.tap(find.text('Preview'));
    await tester.pumpAndSettle();

    expect(find.text('Total (1 employee)'), findsOneWidget);
    expect(find.text('Send now'), findsNothing);
  });

  // ── Asking for a correction (Trey Brannan's suggestion, 2026-09-30) ──

  testWidgets(
    'without manage, your own shift offers only Request a correction, '
    'and it sends the times with what happened',
    (tester) async {
      final writes = <http.Request>[];

      await _pump(
        tester,
        timeClock: _clock(organizationEnabled: true),
        onClock: (_, _) => _week(
          people: [_person(id: 'u-1', name: 'Jose Silva')],
        ),
        onWrite: (request) {
          writes.add(request);

          return _json({
            'data': {'id': 'r-1', 'status': 'pending'},
          }, 201);
        },
      );

      await _tap(tester, find.byTooltip('Shift actions'));
      expect(find.text('Edit times'), findsNothing);
      expect(find.text('Delete shift'), findsNothing);
      await _tap(tester, find.text('Request a correction'));

      final send = find.widgetWithText(FilledButton, 'Send for approval');
      expect(
        tester.widget<FilledButton>(send).onPressed,
        isNull,
        reason: 'what happened is required',
      );

      await tester.enterText(
        find.byKey(const Key('correction-reason')),
        'Forgot to clock in.',
      );
      await tester.pumpAndSettle();
      await _tap(tester, send);

      expect(writes.single.method, 'POST');
      expect(writes.single.url.path, '/api/v1/time-clock/corrections');
      expect(jsonDecode(writes.single.body), {
        'clocked_in_at': '2026-08-25 08:00',
        'clocked_out_at': '2026-08-25 09:05',
        'reason': 'Forgot to clock in.',
        'shift_id': 's-1',
      });
      expect(find.text('Sent for approval.'), findsOneWidget);
    },
  );

  /// Jose, 2026-10-01: the shift you are in can be asked about too, for its
  /// start only; its end is your own Clock Out.
  testWidgets('your running shift can be asked about, start time only', (
    tester,
  ) async {
    final writes = <http.Request>[];

    await _pump(
      tester,
      timeClock: _clock(organizationEnabled: true),
      onClock: (_, _) => _week(
        people: [
          _person(
            id: 'u-1',
            name: 'Jose Silva',
            open: true,
            shifts: [
              {
                ..._bea,
                'clocked_out': null,
                'clocked_out_local': null,
                'open': true,
              },
            ],
          ),
        ],
      ),
      onWrite: (request) {
        writes.add(request);

        return _json({
          'data': {'id': 'r-1', 'status': 'pending'},
        }, 201);
      },
    );

    await _tap(tester, find.byTooltip('Shift actions'));
    await _tap(tester, find.text('Request a correction'));
    expect(find.byType(SidePanel), findsOneWidget);
    expect(
      find.descendant(
        of: find.byType(SidePanel),
        matching: find.text('Clock Out'),
      ),
      findsNothing,
      reason: 'still on the clock: there is no end to correct',
    );

    await tester.enterText(
      find.byKey(const Key('correction-reason')),
      'Got here at 7.',
    );
    await tester.pumpAndSettle();
    await _tap(tester, find.widgetWithText(FilledButton, 'Send for approval'));

    expect(jsonDecode(writes.single.body), {
      'clocked_in_at': '2026-08-25 08:00',
      'reason': 'Got here at 7.',
      'shift_id': 's-1',
    });
  });

  testWidgets("without manage, somebody else's shift has no menu at all", (
    tester,
  ) async {
    await _pump(
      tester,
      timeClock: _clock(organizationEnabled: true),
      onClock: (_, _) => _week(people: [_person()]),
    );

    expect(find.byTooltip('Shift actions'), findsNothing);
    expect(find.byTooltip('Day actions'), findsNothing);
  });

  /// Notifications, 2026-10-12: tapping a correction notification "should
  /// open the exact thing". The approver lands on the requester, at the
  /// week of the asked-for clock-in. The control is the first load, which
  /// selects the first person and asks for this week.
  testWidgets('a tapped correction notification opens its person and week', (
    tester,
  ) async {
    final asked = <Uri>[];
    DeepLinks.open(
      const DeepLink(
        module: 'work_hours',
        type: 'time_clock_correction_request',
        id: 'r-1',
      ),
    );
    addTearDown(() => DeepLinks.pending.value = null);

    await _pump(
      tester,
      timeClock: _clock(organizationEnabled: true),
      grants: const ['work_hours.manage'],
      onClock: (uri, _) {
        asked.add(uri);

        return {
          ..._week(
            people: [
              _person(),
              _person(id: 'u-9', name: 'Jon Park'),
            ],
          ),
          'correction_requests': [
            {
              'id': 'r-1',
              'user': {'id': 'u-9', 'name': 'Jon Park'},
              'clocked_in': '2026-08-10 07:30',
              'clocked_out': '2026-08-10 15:00',
              'reason': 'Forgot to clock in.',
              'status': 'pending',
            },
          ],
        };
      },
    );

    expect(asked.first.queryParameters['on'], isNull);
    expect(asked.last.queryParameters['on'], '2026-08-10');
    // List row, the waiting card, and the detail pane: three only when he
    // is the one selected (Bea is first, and would otherwise be).
    expect(find.text('Jon Park'), findsNWidgets(3));
    expect(DeepLinks.pending.value, isNull, reason: 'taken, once');
  });

  testWidgets('a manager sees waiting requests on the week and approves one', (
    tester,
  ) async {
    final writes = <http.Request>[];
    var waiting = true;

    await _pump(
      tester,
      timeClock: _clock(organizationEnabled: true),
      grants: const ['work_hours.manage'],
      onClock: (_, _) => {
        ..._week(people: [_person()]),
        'correction_requests': [
          if (waiting)
            {
              'id': 'r-1',
              'user': {'id': 'u-2', 'name': 'Bea Okafor'},
              'was_in': '2026-08-25 08:00',
              'was_out': '2026-08-25 09:05',
              'clocked_in': '2026-08-25 07:30',
              'clocked_out': '2026-08-25 09:05',
              'reason': 'Forgot to clock in.',
              'status': 'pending',
            },
        ],
      },
      onWrite: (request) {
        writes.add(request);
        waiting = false;

        return _json({
          'data': {'id': 'r-1', 'status': 'approved'},
        });
      },
    );

    final card = find.byKey(const Key('correction-requests'));
    expect(card, findsOneWidget);
    expect(
      find.descendant(of: card, matching: find.text('Forgot to clock in.')),
      findsOneWidget,
    );
    expect(
      find.descendant(
        of: card,
        matching: find.text('Asks for: 2026-08-25 07:30 – 2026-08-25 09:05'),
      ),
      findsOneWidget,
    );

    await _tap(tester, find.widgetWithText(FilledButton, 'Approve'));

    expect(
      writes.single.url.path,
      '/api/v1/hr/time-clock/corrections/r-1/approve',
    );
    expect(card, findsNothing, reason: 'the week came back without it');
  });
}

Future<void> _pump(
  WidgetTester tester, {
  required Map<String, Object?> timeClock,
  Map<String, dynamic> Function(Uri uri, http.Request request)? onClock,
  Size size = const Size(1400, 1600),
  List<String> grants = const [],
  http.Response? Function(http.Request request)? onWrite,
}) async {
  tester.view.physicalSize = size;
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(
    client: MockClient((request) async {
      final path = request.url.path;

      if (path.endsWith('/me')) {
        return _json({'data': _me(timeClock: timeClock, grants: grants)});
      }

      if (path.endsWith('/hr/dashboard')) {
        return _json({
          'period': 'month',
          'period_starts_on': '2026-08-01T00:00:00-04:00',
          'period_ends_on': '2026-08-24T16:00:00-04:00',
          'glance': _dashboardGlance,
        });
      }

      if (path.endsWith('/hr/time-clock/week')) {
        final payload = onClock?.call(request.url, request);
        if (payload != null) return _json({'data': payload});
      }

      final written = onWrite?.call(request);
      if (written != null) return written;

      return _json({'message': 'Unexpected ${request.method} $path'}, 500);
    }),
  );
  final session = Session(api);

  await tester.runAsync(() => session.adopt('tok'));

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(
        body: WorkHoursScreen(api: api, session: session),
      ),
    ),
  );
  await tester.pumpAndSettle();
}

Future<void> _tap(WidgetTester tester, Finder finder) async {
  final scrollable = find.ancestor(
    of: finder,
    matching: find.byType(Scrollable),
  );

  if (scrollable.evaluate().isNotEmpty) {
    await tester.ensureVisible(finder.first);
    await tester.pumpAndSettle();
  }

  await tester.tap(finder.first);
  await tester.pumpAndSettle();
}

http.Response _json(Object body, [int status = 200]) => http.Response(
  jsonEncode(body),
  status,
  headers: {'content-type': 'application/json'},
);

Map<String, Object?> _clock({required bool organizationEnabled}) => {
  'organization_enabled': organizationEnabled,
  'can_clock': false,
  'show_button': false,
  'open': null,
};

/// A week, shaped like the endpoint Clock In built.
Map<String, dynamic> _week({
  String start = '2026-08-23',
  String end = '2026-08-29',
  String timezoneLabel = 'UTC-5',
  List<Map<String, Object?>> people = const [],
}) => {
  'week_start': start,
  'week_end': end,
  'starts_on': 'sunday',
  // All seven, in the organization's own language, from the server -- so a
  // day nobody worked still has a heading and this screen never names one.
  // `in_future` is the SERVER's, against today in the view's timezone. The
  // only today this side has is the device's, which is a different day for
  // anybody reading an organization set elsewhere.
  //
  // This fixture is a week in progress: Tuesday is today, Wednesday on has
  // not arrived. Today is never in the future.
  'days': const [
    {
      'date': '2026-08-23',
      'weekday': 'sunday',
      'label': 'Sunday, Aug 23',
      'in_future': false,
    },
    {
      'date': '2026-08-24',
      'weekday': 'monday',
      'label': 'Monday, Aug 24',
      'in_future': false,
    },
    {
      'date': '2026-08-25',
      'weekday': 'tuesday',
      'label': 'Tuesday, Aug 25',
      'in_future': false,
    },
    {
      'date': '2026-08-26',
      'weekday': 'wednesday',
      'label': 'Wednesday, Aug 26',
      'in_future': true,
    },
    {
      'date': '2026-08-27',
      'weekday': 'thursday',
      'label': 'Thursday, Aug 27',
      'in_future': true,
    },
    {
      'date': '2026-08-28',
      'weekday': 'friday',
      'label': 'Friday, Aug 28',
      'in_future': true,
    },
    {
      'date': '2026-08-29',
      'weekday': 'saturday',
      'label': 'Saturday, Aug 29',
      'in_future': true,
    },
  ],
  'timezone': 'Etc/GMT+5',
  'timezone_label': timezoneLabel,
  'people': people,
};

Map<String, Object?> _person({
  String id = 'u-2',
  String name = 'Bea Okafor',
  int total = 3900,
  int days = 1,
  int average = 3900,
  bool open = false,
  List<Map<String, Object?>> shifts = const [_bea],
  List<Map<String, Object?>> records = const [],
}) => {
  'id': id,
  'name': name,
  'is_active': true,
  'has_avatar': false,
  'total_seconds': total,
  'days_worked': days,
  'average_seconds': average,
  'open': open,
  'shifts': shifts,
  'days': records,
};

const _bea = {
  'id': 's-1',
  'date': '2026-08-25',
  'clocked_in_at': '2026-08-25T13:00:00Z',
  'clocked_out_at': '2026-08-25T14:05:00Z',
  'clocked_in': '8:00 AM',
  'clocked_out': '9:05 AM',
  'clocked_in_local': '2026-08-25 08:00',
  'clocked_out_local': '2026-08-25 09:05',
  'duration_seconds': 3900,
  'open': false,
  'ended_by': 'person',
  'outside_schedule': false,
  'extensions': 0,
};

Map<String, Object?> _me({
  required Map<String, Object?> timeClock,
  List<String> grants = const [],
}) => {
  'id': 'u-1',
  'name': 'Jose Silva',
  'email': 'jose@example.com',
  'has_avatar': false,
  'role': {'id': 'role-1', 'name': 'Administrator'},
  // Per-tab grants arrived in `08d98e07`: a chip now needs `hr.<tab>.view` as
  // well as the module. Listed one by one rather than with a wildcard, because
  // the point of that change is that a tab nobody granted does not appear, and
  // a fixture that granted everything by pattern could never show that.
  'permissions': [
    'hr.view',
    'hr.create',
    'hr.edit',
    'hr.dashboard.view',
    'hr.hours.view',
    'hr.onboarding.view',
    'hr.records.view',
    'hr.invitations.view',
    'hr.pto.view',
    'hr.contracts.view',
    // Work Hours' own tabs since it left HR (2026-09-24).
    'work_hours.view',
    'work_hours.general.view',
    'work_hours.all',
    ...grants,
  ],
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
  'companies': <Map<String, Object?>>[],
  'franchises': <Map<String, Object?>>[],
  'time_clock': timeClock,
};

const _dashboardGlance = {
  'key': 'hr',
  'label': 'Human Resources',
  'presentation': 'bar',
  'allowed_presentations': ['bar', 'pie', 'text'],
  'snapshot': true,
  'coming_soon': false,
  'options': <String, dynamic>{},
  'data': {
    'empty': false,
    'slices': [
      {'label': 'Active', 'total': 0},
      {'label': 'Inactive', 'total': 0},
    ],
    'sentences': ['1 person on the books.'],
  },
};
