import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/deep_links.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/work_hours_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Work Hours › Daily approval (UI queue 136, options 1 and 4). Every name
/// and time invented.
void main() {
  tearDown(() => DeepLinks.pending.value = null);

  testWidgets('opens on the server\'s yesterday and moves a day at a time', (
    tester,
  ) async {
    final stub = _Stub();
    await _pump(tester, stub);

    expect(stub.gets.first.queryParameters, isEmpty, reason: 'yesterday');
    expect(find.text('Tuesday, Oct 21'), findsOneWidget);
    expect(
      find.text('Brightway'),
      findsOneWidget,
      reason: 'the view, no picker',
    );

    await tester.tap(find.byKey(const ValueKey('approval-previous-day')));
    await tester.pumpAndSettle();
    expect(stub.gets.last.queryParameters, {'date': '2026-10-20'});

    await tester.tap(find.byKey(const ValueKey('approval-next-day')));
    await tester.pumpAndSettle();
    expect(stub.gets.last.queryParameters, {'date': '2026-10-21'});

    // The calendar opens in the page, not as a dialog.
    await tester.tap(find.byKey(const ValueKey('approval-calendar')));
    await tester.pumpAndSettle();
    expect(find.byType(Dialog), findsNothing);
    expect(
      find.byKey(const ValueKey('approval-calendar-picker')),
      findsOneWidget,
    );
    await tester.tap(find.text('14'));
    await tester.pumpAndSettle();
    expect(stub.gets.last.queryParameters, {'date': '2026-10-14'});
  });

  testWidgets('the summary, Approve and Approve all send the day', (
    tester,
  ) async {
    final stub = _Stub();
    await _pump(tester, stub);

    String count(String key) =>
        tester.widget<Text>(find.byKey(ValueKey('approval-count-$key'))).data!;
    expect(count('waiting'), '2');
    expect(count('approved'), '1');
    expect(count('no_shows'), '1');
    expect(find.text('7h 38m of 8h'), findsOneWidget);
    expect(find.text('Early 25m'), findsOneWidget);
    expect(find.text('Late 22m'), findsOneWidget);
    expect(find.textContaining('Approved by Dana Reyes'), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('approval-approve-u-1')));
    await tester.pumpAndSettle();
    expect(stub.posts.single.$1, '/api/v1/hr/time-clock/approvals');
    expect(stub.posts.single.$2, {'user_id': 'u-1', 'date': '2026-10-21'});

    await tester.tap(find.text('Approve all 2 waiting'));
    await tester.pumpAndSettle();
    expect(stub.posts.last.$1, '/api/v1/hr/time-clock/approvals/all');
    expect(stub.posts.last.$2, {'date': '2026-10-21'});
  });

  testWidgets('Adjust needs a reason, posts the changed time and lists it', (
    tester,
  ) async {
    final stub = _Stub();
    await _pump(tester, stub);

    await tester.tap(find.byKey(const ValueKey('approval-adjust-u-1')));
    await tester.pumpAndSettle();
    expect(find.byType(AlertDialog), findsNothing);
    expect(find.text('Was 16:05 (Auto)'), findsOneWidget);

    // One date-and-time field (Jose, 2026-11-03), in the org's YYYY-MM-DD.
    await tester.enterText(
      find.descendant(
        of: find.byKey(const ValueKey('approval-adjust-out-s-2')),
        matching: find.byKey(const Key('calendar-day-text')),
      ),
      '2026-10-21 17:00',
    );
    await tester.pumpAndSettle();

    FilledButton save() => tester.widget<FilledButton>(
      find.byKey(const ValueKey('approval-adjust-save')),
    );
    expect(save().onPressed, isNull, reason: 'no reason yet');

    await tester.enterText(
      find.byKey(const ValueKey('approval-adjust-reason')),
      'Forgot to clock out after the last job',
    );
    await tester.pumpAndSettle();
    expect(save().onPressed, isNotNull);

    await tester.tap(find.byKey(const ValueKey('approval-adjust-save')));
    await tester.pumpAndSettle();
    expect(
      stub.posts.single.$1,
      '/api/v1/hr/time-clock/approvals/shifts/s-2/adjust',
    );
    expect(stub.posts.single.$2, {
      'clocked_out_at': '2026-10-21 17:00',
      'reason': 'Forgot to clock out after the last job',
    });
    expect(
      find.textContaining('Out 16:05 → 17:00 · Dana Reyes'),
      findsOneWidget,
      reason: 'the panel lists the change with who',
    );
  });

  testWidgets('an adjusted time shows its original struck through', (
    tester,
  ) async {
    await _pump(tester, _Stub());

    final shifts = tester.widget<Text>(
      find.byKey(const ValueKey('approval-shifts-u-2')),
    );
    final spans = <TextSpan>[];
    shifts.textSpan!.visitChildren((s) {
      if (s is TextSpan && s.text != null) spans.add(s);
      return true;
    });
    final struck = spans
        .where((s) => s.style?.decoration == TextDecoration.lineThrough)
        .map((s) => s.text)
        .toList();
    expect(struck, ['16:05']);
    expect(shifts.textSpan!.toPlainText(), '8:22–16:05 17:00');
    expect(find.text('Adjusted'), findsOneWidget);

    // The control: an unadjusted row strikes nothing.
    final plain = tester.widget<Text>(
      find.byKey(const ValueKey('approval-shifts-u-1')),
    );
    expect(plain.textSpan!.toPlainText(), '7:58–12:02 · 12:31–16:05');
    var anyStruck = false;
    plain.textSpan!.visitChildren((s) {
      if (s is TextSpan && s.style?.decoration == TextDecoration.lineThrough) {
        anyStruck = true;
      }
      return true;
    });
    expect(anyStruck, isFalse);
  });

  testWidgets('a no-show reads "Didn\'t clock in" with nothing to adjust', (
    tester,
  ) async {
    await _pump(tester, _Stub());

    final row = find.byKey(const ValueKey('approval-row-u-4'));
    expect(
      find.descendant(of: row, matching: find.text("Didn't clock in")),
      findsOneWidget,
    );
    expect(find.text('0h of 8h'), findsOneWidget);
    expect(find.byKey(const ValueKey('approval-adjust-u-4')), findsNothing);
    // The control: a clocked row has Adjust.
    expect(find.byKey(const ValueKey('approval-adjust-u-1')), findsOneWidget);
  });

  testWidgets('a no-show is No-show, excusable, and takes added hours', (
    tester,
  ) async {
    final stub = _Stub();
    await _pump(tester, stub);

    final row = find.byKey(const ValueKey('approval-row-u-4'));
    expect(
      find.descendant(of: row, matching: find.text('No-show')),
      findsOneWidget,
    );
    expect(
      find.descendant(of: row, matching: find.text('Waiting')),
      findsNothing,
    );
    expect(find.byKey(const ValueKey('approval-approve-u-4')), findsNothing);

    await tester.tap(find.byKey(const ValueKey('approval-excuse-u-4')));
    await tester.pumpAndSettle();
    expect(stub.posts.last.$1, '/api/v1/hr/time-clock/approvals/excuse');
    expect(stub.posts.last.$2, {'user_id': 'u-4', 'date': '2026-10-21'});

    await tester.tap(find.byKey(const ValueKey('approval-add-hours-u-4')));
    await tester.pumpAndSettle();
    await tester.enterText(
      find.byKey(const ValueKey('approval-adjust-reason')),
      'Forgot to clock in.',
    );
    await tester.pump();
    await tester.tap(find.byKey(const ValueKey('approval-adjust-save')));
    await tester.pumpAndSettle();
    expect(stub.posts.last.$1, '/api/v1/hr/time-clock/approvals/hours');
    expect(stub.posts.last.$2, {
      'clocked_in_at': '2026-10-21 08:00',
      'clocked_out_at': '2026-10-21 16:00',
      'user_id': 'u-4',
      'date': '2026-10-21',
      'reason': 'Forgot to clock in.',
    });
  });

  testWidgets('without work_hours.approve the tab only reads', (tester) async {
    final stub = _Stub();
    await _pump(tester, stub, approve: false);

    expect(find.text('Pat Lin'), findsOneWidget);
    expect(find.text('Adjust'), findsNothing);
    expect(find.text('Approve'), findsNothing);
    expect(find.textContaining('Approve all'), findsNothing);
    expect(stub.posts, isEmpty);
  });

  testWidgets('a tapped reminder opens Daily approval on its day', (
    tester,
  ) async {
    DeepLinks.open(
      const DeepLink(
        module: 'work_hours',
        type: 'time_clock_day_approval',
        id: 'a-1',
        date: '2026-10-15',
      ),
    );
    final stub = _Stub();
    await _pump(tester, stub, reports: true);

    expect(stub.gets.single.queryParameters, {'date': '2026-10-15'});
    expect(find.text('Thursday, Oct 15'), findsOneWidget);
    expect(DeepLinks.pending.value, isNull, reason: 'taken once');
  });

  testWidgets('a reminder tapped while on another tab switches to its day', (
    tester,
  ) async {
    final stub = _Stub();
    await _pump(tester, stub, reports: true, approvalFirst: false);
    expect(find.text('Pat Lin'), findsNothing, reason: 'on Reports');
    expect(stub.gets, isEmpty);

    DeepLinks.open(
      const DeepLink(
        module: 'work_hours',
        type: 'time_clock_day_approval',
        id: 'a-1',
        date: '2026-10-19',
      ),
    );
    await tester.pumpAndSettle();

    expect(stub.gets.single.queryParameters, {'date': '2026-10-19'});
    expect(find.text('Pat Lin'), findsOneWidget);
  });

  testWidgets(
    'a phone at 390x844: cards with Adjust, Approve and Approve all',
    (tester) async {
      final stub = _Stub();
      await _pump(tester, stub, size: const Size(390, 844));

      expect(tester.takeException(), isNull);
      expect(find.text('2 waiting'), findsOneWidget);
      expect(find.text('Approve all 2'), findsOneWidget);
      expect(find.text('PERSON'), findsNothing, reason: 'no table header');
      expect(find.byKey(const ValueKey('approval-adjust-u-1')), findsOneWidget);
      // The day bar spans the card, not a 2 px stub; and Approve all is not
      // a filled button under the thumb (phone audit 2026-11-04).
      expect(
        tester.getSize(find.byKey(const ValueKey('approval-bar-u-1'))).width,
        greaterThan(200),
      );
      expect(
        find.ancestor(
          of: find.text('Approve all 2'),
          matching: find.byType(FilledButton),
        ),
        findsNothing,
      );

      await tester.tap(find.text('Approve all 2'));
      await tester.pumpAndSettle();
      expect(stub.posts.single.$2, {'date': '2026-10-21'});
      expect(tester.takeException(), isNull);
    },
  );
}

