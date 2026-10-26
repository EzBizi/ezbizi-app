import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/deep_links.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/hr/pto_tab.dart';
import 'package:flutter/material.dart';
import 'package:flutter/rendering.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Paid Time Off › Requests — design 146, Jose's options 1 and 4
/// (2026-10-26, docs/previews/pto-requests-layouts.html). Every name, date
/// and number here is invented.
void main() {
  tearDown(() => DeepLinks.pending.value = null);

  group('the maths', () {
    test('halves are written as ½, negatives with a minus', () {
      expect(PtoRequestMaths.half(2.5), '2½');
      expect(PtoRequestMaths.half(0.5), '½');
      expect(PtoRequestMaths.half(3), '3');
      expect(PtoRequestMaths.half(-1), '−1');
      expect(PtoRequestMaths.half(-1.5), '−1½');
    });

    test('consecutive full days are one run; a half day stands alone', () {
      expect(
        PtoRequestMaths.daysLabel([
          {'date': '2026-11-14', 'portion': 0.5},
          {'date': '2026-11-11', 'portion': 1},
          {'date': '2026-11-12', 'portion': 1},
          {'date': '2026-11-30', 'portion': 1},
          {'date': '2026-12-01', 'portion': 1},
        ]),
        'Nov 11–12 · Nov 14 (½) · Nov 30–Dec 1',
      );
    });
  });

  testWidgets('the segment: Balances first, Requests with the count of what '
      'waits for the reader', (tester) async {
    await _pump(tester, width: 1200);

    expect(find.byKey(const Key('pto-seg-balances')), findsOneWidget);
    expect(
      find.descendant(
        of: find.byKey(const Key('pto-requests-badge')),
        matching: find.text('2'),
      ),
      findsOneWidget,
    );
    // Balances is today's view, unchanged.
    expect(find.byKey(const Key('pto-search')), findsOneWidget);
    expect(find.text('Waiting for you · 2'), findsNothing);

    await tester.tap(find.byKey(const Key('pto-seg-requests')));
    await tester.pumpAndSettle();
    expect(find.text('Waiting for you · 2'), findsOneWidget);
    expect(find.byKey(const Key('pto-req-search')), findsOneWidget);
    expect(find.byKey(const Key('pto-req-status')), findsOneWidget);
    expect(find.byType(Dialog), findsNothing);
  });

  testWidgets('a waiting row: person, asked, grouped days with ½, total, '
      'note, and what approving leaves — red below zero', (tester) async {
    await _pump(tester, width: 1200, requestsView: true);

    final row = find.byKey(const Key('pto-req-waiting-q-1'));
    Finder inRow(String text) =>
        find.descendant(of: row, matching: find.text(text));
    expect(inRow('Dana Reyes'), findsOneWidget);
    expect(inRow('asked Oct 24'), findsOneWidget);
    expect(inRow('Nov 11–12 · Nov 14 (½)'), findsOneWidget);
    expect(inRow('2½'), findsOneWidget);
    expect(inRow('Family trip'), findsOneWidget);
    expect(inRow('leaves 3½'), findsOneWidget);

    final scheme = Theme.of(tester.element(row)).colorScheme;
    Color? ink(String id) =>
        tester.widget<Text>(find.byKey(Key('pto-req-leaves-$id'))).style?.color;
    expect(ink('q-1'), isNot(scheme.error));
    expect(
      tester.widget<Text>(find.byKey(const Key('pto-req-leaves-q-2'))).data,
      'leaves −1',
    );
    expect(ink('q-2'), scheme.error);
  });

  testWidgets('Approve POSTs and the row takes the answer', (tester) async {
    final requests = <http.Request>[];
    await _pump(tester, width: 1200, requestsView: true, requests: requests);

    await tester.tap(find.byKey(const Key('pto-req-approve-q-1')));
    await tester.pumpAndSettle();

    final post = requests.singleWhere((r) => r.method == 'POST');
    expect(post.url.path, endsWith('/hr/pto/requests/q-1/approve'));
    expect(find.byKey(const Key('pto-req-waiting-q-1')), findsNothing);
    expect(find.text('Waiting for you · 1'), findsOneWidget);
    expect(
      find.descendant(
        of: find.byKey(const Key('pto-requests-badge')),
        matching: find.text('1'),
      ),
      findsOneWidget,
    );
  });

  testWidgets('Deny asks for a reason on the row and sends it', (tester) async {
    final requests = <http.Request>[];
    await _pump(tester, width: 1200, requestsView: true, requests: requests);

    await tester.tap(find.byKey(const Key('pto-req-deny-q-2')));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('pto-req-reason-q-2')), findsOneWidget);
    expect(find.byType(Dialog), findsNothing);

    final confirm = find.byKey(const Key('pto-req-deny-confirm-q-2'));
    expect(tester.widget<FilledButton>(confirm).onPressed, isNull);
    await tester.tap(confirm);
    await tester.pumpAndSettle();
    expect(requests.where((r) => r.method == 'POST'), isEmpty);

    await tester.enterText(
      find.byKey(const Key('pto-req-reason-q-2')),
      'over the balance',
    );
    await tester.pump();
    expect(tester.widget<FilledButton>(confirm).onPressed, isNotNull);
    await tester.tap(confirm);
    await tester.pumpAndSettle();

    final post = requests.singleWhere((r) => r.method == 'POST');
    expect(post.url.path, endsWith('/hr/pto/requests/q-2/deny'));
    expect(jsonDecode(post.body), {'reason': 'over the balance'});
    expect(find.byKey(const Key('pto-req-waiting-q-2')), findsNothing);
  });

  testWidgets('Request time off: inline, the shared calendar, recorded days '
      'disabled, and Send POSTs the portions', (tester) async {
    final requests = <http.Request>[];
    await _pump(tester, width: 1200, requestsView: true, requests: requests);

    await tester.tap(find.byKey(const Key('pto-req-new')));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('pto-req-form')), findsOneWidget);
    expect(find.byKey(const Key('pto-calendar')), findsOneWidget);
    expect(find.byType(Dialog), findsNothing);

    String figure(String key) => tester
        .widgetList<Text>(
          find.descendant(
            of: find.byKey(Key(key)),
            matching: find.byType(Text),
          ),
        )
        .last
        .data!;
    // Left 3, less the 1 day still waiting.
    expect(figure('pto-req-left-now'), '2');

    // Recorded on the 3rd: a tap does nothing.
    await tester.tap(find.byKey(Key('pto-day-${_iso(_monthDay(3))}')));
    await tester.pumpAndSettle();
    expect(figure('pto-req-this'), '0');

    await tester.tap(find.byKey(Key('pto-day-${_iso(_monthDay(10))}')));
    await tester.pumpAndSettle();
    for (var i = 0; i < 2; i++) {
      await tester.tap(find.byKey(Key('pto-day-${_iso(_monthDay(11))}')));
      await tester.pumpAndSettle();
    }
    expect(figure('pto-req-this'), '1½');
    expect(figure('pto-req-left-after'), '½');

    await tester.enterText(find.byKey(const Key('pto-req-note')), 'Trip');
    await tester.tap(find.byKey(const Key('pto-req-send')));
    await tester.pumpAndSettle();

    final post = requests.singleWhere((r) => r.method == 'POST');
    expect(post.url.path, endsWith('/hr/pto/requests'));
    expect(jsonDecode(post.body), {
      'days': [
        {'date': _iso(_monthDay(10)), 'portion': 1},
        {'date': _iso(_monthDay(11)), 'portion': 0.5},
      ],
      'note': 'Trip',
    });
    expect(find.byKey(const Key('pto-req-form')), findsNothing);
    expect(find.byKey(const Key('pto-req-mine-q-9')), findsOneWidget);
  });

  testWidgets('over the balance: one sentence and Send stays off', (
    tester,
  ) async {
    await _pump(tester, width: 1200, requestsView: true);
    await tester.tap(find.byKey(const Key('pto-req-new')));
    await tester.pumpAndSettle();

    for (final d in [10, 11, 12]) {
      await tester.tap(find.byKey(Key('pto-day-${_iso(_monthDay(d))}')));
      await tester.pumpAndSettle();
    }
    expect(find.byKey(const Key('pto-req-over')), findsOneWidget);
    expect(
      tester
          .widget<FilledButton>(find.byKey(const Key('pto-req-send')))
          .onPressed,
      isNull,
    );
  });

  testWidgets('a refusal shows the server’s sentence and keeps the form', (
    tester,
  ) async {
    const sentence = 'Already recorded as a day off: 2026-11-10.';
    await _pump(
      tester,
      width: 1200,
      requestsView: true,
      post: () => _json({
        'message': sentence,
        'errors': {
          'days': [sentence],
        },
      }, 422),
    );
    await tester.tap(find.byKey(const Key('pto-req-new')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(Key('pto-day-${_iso(_monthDay(10))}')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('pto-req-send')));
    await tester.pumpAndSettle();

    expect(find.text(sentence), findsOneWidget);
    expect(find.byKey(const Key('pto-req-form')), findsOneWidget);
  });

  testWidgets('My requests: a chip each, and Cancel while waiting', (
    tester,
  ) async {
    final requests = <http.Request>[];
    await _pump(tester, width: 1200, requestsView: true, requests: requests);

    String chip(String id) => tester
        .widget<Text>(
          find.descendant(
            of: find.byKey(Key('pto-req-status-$id')),
            matching: find.byType(Text),
          ),
        )
        .data!;
    expect(chip('q-3'), 'Waiting');
    expect(chip('q-4'), 'Approved by Shoval · Aug 28');
    expect(chip('q-5'), 'Denied by Shoval · "short-staffed that week"');
    expect(chip('q-6'), 'Cancelled');
    expect(find.byKey(const Key('pto-req-cancel-q-4')), findsNothing);

    await tester.tap(find.byKey(const Key('pto-req-cancel-q-3')));
    await tester.pumpAndSettle();
    final post = requests.singleWhere((r) => r.method == 'POST');
    expect(post.url.path, endsWith('/hr/pto/requests/q-3/cancel'));
    expect(chip('q-3'), 'Cancelled');
    expect(find.byKey(const Key('pto-req-cancel-q-3')), findsNothing);
  });

  testWidgets('a pto_request notification opens Requests', (tester) async {
    DeepLinks.open(
      const DeepLink(module: 'work_hours', type: ptoRequestLinkType, id: 'q-1'),
    );
    await _pump(tester, width: 1200);
    expect(find.text('Waiting for you · 2'), findsOneWidget);
    expect(DeepLinks.pending.value, isNull);
  });

  testWidgets('the calendar paints a picked day filled with the brand colour', (
    tester,
  ) async {
    await _pump(tester, width: 1200, requestsView: true);
    await tester.tap(find.byKey(const Key('pto-req-new')));
    await tester.pumpAndSettle();

    final day = find.byKey(Key('pto-day-${_iso(_monthDay(10))}'));
    RenderPhysicalShape shape() => tester.renderObject<RenderPhysicalShape>(
      find.ancestor(of: day, matching: find.byType(PhysicalShape)).first,
    );
    final scheme = Theme.of(tester.element(day)).colorScheme;
    expect(shape().color.a, 0);

    await tester.tap(day);
    await tester.pumpAndSettle();
    expect(shape().color, scheme.primary);
  });

  testWidgets('phone at 390×844: the segment, waiting cards, and the form '
      'full screen in place with ←', (tester) async {
    await _pump(tester, width: 390, height: 844);
    expect(tester.takeException(), isNull);

    await tester.tap(find.byKey(const Key('pto-seg-requests')));
    await tester.pumpAndSettle();
    expect(tester.takeException(), isNull);
    expect(find.byKey(const Key('pto-req-approve-q-1')), findsOneWidget);
    expect(find.text('Dana Reyes · 2½ days'), findsOneWidget);

    await tester.tap(find.byKey(const Key('pto-req-deny-q-1')));
    await tester.pumpAndSettle();
    expect(tester.takeException(), isNull);
    await tester.tap(find.byKey(const Key('pto-req-deny-back-q-1')));
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const Key('pto-req-new')));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('pto-phone-request')), findsOneWidget);
    expect(find.byKey(const Key('pto-calendar')), findsOneWidget);
    expect(find.byKey(const Key('pto-seg-requests')), findsNothing);
    expect(find.byType(Navigator).evaluate().length, 1);
    await tester.tap(find.byKey(Key('pto-day-${_iso(_monthDay(10))}')));
    await tester.pumpAndSettle();
    expect(tester.takeException(), isNull);

    await tester.tap(find.byIcon(Icons.arrow_back));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('pto-phone-request')), findsNothing);
    expect(find.byKey(const Key('pto-seg-requests')), findsOneWidget);
  });
}

