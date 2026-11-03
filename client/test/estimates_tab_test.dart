import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/jobs/estimates_tab.dart';
import 'package:ezbizi_client/screens/jobs_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Queue 32, Jose's 1 and 4 (2026-09-29): Estimates is the Jobs module's
/// second tab. Chips with their counts, what was quoted and accepted, the
/// estimate docked beside the list on a desk and in place on a phone, and
/// only the steps the server's NEXT allows from where it stands.
void main() {
  group('the tab', () {
    testWidgets('is drawn only with estimates.view and Financials', (
      tester,
    ) async {
      await _jobs(tester, const ['jobs.view', 'jobs.financials']);
      expect(find.text('Estimates'), findsNothing, reason: 'no estimates.view');
      expect(find.text('Chimney Sweep'), findsOneWidget, reason: 'the control');

      await _jobs(tester, const ['jobs.view', 'estimates.view']);
      expect(find.text('Estimates'), findsNothing, reason: 'no Financials');

      await _jobs(tester, _all);
      expect(find.text('Estimates'), findsOneWidget);

      expect(_server.lastList, isNull, reason: 'nothing fetched unasked');
      await tester.tap(find.text('Estimates'));
      await tester.pumpAndSettle();
      expect(find.text('Brian Moss'), findsOneWidget);
      expect(find.text('Chimney Sweep'), findsNothing);
    });

    testWidgets("a look at the estimates keeps a job's unsaved edits", (
      tester,
    ) async {
      await _jobs(tester, const [..._all, 'jobs.edit']);
      await tester.tap(find.text('Chimney Sweep'));
      await tester.pumpAndSettle();

      final title = find.widgetWithText(TextField, 'What is the job');
      await tester.enterText(title, 'Chimney Sweep, both flues');
      await tester.pump();

      await tester.tap(find.text('Estimates'));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Jobs'));
      await tester.pumpAndSettle();

      expect(
        tester.widget<TextField>(title).controller!.text,
        'Chimney Sweep, both flues',
      );
    });
  });

  // Design 172, option 1 with phone 4 (Jose, 2026-11-03: "as u recommend").
  group('the board', () {
    testWidgets('a column per status, Archived shut, a card dragged across', (
      tester,
    ) async {
      await _estimates(tester);
      await tester.tap(find.text('Board'));
      await tester.pumpAndSettle();

      expect(_server.preferences['jobs.estimates.view'], 'board');
      for (final s in ['draft', 'sent', 'accepted', 'declined']) {
        expect(find.byKey(ValueKey('estimate-column-$s')), findsOneWidget);
      }
      expect(find.text('▸ Archived · 1,954'), findsOneWidget);
      expect(
        find.text('Paula Grant'),
        findsNothing,
        reason: 'Archived is shut',
      );

      // Brian Moss is sent: Draft is not somewhere it can go.
      final drag = await tester.startGesture(
        tester.getCenter(find.byKey(const ValueKey('estimate-card-e-1'))),
      );
      await drag.moveBy(const Offset(20, 0));
      await tester.pump();
      expect(
        find.byKey(const ValueKey('estimate-column-draft-refused')),
        findsOneWidget,
      );
      expect(
        find.byKey(const ValueKey('estimate-column-accepted')),
        findsOneWidget,
      );

      await drag.moveTo(
        tester.getCenter(
          find.byKey(const ValueKey('estimate-column-accepted')),
        ),
      );
      await tester.pump();
      await drag.up();
      await tester.pumpAndSettle();
      expect(_server.patches.last, {'status': 'accepted'});
      expect(_server.estimates['e-1']!['status'], 'accepted');
    });

    testWidgets(
      'a phone shows one status, and Move to lists only the allowed',
      (tester) async {
        await _estimates(tester, size: const Size(400, 860));
        await tester.tap(find.text('Board'));
        await tester.pumpAndSettle();
        expect(_server.lastList?['status'], 'draft');

        await tester.tap(find.byKey(const ValueKey('estimate-chip-sent')));
        await tester.pumpAndSettle();
        await tester.tap(find.byKey(const ValueKey('estimate-move-to-e-1')));
        await tester.pumpAndSettle();

        expect(find.byKey(const ValueKey('estimate-move-draft')), findsNothing);
        for (final s in ['accepted', 'declined', 'archived']) {
          expect(find.byKey(ValueKey('estimate-move-$s')), findsOneWidget);
        }
        await tester.tap(find.byKey(const ValueKey('estimate-move-declined')));
        await tester.pumpAndSettle();
        expect(_server.patches.last, {'status': 'declined'});
      },
    );
  });

  group('the list', () {
    testWidgets('counts each status, and a chip narrows it', (tester) async {
      await _estimates(tester);

      for (final (key, count) in [
        ('all', '2,388'),
        ('draft', '46'),
        ('sent', '52'),
        ('accepted', '275'),
        ('declined', '61'),
        ('archived', '1,954'),
      ]) {
        expect(
          find.descendant(
            of: find.byKey(ValueKey('estimate-chip-$key')),
            matching: find.textContaining(count),
          ),
          findsOneWidget,
          reason: '$key says how many',
        );
      }

      await tester.tap(find.byKey(const ValueKey('estimate-chip-sent')));
      await tester.pumpAndSettle();
      expect(_server.lastList?['status'], 'sent');
    });

    // UI review, 2026-10-08: the empty list says what to do, with the button.
    testWidgets('with none yet, the empty list offers the first one', (
      tester,
    ) async {
      await _estimates(tester, empty: true);

      expect(find.text('No estimates yet.'), findsOneWidget);
      await tester.tap(find.byKey(const ValueKey('estimate-new-empty')));
      await tester.pumpAndSettle();
      expect(find.text('New estimate'), findsWidgets);
    });

    testWidgets('searches by number or customer when asked', (tester) async {
      await _estimates(tester);

      await tester.enterText(
        find.byKey(const ValueKey('estimate-search')),
        'Moss',
      );
      await tester.pump();
      expect(_server.lastList?['search'], isNull, reason: 'not on every key');

      await tester.testTextInput.receiveAction(TextInputAction.search);
      await tester.pumpAndSettle();
      expect(_server.lastList?['search'], 'Moss');

      await tester.enterText(
        find.byKey(const ValueKey('estimate-search')),
        'nobody',
      );
      await tester.testTextInput.receiveAction(TextInputAction.search);
      await tester.pumpAndSettle();
      expect(find.text('Nothing matches "nobody".'), findsOneWidget);

      await tester.tap(find.byTooltip('Clear the search'));
      await tester.pumpAndSettle();
      expect(_server.lastList?.containsKey('search'), isFalse);
      expect(find.text('Brian Moss'), findsOneWidget);
    });

    testWidgets("says each one's title, and the date A1's came with", (
      tester,
    ) async {
      await _estimates(tester);

      expect(find.text('Title'), findsOneWidget, reason: 'the column');
      expect(find.text('Rebuild the crown'), findsOneWidget);
      // No issue date on A1's rows, so the list dates them by when they were
      // made, which A1 kept: the only date that history has.
      expect(find.text('Created'), findsOneWidget);
      expect(find.text('2026-03-03'), findsOneWidget);
      expect(find.text('2026-09-26'), findsNWidgets(3));
    });

    testWidgets('says what was quoted and what was accepted', (tester) async {
      await _estimates(tester);

      String total(String what) => tester
          .widget<Text>(find.byKey(ValueKey('estimate-total-$what-USD')))
          .textSpan!
          .toPlainText();
      expect(total('quoted'), r'Quoted $5,850,212');
      expect(total('accepted'), r'Accepted $1,106,480');
    });

    testWidgets('a row docks its estimate beside the list', (tester) async {
      await _estimates(tester);
      expect(find.text('Estimate'), findsOneWidget, reason: 'the table');

      await tester.tap(find.text('Brian Moss'));
      await tester.pumpAndSettle();

      expect(find.text('EST-0431 · Brian Moss'), findsOneWidget);
      expect(find.text('Illinois · for job J-10479'), findsOneWidget);
      expect(_field('estimate-line-name-0'), 'Stainless steel liner');
      expect(_text(tester, 'estimate-total'), r'$3,850.00');
      expect(find.text('Karen Holt'), findsOneWidget, reason: 'list stays');

      // Sent: its three ways on, and no delete.
      for (final key in ['archive', 'decline', 'accept']) {
        expect(find.byKey(ValueKey('estimate-$key')), findsOneWidget);
      }
      expect(find.byKey(const ValueKey('estimate-delete')), findsNothing);
    });
  });

  group('an estimate', () {
    testWidgets('retotals as a price changes, and saves it in cents', (
      tester,
    ) async {
      await _estimates(tester);
      await tester.tap(find.text('Brian Moss'));
      await tester.pumpAndSettle();
      expect(find.byKey(const ValueKey('estimate-save')), findsNothing);

      await tester.enterText(
        find.byKey(const ValueKey('estimate-line-price-0')),
        r'2,500',
      );
      await tester.pump();
      expect(_text(tester, 'estimate-line-amount-0'), r'$2,500.00');
      expect(_text(tester, 'estimate-total'), r'$3,950.00');

      await tester.tap(find.byKey(const ValueKey('estimate-save')));
      await tester.pumpAndSettle();

      final sent = _server.patches.single;
      expect(sent.containsKey('status'), isFalse);
      final lines = sent['lines'] as List;
      expect(lines, hasLength(3));
      expect(lines.first, {
        'name': 'Stainless steel liner',
        'quantity': 1.0,
        'unit_price_minor': 250000,
      });
      expect(
        find.byKey(const ValueKey('estimate-save')),
        findsNothing,
        reason: 'saved is unchanged again',
      );
    });

    testWidgets('unsaved changes keep another row from replacing it', (
      tester,
    ) async {
      await _estimates(tester);
      await tester.tap(find.text('Brian Moss'));
      await tester.pumpAndSettle();

      // The control: nothing typed, and the next row opens.
      await tester.tap(find.text('Karen Holt'));
      await tester.pumpAndSettle();
      expect(find.text('EST-0430 · Karen Holt'), findsOneWidget);

      await tester.enterText(
        find.byKey(const ValueKey('estimate-line-price-0')),
        '1,300',
      );
      await tester.pump();
      await tester.tap(find.text('Olivia Chen'));
      await tester.pumpAndSettle();

      expect(find.text('EST-0430 · Karen Holt'), findsOneWidget);
      expect(
        find.text('Save or cancel the changes to this estimate first.'),
        findsOneWidget,
      );
    });

    testWidgets('marked accepted, it can only be archived', (tester) async {
      await _estimates(tester);
      await tester.tap(find.text('Brian Moss'));
      await tester.pumpAndSettle();

      await tester.tap(find.byKey(const ValueKey('estimate-accept')));
      await tester.pumpAndSettle();

      // Nothing was edited, so only the step goes.
      expect(_server.patches.single, {'status': 'accepted'});
      expect(find.byKey(const ValueKey('estimate-accept')), findsNothing);
      expect(find.byKey(const ValueKey('estimate-archive')), findsOneWidget);
      expect(
        find.byKey(const ValueKey('estimate-line-remove-0')),
        findsNothing,
        reason: "a decided estimate's lines are fixed",
      );
    });

    testWidgets("a decided one's lines cannot be changed", (tester) async {
      await _estimates(tester);
      await tester.tap(find.text('Olivia Chen'));
      await tester.pumpAndSettle();

      expect(_field('estimate-line-name-0'), 'Chimney cap');
      final name = tester.widget<TextField>(
        find.byKey(const ValueKey('estimate-line-name-0')),
      );
      expect(name.enabled, isFalse);
      expect(find.byKey(const ValueKey('estimate-line-add')), findsNothing);
      expect(
        find.byKey(const ValueKey('estimate-line-remove-0')),
        findsNothing,
      );
      expect(find.byKey(const ValueKey('estimate-archive')), findsOneWidget);
      expect(find.byKey(const ValueKey('estimate-accept')), findsNothing);
    });

    testWidgets('a draft is deleted only after asking', (tester) async {
      await _estimates(tester);
      await tester.tap(find.text('Karen Holt'));
      await tester.pumpAndSettle();

      await tester.tap(find.byKey(const ValueKey('estimate-delete')));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Keep it'));
      await tester.pumpAndSettle();
      expect(_server.deleted, isEmpty, reason: 'kept is kept');
      expect(find.text('EST-0430 · Karen Holt'), findsOneWidget);

      await tester.tap(find.byKey(const ValueKey('estimate-delete')));
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const ValueKey('estimate-delete-confirm')));
      await tester.pumpAndSettle();
      expect(_server.deleted, ['e-2']);
      expect(find.text('EST-0430 · Karen Holt'), findsNothing);
    });

    testWidgets('a title names the estimate, and saves when changed', (
      tester,
    ) async {
      await _estimates(tester);
      await tester.tap(find.text('Rebuild the crown'));
      await tester.pumpAndSettle();

      // Named by its title, with the number and the customer under it.
      expect(
        find.byWidgetPredicate(
          (w) => w is Text && w.data == 'Rebuild the crown',
        ),
        findsOneWidget,
        reason: 'the heading, not only the field',
      );
      expect(find.text('A1-2291 · Paula Grant · Illinois'), findsOneWidget);

      await tester.tap(find.byTooltip('Close Rebuild the crown'));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Karen Holt'));
      await tester.pumpAndSettle();
      expect(
        find.text('EST-0430 · Karen Holt'),
        findsOneWidget,
        reason: 'none',
      );

      await tester.enterText(
        find.byKey(const ValueKey('estimate-title')),
        'Crown and cap',
      );
      await tester.pump();
      await tester.tap(find.byKey(const ValueKey('estimate-save')));
      await tester.pumpAndSettle();

      expect(_server.patches.single['title'], 'Crown and cap');
      expect(find.text('EST-0430 · Karen Holt · Illinois'), findsOneWidget);
    });

    testWidgets('A1 Tools brought over as a total keeps that total', (
      tester,
    ) async {
      await _estimates(tester);
      await tester.tap(find.text('Paula Grant'));
      await tester.pumpAndSettle();

      expect(find.byKey(const ValueKey('estimate-imported')), findsOneWidget);
      expect(
        find.textContaining('Adding lines'),
        findsNothing,
        reason: 'archived: no lines can be added',
      );
      expect(_text(tester, 'estimate-total'), r'$2,600.00');
      // Archived: nothing further, not even a button to press.
      for (final type in [FilledButton, OutlinedButton, TextButton]) {
        expect(
          find.descendant(
            of: find.byType(EstimatePanel),
            matching: find.byType(type),
          ),
          findsNothing,
          reason: '$type',
        );
      }
    });
  });

  testWidgets('a new one: who, what for, its lines, and then it opens', (
    tester,
  ) async {
    await _estimates(tester);
    await tester.tap(find.byKey(const ValueKey('estimate-new')));
    await tester.pumpAndSettle();

    final create = find.byKey(const ValueKey('estimate-create'));
    expect(tester.widget<FilledButton>(create).onPressed, isNull);

    await tester.enterText(
      find.byKey(const ValueKey('estimate-customer')),
      'Nin',
    );
    await tester.pumpAndSettle();
    await tester.tap(find.text('Nina Patel · C-9'));
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('estimate-job')), findsOneWidget);

    await tester.enterText(
      find.byKey(const ValueKey('estimate-title')),
      'Dryer vent',
    );
    final add = find.byKey(const ValueKey('estimate-line-add'));
    await tester.ensureVisible(add);
    await tester.tap(add);
    await tester.pump();
    await tester.enterText(
      find.byKey(const ValueKey('estimate-line-name-0')),
      'Dryer vent cleaning',
    );
    await tester.enterText(
      find.byKey(const ValueKey('estimate-line-price-0')),
      '189',
    );
    await tester.pump();
    expect(_text(tester, 'estimate-total'), r'$189.00');

    await tester.tap(create);
    await tester.pumpAndSettle();

    final posted = _server.posts.single;
    expect(posted['franchise_id'], 'fr-il', reason: 'the only one there is');
    expect(posted['customer_id'], 'c-9');
    expect(posted.containsKey('job_id'), isFalse);
    expect(posted['title'], 'Dryer vent');
    expect(posted['lines'], [
      {
        'name': 'Dryer vent cleaning',
        'quantity': 1.0,
        'unit_price_minor': 18900,
      },
    ]);
    expect(find.text('EST-0432 · Nina Patel'), findsOneWidget);
    expect(find.byKey(const ValueKey('estimate-send')), findsOneWidget);
  });

  testWidgets('a phone opens the estimate in place, with a way back', (
    tester,
  ) async {
    await _estimates(tester, size: const Size(390, 844));
    expect(find.text('Estimate'), findsNothing, reason: 'tiles, no table');

    await tester.tap(find.text('Brian Moss'));
    await tester.pumpAndSettle();

    expect(find.text('EST-0431 · Brian Moss'), findsOneWidget);
    expect(find.text('Karen Holt'), findsNothing, reason: 'in place');
    expect(find.byType(AppBar), findsNothing, reason: 'containment');
    expect(find.byTooltip('Close EST-0431 · Brian Moss'), findsNothing);

    await tester.tap(find.byTooltip('Back'));
    await tester.pumpAndSettle();
    expect(find.text('Karen Holt'), findsOneWidget);
  });
}