class _Stub {
  final gets = <Uri>[];
  final posts = <(String, Map<String, dynamic>)>[];

  Future<http.Response> handle(http.Request request) async {
    final path = request.url.path;
    if (path.endsWith('/me')) return _json({'data': _me});

    if (path.endsWith('/hr/time-clock/approvals') && request.method == 'GET') {
      gets.add(request.url);
      return _json({'data': _day(request.url.queryParameters['date'])});
    }

    if (request.method == 'POST' && path.contains('/hr/time-clock/approvals')) {
      final body = jsonDecode(request.body) as Map<String, dynamic>;
      posts.add((path, body));
      return _json({
        'data': _day('2026-10-21', adjusted: path.endsWith('/adjust')),
      });
    }

    return _json({'data': <Object>[]});
  }
}

const _labels = {
  '2026-10-14': 'Wednesday, Oct 14',
  '2026-10-15': 'Thursday, Oct 15',
  '2026-10-19': 'Monday, Oct 19',
  '2026-10-20': 'Monday, Oct 20',
  '2026-10-21': 'Tuesday, Oct 21',
};

Map<String, dynamic> _shift(
  String id,
  String date,
  String inAt,
  String? outAt, {
  String endedBy = 'person',
}) => {
  'id': id,
  'clocked_in': inAt,
  'clocked_out': outAt,
  'clocked_in_local': '$date ${inAt.padLeft(5, '0')}',
  'clocked_out_local': outAt == null ? null : '$date ${outAt.padLeft(5, '0')}',
  'ended_by': endedBy,
  'edited': null,
};