// ── fixtures ─────────────────────────────────────────────────────────

DateTime _monthDay(int day) {
  final now = DateTime.now();
  return DateTime(now.year, now.month, day);
}

String _iso(DateTime at) =>
    '${at.year}-${at.month.toString().padLeft(2, '0')}-'
    '${at.day.toString().padLeft(2, '0')}';

Future<void> _pump(
  WidgetTester tester, {
  required double width,
  double height = 1400,
  bool requestsView = false,
  List<http.Request>? requests,
  http.Response Function()? post,
}) async {
  tester.view.physicalSize = Size(width, height);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(
    client: MockClient((request) async {
      requests?.add(request);
      final path = request.url.path;
      if (path.endsWith('/me')) return _json({'data': _me});
      if (request.method == 'POST') {
        final m = RegExp(
          r'/hr/pto/requests/([^/]+)/(approve|deny|cancel)$',
        ).firstMatch(path);
        if (m != null) {
          final row = Map<String, dynamic>.from(
            _rows.firstWhere((r) => r['id'] == m.group(1)),
          );
          row['status'] = switch (m.group(2)) {
            'approve' => 'approved',
            'deny' => 'denied',
            _ => 'cancelled',
          };
          row['can_decide'] = false;
          row['would_leave'] = null;
          return _json({'data': row});
        }
        if (path.endsWith('/hr/pto/requests')) {
          return post?.call() ??
              _json({
                'data': {
                  'id': 'q-9',
                  'person': {'id': 'u-1', 'name': 'Pat Lin'},
                  'days': [
                    {'date': _iso(_monthDay(10)), 'portion': 1},
                  ],
                  'total': 1.5,
                  'status': 'pending',
                  'mine': true,
                  'can_decide': false,
                  'created_at': '2026-10-26T12:00:00+00:00',
                },
              }, 201);
        }
      }
      if (path.endsWith('/hr/pto/requests')) return _json({'data': _rows});
      if (path.endsWith('/hr/pto')) return _json(_body());
      return _json({'message': 'Unexpected $path'}, 500);
    }),
  );

  final session = Session(api);
  await tester.runAsync(() => session.adopt('tok'));

  await tester.pumpWidget(
    MaterialApp(
      key: UniqueKey(),
      theme: EzBiziTheme.light(),
      home: Scaffold(
        body: PtoTab(api: api, session: session),
      ),
    ),
  );
  await tester.pumpAndSettle();
  if (requestsView) {
    await tester.tap(find.byKey(const Key('pto-seg-requests')));
    await tester.pumpAndSettle();
  }
}

