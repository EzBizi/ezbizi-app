import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/project_board_screen.dart';
import 'package:ezbizi_client/widgets/status_pill.dart';
import 'package:ezbizi_client/widgets/tag.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Project Management (Jose, 2026-11-06): the table is drawn from the
/// company's settings the server sends, the Franchise filter is sent, and
/// the Settings sub-tab saves the settings it edits. Invented people only.
void main() {
  testWidgets('columns, labels, the franchise filter and Settings', (
    tester,
  ) async {
    final requests = <http.Request>[];
    var over = 500;
    final api = ApiClient(
      client: MockClient((request) async {
        requests.add(request);
        final path = request.url.path;
        if (path.endsWith('/me')) {
          return _json({
            'data': {
              'id': 'u-1',
              'name': 'Dana',
              'email': 'dana@brightside.example',
              'permissions': [
                'project_board.view',
                'jobs.view',
                'organizations.edit',
              ],
              'modules': <dynamic>[],
              'organization': {'id': 'org-1', 'name': 'Brightside'},
              'scope': {
                'type': 'organization',
                'id': null,
                'label': 'Brightside',
                'path': ['Brightside'],
              },
            },
          });
        }
        if (path.endsWith('/settings') && request.method == 'GET') {
          return _json({
            'data': [
              {
                'key': 'project_board.tabs',
                'value': {
                  'unscheduled': {
                    'statuses': ['Pending'],
                    'scheduled': false,
                  },
                },
              },
              {
                'key': 'project_board.part_tags',
                'value': ['WAITING PARTS'],
              },
            ],
          });
        }
        if (request.method == 'PATCH') return _json({'data': {}});
        if (path.endsWith('/jobs/j-1/performers')) {
          return _json({
            'data': [
              {'id': 'u-7', 'name': 'Guy Shimonov'},
            ],
          });
        }
        if (path.endsWith('/project-board/repair-teams')) {
          return _json({
            'data': {
              'outcomes': {'Ohio': 'created'},
              'list': [
                {'name': 'KENNI REPAIR TEAM', 'phone': '555', 'user_id': null},
              ],
            },
          });
        }
        if (path.endsWith('/project-board/options')) {
          return _json({
            'data': {
              'statuses': ['Pending', 'On Hold'],
              'tags': ['WAITING PARTS', 'VIP'],
            },
          });
        }
        return _json({
          'data': [
            {
              'id': 'j-1',
              'number': 'J-1001',
              'workiz_id': '7001',
              'customer': 'Lena Park',
              'franchise': 'Ohio',
              'status': 'Pending',
              'part_status': 'WAITING PARTS',
              'required': 'Crown Repair',
              'status_days': 8,
              'ready_missing': <String>[],
              'tags': ['VIP', 'DEPOSIT'],
              'tag_colours': {'VIP': '#D32F2F'},
            },
          ],
          'meta': {
            'total': 1,
            'last_page': 1,
            'counts': {'unscheduled': 1},
            'places': [
              {'value': 'company:c-1', 'label': 'Brightside', 'depth': 0},
              {'value': 'f-1', 'label': 'Brightside · Ohio', 'depth': 1},
            ],
            'columns': [
              {'key': 'customer', 'label': 'Client', 'visible': true},
              {'key': 'number', 'label': 'EzBizi ID', 'visible': false},
              {
                'key': 'part_status',
                'label': 'Parts',
                'visible': true,
                'as_label': true,
              },
              {'key': 'required', 'label': 'Required', 'visible': true},
              {'key': 'technician', 'label': 'Technician', 'visible': true},
              {'key': 'status_days', 'label': 'Status Time', 'visible': true},
              {
                'key': 'tags',
                'label': 'Tags',
                'visible': true,
                'as_label': true,
              },
            ],
            'labels': {
              'part_status': {'WAITING PARTS': 'caution'},
            },
            'settings_scope': {'scope_type': 'company', 'scope_id': 'c-1'},
            'totals': {
              'paid': 1500,
              'due': 1000,
              'total': 2000,
              'over': over,
              'currency': 'USD',
            },
            'can_edit': true,
            'sortable': ['status_days', 'customer'],
          },
        });
      }),
    );
    final session = Session(api);
    await tester.runAsync(() => session.adopt('tok'));

    tester.view.physicalSize = const Size(1600, 1400);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);
    await tester.pumpWidget(
      MaterialApp(
        theme: EzBiziTheme.light(),
        home: Scaffold(
          body: ProjectBoardScreen(api: api, session: session),
        ),
      ),
    );
    await tester.pumpAndSettle();

    // Live: "Updated hh:mm", a manual refresh, and a quiet reload every
    // minute while on screen (Jose, 2026-11-10).
    expect(find.byKey(const Key('project-board-updated')), findsOneWidget);
    // Totals as tiles (design 193); Overpaid only when there is any.
    expect(find.text('Total Paid'), findsOneWidget);
    expect(find.text('Total Remaining Balance'), findsOneWidget);
    expect(find.text('Total Amount of Jobs'), findsOneWidget);
    expect(find.byKey(const Key('project-board-total-over')), findsOneWidget);
    expect(find.byTooltip('Paid beyond the job totals'), findsOneWidget);
    over = 0;
    final before = requests.length;
    await tester.tap(find.byKey(const Key('project-board-refresh')));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('project-board-total-over')), findsNothing);
    expect(find.text('Total Paid'), findsOneWidget);
    expect(requests.length, before + 1);
    expect(requests.last.url.queryParameters['per_page'], '50');
    await tester.pump(const Duration(seconds: 61));
    await tester.pumpAndSettle();
    expect(requests.length, before + 2);

    // A technician is settable from the row (Jose, 2026-11-12).
    await tester.tap(
      find.byKey(const ValueKey('project-board-technician:j-1')),
    );
    await tester.pumpAndSettle();
    await tester.tap(find.text('Guy Shimonov'));
    await tester.pumpAndSettle();
    final tech = requests.lastWhere(
      (r) => r.method == 'PATCH' && r.url.path.endsWith('/jobs/j-1'),
    );
    expect(jsonDecode(tech.body), {'performed_by_user_id': 'u-7'});
    ScaffoldMessenger.of(
      tester.element(find.byType(ProjectBoardScreen)),
    ).hideCurrentSnackBar();
    await tester.pumpAndSettle();

    // Status Time: 7 days and up is red (Jose, 2026-11-10), and Ready to
    // schedule posts once nothing is missing.
    expect(
      tester.widget<StatusPill>(find.widgetWithText(StatusPill, '8 days')).tone,
      Tone.bad,
    );
    await tester.tap(find.byKey(const ValueKey('project-board-ready:j-1')));
    await tester.pumpAndSettle();
    // The four questions first; Save waits for all of them (Jose, 2026-11-10).
    final save = find.byKey(const Key('project-board-times-save'));
    expect(tester.widget<FilledButton>(save).onPressed, isNull);
    for (final (key, value) in const [
      ('date', '2026-11-20'),
      ('slot', '8 - 11 AM'),
      ('alt_date', '2026-11-21'),
      ('alt_slot', '2 - 5 PM'),
    ]) {
      final field = find.byKey(Key('project-board-times-$key'));
      await tester.enterText(
        tester.widget(field) is TextField
            ? field
            : find
                  .descendant(of: field, matching: find.byType(TextField))
                  .first,
        value,
      );
      await tester.pump();
    }
    await tester.tap(save);
    await tester.pumpAndSettle();
    final ready = requests.lastWhere(
      (r) =>
          r.method == 'POST' &&
          r.url.path.endsWith('/project-board/jobs/j-1/ready'),
    );
    expect(jsonDecode(ready.body), {
      'date': '2026-11-20',
      'slot': '8 - 11 AM',
      'alt_date': '2026-11-21',
      'alt_slot': '2 - 5 PM',
    });

    // A header click sorts the whole tab on the server; a second click
    // reverses it, with an arrow (Jose, 2026-11-10).
    await tester.tap(find.text('STATUS TIME'));
    await tester.pumpAndSettle();
    expect(requests.last.url.queryParameters['sort'], 'status_days');
    expect(requests.last.url.queryParameters['dir'], 'asc');
    await tester.tap(find.text('STATUS TIME'));
    await tester.pumpAndSettle();
    expect(requests.last.url.queryParameters['dir'], 'desc');
    expect(find.byIcon(Icons.arrow_downward), findsOneWidget);

    // Part Status opens the parts list: one to order (Jose, 2026-11-10).
    await tester.tap(find.byKey(const ValueKey('project-board-parts:j-1')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('project-board-part-add')));
    await tester.pumpAndSettle();
    await tester.enterText(
      find.byKey(const ValueKey('project-board-part-item-0')),
      'Stainless cap',
    );
    await tester.tap(find.byKey(const Key('project-board-parts-save')));
    await tester.pumpAndSettle();
    final put = requests.lastWhere(
      (r) => r.method == 'PUT' && r.url.path.endsWith('/parts'),
    );
    expect((jsonDecode(put.body)['parts'] as List).single, {
      'kind': 'order',
      'item': 'Stainless cap',
      'quantity': 1,
      'price_minor': null,
    });

    // The company's names and order; a hidden column is not drawn.
    expect(find.text('CLIENT'), findsOneWidget);
    expect(find.text('Lena Park'), findsOneWidget);
    expect(find.text('J-1001'), findsNothing);
    final pill = tester.widget<StatusPill>(
      find.widgetWithText(StatusPill, 'WAITING PARTS'),
    );
    expect(pill.tone, Tone.caution);
    // Tags: one chip per tag.
    // In its Workiz colour; one without a colour stays grey.
    expect(
      tester.widget<Tag>(find.widgetWithText(Tag, 'VIP')).colour,
      const Color(0xFFD32F2F),
    );
    expect(
      tester.widget<Tag>(find.widgetWithText(Tag, 'DEPOSIT')).colour,
      isNull,
    );

    await tester.tap(find.byKey(const Key('project-board-franchise')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Brightside · Ohio').last);
    await tester.pumpAndSettle();
    expect(requests.last.url.queryParameters['franchise_id'], 'f-1');

    await tester.enterText(
      find.byKey(const Key('project-board-search')),
      'lena',
    );
    await tester.pump(const Duration(milliseconds: 401));
    await tester.pumpAndSettle();
    expect(requests.last.url.queryParameters['q'], 'lena');
    expect(requests.last.url.queryParameters['franchise_id'], 'f-1');

    await tester.tap(find.text('Settings'));
    await tester.pumpAndSettle();
    // Tag lists are compact pickers, not a wall of chips; the new columns
    // have their settings (Jose, 2026-11-10).
    expect(
      find.byKey(const Key('project-board-tab-tags:unscheduled-add')),
      findsOneWidget,
    );
    expect(
      find.byKey(const Key('project-board-repair-tags-add')),
      findsOneWidget,
    );
    expect(
      find.byKey(const ValueKey('project-board-ready-needs:note')),
      findsOneWidget,
    );
    expect(find.byKey(const Key('project-board-warn-days')), findsOneWidget);
    // Statuses are the company's real ones, picked as chips.
    await tester.tap(
      find.descendant(
        of: find.byKey(const Key('project-board-statuses:unscheduled')),
        matching: find.text('On Hold'),
      ),
    );
    await tester.pumpAndSettle();
    // A colour is a swatch, previewed on the label itself.
    await tester.ensureVisible(
      find.byKey(const ValueKey('project-board-swatch:part_status:0:bad')),
    );
    await tester.pumpAndSettle();
    await tester.tap(
      find.byKey(const ValueKey('project-board-swatch:part_status:0:bad')),
    );
    await tester.pumpAndSettle();
    expect(
      tester
          .widgetList<StatusPill>(
            find.widgetWithText(StatusPill, 'WAITING PARTS'),
          )
          .last
          .tone,
      Tone.bad,
    );
    await tester.ensureVisible(
      find.byKey(const Key('project-board-save:tabs')),
    );
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('project-board-save:tabs')));
    await tester.pumpAndSettle();

    final tabs = requests.firstWhere(
      (r) => r.method == 'PATCH' && r.url.path.endsWith('project_board.tabs'),
    );
    expect(tabs.url.queryParameters['scope_type'], 'company');
    expect((jsonDecode(tabs.body)['value'] as Map)['unscheduled']['statuses'], [
      'Pending',
      'On Hold',
    ]);
    expect(find.text('Project Management settings saved.'), findsOneWidget);
    // A repair team is added here and on every Workiz account (Jose, 2026-11-10).
    await tester.ensureVisible(
      find.byKey(const Key('project-board-repair-tags-add')),
    );
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('project-board-repair-tags-add')));
    await tester.pumpAndSettle();
    await tester.enterText(
      find.byKey(const Key('project-board-repair-team-name')),
      'KENNI REPAIR TEAM',
    );
    await tester.enterText(
      find.byKey(const Key('project-board-repair-team-phone')),
      '555',
    );
    await tester.pump();
    await tester.tap(find.byKey(const Key('project-board-repair-team-save')));
    await tester.pumpAndSettle();
    final team = requests.lastWhere(
      (r) => r.url.path.endsWith('/project-board/repair-teams'),
    );
    expect(jsonDecode(team.body), {
      'op': 'create',
      'name': 'KENNI REPAIR TEAM',
      'color': null,
      'phone': '555',
      'user_id': null,
    });
    expect(find.text('Ohio: created'), findsOneWidget);
    expect(
      find.byKey(const ValueKey('project-board-repair-team:KENNI REPAIR TEAM')),
      findsOneWidget,
    );
    await tester.pump(const Duration(seconds: 5));
    await tester.pumpAndSettle();
  });
}

http.Response _json(Object body) => http.Response(
  jsonEncode(body),
  200,
  headers: {'content-type': 'application/json'},
);