Map<String, dynamic> _day(String? date, {bool adjusted = false}) {
  final d = date ?? '2026-10-21';
  return {
    'date': d,
    'label': _labels[d] ?? d,
    'counts': {'waiting': 2, 'approved': 1, 'no_shows': 1},
    'rows': [
      {
        'person': {'id': 'u-1', 'name': 'Pat Lin', 'role': 'Technician'},
        'shifts': [
          _shift('s-1', d, '7:58', '12:02'),
          _shift(
            's-2',
            d,
            '12:31',
            adjusted ? '17:00' : '16:05',
            endedBy: adjusted ? 'correction' : 'schedule',
          ),
        ],
        'worked_seconds': 7 * 3600 + 38 * 60,
        'scheduled': {'start': '08:00', 'end': '16:00'},
        'late_seconds': -120,
        'early_seconds': 25 * 60,
        'no_show': false,
        'status': 'waiting',
        'approved_by': null,
        'approved_at': null,
        'adjustments': [
          if (adjusted)
            {
              'shift_id': 's-2',
              'was_in': '12:31',
              'was_out': '16:05',
              'now_in': '12:31',
              'now_out': '17:00',
              'reason': 'Forgot to clock out after the last job',
              'by': 'Dana Reyes',
              'at': '2026-10-22T12:10:00+00:00',
            },
        ],
      },
      {
        'person': {'id': 'u-2', 'name': 'Sam Ortiz', 'role': 'Technician'},
        'shifts': [_shift('s-3', d, '8:22', '17:00', endedBy: 'correction')],
        'worked_seconds': 8 * 3600 + 38 * 60,
        'scheduled': {'start': '08:00', 'end': '16:00'},
        'late_seconds': 22 * 60,
        'early_seconds': -3600,
        'no_show': false,
        'status': 'waiting',
        'approved_by': null,
        'approved_at': null,
        'adjustments': [
          {
            'shift_id': 's-3',
            'was_in': '8:22',
            'was_out': '16:05',
            'now_in': '8:22',
            'now_out': '17:00',
            'reason': 'Stayed for a callback',
            'by': 'Dana Reyes',
            'at': '2026-10-22T12:00:00+00:00',
          },
        ],
      },
      {
        'person': {'id': 'u-3', 'name': 'Morgan Kay', 'role': 'Helper'},
        'shifts': [_shift('s-4', d, '8:00', '16:00')],
        'worked_seconds': 8 * 3600,
        'scheduled': {'start': '08:00', 'end': '16:00'},
        'late_seconds': 0,
        'early_seconds': 0,
        'no_show': false,
        'status': 'approved',
        'approved_by': {'id': 'u-9', 'name': 'Dana Reyes'},
        'approved_at': '2026-10-22T12:14:00+00:00',
        'adjustments': <Object>[],
      },
      {
        'person': {'id': 'u-4', 'name': 'Rae Moss', 'role': 'Technician'},
        'shifts': <Object>[],
        'worked_seconds': 0,
        'scheduled': {'start': '08:00', 'end': '16:00'},
        'late_seconds': null,
        'early_seconds': null,
        'no_show': true,
        'status': 'no_show',
        'scheduled_local': {
          'start': '2026-10-21 08:00',
          'end': '2026-10-21 16:00',
        },
        'approved_by': null,
        'approved_at': null,
        'adjustments': <Object>[],
      },
    ],
  };
}

