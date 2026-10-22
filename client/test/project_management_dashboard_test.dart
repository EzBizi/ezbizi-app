import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/project_management_screen.dart';
import 'package:ezbizi_client/widgets/status_pill.dart';
import 'package:ezbizi_client/widgets/tab_bar_like.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Project Management › Dashboard | Pending Jobs | Settings (UI queue 127,
/// option 1 and phone 4). Every name and number invented.
void main() {
  // A fixed local noon on a Wednesday: "Today" is 2026-10-21.
  final now = DateTime(2026, 10, 21, 12);

  testWidgets('four tabs, opening on Dashboard', (tester) async {
    final stub = _Stub();
    await _pump(tester, stub, now: now);

    final labels = [
      for (final l
          in tester.widget<TabBarLike>(find.byType(TabBarLike).first).labels)
        l.$1,
    ];
    expect(labels, ['Dashboard', 'Pending Jobs', 'Logs', 'Settings']);
    expect(stub.stats.single.queryParameters, {
      'from': '2026-10-21',
      'to': '2026-10-21',
      'timezone': 'America/New_York',
    });
  });

  testWidgets('five equal tiles from the stats, Paid with its amount', (
    tester,
  ) async {
    final stub = _Stub();
    await _pump(tester, stub, now: now);

    String count(String key) =>
        tester.widget<Text>(find.byKey(ValueKey('pm-tile-$key-count'))).data!;
    expect(count('payments'), '2');
    expect(count('status_changes'), '3');
    expect(count('tag_changes'), '1');
    expect(count('cancellations'), '0');
    expect(count('deadlines_due'), '1');
    expect(
      tester
          .widget<Text>(find.byKey(const ValueKey('pm-tile-payments-amount')))
          .data,
      r'$1,650.00',
    );

    final sizes = {
      for (final key in _tiles)
        tester.getSize(find.byKey(ValueKey('pm-tile-$key'))),
    };
    expect(sizes, hasLength(1), reason: 'sibling tiles are one size');
    // Five across.
    final tops = {
      for (final key in _tiles)
        tester.getTopLeft(find.byKey(ValueKey('pm-tile-$key'))).dy,
    };
    expect(tops, hasLength(1));
  });

  testWidgets('no amount on Paid when the server sends none', (tester) async {
    final stub = _Stub(money: false);
    await _pump(tester, stub, now: now);

    // The control: the tile and its count are there.
    expect(
      tester
          .widget<Text>(find.byKey(const ValueKey('pm-tile-payments-count')))
          .data,
      '2',
    );
    expect(find.byKey(const ValueKey('pm-tile-payments-amount')), findsNothing);
    expect(find.textContaining(r'$'), findsNothing);
  });

  testWidgets('Cancelled points at Settings until statuses are chosen', (
    tester,
  ) async {
    await _pump(tester, _Stub(), now: now);
    expect(
      find.text('Choose which statuses mean cancelled in Settings'),
      findsOneWidget,
    );

    await _pump(tester, _Stub(cancelled: const ['lost']), now: now);
    expect(
      find.byKey(const ValueKey('pm-tile-cancellations-hint')),
      findsNothing,
    );
  });

  testWidgets('a tile asks for its jobs by name and lists what happened', (
    tester,
  ) async {
    final stub = _Stub();
    await _pump(tester, stub, now: now);
    expect(find.byKey(const ValueKey('pm-dash-choose')), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('pm-tile-payments')));
    await tester.pumpAndSettle();

    // By the stat's name, never its ids: Yesterday's 514 broke the 500 cap.
    final asked = stub.lists.single;
    expect(asked.queryParameters, {
      'event': 'payments',
      'from': '2026-10-21',
      'to': '2026-10-21',
      'timezone': 'America/New_York',
    });
    expect(asked.queryParametersAll.containsKey('ids[]'), isFalse);

    for (final h in ['JOB', 'CUSTOMER', 'TECHNICIAN', 'WHAT', 'WHEN', 'BY']) {
      expect(find.text(h), findsOneWidget);
    }
    Finder inRow(String id, String text) => find.descendant(
      of: find.byKey(ValueKey('pm-dash-row-$id')),
      matching: find.textContaining(text),
    );
    expect(
      inRow('j-1', r'Payment received, partly paid · $1,200.00'),
      findsOneWidget,
    );
    expect(inRow('j-1', 'Workiz'), findsOneWidget);
    expect(inRow('j-1', 'Lena Vroom'), findsOneWidget);
    expect(inRow('j-1', 'Omar Pell'), findsOneWidget);
    // Three tags, then the count of the rest.
    final tags = find.byKey(const ValueKey('pm-tags-j-1'));
    for (final t in ['Follow up', 'VIP', 'Rush', '+1']) {
      expect(find.descendant(of: tags, matching: find.text(t)), findsOneWidget);
    }
    expect(find.text('Warranty'), findsNothing);
    // Today's step is a time; the older payment is outside the dates.
    expect(inRow('j-1', '9:42'), findsOneWidget);
    expect(inRow('j-1', r'$300.00'), findsNothing);

    await tester.tap(find.byKey(const ValueKey('pm-dash-row-j-1')));
    await tester.pumpAndSettle();
    final pane = find.byKey(const ValueKey('pm-timeline'));
    expect(pane, findsOneWidget);
    expect(
      find.descendant(of: pane, matching: find.text('Open the job')),
      findsOneWidget,
    );
  });

  testWidgets("status changes use the server's sentence", (tester) async {
    final stub = _Stub();
    await _pump(tester, stub, now: now);

    await tester.tap(find.byKey(const ValueKey('pm-tile-status_changes')));
    await tester.pumpAndSettle();

    expect(
      find.descendant(
        of: find.byKey(const ValueKey('pm-dash-row-j-1')),
        matching: find.text('Status Quote out → Booked'),
      ),
      findsOneWidget,
    );
  });

  testWidgets('an empty range says so, and asks for no jobs', (tester) async {
    final stub = _Stub(empty: true);
    await _pump(tester, stub, now: now);

    expect(find.text('Nothing happened in these dates.'), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('pm-tile-payments')));
    await tester.pumpAndSettle();
    expect(stub.lists, isEmpty, reason: 'no ids is every watched job');
  });

  testWidgets('a range choice asks again with its dates', (tester) async {
    final stub = _Stub();
    await _pump(tester, stub, now: now);

    await tester.tap(find.byKey(const ValueKey('pm-range-this_week')));
    await tester.pumpAndSettle();
    expect(stub.stats.last.queryParameters, {
      'from': '2026-10-19',
      'to': '2026-10-21',
      'timezone': 'America/New_York',
    });

    await tester.tap(find.byKey(const ValueKey('pm-range-custom')));
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('pm-range-panel')), findsOneWidget);
  });

  testWidgets('Pending Jobs opens on Recently paid, with the dates', (
    tester,
  ) async {
    final stub = _Stub();
    await _pump(tester, stub, now: now);

    await tester.tap(find.text('Pending Jobs'));
    await tester.pumpAndSettle();

    expect(stub.lists.single.queryParameters, {
      'filter': 'recently_paid',
      'from': '2026-10-21',
      'to': '2026-10-21',
      'timezone': 'America/New_York',
    });
    final chip = tester.widget<ChoiceChip>(
      find.byKey(const ValueKey('pm-stage-recently_paid')),
    );
    expect(chip.selected, isTrue);
    // A colour of its own, as each stage has.
    expect((chip.avatar! as ToneDot).tone, Tone.review);
    expect({
      for (final st in ['recently_paid', 'waiting', 'partial', 'full'])
        (tester.widget<ChoiceChip>(find.byKey(ValueKey('pm-stage-$st'))).avatar!
                as ToneDot)
            .tone,
    }, hasLength(4));
    // The row's technician and tags, on Pending Jobs too.
    expect(find.text('TECHNICIAN'), findsOneWidget);
    expect(
      find.descendant(
        of: find.byKey(const ValueKey('pm-row-j-1')),
        matching: find.text('Omar Pell'),
      ),
      findsOneWidget,
    );
    expect(find.byKey(const ValueKey('pm-tags-j-1')), findsOneWidget);
    expect(find.byKey(const ValueKey('pm-stage-waiting')), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('pm-range-yesterday')));
    await tester.pumpAndSettle();
    expect(stub.lists.last.queryParameters, {
      'filter': 'recently_paid',
      'from': '2026-10-20',
      'to': '2026-10-20',
      'timezone': 'America/New_York',
    });

    // Another stage is the watched list, without the filter; the dates still
    // go, for Recently paid's count.
    await tester.tap(find.byKey(const ValueKey('pm-stage-waiting')));
    await tester.pumpAndSettle();
    expect(stub.lists.last.queryParameters, {
      'from': '2026-10-20',
      'to': '2026-10-20',
      'timezone': 'America/New_York',
    });
    expect(find.byKey(const ValueKey('pm-range')), findsNothing);
  });

  testWidgets('Settings writes both lists', (tester) async {
    final stub = _Stub();
    await _pump(tester, stub, now: now);

    await tester.tap(find.text('Settings'));
    await tester.pumpAndSettle();
    expect(
      find.text('A job cancels 30 days after its estimate.'),
      findsOneWidget,
    );

    // Remove a watched status.
    await tester.tap(
      find.descendant(
        of: find.byKey(
          const ValueKey('pm-settings-jobs.estimate_aging_statuses-approved'),
        ),
        matching: find.byTooltip('Remove'),
      ),
    );
    await tester.pumpAndSettle();

    // Add a cancelled one.
    await tester.tap(
      find.byKey(const ValueKey('pm-settings-jobs.cancelled_statuses-add')),
    );
    await tester.pumpAndSettle();
    await tester.tap(find.text('Lost').last);
    await tester.pumpAndSettle();

    expect(stub.patches.keys, [
      'jobs.estimate_aging_statuses',
      'jobs.cancelled_statuses',
    ]);
    expect(stub.patches['jobs.estimate_aging_statuses'], {
      'value': ['estimate_sent'],
    });
    expect(stub.patches['jobs.cancelled_statuses'], {
      'value': ['lost'],
    });
    for (final q in stub.patchQueries) {
      expect(q, {'scope_type': 'organization', 'scope_id': 'org-1'});
    }
    expect(
      find.byKey(const ValueKey('pm-settings-jobs.cancelled_statuses-lost')),
      findsOneWidget,
    );
  });

  testWidgets('the last watched status cannot be removed', (tester) async {
    final stub = _Stub();
    await _pump(tester, stub, now: now);
    await tester.tap(find.text('Settings'));
    await tester.pumpAndSettle();

    const approved = ValueKey(
      'pm-settings-jobs.estimate_aging_statuses-approved',
    );
    const sent = ValueKey(
      'pm-settings-jobs.estimate_aging_statuses-estimate_sent',
    );
    await tester.tap(
      find.descendant(
        of: find.byKey(approved),
        matching: find.byTooltip('Remove'),
      ),
    );
    await tester.pumpAndSettle();
    expect(stub.patchLog, ['jobs.estimate_aging_statuses ["estimate_sent"]']);

    // One left: no X, and a line saying why.
    expect(
      find.descendant(of: find.byKey(sent), matching: find.byTooltip('Remove')),
      findsNothing,
    );
    expect(tester.widget<InputChip>(find.byKey(sent)).onDeleted, isNull);
    expect(
      find.text(
        'Keep at least one status; add another before removing this one.',
      ),
      findsOneWidget,
    );

    // Even a direct call on the chip sends nothing empty.
    tester.widget<InputChip>(find.byKey(sent)).onDeleted?.call();
    await tester.pumpAndSettle();
    expect(stub.patchLog.where((p) => p.endsWith(' []')), isEmpty);
    expect(stub.patchLog, hasLength(1));
    expect(find.byKey(sent), findsOneWidget);
  });

  testWidgets('removing the last cancelled status saves [] and offers Undo', (
    tester,
  ) async {
    final stub = _Stub(cancelled: const ['lost']);
    await _pump(tester, stub, now: now);
    await tester.tap(find.text('Settings'));
    await tester.pumpAndSettle();

    const lost = ValueKey('pm-settings-jobs.cancelled_statuses-lost');
    await tester.tap(
      find.descendant(of: find.byKey(lost), matching: find.byTooltip('Remove')),
    );
    await tester.pumpAndSettle();
    expect(stub.patchLog, ['jobs.cancelled_statuses []']);
    expect(find.byKey(lost), findsNothing);
    expect(find.text('Nothing counts as cancelled now'), findsOneWidget);

    await tester.tap(find.widgetWithText(SnackBarAction, 'Undo'));
    await tester.pumpAndSettle();
    expect(stub.patchLog, [
      'jobs.cancelled_statuses []',
      'jobs.cancelled_statuses ["lost"]',
    ]);
    expect(find.byKey(lost), findsOneWidget);
  });

  testWidgets('phone: two tiles a row, the list and timeline full screen', (
    tester,
  ) async {
    final overflows = <String>[];
    final previous = FlutterError.onError;
    FlutterError.onError = (details) {
      if ('${details.exception}'.contains('overflowed')) {
        overflows.add('${details.exception}');
        return;
      }
      previous?.call(details);
    };
    addTearDown(() => FlutterError.onError = previous);

    final stub = _Stub();
    await _pump(tester, stub, now: now, size: const Size(390, 844));

    final first = tester.getTopLeft(
      find.byKey(const ValueKey('pm-tile-payments')),
    );
    final second = tester.getTopLeft(
      find.byKey(const ValueKey('pm-tile-status_changes')),
    );
    final third = tester.getTopLeft(
      find.byKey(const ValueKey('pm-tile-tag_changes')),
    );
    expect(second.dy, first.dy);
    expect(third.dy, greaterThan(first.dy));
    expect({
      for (final key in _tiles)
        tester.getSize(find.byKey(ValueKey('pm-tile-$key'))),
    }, hasLength(1));

    await tester.tap(find.byKey(const ValueKey('pm-tile-payments')));
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('pm-tile-payments')), findsNothing);
    expect(find.byKey(const ValueKey('pm-dash-card-j-1')), findsOneWidget);
    // The card's second line: the technician and the tags.
    final card = find.byKey(const ValueKey('pm-dash-card-j-1'));
    final tech = find.byKey(const ValueKey('pm-dash-tech-j-1'));
    expect(find.descendant(of: card, matching: tech), findsOneWidget);
    expect(
      find.descendant(
        of: card,
        matching: find.byKey(const ValueKey('pm-tags-j-1')),
      ),
      findsOneWidget,
    );

    await tester.tap(find.byKey(const ValueKey('pm-dash-card-j-1')));
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('pm-timeline')), findsOneWidget);
    expect(find.byKey(const ValueKey('pm-dash-card-j-1')), findsNothing);

    await tester.tap(find.byKey(const ValueKey('pm-timeline-close')));
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('pm-dash-card-j-1')), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('pm-dash-back')));
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('pm-tile-payments')), findsOneWidget);

    // The other two tabs at the same width.
    await tester.tap(find.text('Pending Jobs'));
    await tester.pumpAndSettle();
    await tester.ensureVisible(find.text('Settings'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Settings'));
    await tester.pumpAndSettle();
    expect(
      find.text('A job cancels 30 days after its estimate.'),
      findsOneWidget,
    );

    expect(overflows, isEmpty);
  });
}