const _all = ['jobs.view', 'jobs.financials', 'estimates.view'];
const _writes = [
  ..._all,
  'estimates.create',
  'estimates.edit',
  'estimates.delete',
];

String? _field(String key) => find
    .byKey(ValueKey(key))
    .evaluate()
    .map((e) => (e.widget as TextField).controller?.text)
    .firstOrNull;

String? _text(WidgetTester tester, String key) =>
    tester.widget<Text>(find.byKey(ValueKey(key))).data;

/// What the tab asked of the server, and a server that answers it.
class _Server {
  Map<String, String>? lastList;
  final patches = <Map<String, dynamic>>[];
  final posts = <Map<String, dynamic>>[];
  final deleted = <String>[];

  final estimates = {
    for (final e in [
      _estimate(
        'e-1',
        'EST-0431',
        'sent',
        'Brian Moss',
        385000,
        job: 'J-10479',
        lines: [
          _line('Stainless steel liner', 240000),
          _line('Liner installation labor', 120000),
          _line('Chimney cap, black', 25000),
        ],
      ),
      _estimate(
        'e-2',
        'EST-0430',
        'draft',
        'Karen Holt',
        124000,
        lines: [_line('Chimney sweep and inspection', 124000)],
      ),
      _estimate(
        'e-3',
        'EST-0428',
        'accepted',
        'Olivia Chen',
        45000,
        job: 'J-10471',
        lines: [_line('Chimney cap', 45000)],
      ),
      _estimate(
        'e-4',
        'A1-2291',
        'archived',
        'Paula Grant',
        260000,
        source: 'a1',
        title: 'Rebuild the crown',
        issued: null,
        created: '2026-03-03T12:00:00+00:00',
      ),
    ])
      e['id'] as String: e,
  };

