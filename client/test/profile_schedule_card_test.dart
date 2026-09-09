import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/profile_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// The Work schedule card on Your account (design 2026-09-08 §10.3).
void main() {
  testWidgets('shows the week read-only when the organization does not allow edits', (
    tester,
  ) async {
    await _pump(tester, canEdit: false);

    expect(find.text('Work schedule'), findsOneWidget);
    expect(find.text('Mon'), findsOneWidget);
    expect(find.text('Sun'), findsOneWidget);
    expect(find.text('08:00'), findsNWidgets(5));
    expect(find.text('17:03'), findsOneWidget);
    expect(find.text('16:35'), findsOneWidget);
    expect(find.text('Day off'), findsNWidgets(2));
    expect(find.text('Edit'), findsNothing);
  });

  testWidgets('offers Edit when the organization allows it', (tester) async {
    await _pump(tester, canEdit: true);

    expect(find.text('Work schedule'), findsOneWidget);
    expect(find.text('Edit'), findsOneWidget);
  });

  /// Layout 5, chosen 2026-09-09: a phone leads with today rather than making
  /// you find it among seven equal rows.
  testWidgets('on a phone, today is lifted out and not repeated below', (
    tester,
  ) async {
    await _pump(tester, canEdit: false, width: 400, height: 2400);

    // On a phone the card is not inline: the screen shows a summary row that
    // opens it. Reached by its icon rather than its text, because the text is
    // today's hours and changes with the day the suite runs.
    await tester.tap(find.byIcon(Icons.schedule_outlined));
    await tester.pumpAndSettle();

    // Independent of the widget's own list indexing: this names the day from
    // Dart's weekday constants rather than by position, so a `days` list
    // reordered to start on Sunday fails here instead of shipping a card that
    // is confidently one day out.
    final today = switch (DateTime.now().weekday) {
      DateTime.monday => 'MONDAY',
      DateTime.tuesday => 'TUESDAY',
      DateTime.wednesday => 'WEDNESDAY',
      DateTime.thursday => 'THURSDAY',
      DateTime.friday => 'FRIDAY',
      DateTime.saturday => 'SATURDAY',
      _ => 'SUNDAY',
    };

    expect(find.text('TODAY · $today'), findsOneWidget);

    // Six short rows, not seven: today is above, and showing it twice is the
    // failure this layout exists to avoid.
    final shorts = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
    final shown = shorts.where((d) => find.text(d).evaluate().isNotEmpty);
    expect(shown.length, 6, reason: 'Expected six day rows beside the today '
        'block, found ${shown.length}: ${shown.join(', ')}.');

    final missing = shorts.firstWhere((d) => find.text(d).evaluate().isEmpty);
    expect(today.substring(0, 3), missing.toUpperCase());
  });

  testWidgets('the desktop card is unchanged: seven equal rows, no today block', (
    tester,
  ) async {
    await _pump(tester, canEdit: false);

    expect(find.textContaining('TODAY'), findsNothing);
    expect(find.text('Mon'), findsOneWidget);
    expect(find.text('Sun'), findsOneWidget);
  });

  /// This test's NAME always said "says so"; its assertion accepted seven
  /// "Day off" rows, which is what a person who genuinely works no days looks
  /// like. Two opposite facts rendering identically, and the assertion could
  /// not tell them apart. Now it asserts what the name always claimed.
  testWidgets('no schedule at all says so and still offers nothing to a reader', (
    tester,
  ) async {
    await _pump(tester, canEdit: false, schedule: null);

    expect(find.text('Work schedule'), findsOneWidget);
    expect(find.textContaining('No working hours have been set'), findsOneWidget);
    expect(find.text('Day off'), findsNothing,
        reason: 'An unwritten schedule is being drawn as seven days off, which '
            'is a different fact.');
    expect(find.text('Edit'), findsNothing);
  });

  /// The row is the only thing a phone shows before a tap, so it carries the
  /// answer rather than the working pattern.
  testWidgets('the phone row leads with today, not with which days you work', (
    tester,
  ) async {
    await _pump(tester, canEdit: false, width: 400, height: 2400);

    expect(find.text('Mon Tue Wed Thu Fri'), findsNothing);

    // Whatever today is, the row says something about TODAY: the hours from
    // the fixture, or that it is a day off. Saturday and Sunday are null in
    // `_week`, so both branches are reachable depending on the day it runs.
    final weekend = DateTime.now().weekday >= DateTime.saturday;
    expect(
      weekend
          ? find.text('Off today')
          : find.textContaining('Today 08:00'),
      findsOneWidget,
    );
  });
}

const _week = {
  'timezone': 'Etc/GMT+5',
  'days': {
    'monday': {'start': '08:00', 'end': '17:03'},
    'tuesday': {'start': '08:00', 'end': '17:10'},
    'wednesday': {'start': '08:00', 'end': '17:15'},
    'thursday': {'start': '08:00', 'end': '17:07'},
    'friday': {'start': '08:00', 'end': '16:35'},
    'saturday': null,
    'sunday': null,
  },
};

Future<void> _pump(
  WidgetTester tester, {
  required bool canEdit,
  Map<String, Object?>? schedule = _week,
  double width = 1280,
  double height = 900,
}) async {
  tester.view.physicalSize = Size(width, height);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(
    client: MockClient((request) async {
      final path = request.url.path;
      if (path.endsWith('/me')) {
        return _json({'data': _me});
      }
      if (path.endsWith('/me/sessions')) {
        return _json({'sessions': <Map<String, Object?>>[]});
      }
      if (path.endsWith('/me/profile')) {
        return _json({
          'first_name': 'Jose',
          'middle_name': '',
          'last_name': 'Demasi',
          'phone': '',
          'address': '',
          'gender': '',
          'has_avatar': false,
          'date_of_birth': null,
          'work_schedule': schedule,
          'can_edit_schedule': canEdit,
        });
      }
      return _json({'message': 'Nothing stubbed for $path.'}, 500);
    }),
  );

  final session = Session(api);
  await tester.runAsync(() => session.adopt('tok'));

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(body: ProfileScreen(api: api, session: session)),
    ),
  );
  await tester.pumpAndSettle();
}

http.Response _json(Object body, [int status = 200]) => http.Response(
  jsonEncode(body),
  status,
  headers: const {'content-type': 'application/json'},
);

const _me = {
  'id': 'u-1',
  'name': 'Jose Demasi',
  'email': 'jose@example.com',
  'has_avatar': false,
  'role': {'id': 'role-1', 'name': 'Technician'},
  'permissions': <String>['time_clock.view'],
  'modules': <Map<String, Object?>>[],
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
};