const _tiles = [
  'payments',
  'status_changes',
  'tag_changes',
  'cancellations',
  'deadlines_due',
];

/// The server, and what was asked of it.
class _Stub {
  _Stub({this.money = true, this.empty = false, this.cancelled = const []});

  final bool money;
  final bool empty;
  final List<String> cancelled;

  final stats = <Uri>[];
  final lists = <Uri>[];
  final patches = <String, Object?>{};
  final patchQueries = <Map<String, String>>[];

  /// Every PATCH in order, as `key value-json`.
  final patchLog = <String>[];

  Map<String, dynamic> bucket(List<String> ids) => {
    'count': empty ? 0 : ids.length,
    'job_ids': empty ? const <String>[] : ids,
  };

  Future<http.Response> handle(http.Request request) async {
    final path = request.url.path;
    if (path.endsWith('/me')) return _json({'data': _me});

    if (path.endsWith('/jobs/project-management/stats')) {
      stats.add(request.url);
      final q = request.url.queryParameters;
      return _json({
        'data': {
          'from': q['from'],
          'to': q['to'],
          'payments': {
            ...bucket(['j-1', 'j-2']),
            if (money && !empty) 'amount_minor': 165000,
          },
          'status_changes': {
            ...bucket(['j-1', 'j-2', 'j-3']),
            'count': empty ? 0 : 3,
          },
          'tag_changes': bucket(['j-2']),
          'cancellations': bucket(const []),
          'deadlines_due': bucket(['j-3']),
        },
      });
    }

    if (path.endsWith('/jobs/project-management')) {
      lists.add(request.url);
      return _json({
        'data': [_row('j-1', 'JOB-601', 'Lena Vroom', money: money)],
        'meta': {
          'watched': ['estimate_sent'],
          'deadline_days': 30,
        },
      });
    }

    if (path.contains('/settings/') && request.method == 'PATCH') {
      final key = path.split('/').last;
      final body = jsonDecode(request.body) as Map<String, dynamic>;
      patches[key] = body;
      patchLog.add('$key ${jsonEncode(body['value'])}');
      patchQueries.add(request.url.queryParameters);
      return _json({
        'data': {'key': key, 'value': body['value']},
      });
    }

    if (path.endsWith('/settings')) {
      List<Map<String, String>> options() => const [
        {'value': 'estimate_sent', 'label': 'Estimate sent'},
        {'value': 'approved', 'label': 'Approved'},
        {'value': 'lost', 'label': 'Lost'},
      ];
      return _json({
        'data': [
          {'key': 'jobs.statuses', 'value': options()},
          {
            'key': 'jobs.estimate_aging_statuses',
            'value': ['estimate_sent', 'approved'],
            'options': options(),
            'may_edit': true,
          },
          {
            'key': 'jobs.cancelled_statuses',
            'value': cancelled,
            'options': options(),
            'may_edit': true,
          },
        ],
      });
    }

    return _json({'message': 'Nothing stubbed for $path.'}, 404);
  }
}