  Future<http.Response> handle(http.Request request) async {
    final path = request.url.path;
    final id = path.split('/').last;

    if (path.endsWith('/me')) return _json({'data': _me});
    if (path.endsWith('/me/preferences')) {
      final body = jsonDecode(request.body) as Map<String, dynamic>;
      preferences.addAll(body['preferences'] as Map<String, dynamic>);
      return _json({'data': preferences});
    }
    if (path.endsWith('/services')) {
      return _json({
        'data': [
          {'id': 's-1', 'name': 'Chimney sweep', 'price_minor': 30000},
        ],
      });
    }
    if (path.endsWith('/customers')) {
      return _json({
        'data': [
          {'id': 'c-9', 'name': 'Nina Patel', 'number': 'C-9'},
        ],
      });
    }
    if (path.endsWith('/estimates') && request.method == 'GET') {
      lastList = request.url.queryParameters;
      final nobody = lastList!['search'] == 'nobody';
      // A board column asks for one status a page at a time.
      final column = lastList!.containsKey('per_page')
          ? lastList!['status']
          : null;
      return _json({
        'data': [
          if (!nobody)
            for (final e in estimates.values)
              if (column == null || e['status'] == column)
                {...e}..remove('lines'),
        ],
        'meta': {
          'transitions': {
            'draft': ['sent', 'archived'],
            'sent': ['accepted', 'declined', 'archived'],
            'accepted': ['archived'],
            'declined': ['archived'],
            'archived': <String>[],
          },
          'total': 2388,
          'current_page': 1,
          'last_page': 1,
          'status_counts': {
            'draft': 46,
            'sent': 52,
            'accepted': 275,
            'declined': 61,
            'archived': 1954,
          },
          'totals': {
            'USD': {'quoted': 585021200, 'accepted': 110648000},
          },
        },
      });
    }
    if (path.endsWith('/estimates') && request.method == 'POST') {
      final body = jsonDecode(request.body) as Map<String, dynamic>;
      posts.add(body);
      final made = _estimate(
        'e-5',
        'EST-0432',
        'draft',
        'Nina Patel',
        18900,
        lines: [
          for (final l in body['lines'] as List)
            Map<String, dynamic>.from(l as Map),
        ],
      );
      estimates['e-5'] = made;
      return _json({'data': made}, 201);
    }
    if (path.contains('/estimates/')) {
      switch (request.method) {
        case 'PATCH':
          final body = jsonDecode(request.body) as Map<String, dynamic>;
          patches.add(body);
          final e = estimates[id]!;
          if (body['status'] != null) e['status'] = body['status'];
          if (body['lines'] != null) e['lines'] = body['lines'];
          if (body.containsKey('title')) e['title'] = body['title'];
          return _json({'data': e});
        case 'DELETE':
          deleted.add(id);
          estimates.remove(id);
          return http.Response('', 204);
        default:
          return _json({'data': estimates[id]});
      }
    }
    if (path.contains('/jobs/')) {
      return _json({
        'data': {
          ..._job,
          'description': null,
          'duration_minutes': null,
          'allowed_statuses': ['Pending', 'Done'],
          'allowed_service_areas': <dynamic>[],
        },
      });
    }
    if (path.endsWith('/jobs')) {
      if (request.url.queryParameters['search'] == 'Nina Patel') {
        return _json({
          'data': [
            {
              'id': 'j-9',
              'number': 'J-10490',
              'title': 'Dryer vent',
              'customer': {'id': 'c-9', 'name': 'Nina Patel'},
              'franchise': {'id': 'fr-il', 'name': 'Illinois'},
            },
          ],
        });
      }
      return _json({
        'data': [_job],
        'meta': {'total': 1, 'last_page': 1, 'current_page': 1},
      });
    }
    if (path.endsWith('/exports')) {
      return _json({
        'resources': <dynamic>[],
        'organization': {'export_refusal': null, 'inventory_refusal': null},
      });
    }
    if (path.endsWith('/settings')) return _json({'data': <dynamic>[]});

    return _json({'message': 'Nothing stubbed for $path.'}, 500);
  }