final _rows = <Map<String, dynamic>>[
  {
    'id': 'q-1',
    'person': {'id': 'u-2', 'name': 'Dana Reyes'},
    'days': [
      {'date': '2026-11-11', 'portion': 1},
      {'date': '2026-11-12', 'portion': 1},
      {'date': '2026-11-14', 'portion': 0.5},
    ],
    'total': 2.5,
    'note': 'Family trip',
    'status': 'pending',
    'created_at': '2026-10-24T15:00:00+00:00',
    'left': 6,
    'would_leave': 3.5,
    'mine': false,
    'can_decide': true,
  },
  {
    'id': 'q-2',
    'person': {'id': 'u-3', 'name': 'Sam Ortiz'},
    'days': [
      {'date': '2026-12-23', 'portion': 1},
      {'date': '2026-12-24', 'portion': 1},
    ],
    'total': 2,
    'note': null,
    'status': 'pending',
    'created_at': '2026-10-25T15:00:00+00:00',
    'left': 1,
    'would_leave': -1,
    'mine': false,
    'can_decide': true,
  },
  {
    'id': 'q-3',
    'person': {'id': 'u-1', 'name': 'Pat Lin'},
    'days': [
      {'date': '2026-11-20', 'portion': 1},
    ],
    'total': 1,
    'status': 'pending',
    'created_at': '2026-10-20T15:00:00+00:00',
    'mine': true,
    'can_decide': false,
  },
  {
    'id': 'q-4',
    'person': {'id': 'u-1', 'name': 'Pat Lin'},
    'days': [
      {'date': '2026-09-02', 'portion': 1},
      {'date': '2026-09-03', 'portion': 1},
    ],
    'total': 2,
    'status': 'approved',
    'decided_by': 'Shoval',
    'decided_at': '2026-08-28T15:00:00+00:00',
    'mine': true,
    'can_decide': false,
  },
  {
    'id': 'q-5',
    'person': {'id': 'u-1', 'name': 'Pat Lin'},
    'days': [
      {'date': '2026-07-04', 'portion': 1},
    ],
    'total': 1,
    'status': 'denied',
    'decided_by': 'Shoval',
    'decided_at': '2026-07-01T15:00:00+00:00',
    'reason': 'short-staffed that week',
    'mine': true,
    'can_decide': false,
  },
  {
    'id': 'q-6',
    'person': {'id': 'u-1', 'name': 'Pat Lin'},
    'days': [
      {'date': '2026-06-01', 'portion': 1},
    ],
    'total': 1,
    'status': 'cancelled',
    'mine': true,
    'can_decide': false,
  },
];

