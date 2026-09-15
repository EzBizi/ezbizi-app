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

    // The back arrow used to exist to LEAVE the builder; the Reports chip does
    // that now, so it appears only when a report is open. Changed deliberately
    // with the sub tabs rather than keeping two ways out of the same place.
    expect(find.byTooltip('All reports'), findsNothing);
    expect(find.widgetWithText(ChoiceChip, 'Reports'), findsOneWidget);
  });

  testWidgets('the three sub tabs are Reports, Builder and Experimental', (
    tester,
  ) async {
    await _pumpReports(tester, reports: [_report]);

    expect(find.widgetWithText(ChoiceChip, 'Reports'), findsOneWidget);
    expect(find.widgetWithText(ChoiceChip, 'Builder'), findsOneWidget);
    expect(find.widgetWithText(ChoiceChip, 'Experimental'), findsOneWidget);
  });

  testWidgets('the Builder tab opens the builder', (tester) async {
    await _pumpReports(
      tester,
      reports: [_report],
      onRequest: (request) {
        if (request.url.path.endsWith('/reports/fields')) {
          return _json({'data': _fieldsStub});
        }
        return null;
      },
    );

    await tester.tap(find.widgetWithText(ChoiceChip, 'Builder'));
    await tester.pumpAndSettle();

    expect(find.widgetWithText(TextField, 'Name this report'), findsOneWidget);
  });

  testWidgets('the Experimental tab shows the day per person', (
    tester,
  ) async {
    await _pumpReports(
      tester,
      reports: [_report],
      onRequest: (request) => request.url.path.contains('experimental/calls')
          ? _json(_callDay())
          : null,
    );

    await tester.tap(find.widgetWithText(ChoiceChip, 'Experimental'));
    await tester.pumpAndSettle();

    expect(find.text('Trey Brannan'), findsOneWidget);
    expect(find.text('183'), findsOneWidget);
  });

  testWidgets('a person only one system knows is marked, never merged', (
    tester,
  ) async {
    // Only one direction is knowable: this screen holds a 30-day Workiz roster
    // and ONE day of calls. "Workiz has never heard of this name" is a real
    // signal; "they took no calls today" is not, and booking without answering
    // is a normal way to work.
    await _pumpReports(
      tester,
      reports: [_report],
      onRequest: (request) => request.url.path.contains('experimental/calls')
          ? _json(_callDay())
          : null,
    );

    await tester.tap(find.widgetWithText(ChoiceChip, 'Experimental'));
    await tester.pumpAndSettle();

    expect(find.text('not in Workiz'), findsOneWidget);
  });

  testWidgets('the Workiz accounts the jobs column covers are named', (
    tester,
  ) async {
    await _pumpReports(
      tester,
      reports: [_report],
      onRequest: (request) => request.url.path.contains('experimental/calls')
          ? _json(_callDay())
          : null,
    );

    await tester.tap(find.widgetWithText(ChoiceChip, 'Experimental'));
    await tester.pumpAndSettle();

    // The accounts are disjoint and the same people book into all of them, so
    // a count that silently covers fewer is wrong rather than partial. This
    // stays in the SENTENCE even with the chips collapsed - it belongs to the
    // report, not to setup.
    expect(find.textContaining('Nationwide, NJ'), findsOneWidget);
    expect(find.widgetWithText(InputChip, 'Nationwide'), findsNothing);
  });

  testWidgets('a connected branch can be taken back from this screen', (
    tester,
  ) async {
    // Workiz is a data source, and the shared connections list excludes those
    // on purpose. So this screen is the only place the id is published, and
    // without this chip a credential that can act on A-1's jobs could be
    // connected and never removed.
    String? deleted;

    await _pumpReports(
      tester,
      reports: [_report],
      onRequest: (request) {
        if (request.method == 'DELETE' &&
            request.url.path.contains('/social/connections/')) {
          deleted = request.url.path.split('/').last;
          return _json({
            'data': {'id': deleted, 'problems': []},
          });
        }
        if (request.url.path.contains('experimental/calls')) {
          return _json(_callDay());
        }
        return null;
      },
    );

    await tester.tap(find.widgetWithText(ChoiceChip, 'Experimental'));
    await tester.pumpAndSettle();

    // The chips are behind the toggle now: a row of them above the table read
    // as a filter, and they are setup rather than part of the report.
    expect(find.widgetWithText(InputChip, 'Nationwide'), findsNothing);

    await tester.tap(find.textContaining('Manage Workiz accounts'));
    await tester.pumpAndSettle();

    expect(find.widgetWithText(InputChip, 'Nationwide'), findsOneWidget);

    await tester.tap(
      find.descendant(
        of: find.widgetWithText(InputChip, 'Nationwide'),
        matching: find.byIcon(Icons.close),
      ),
    );
    await tester.pumpAndSettle();

    // Through the confirmation, which is the only way it goes now.
    await tester.tap(find.widgetWithText(TextButton, 'Disconnect'));
    await tester.pumpAndSettle();

    expect(deleted, 'id-Nationwide');
  });

  testWidgets('an account with no API subscription says so, permanently', (
    tester,
  ) async {
    // Jose, 2026-09-15: "we dont pay the api access there". The undercount is
    // structural, so the screen names it rather than leaving it absent -
    // "not connected" would read as a task somebody should go and do.
    await _pumpReports(
      tester,
      reports: [_report],
      onRequest: (request) => request.url.path.contains('experimental/calls')
          ? _json(_callDay())
          : null,
    );

    await tester.tap(find.widgetWithText(ChoiceChip, 'Experimental'));
    await tester.pumpAndSettle();

    expect(
      find.textContaining('Massachusetts is never included'),
      findsOneWidget,
    );
  });

  testWidgets('average talk time shows, and a dash when there is none', (
    tester,
  ) async {
    await _pumpReports(
      tester,
      reports: [_report],
      onRequest: (request) => request.url.path.contains('experimental/calls')
          ? _json(_callDay())
          : null,
    );

    await tester.tap(find.widgetWithText(ChoiceChip, 'Experimental'));
    await tester.pumpAndSettle();

    expect(find.text('2:28'), findsOneWidget); // 148s, the day's average
    expect(find.text('3:05'), findsOneWidget); // 185s, Trey Brannan

    // Ranya Lara answered nothing. No average is a different fact from an
    // average of zero, and "0:00" would read as a real short call.
    expect(find.text('—'), findsOneWidget);
    expect(find.text('0:00'), findsNothing);
  });

  testWidgets('disconnecting a branch asks first', (tester) async {
    // It used to go on one click. Undoing it needs that branch's Workiz token
    // pasted again, and the token cannot be read back out of the app.
    var deleted = false;

    await _pumpReports(
      tester,
      reports: [_report],
      onRequest: (request) {
        if (request.method == 'DELETE') {
          deleted = true;
          return _json({
            'data': {'id': 'x', 'problems': []},
          });
        }
        if (request.url.path.contains('experimental/calls')) {
          return _json(_callDay());
        }
        return null;
      },
    );

    await tester.tap(find.widgetWithText(ChoiceChip, 'Experimental'));
    await tester.pumpAndSettle();

    await tester.tap(find.textContaining('Manage Workiz accounts'));
    await tester.pumpAndSettle();

    await tester.tap(
      find.descendant(
        of: find.widgetWithText(InputChip, 'Nationwide'),
        matching: find.byIcon(Icons.close),
      ),
    );
    await tester.pumpAndSettle();

    expect(deleted, isFalse, reason: 'the X disconnected without asking');
    expect(find.text('Disconnect Nationwide?'), findsOneWidget);

    await tester.tap(find.widgetWithText(TextButton, 'Keep'));
    await tester.pumpAndSettle();

    expect(deleted, isFalse);
    expect(find.widgetWithText(InputChip, 'Nationwide'), findsOneWidget);
  });

  testWidgets('a refused Workiz account is named, not folded away', (
    tester,
  ) async {
    await _pumpReports(
      tester,
      reports: [_report],
      onRequest: (request) => request.url.path.contains('experimental/calls')
          ? _json(_callDay(failed: const ['NJ']))
          : null,
    );

    await tester.tap(find.widgetWithText(ChoiceChip, 'Experimental'));
    await tester.pumpAndSettle();

    expect(find.textContaining('Did not answer: NJ'), findsOneWidget);
  });

  testWidgets('a missing vendor reads as not connected, not as no calls', (
    tester,
  ) async {
    // An empty report and a missing connection look identical and mean
    // opposite things.
    await _pumpReports(
      tester,
      reports: [_report],
      onRequest: (request) => request.url.path.contains('experimental/calls')
          ? _json(_callDay(connected: false))
          : null,
    );

    await tester.tap(find.widgetWithText(ChoiceChip, 'Experimental'));
    await tester.pumpAndSettle();

    expect(find.text('RingCentral is not connected.'), findsOneWidget);
    expect(find.text('Trey Brannan'), findsNothing);
  });

  testWidgets('the Experimental tab offers a way to connect the first account', (
    tester,
  ) async {
    // THE ENTRY POINT. A screen that only ever renders stubbed rows never shows
    // that there is no way to create the first one, which is how a dead end
    // ships looking finished.
    await _pumpReports(
      tester,
      reports: [_report],
      onRequest: (request) => request.url.path.contains('experimental/calls')
          ? _json(_callDay(accounts: const []))
          : null,
    );

    await tester.tap(find.widgetWithText(ChoiceChip, 'Experimental'));
    await tester.pumpAndSettle();

    // Behind the manage toggle now, with the chips - connecting is setup.
    expect(find.text('Connect a Workiz account'), findsNothing);

    await tester.tap(find.textContaining('Manage Workiz accounts'));
    await tester.pumpAndSettle();

    expect(find.text('Connect a Workiz account'), findsOneWidget);

    await tester.tap(find.text('Connect a Workiz account'));
    await tester.pumpAndSettle();

    expect(find.widgetWithText(TextField, 'Workiz API Token'), findsOneWidget);
  });

  testWidgets('the server sentence is what explains a refused connect', (
    tester,
  ) async {
    // The branch-view rule lives on the server and its refusal says so. The
    // screen does not duplicate the rule - one place deciding beats two places
    // agreeing, and a second copy is the one that goes stale.
    await _pumpReports(
      tester,
      reports: [_report],
      onRequest: (request) {
        if (request.url.path.contains('experimental/workiz')) {
          return _json({
            'message': 'Switch to the branch this Workiz account belongs to.',
            'errors': {
              'api_token': ['Switch to the branch this Workiz account belongs to.'],
            },
          }, 422);
        }
        if (request.url.path.contains('experimental/calls')) {
          return _json(_callDay(accounts: const []));
        }
        return null;
      },
    );

    await tester.tap(find.widgetWithText(ChoiceChip, 'Experimental'));
    await tester.pumpAndSettle();
    await tester.tap(find.textContaining('Manage Workiz accounts'));
    await tester.pumpAndSettle();

    await tester.tap(find.text('Connect a Workiz account'));
    await tester.pumpAndSettle();

    await tester.enterText(
      find.widgetWithText(TextField, 'Workiz API Token'),
      'api_whatever',
    );
    await tester.tap(find.widgetWithText(FilledButton, 'Connect'));
    await tester.pumpAndSettle();

    expect(
      find.textContaining('Switch to the branch'),
      findsWidgets,
    );
  });

  testWidgets('without reports.build there is no Builder tab at all', (
    tester,
  ) async {
    await _pumpReports(
      tester,
      reports: [_report],
      permissions: const ['reports.view'],
    );

    expect(find.widgetWithText(ChoiceChip, 'Reports'), findsOneWidget);
    // Absent, not disabled - a chip leading to a refusal tells somebody the
    // app is for other people.
    expect(find.widgetWithText(ChoiceChip, 'Builder'), findsNothing);
    // Experimental goes too, and for the same reason: its endpoint is gated on
    // `reports.build` because the report names every member of staff and how
    // many calls they took. It used to be shown to everyone, which was right
    // while the pane was an empty placeholder and became wrong the moment it
    // had contents.
    expect(find.widgetWithText(ChoiceChip, 'Experimental'), findsNothing);
  });

  testWidgets('Experimental is still reachable when Builder is absent', (
    tester,
  ) async {
    // The chips SHIFT when Builder is missing, so an index remembered from the
    // three-chip layout would land on the wrong destination. Resolved by name.
    //
    // Builder is dropped by WIDTH here rather than by permission, because
    // Experimental needs `reports.build` too - dropping the permission would
    // drop both chips and leave this test with nothing to shift.
    await _pumpReports(
      tester,
      reports: [_report],
      width: 375,
    );

    await tester.tap(find.widgetWithText(ChoiceChip, 'Experimental'));
    await tester.pumpAndSettle();

    // Reached the Experimental pane, not the Builder that used to sit at this
    // index when all three chips were present. The day bar is the pane's own
    // furniture and renders whether or not the request succeeds.
    expect(find.byIcon(Icons.chevron_left), findsOneWidget);
    expect(find.widgetWithText(TextField, 'Name this report'), findsNothing);
  });

  testWidgets('New report jumps to the Builder tab', (tester) async {
    await _pumpReports(
      tester,
      reports: [_report],
      onRequest: (request) {
        if (request.url.path.endsWith('/reports/fields')) {
          return _json({'data': _fieldsStub});
        }
        return null;
      },
    );

    await tester.tap(find.text('New report'));
    await tester.pumpAndSettle();

    expect(find.widgetWithText(TextField, 'Name this report'), findsOneWidget);
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

const _fieldsStub = {
  'jobs': {
    'label': 'Jobs',
    'columns': {
      'title': {'label': 'Title', 'type': 'string'},
    },
    'relations': <dynamic>[],
  },
};

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

/// The Experimental endpoint's payload, shaped from the live one.
Map<String, dynamic> _callDay({
  bool connected = true,
  List<String> failed = const [],
  List<String> accounts = const ['Nationwide', 'NJ'],
}) => connected
    ? {
        'connected': true,
        'timezone': 'America/New_York',
        'day': '2026-09-14',
        'workiz_accounts': [
          for (final a in accounts) {'id': 'id-$a', 'name': a},
        ],
        'workiz_failed': {for (final a in failed) a: 'refused'},
        'workiz_unreachable': [
          {
            'name': 'Massachusetts',
            'reason': 'Workiz API access is not paid for on that account.',
          },
        ],
        'totals': {
          'calls': 280,
          'answered': 183,
          'reached_nobody': 81,
          'new_client': 135,
          'existing_client': 66,
          'jobs': 66,
          'avg_talk_seconds': 148,
        },
        'people': [
          {
            'person': 'Trey Brannan',
            'rang': 55,
            'answered': 51,
            'new_client': 35,
            'existing_client': 14,
            'jobs': 16,
            'avg_talk_seconds': 185,
            'in_calls': true,
            'in_jobs': true,
          },
          {
            'person': 'Ranya Lara',
            'rang': 3,
            'answered': 2,
            'new_client': 0,
            'existing_client': 1,
            'jobs': 0,
            'avg_talk_seconds': null,
            'in_calls': true,
            'in_jobs': false,
          },
        ],
      }
    : {
        'connected': false,
        'missing': 'RingCentral',
        'day': '2026-09-14',
        'timezone': 'America/New_York',
      };
