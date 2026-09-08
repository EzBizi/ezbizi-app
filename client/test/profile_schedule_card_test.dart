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

  testWidgets('no schedule at all says so and still offers nothing to a reader', (
    tester,
  ) async {
    await _pump(tester, canEdit: false, schedule: null);

    expect(find.text('Work schedule'), findsOneWidget);
    expect(find.text('Day off'), findsNWidgets(7));
    expect(find.text('Edit'), findsNothing);
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
}) async {
  tester.view.physicalSize = const Size(1280, 900);
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