  List<String> permissions = _writes;
  final preferences = <String, dynamic>{};

  Map<String, dynamic> get _me => {
    'id': 'u-1',
    'name': 'Jose',
    'email': 'jose@example.com',
    'role': {'name': 'Dispatcher'},
    'has_avatar': false,
    'preferences': preferences,
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
    'companies': <dynamic>[],
    'franchises': [
      {'id': 'fr-il', 'name': 'Illinois', 'company_id': 'co-1'},
    ],
    'permissions': permissions,
  };
}

late _Server _server;

const _job = {
  'id': 'j-1',
  'number': 'J-1',
  'title': 'Chimney Sweep',
  'status': 'Pending',
  'status_label': 'Pending',
  'scheduled_for': null,
  'franchise': {'id': 'fr-il', 'name': 'Illinois'},
  'customer': {'id': 'c-1', 'name': 'Brian Moss', 'number': 'C-1'},
};

Map<String, dynamic> _line(String name, int price) => {
  'id': 'l-$name',
  'service_id': null,
  'name': name,
  'description': null,
  'quantity': 1.0,
  'unit_price_minor': price,
};

Map<String, dynamic> _estimate(
  String id,
  String number,
  String status,
  String customer,
  int total, {
  String? job,
  String source = 'ezbizi',
  String? title,
  String? issued = '2026-09-26',
  String created = '2026-09-26T14:00:00+00:00',
  List<Map<String, dynamic>> lines = const [],
}) => {
  'id': id,
  'number': number,
  'status': status,
  'source': source,
  'franchise': {'id': 'fr-il', 'name': 'Illinois'},
  'customer': {'id': 'c-$id', 'name': customer, 'number': 'C-$id'},
  'job': job == null ? null : {'id': 'j-$job', 'number': job},
  'title': title,
  'issued_on': issued,
  'created_at': created,
  'accepted_at': status == 'accepted' ? '2026-09-27T10:00:00-05:00' : null,
  'currency': 'USD',
  'total_minor': total,
  'note': null,
  'lines': lines,
};

Future<Session> _session(WidgetTester tester, List<String> permissions) async {
  _server = _Server()..permissions = permissions;
  final api = ApiClient(client: MockClient(_server.handle));
  final session = Session(api);
  await tester.runAsync(() => session.adopt('tok'));
  return session;
}

Future<void> _estimates(
  WidgetTester tester, {
  Size size = const Size(1280, 900),
  bool empty = false,
}) async {
  tester.view.physicalSize = size;
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final session = await _session(tester, _writes);
  if (empty) _server.estimates.clear();
  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(
        body: EstimatesTab(api: session.api, session: session),
      ),
    ),
  );
  await tester.pumpAndSettle();
}

Future<void> _jobs(WidgetTester tester, List<String> permissions) async {
  tester.view.physicalSize = const Size(1280, 900);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final session = await _session(tester, permissions);
  await tester.pumpWidget(const SizedBox.shrink());
  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(
        body: JobsScreen(api: session.api, session: session),
      ),
    ),
  );
  await tester.pumpAndSettle();
}

http.Response _json(Object body, [int status = 200]) => http.Response(
  jsonEncode(body),
  status,
  headers: const {'content-type': 'application/json'},
);
