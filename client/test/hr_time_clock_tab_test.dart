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

/// Human Resources Clock in–out chip and one-day list (design §9, §11).
void main() {
  testWidgets(
    'organizationEnabled false and hr.view keep the original five chips',
    (tester) async {
      await _pump(tester, timeClock: _clock(organizationEnabled: false));

      expect(_chips(tester), [
        'Dashboard',
        'Onboarding',
        'Records',
        'Invitations',
        'Contracts',
      ]);
      expect(_tab('Clock in\u2013out'), findsNothing);
    },
  );

  testWidgets(
    'organizationEnabled and hr.view add Clock in–out; the first fetch has '
    'no on and the date and clocks come from the payload',
    (tester) async {
      final clockRequests = <Uri>[];

      await _pump(
        tester,
        timeClock: _clock(organizationEnabled: true),
        onClock: (uri, _) {
          clockRequests.add(uri);
          return _day(
            date: '2026-08-25',
            timezoneLabel: 'UTC-5',
            shifts: [_bea],
          );
        },
      );

      expect(_chips(tester), [
        'Dashboard',
        'Onboarding',
        'Records',
        'Invitations',
        'Contracts',
        'Clock in\u2013out',
      ]);
      expect(
        clockRequests,
        isEmpty,
        reason: 'Fetch only once the chip is shown.',
      );

      await _tap(tester, _tab('Clock in\u2013out'));

      expect(clockRequests, hasLength(1));
      expect(clockRequests.single.path, '/api/v1/hr/time-clock');
      expect(
        clockRequests.single.queryParameters.containsKey('on'),
        isFalse,
        reason: 'The first fetch is the server’s view-local today, not ?on=.',
      );

      expect(find.text('Bea Okafor'), findsOneWidget);
      expect(find.text('8:00 AM'), findsOneWidget);
      expect(find.text('9:05 AM'), findsOneWidget);
      expect(find.text('1:05'), findsOneWidget);
      expect(find.text('Times in UTC-5'), findsOneWidget);
      expect(find.text('2026-08-25'), findsOneWidget);
    },
  );

  testWidgets('a row shows Auto, Outside schedule and the extension count', (
    tester,
  ) async {
    await _pump(
      tester,
      timeClock: _clock(organizationEnabled: true),
      onClock: (_, _) => _day(
        date: '2026-08-25',
        shifts: [
          {
            ..._bea,
            'ended_by': 'schedule',
            'outside_schedule': true,
            'extensions': 2,
          },
        ],
      ),
    );

    await _tap(tester, _tab('Clock in\u2013out'));

    expect(find.text('Auto'), findsOneWidget);
    expect(find.text('Outside schedule'), findsOneWidget);
    expect(find.text('+2'), findsOneWidget);
  });

  testWidgets('empty shifts show the empty-day sentence', (tester) async {
    await _pump(
      tester,
      timeClock: _clock(organizationEnabled: true),
      onClock: (_, _) => _day(date: '2026-08-25', shifts: const []),
    );

    await _tap(tester, _tab('Clock in\u2013out'));

    expect(find.text('Nobody clocked in on this day.'), findsOneWidget);
    expect(find.text('Bea Okafor'), findsNothing);
  });

  testWidgets('an open shift says In and uses an em dash for duration', (
    tester,
  ) async {
    await _pump(
      tester,
      timeClock: _clock(organizationEnabled: true),
      onClock: (_, _) => _day(
        date: '2026-08-25',
        shifts: [
          {
            ..._bea,
            'clocked_out_at': null,
            'clocked_out': null,
            'duration_seconds': null,
            'open': true,
          },
        ],
      ),
    );

    await _tap(tester, _tab('Clock in\u2013out'));

    expect(find.text('Bea Okafor'), findsOneWidget);
    expect(find.text('8:00 AM'), findsOneWidget);
    expect(find.text('In'), findsOneWidget);
    expect(find.text('\u2014'), findsOneWidget);
    expect(find.text('9:05 AM'), findsNothing);
  });

  testWidgets('moving the day forward refetches with on=', (tester) async {
    final ons = <String?>[];

    await _pump(
      tester,
      timeClock: _clock(organizationEnabled: true),
      onClock: (uri, _) {
        final on = uri.queryParameters['on'];
        ons.add(on);
        return _day(
          date: on ?? '2026-08-25',
          timezoneLabel: 'UTC-5',
          shifts: on == null || on == '2026-08-25' ? [_bea] : const [],
        );
      },
    );

    await _tap(tester, _tab('Clock in\u2013out'));

    expect(ons, [null]);
    expect(find.text('2026-08-25'), findsOneWidget);

    await _tap(tester, find.byIcon(Icons.chevron_right));

    expect(ons, [null, '2026-08-26']);
    expect(find.text('2026-08-26'), findsOneWidget);
    expect(find.text('Nobody clocked in on this day.'), findsOneWidget);
  });

  test('the tab does not read the device clock', () {
    final file = File('lib/screens/hr/time_clock_tab.dart');
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

      if (path.endsWith('/hr/time-clock')) {
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

Map<String, dynamic> _day({
  required String date,
  String timezoneLabel = 'UTC-5',
  List<Map<String, Object?>> shifts = const [],
}) => {
  'date': date,
  'timezone': 'Etc/GMT+5',
  'timezone_label': timezoneLabel,
  'shifts': shifts,
};

const _bea = {
  'id': 's-1',
  'user': {'id': 'u-2', 'name': 'Bea Okafor'},
  'clocked_in_at': '2026-08-25T13:00:00Z',
  'clocked_out_at': '2026-08-25T14:05:00Z',
  'clocked_in': '8:00 AM',
  'clocked_out': '9:05 AM',
  'duration_seconds': 3900,
  'open': false,
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