Map<String, dynamic> _row(
  String id,
  String number,
  String customer, {
  required bool money,
}) => {
  'id': id,
  'number': number,
  'title': 'Flue reline',
  'status': 'approved',
  'tags': const ['Follow up', 'VIP', 'Rush', 'Warranty'],
  'technician': 'Omar Pell',
  'franchise': {'id': 'fr-1', 'name': 'Northgate'},
  'customer': {'id': 'c-$id', 'name': customer},
  'stage': 'partial',
  'waiting': {
    'estimate_date': '2026-10-01',
    'days_waiting': 20,
    'deadline': null,
    'deadline_at': null,
    'timer': 'stopped',
  },
  'timeline': [
    {
      'kind': 'entered',
      'at': '2026-10-01T14:00:00Z',
      'status': 'estimate_sent',
      'by': 'Dara Quill',
      'what': 'Entered estimate_sent',
    },
    {
      'kind': 'payment',
      'at': DateTime(2026, 10, 2, 10).toUtc().toIso8601String(),
      'marks': 'partial',
      'by': 'Workiz',
      'what': 'Payment received, partly paid',
      if (money) 'amount_minor': 30000,
    },
    {
      'kind': 'status',
      'at': DateTime(2026, 10, 21, 9, 10).toUtc().toIso8601String(),
      'from': 'estimate_sent',
      'to': 'approved',
      'by': 'Dara Quill',
      // Not the client's own status words: the sentence is the server's.
      'what': 'Status Quote out → Booked',
    },
    {
      'kind': 'payment',
      'at': DateTime(2026, 10, 21, 9, 42).toUtc().toIso8601String(),
      'marks': 'partial',
      'by': 'Workiz',
      'what': 'Payment received, partly paid',
      if (money) 'amount_minor': 120000,
    },
  ],
};

