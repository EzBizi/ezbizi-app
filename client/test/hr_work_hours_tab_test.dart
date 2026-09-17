import 'dart:convert';
import 'dart:io';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/hr_screen.dart';
import 'package:ezbizi_client/widgets/tab_bar_like.dart';
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
    'organizationEnabled false and hr.view keep the other six chips',
    (tester) async {
      await _pump(tester, timeClock: _clock(organizationEnabled: false));

      expect(_chips(tester), [
        'Dashboard',
        'Onboarding',
        'Records',
        'Invitations',
        'Paid time off',
        'Contracts',
      ]);
      expect(_tab('Work Hours'), findsNothing);
    },
  );

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

      expect(_chips(tester), [
        'Dashboard',
        'Work Hours',
        'Onboarding',
        'Records',
        'Invitations',
        'Paid time off',
        'Contracts',
      ]);

      await _tap(tester, _tab('Work Hours'));

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

  /// The brief's most important gotcha, and the one I would have got wrong:
  /// everybody in reach is in `people`, including anyone who worked nothing.
  /// A name missing from this list reads as "not on the team", which is not
  /// what "did not clock in" means.
  testWidgets('somebody who worked nothing is listed, not dropped', (
    tester,
  ) async {
    await _pump(
      tester,
      timeClock: _clock(organizationEnabled: true),
      onClock: (_, _) => _week(
        people: [
          _person(),
          _person(
            id: 'u-9',
            name: 'Jon Park',
            total: 0,
            days: 0,
            average: 0,
            shifts: const [],
          ),
        ],
      ),
    );

    await _tap(tester, _tab('Work Hours'));

    expect(
      find.text('Jon Park'),
      findsOneWidget,
      reason: 'Dropping him would say he is not on the team.',
    );
    expect(
      find.text('\u2014'),
      findsWidgets,
      reason: 'His total is an em dash rather than 0h 00m, which would read '
          'as a measurement somebody made.',
    );
  });

  testWidgets('the three numbers he asked for are on the person', (
    tester,
  ) async {
    await _pump(
      tester,
      timeClock: _clock(organizationEnabled: true),
      onClock: (_, _) => _week(
        people: [_person(total: 29100, days: 4, average: 7275)],
      ),
    );

    await _tap(tester, _tab('Work Hours'));

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

    await _tap(tester, _tab('Work Hours'));

    expect(find.text('Auto'), findsOneWidget);
    expect(find.text('Outside schedule'), findsOneWidget);
    expect(find.text('+2'), findsOneWidget);
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

    await _tap(tester, _tab('Work Hours'));

    expect(find.text('still clocked in'), findsOneWidget);
    expect(
      find.text('9:05 AM'),
      findsNothing,
      reason: 'There is no clock-out to draw.',
    );
    expect(
      find.text('1h 05m'),
      findsWidgets,
      reason: 'The time so far is summed into the total, so it is shown '
          'rather than hidden behind an em dash.',
    );
  });

  testWidgets('a week nobody worked says so', (tester) async {
    await _pump(
      tester,
      timeClock: _clock(organizationEnabled: true),
      onClock: (_, _) => _week(people: const []),
    );

    await _tap(tester, _tab('Work Hours'));

    // The real string, not one I invented: UiStrings falls back to its own
    // English map, and asserting my own wording would have passed only
    // against a build where the copy was missing.
    expect(find.text('Nothing clocked this week.'), findsOneWidget);
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

    await _tap(tester, _tab('Work Hours'));
    expect(ons, [null]);

    await _tap(tester, find.byIcon(Icons.chevron_right));

    expect(
      ons,
      [null, '2026-08-30'],
      reason: 'Seven days, not one. The tab this replaces had day arrows and '
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

    await _tap(tester, _tab('Work Hours'));

    expect(find.text('Tuesday, Aug 25'), findsOneWidget);
    expect(
      find.text('Sunday, Aug 23'),
      findsOneWidget,
      reason: 'A day nobody worked still gets a heading, which is why the '
          'seven days are the loop rather than the shifts.',
    );
  });

  test('the tab names no weekday of its own', () {
    final source = File('lib/screens/hr/work_hours_tab.dart').readAsStringSync();

    expect(
      source,
      isNot(contains("'Wednesday'")),
      reason: 'A weekday name here is locale text on a screen that ships in '
          'two languages. Three other widgets in this tree each hardcode '
          'their own English list; this one asked for the payload instead.',
    );
  });

  test('the tab does not read the device clock', () {
    final file = File('lib/screens/hr/work_hours_tab.dart');
    expect(file.existsSync(), isTrue);
    expect(file.readAsStringSync(), isNot(contains('DateTime.now()')));
  });
}

Future<void> _pump(
  WidgetTester tester, {
  required Map<String, Object?> timeClock,
  Map<String, dynamic> Function(Uri uri, http.Request request)? onClock,
  Size size = const Size(1400, 1600),
}) async {
  tester.view.physicalSize = size;
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(
    client: MockClient((request) async {
      final path = request.url.path;

      if (path.endsWith('/me')) {
        return _json({'data': _me(timeClock: timeClock)});
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

      return _json({'message': 'Unexpected ${request.method} $path'}, 500);
    }),
  );
  final session = Session(api);

  await tester.runAsync(() => session.adopt('tok'));

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(
        body: HrScreen(api: api, session: session),
      ),
    ),
  );
  await tester.pumpAndSettle();
}

Finder _tab(String label) => find.widgetWithText(ChoiceChip, label);

List<String> _chips(WidgetTester tester) => tester
    .widgetList<ChoiceChip>(
      find.descendant(
        of: find.byType(TabBarLike),
        matching: find.byType(ChoiceChip),
      ),
    )
    .map((chip) => (chip.label as Text).data!)
    .toList();

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
  'days': const [
    {'date': '2026-08-23', 'weekday': 'sunday', 'label': 'Sunday, Aug 23'},
    {'date': '2026-08-24', 'weekday': 'monday', 'label': 'Monday, Aug 24'},
    {'date': '2026-08-25', 'weekday': 'tuesday', 'label': 'Tuesday, Aug 25'},
    {'date': '2026-08-26', 'weekday': 'wednesday', 'label': 'Wednesday, Aug 26'},
    {'date': '2026-08-27', 'weekday': 'thursday', 'label': 'Thursday, Aug 27'},
    {'date': '2026-08-28', 'weekday': 'friday', 'label': 'Friday, Aug 28'},
    {'date': '2026-08-29', 'weekday': 'saturday', 'label': 'Saturday, Aug 29'},
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
};

const _bea = {
  'id': 's-1',
  'date': '2026-08-25',
  'clocked_in_at': '2026-08-25T13:00:00Z',
  'clocked_out_at': '2026-08-25T14:05:00Z',
  'clocked_in': '8:00 AM',
  'clocked_out': '9:05 AM',
  'duration_seconds': 3900,
  'open': false,
  'ended_by': 'person',
  'outside_schedule': false,
  'extensions': 0,
};

Map<String, Object?> _me({required Map<String, Object?> timeClock}) => {
  'id': 'u-1',
  'name': 'Jose Silva',
  'email': 'jose@example.com',
  'has_avatar': false,
  'role': {'id': 'role-1', 'name': 'Administrator'},
  'permissions': ['hr.view', 'hr.create', 'hr.edit'],
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
