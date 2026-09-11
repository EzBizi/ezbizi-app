import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/reports_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

void main() {
  testWidgets('a saved report is listed by name', (tester) async {
    await _pumpReports(tester, reports: [_report]);

    expect(find.text('Open jobs'), findsOneWidget);
  });

  testWidgets('an empty library tells a builder they can make the first one', (
    tester,
  ) async {
    await _pumpReports(tester, reports: []);

    expect(find.text('No reports built yet.'), findsOneWidget);
    expect(
      find.text('Build one and it appears here for everybody who can run it.'),
      findsOneWidget,
    );
    // The other empty state belongs to a RUN, and the two mean opposite things.
    expect(find.textContaining('No rows matched'), findsNothing);
  });

  testWidgets('an empty library tells a runner somebody else makes the first', (
    tester,
  ) async {
    await _pumpReports(
      tester,
      reports: [],
      permissions: const ['reports.view'],
    );

    expect(find.text('No reports built yet.'), findsOneWidget);
    expect(
      find.text('Somebody who can build reports has to make the first one.'),
      findsOneWidget,
    );
    // Must not read as this reader's own dead end.
    expect(
      find.textContaining('Build one and it appears here'),
      findsNothing,
    );
  });

  testWidgets('without reports.build there is no New report control at all', (
    tester,
  ) async {
    await _pumpReports(
      tester,
      reports: [_report],
      permissions: const ['reports.view'],
    );

    expect(find.text('New report'), findsNothing);
  });

  testWidgets('with reports.build the New report control is offered', (
    tester,
  ) async {
    await _pumpReports(tester, reports: [_report]);

    expect(find.text('New report'), findsOneWidget);
  });

  testWidgets('a refusal is a sentence, not an empty library', (tester) async {
    await _pumpReports(
      tester,
      onRequest: (request) {
        if (request.url.path.endsWith('/reports')) {
          return _json({
            'message':
                'You do not have permission to see this report. That is part '
                'of a role rather than of an account.',
          }, 403);
        }
        return null;
      },
    );

    expect(find.textContaining('do not have permission'), findsOneWidget);
    // States 3 and 5 are different facts and must not share a widget: refused
    // is "you may not", empty is "there is nothing".
    expect(find.text('No reports built yet.'), findsNothing);
    expect(find.textContaining('No rows matched'), findsNothing);
  });

  testWidgets('a report can be deleted, confirmed in place and never a dialog', (
    tester,
  ) async {
    var deleted = false;

    await _pumpReports(
      tester,
      reports: [_report],
      onRequest: (request) {
        if (request.method == 'DELETE') {
          deleted = true;
          return _json({}, 204);
        }
        return null;
      },
    );

    await tester.tap(find.byTooltip('Delete report'));
    await tester.pumpAndSettle();

    expect(find.byType(Dialog), findsNothing);
    expect(find.textContaining('Delete this report?'), findsOneWidget);
    // Says what it does NOT destroy: a report is a question, not the records.
    expect(find.textContaining('records it reads on are untouched'), findsOneWidget);

    await tester.tap(find.text('Delete report'));
    await tester.pumpAndSettle();

    expect(deleted, isTrue);
  });

  testWidgets('a delete can be backed out of without sending anything', (
    tester,
  ) async {
    var deleted = false;

    await _pumpReports(
      tester,
      reports: [_report],
      onRequest: (request) {
        if (request.method == 'DELETE') {
          deleted = true;
          return _json({}, 204);
        }
        return null;
      },
    );

    await tester.tap(find.byTooltip('Delete report'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Keep it'));
    await tester.pumpAndSettle();

    expect(deleted, isFalse);
    expect(find.text('Open jobs'), findsOneWidget);
  });

  testWidgets('without reports.build there is no delete control', (
    tester,
  ) async {
    await _pumpReports(
      tester,
      reports: [_report],
      permissions: const ['reports.view'],
    );

    // Same rule as New report: absent, not disabled.
    expect(find.byTooltip('Delete report'), findsNothing);
  });

  testWidgets('tapping a report opens its run in place, never a dialog', (
    tester,
  ) async {
    await _pumpReports(
      tester,
      reports: [_report],
      onRequest: (request) {
        if (request.url.path.endsWith('/run')) {
          return _json({
            'data': {
              'columns': [
                {'key': 'jobs.title', 'label': 'Title', 'type': 'string'},
              ],
              'rows': [
                {'jobs.title': 'Sweep'},
              ],
              'capped': false,
              'count': 1,
              'cap': 5000,
            },
          });
        }
        return null;
      },
    );

    await tester.tap(find.text('Open jobs'));
    await tester.pumpAndSettle();

    expect(find.text('Sweep'), findsOneWidget);
    // Screens and side panels, never a modal (development rules).
    expect(find.byType(Dialog), findsNothing);
    // The header becomes the report's name, with a way back.
    expect(find.byTooltip('All reports'), findsOneWidget);
  });

  testWidgets('New report opens the builder in place, never a dialog', (
    tester,
  ) async {
    await _pumpReports(
      tester,
      reports: [_report],
      onRequest: (request) {
        if (request.url.path.endsWith('/reports/fields')) {
          return _json({
            'data': {
              'jobs': {
                'label': 'Jobs',
                'columns': {
                  'title': {'label': 'Title', 'type': 'string'},
                },
                'relations': <String, dynamic>{},
              },
            },
          });
        }
        return null;
      },
    );

    await tester.tap(find.text('New report'));
    await tester.pumpAndSettle();

    expect(find.widgetWithText(TextField, 'Name this report'), findsOneWidget);
    expect(find.byType(Dialog), findsNothing);
    expect(find.byTooltip('All reports'), findsOneWidget);
  });

  testWidgets('a narrow screen says reports are built on a computer', (
    tester,
  ) async {
    await _pumpReports(tester, reports: [_report], width: 375);

    // A phone runs what a desk built. It still holds reports.build here, and
    // is told why the builder is absent rather than left wondering.
    expect(find.text('New report'), findsNothing);
    expect(find.textContaining('built on a computer'), findsOneWidget);
  });
}

Future<void> _pumpReports(
  WidgetTester tester, {
  List<String> permissions = const [
    'reports.view',
    'reports.build',
    'reports.export',
  ],
  List<Map<String, dynamic>> reports = const [],
  http.Response? Function(http.Request request)? onRequest,
  double width = 1280,
}) async {
  tester.view.physicalSize = Size(width, 900);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(
    client: MockClient((request) async {
      final override = onRequest?.call(request);
      if (override != null) return override;

      final path = request.url.path;

      if (path.endsWith('/me')) {
        return _json({
          'data': {..._me, 'permissions': permissions},
        });
      }

      if (path.endsWith('/reports')) {
        return _json({'data': reports});
      }

      return _json({'message': 'Nothing stubbed for $path.'}, 500);
    }),
  );

  final session = Session(api);
  await tester.runAsync(() => session.adopt('tok'));
  expect(session.signedIn, isTrue);

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(body: ReportsScreen(api: api, session: session)),
    ),
  );
  await tester.pumpAndSettle();
}

http.Response _json(Object body, [int status = 200]) => http.Response(
  jsonEncode(body),
  status,
  headers: const {'content-type': 'application/json'},
);

const _report = {
  'id': 'rep-1',
  'name': 'Open jobs',
  'description': 'Everything still open.',
  'company_id': 'co-1',
  'franchise_id': null,
  'definition': {
    'root': 'jobs',
    'columns': [
      {'resource': 'jobs', 'column': 'title'},
    ],
    'filters': <Map<String, dynamic>>[],
  },
};

const _me = {
  'id': 'u-1',
  'name': 'Jose',
  'email': 'jose@example.com',
  'role': {'name': 'Manager'},
  'has_avatar': false,
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
};