Future<void> _pump(
  WidgetTester tester,
  _Stub stub, {
  required DateTime now,
  Size size = const Size(1280, 900),
}) async {
  tester.view.physicalSize = size;
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(client: MockClient(stub.handle));
  final session = Session(api);
  await tester.runAsync(() => session.adopt('tok'));
  expect(session.signedIn, isTrue);

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(
        body: ProjectManagementScreen(
          key: UniqueKey(),
          api: api,
          session: session,
          now: () => now,
          zone: () => 'America/New_York',
        ),
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

const _me = {
  'id': 'u-1',
  'name': 'Rhea Tollan',
  'email': 'rhea@example.com',
  'role': {'name': 'Manager'},
  'has_avatar': false,
  'permissions': ['jobs.view', 'jobs.financials', 'project_management.view'],
  'preferences': <String, dynamic>{},
  'organization': {
    'id': 'org-1',
    'name': 'Brightway',
    'franchise_label': 'Franchise',
    'is_read_only': false,
  },
  'scope': {
    'type': 'organization',
    'id': 'org-1',
    'label': 'Brightway',
    'path': ['Brightway'],
  },
  'companies': [
    {'id': 'co-1', 'name': 'Brightway', 'parent_company_id': null},
  ],
  'franchises': [
    {'id': 'fr-1', 'name': 'Northgate', 'company_id': 'co-1'},
    {'id': 'fr-2', 'name': 'Lakeside', 'company_id': 'co-1'},
  ],
};
