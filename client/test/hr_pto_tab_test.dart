import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/hr/pto_tab.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Paid time off — Jose's desktop 1 and phone 4, 2026-09-17.
///
/// The states that carry the meaning are the two that are NOT a number: the
/// organization that has not set it up, which is the common case, and the
/// person whose start date nobody has, where the screen must refuse to guess.
void main() {
  testWidgets('with no policy the whole screen says so, and names the place', (
    tester,
  ) async {
    await _pump(tester, _body(enabled: false), width: 1200);

    expect(
      find.text('This organization has not set up paid time off.'),
      findsOneWidget,
    );
    expect(
      find.textContaining('Settings › Human Resources'),
      findsOneWidget,
      reason: 'Naming the place is what makes it actionable for somebody who can.',
    );

    // It replaces the screen rather than sitting beside it: the settings are
    // organization-scoped, so this is never one person's state.
    expect(find.text('6 days'), findsNothing);
    expect(find.text('When you can use them'), findsNothing);
  });

  testWidgets('desktop: the team beside one person, and the date in its own box', (
    tester,
  ) async {
    await _pump(tester, _body(), width: 1200);

    // Everybody in reach is in the list.
    expect(find.textContaining('Bea Okafor'), findsWidgets);
    expect(find.textContaining('Jon Ames'), findsWidgets);

    // And it opens on the reader's own panel without a tap. Asserted on
    // something only HER panel can say — "6 days" appears in the list rows
    // too, so it cannot tell whose panel is open.
    expect(find.text('Toula Elwell (you)'), findsOneWidget);
    expect(find.text('When you can use them'), findsOneWidget);
    expect(find.textContaining('You started on'), findsOneWidget);
  });

  /// The sentence names the NUMBER, never "three months".
  ///
  /// 90 is the setting and "three months" is a description of it, so a
  /// hardcoded phrase would go stale the moment somebody changed the setting —
  /// and would then be wrong in both directions at once.
  testWidgets('waiting: the date, and the rule stated as the setting', (
    tester,
  ) async {
    await _pump(tester, _body(), width: 1200);

    expect(find.textContaining('90 days after somebody starts here'), findsOneWidget);
    expect(find.textContaining('three months'), findsNothing);
  });

  /// The state that must not point at a door.
  ///
  /// There is no way to record a start date that already happened — both
  /// writers of a started employment event stamp today — so telling somebody
  /// to go and add it would have them deactivate and reactivate a colleague,
  /// manufacturing a confidently wrong date.
  testWidgets('unknown start date says why, and offers no remedy that would harm', (
    tester,
  ) async {
    await _pump(tester, _body(), width: 1200);

    await tester.tap(find.textContaining('Jon Ames').first);
    await tester.pumpAndSettle();

    expect(find.text('We cannot tell you'), findsOneWidget);
    expect(find.textContaining('any date would be a guess'), findsOneWidget);
    expect(
      find.textContaining('only been kept since this was added'),
      findsOneWidget,
      reason: 'Without this the reader assumes somebody lost it and goes looking.',
    );
    expect(find.textContaining('Somebody in HR can add it'), findsNothing);
  });

  testWidgets('phone: you first, everybody after', (tester) async {
    await _pump(tester, _body(), width: 390);

    expect(find.text('You'), findsOneWidget);
    expect(find.text('Everyone you can see'), findsOneWidget);

    // Your own panel is above the heading the others sit under.
    expect(
      tester.getTopLeft(find.text('You')).dy,
      lessThan(tester.getTopLeft(find.text('Everyone you can see')).dy),
    );
  });
}

Future<void> _pump(
  WidgetTester tester,
  Map<String, dynamic> body, {
  required double width,
}) async {
  tester.view.physicalSize = Size(width, 1600);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(client: MockClient((request) async {
    if (request.url.path.endsWith('/me')) {
      return _json({'data': _me});
    }
    if (request.url.path.endsWith('/hr/pto')) {
      return _json(body);
    }
    return _json({'message': 'Unexpected ${request.url.path}'}, 500);
  }));

  final session = Session(api);
  await tester.runAsync(() => session.adopt('tok'));

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(body: PtoTab(api: api, session: session)),
    ),
  );
  await tester.pumpAndSettle();
}

Map<String, dynamic> _body({bool enabled = true}) => {
  'policy': {
    'enabled': enabled,
    'days_per_year': 6,
    'waiting_days': 90,
    'carry_over': false,
  },
  'me': 'u-1',
  'data': [
    {
      'id': 'u-1',
      'name': 'Toula Elwell',
      'role': 'Office',
      'days_per_year': 6,
      'started_on': '2026-09-14',
      'eligible_from': '2026-12-13',
      'eligible': false,
      'taken': null,
    },
    {
      'id': 'u-2',
      'name': 'Bea Okafor',
      'role': 'Technician',
      'days_per_year': 6,
      'started_on': '2025-01-06',
      'eligible_from': '2025-04-06',
      'eligible': true,
      'taken': null,
    },
    {
      // Nobody on the books before employment_events has a start date.
      'id': 'u-3',
      'name': 'Jon Ames',
      'role': 'Technician',
      'days_per_year': 6,
      'started_on': null,
      'eligible_from': null,
      'eligible': false,
      'taken': null,
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
  'name': 'Toula Elwell',
  'email': 'toula@example.test',
  'has_avatar': false,
  'role': {'id': 'role-1', 'name': 'Office'},
  'permissions': ['hr.view'],
  'modules': [
    {'key': 'hr', 'label': 'Human Resources', 'available': true, 'reachable': true},
  ],
  'preferences': <String, dynamic>{},
  'organization': {
    'id': 'org-1',
    'name': 'A1-Group',
    'franchise_label': 'Franchise',
    'is_read_only': false,
  },
  'scope': {
    'type': 'organization',
    'id': 'org-1',
    'label': 'A1-Group',
    'path': ['A1-Group'],
  },
  'companies': <Map<String, Object?>>[],
  'franchises': <Map<String, Object?>>[],
};
