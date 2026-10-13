import 'dart:convert';

import 'package:ezbizi_client/core/deep_links.dart';
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
    await _pumpReports(tester, reports: [], permissions: _runner);

    expect(find.text('No reports built yet.'), findsOneWidget);
    expect(
      find.text('Somebody who can build reports has to make the first one.'),
      findsOneWidget,
    );
    // Must not read as this reader's own dead end.
    expect(find.textContaining('Build one and it appears here'), findsNothing);
  });

  testWidgets('without reports.build there is no New report control at all', (
    tester,
  ) async {
    await _pumpReports(tester, reports: [_report], permissions: _runner);

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

  testWidgets(
    'a report can be deleted, confirmed in place and never a dialog',
    (tester) async {
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
      expect(
        find.textContaining('records it reads on are untouched'),
        findsOneWidget,
      );

      await tester.tap(find.text('Delete report'));
      await tester.pumpAndSettle();

      expect(deleted, isTrue);
    },
  );

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
    await _pumpReports(tester, reports: [_report], permissions: _runner);

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
    expect(find.widgetWithText(ChoiceChip, 'Report Builder'), findsOneWidget);
    expect(find.widgetWithText(ChoiceChip, 'Performance'), findsOneWidget);
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

    await tester.tap(find.widgetWithText(ChoiceChip, 'Report Builder'));
    await tester.pumpAndSettle();

    expect(find.widgetWithText(TextField, 'Name this report'), findsOneWidget);
  });

  testWidgets('the Experimental tab shows the day per person', (tester) async {
    await _pumpReports(
      tester,
      reports: [_report],
      onRequest: (request) => request.url.path.contains('experimental/calls')
          ? _json(_callDay())
          : null,
    );

    await tester.tap(find.widgetWithText(ChoiceChip, 'Performance'));
    await tester.pumpAndSettle();

    expect(find.byKey(const ValueKey('stat-reached-nobody')), findsOneWidget);

    // The table is on the People tab since UI queue 60.
    await _performanceTab(tester, 'People');
    expect(find.text('Trey Brannan'), findsOneWidget);
    // The Answered card left the figures (Jose's order, 2026-10-05); the
    // per-person Answered column still carries it.
    expect(find.text('51'), findsOneWidget);
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

    await tester.tap(find.widgetWithText(ChoiceChip, 'Performance'));
    await tester.pumpAndSettle();

    await _performanceTab(tester, 'People');
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

    await tester.tap(find.widgetWithText(ChoiceChip, 'Performance'));
    await tester.pumpAndSettle();

    // The accounts are disjoint and the same people book into all of them, so
    // a count that silently covers fewer is wrong rather than partial. Since
    // UI queue 60 the page carries how many, on Sources, and an amber line
    // when one did not answer; Sources names them.
    expect(find.text('Sources · 2 Workiz accounts'), findsOneWidget);
    expect(find.widgetWithText(InputChip, 'Nationwide'), findsNothing);

    await _openSources(tester);
    expect(find.textContaining('Nationwide, NJ'), findsOneWidget);
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

    await tester.tap(find.widgetWithText(ChoiceChip, 'Performance'));
    await tester.pumpAndSettle();

    // The chips are behind Sources now: a row of them above the table read
    // as a filter, and they are setup rather than part of the report.
    expect(find.widgetWithText(InputChip, 'Nationwide'), findsNothing);

    await _openSources(tester);

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

    await tester.tap(find.widgetWithText(ChoiceChip, 'Performance'));
    await tester.pumpAndSettle();

    // Named in Sources, with the accounts (UI queue 60).
    await _openSources(tester);
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

    await tester.tap(find.widgetWithText(ChoiceChip, 'Performance'));
    await tester.pumpAndSettle();

    await _performanceTab(tester, 'People');

    // The day's average card left the figures (Jose's order, 2026-10-05);
    // each person's average is still in the table.
    expect(find.text('2:28'), findsNothing);
    expect(find.text('3:05'), findsOneWidget); // 185s, Trey Brannan

    // Ranya Lara answered nothing. No average is a different fact from an
    // average of zero, and "0:00" would read as a real short call.
    //
    // findsWidgets rather than findsOneWidget since 2026-09-17: she now shows
    // a dash in TWO columns, avg talk and conversion, and both are right. The
    // rule this test protects is that an absent value is never rendered as a
    // zero — counting dashes was a proxy for it that a new column invalidates
    // without anything being wrong.
    expect(find.text('—'), findsWidgets);
    expect(find.text('0:00'), findsNothing);
    expect(find.text('0%'), findsNothing);
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

    await tester.tap(find.widgetWithText(ChoiceChip, 'Performance'));
    await tester.pumpAndSettle();

    await _openSources(tester);

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

    await tester.tap(find.widgetWithText(ChoiceChip, 'Performance'));
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

    await tester.tap(find.widgetWithText(ChoiceChip, 'Performance'));
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

    await tester.tap(find.widgetWithText(ChoiceChip, 'Performance'));
    await tester.pumpAndSettle();

    // Behind Sources now, with the chips - connecting is setup.
    expect(find.text('Connect a Workiz account'), findsNothing);

    await _openSources(tester);

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
              'api_token': [
                'Switch to the branch this Workiz account belongs to.',
              ],
            },
          }, 422);
        }
        if (request.url.path.contains('experimental/calls')) {
          return _json(_callDay(accounts: const []));
        }
        return null;
      },
    );

    await tester.tap(find.widgetWithText(ChoiceChip, 'Performance'));
    await tester.pumpAndSettle();
    await _openSources(tester);

    await tester.tap(find.text('Connect a Workiz account'));
    await tester.pumpAndSettle();

    await tester.enterText(
      find.widgetWithText(TextField, 'Workiz API Token'),
      'api_whatever',
    );
    await tester.tap(find.widgetWithText(FilledButton, 'Connect'));
    await tester.pumpAndSettle();

    expect(find.textContaining('Switch to the branch'), findsWidgets);
  });

  testWidgets('a module registered inside Reports gets a chip', (tester) async {
    // A module with parent: 'reports' gets NO sidebar row - _hasDestination in
    // main.dart refuses every parented module by design, because it is meant to
    // be drawn as a chip inside its parent. Google Ads shipped registered,
    // permissioned, deployed and reachable by NOBODY, because this strip was
    // three hardcoded entries. Its registry test asserted the parent, the
    // label, the order and the permissions, and passed throughout.
    await _pumpReports(
      tester,
      reports: [_report],
      modules: const [
        {
          'key': 'ads_performance',
          'label': 'Google Ads',
          'reachable': true,
          'parent': 'reports',
        },
      ],
    );

    expect(find.widgetWithText(ChoiceChip, 'Google Ads'), findsOneWidget);
  });

  testWidgets('a child module nobody can reach gets no chip', (tester) async {
    // reachableModules is doing real work: ads_performance.view is a separate
    // grant from reports.view and somebody can hold either without the other.
    await _pumpReports(
      tester,
      reports: [_report],
      modules: const [
        {
          'key': 'ads_performance',
          'label': 'Google Ads',
          'reachable': false,
          'parent': 'reports',
        },
      ],
    );

    expect(find.widgetWithText(ChoiceChip, 'Google Ads'), findsNothing);
  });

  testWidgets('the screen does not say its own name twice', (tester) async {
    // The shell draws this module's label above every screen (_titled ->
    // ModulePageHeader), so a heading here made "Reports" appear twice. The
    // heading now carries ONLY an open report's name, which is the thing the
    // shell cannot know. Marketing has no heading of its own for this reason.
    await _pumpReports(tester, reports: [_report]);

    // EXACTLY ONE, and it is the chip. Asserting findsNothing was wrong: the
    // chip legitimately carries that text, so the assertion has to count rather
    // than forbid - otherwise it would go red on a correct screen.
    expect(find.text('Reports'), findsOneWidget);
    expect(find.widgetWithText(ChoiceChip, 'Reports'), findsOneWidget);
  });

  testWidgets('without reports.build there is no Builder tab at all', (
    tester,
  ) async {
    await _pumpReports(tester, reports: [_report], permissions: _runner);

    expect(find.widgetWithText(ChoiceChip, 'Reports'), findsOneWidget);
    // Absent, not disabled - a chip leading to a refusal tells somebody the
    // app is for other people.
    expect(find.widgetWithText(ChoiceChip, 'Report Builder'), findsNothing);
  });

  testWidgets('a missed-call notification opens Performance', (tester) async {
    // Bar, via Jose, 2026-10-12: a notification opens "the exact thing".
    DeepLinks.open(
      const DeepLink(module: 'reports', type: 'call', id: 'call-1'),
    );
    addTearDown(() => DeepLinks.pending.value = null);

    await _pumpReports(tester, reports: [_report]);

    final chip = tester.widget<ChoiceChip>(
      find.widgetWithText(ChoiceChip, 'Performance'),
    );
    expect(chip.selected, isTrue);
    // Consumed: a later rebuild does not open it again.
    expect(DeepLinks.pending.value, isNull);
  });

  testWidgets('the Performance tab alone opens Performance, without Build', (
    tester,
  ) async {
    // Jose, 2026-10-12: a Lead Manager role given Reports › Performance still
    // could not see it, because the chip also asked for reports.build. The
    // server reads Performance on reports.experimental.view alone; only what
    // changes data inside it (Convert, a person's tag) asks for Build.
    await _pumpReports(tester, reports: [_report], permissions: _runner);

    expect(find.widgetWithText(ChoiceChip, 'Performance'), findsOneWidget);
  });

  testWidgets('a phone reaches Performance from the Reports picker', (
    tester,
  ) async {
    // This was "Experimental is still reachable when Builder is absent", with
    // Builder dropped by WIDTH. A phone has the Builder since UI queue 72, and
    // since UI queue 87 (phone 4) one picker lists every tab in place of a
    // strip that showed two and hid the rest; what stays is that Performance
    // is found by name and opens its own pane.
    await _pumpReports(tester, reports: [_report], width: 375);

    expect(find.byType(ChoiceChip), findsNothing);
    await tester.tap(find.byKey(const ValueKey('tour-reports-tabs')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Performance').last);
    await tester.pumpAndSettle();

    // Reached the Experimental pane, not the Builder beside it. The day bar
    // is the pane's own furniture and renders whether or not the request
    // succeeds.
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

  testWidgets('a phone builds reports too', (tester) async {
    await _pumpReports(tester, reports: [_report], width: 375);

    // It used to say reports are built on a computer. The builder stacks on
    // a phone now, its preview on top (UI queue 72), and New report is the +
    // beside the picker so the picker keeps the width (UI queue 87).
    expect(find.byTooltip('New report'), findsOneWidget);
    expect(find.textContaining('built on a computer'), findsNothing);
  });
  // ── each tab is its own grant (Jose, 2026-09-17) ───────────────

  testWidgets('the Experimental chip needs its own grant, not reports.build', (
    tester,
  ) async {
    // The carve-out Jose asked for: this person builds reports and is not
    // thereby owed the pane that names every colleague's call performance.
    await _pumpReports(
      tester,
      reports: [_report],
      permissions: const [
        'reports.view',
        'reports.build',
        'reports.library.view',
        'reports.builder.view',
      ],
    );

    expect(find.widgetWithText(ChoiceChip, 'Report Builder'), findsOneWidget);
    expect(find.widgetWithText(ChoiceChip, 'Performance'), findsNothing);
  });

  testWidgets('the library chip is absent without the library grant', (
    tester,
  ) async {
    await _pumpReports(
      tester,
      reports: [_report],
      permissions: const [
        'reports.view',
        'reports.build',
        'reports.builder.view',
      ],
    );

    // Absent, not disabled — the same rule as New report. A chip leading to a
    // refusal tells somebody the app is for other people.
    expect(find.widgetWithText(ChoiceChip, 'Reports'), findsNothing);
    expect(find.widgetWithText(ChoiceChip, 'Report Builder'), findsOneWidget);
  });

  testWidgets(
    'without the library grant the list is neither asked for nor drawn',
    (tester) async {
      // Deploy desk audit, 2026-09-29: the chip was hidden but the PANE was
      // not - an out-of-range tab fell back to the library and drew it.
      var asked = false;

      await _pumpReports(
        tester,
        reports: [_report],
        permissions: const [
          'reports.view',
          'reports.build',
          'reports.experimental.view',
        ],
        onRequest: (request) {
          if (request.method == 'GET' &&
              request.url.path.endsWith('/reports')) {
            asked = true;
          }
          if (request.url.path.endsWith('/reports/experimental/calls')) {
            return _json({'connected': false, 'missing': 'RingCentral'});
          }
          return null;
        },
      );

      expect(asked, isFalse, reason: 'the library endpoint was called');
      expect(find.text('Open jobs'), findsNothing);
      expect(find.text('New report'), findsNothing);
    },
  );

  testWidgets('New report finds the Builder by name, not by position', (
    tester,
  ) async {
    // Library and Builder, no Experimental: the Builder is still at 1 here,
    // so this is the control - the assertion that matters is the one above,
    // where position 1 would not have been the Builder at all.
    await _pumpReports(
      tester,
      reports: [_report],
      permissions: const [
        'reports.view',
        'reports.build',
        'reports.library.view',
        'reports.builder.view',
      ],
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

  testWidgets('a session that has not loaded yet draws no tabs', (
    tester,
  ) async {
    // Absent `/me` answers NO. A null session is a load that has not finished,
    // and the safe reading of "I do not know yet" is to draw nothing rather
    // than everything — the failure here is silent and in the open direction.
    await _pumpReports(tester, reports: [_report], permissions: const []);

    expect(find.widgetWithText(ChoiceChip, 'Reports'), findsNothing);
    expect(find.widgetWithText(ChoiceChip, 'Report Builder'), findsNothing);
    expect(find.widgetWithText(ChoiceChip, 'Performance'), findsNothing);
  });
}

/// Somebody who may read reports and not build them.
///
/// Holds every TAB, because that is where the grant migration leaves an
/// existing role: whoever could open the module keeps all of its panes, and an
/// administrator narrows from there. Withholding `reports.build` is the whole
/// point of the fixture, and keeping the tabs is what makes the Builder chip's
/// absence a statement about the capability rather than about the tab.
const _runner = [
  'reports.view',
  'reports.library.view',
  'reports.builder.view',
  'reports.experimental.view',
];

Future<void> _pumpReports(
  WidgetTester tester, {
  List<String> permissions = const [
    'reports.view',
    'reports.build',
    'reports.export',

    // The tabs, since 2026-09-17. Every chip is its own grant now, and a
    // fixture without them draws a screen with no tabs at all — which is the
    // correct behaviour and would make almost every test here assert the
    // absence of something for the wrong reason.
    'reports.library.view',
    'reports.builder.view',
    'reports.experimental.view',
  ],
  List<Map<String, dynamic>> reports = const [],
  List<Map<String, dynamic>> modules = const [],
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
          'data': {..._me, 'permissions': permissions, 'modules': modules},
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
      home: Scaffold(
        body: ReportsScreen(api: api, session: session),
      ),
    ),
  );
  await tester.pumpAndSettle();
}

/// Performance opens on its Overview since UI queue 60: the table is on the
/// People tab, and the Workiz accounts are behind Sources.
Future<void> _performanceTab(WidgetTester tester, String tab) async {
  await tester.tap(
    find.descendant(
      of: find.byKey(const Key('call-report-tabs')),
      matching: find.textContaining(tab),
    ),
  );
  await tester.pumpAndSettle();
}

Future<void> _openSources(WidgetTester tester) async {
  await tester.tap(find.byKey(const Key('call-report-sources')));
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