Future<void> _pump(
  WidgetTester tester,
  _Stub stub, {
  bool approve = true,
  bool reports = false,
  bool approvalFirst = true,
  Size size = const Size(1280, 900),
}) async {
  tester.view.physicalSize = size;
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(
    client: MockClient(
      (request) => stub.handle(request).then((r) {
        if (request.url.path.endsWith('/me')) {
          return _json({
            'data': {
              ..._me,
              'permissions': [
                'work_hours.view',
                'work_hours.approval.view',
                if (approve) 'work_hours.approve',
                if (reports) 'work_hours.reports.view',
              ],
            },
          });
        }
        return r;
      }),
    ),
  );
  final session = Session(api);
  // Keep the link for the screen: adopting must not consume it.
  await tester.runAsync(() => session.adopt('tok'));
  expect(session.signedIn, isTrue);

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(
        body: WorkHoursScreen(key: UniqueKey(), api: api, session: session),
      ),
    ),
  );
  await tester.pumpAndSettle();

  // Reports is before Daily approval; open ours unless the test wants to
  // stand on Reports.
  if (reports && approvalFirst && DeepLinks.pending.value == null) {
    if (find.text('Pat Lin').evaluate().isEmpty) {
      await tester.tap(find.text('Daily approval'));
      await tester.pumpAndSettle();
    }
  }
}

http.Response _json(Object body, [int status = 200]) => http.Response(
  jsonEncode(body),
  status,
  headers: const {'content-type': 'application/json'},
);

const _me = {
  'id': 'u-9',
  'name': 'Dana Reyes',
  'email': 'dana@example.com',
  'role': {'name': 'Manager'},
  'has_avatar': false,
  'permissions': <String>[],
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
  'franchises': <Object>[],
};