Map<String, dynamic> _body() => {
  'policy': {
    'enabled': true,
    'allowance': 6,
    'allowance_period': 'year',
    'waiting': 0,
    'waiting_unit': 'day',
    'carry_over': false,
    'reset': 'anniversary',
    'allow_negative': false,
  },
  'me': 'u-1',
  'data': [
    {
      'id': 'u-1',
      'name': 'Pat Lin',
      'allowance': 6,
      'eligible': true,
      'total': 6,
      'used': 3,
      'left': 3,
      'can_edit_used': false,
      'days_off': [
        {'id': 'd-1', 'date': _iso(_monthDay(3)), 'portion': 1},
      ],
      'used_changes': <Object>[],
      'resets': <Object>[],
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
  'name': 'Pat Lin',
  'email': 'pat@example.test',
  'has_avatar': false,
  'role': {'id': 'r-office', 'name': 'Office'},
  'permissions': ['hr.view', 'work_hours.pto.view', 'work_hours.pto.request'],
  'modules': <Object>[],
  'preferences': <String, dynamic>{},
  'organization': {
    'id': 'org-1',
    'name': 'Brightside Home Services',
    'franchise_label': 'Franchise',
    'is_read_only': false,
  },
  'scope': {
    'type': 'organization',
    'id': 'org-1',
    'label': 'Brightside Home Services',
    'path': ['Brightside Home Services'],
  },
};
