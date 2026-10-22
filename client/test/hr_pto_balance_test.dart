import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/core/timestamps.dart';
import 'package:ezbizi_client/screens/hr/pto_tab.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Paid time off with a balance — design 126, Jose's options 1 and 4
/// (2026-10-21, docs/previews/paid-time-off-layouts.html).
///
/// The balance fields are HR's to send and were proposed before they existed,
/// so the last test is the one that matters most on the day this lands: with
/// none of them, the screen draws what it drew before.
void main() {
  testWidgets('rows: days left, 0 in the error colour, and chips in place of '
      'a number for people who cannot use theirs yet', (tester) async {
    await _pump(tester, _body(), width: 1200);

    expect(find.text('7 left'), findsOneWidget);
    expect(find.text('12 left'), findsOneWidget);

    final zero = tester.widget<Text>(find.text('0 left'));
    final context = tester.element(find.text('0 left'));
    expect(zero.style?.color, Theme.of(context).colorScheme.error);

    expect(find.text('From ${_fmt(_inDays(133))}'), findsWidgets);
    expect(
      find.text('Start date unknown'),
      findsOneWidget,
      reason: 'Rae has no start date and is not eligible.',
    );

    // The chip replaces the number: Morgan has a balance on the server, and
    // "10 left" beside "From …" would read as ten days she may book.
    expect(find.text('10 left'), findsNothing);
  });

  testWidgets('search and Everyone / Can use / Not yet narrow the list', (
    tester,
  ) async {
    await _pump(tester, _body(), width: 1200);

    await tester.tap(find.text('Not yet'));
    await tester.pumpAndSettle();
    expect(find.text('7 left'), findsNothing);
    expect(find.text('Start date unknown'), findsOneWidget);

    await tester.tap(find.text('Can use'));
    await tester.pumpAndSettle();
    expect(find.text('7 left'), findsOneWidget);
    expect(find.text('Start date unknown'), findsNothing);

    await tester.tap(find.text('Everyone'));
    await tester.enterText(find.byKey(const Key('pto-search')), 'sam');
    await tester.pumpAndSettle();
    expect(find.text('0 left'), findsOneWidget);
    expect(find.text('7 left'), findsNothing);
  });

  testWidgets('the panel: three tiles, carried in the Total, and the bar', (
    tester,
  ) async {
    await _pump(tester, _body(), width: 1200);
    await tester.tap(find.text('Dana Reyes'));
    await tester.pumpAndSettle();

    final total = find.byKey(const Key('pto-tile-total'));
    expect(
      find.descendant(of: total, matching: find.text('12')),
      findsOneWidget,
    );
    expect(
      find.descendant(
        of: total,
        matching: find.text('10 this year + 2 carried'),
      ),
      findsOneWidget,
    );
    expect(
      find.descendant(
        of: find.byKey(const Key('pto-tile-used')),
        matching: find.text('5'),
      ),
      findsOneWidget,
    );
    expect(
      find.descendant(
        of: find.byKey(const Key('pto-tile-left')),
        matching: find.text('7'),
      ),
      findsOneWidget,
    );

    // Three EQUAL tiles.
    final widths = [
      for (final k in ['total', 'used', 'left'])
        tester.getSize(find.byKey(Key('pto-tile-$k'))).width,
    ];
    expect(widths.toSet(), hasLength(1));

    final bar = tester.widget<LinearProgressIndicator>(
      find.byKey(const Key('pto-used-bar')),
    );
    expect(bar.value, closeTo(5 / 12, 1e-9));

    expect(find.text('Can use now'), findsOneWidget);
    expect(find.text('Started ${_fmt(_inDays(-900))}'), findsOneWidget);

    expect(find.text('Changes to Used'), findsOneWidget);
    expect(
      find.text(
        '${_fmt(_inDays(-19))} · Pat Lin · 3 → 5 · “Two sick days, Sep 29–30”',
      ),
      findsOneWidget,
    );

    // Drawn in the server's order, which is newest first.
    final newer = tester.getTopLeft(find.textContaining('3 → 5')).dy;
    final older = tester.getTopLeft(find.textContaining('0 → 3')).dy;
    expect(newer, lessThan(older));
  });

  testWidgets('a person with no carried days has no carried line', (
    tester,
  ) async {
    await _pump(tester, _body(), width: 1200);

    // Pat's own panel is open: total 12, nothing carried.
    expect(find.textContaining('carried'), findsNothing);
    expect(find.text('Changes to Used'), findsNothing);
  });

  testWidgets('the reset line: the date, the anniversary, and how far away', (
    tester,
  ) async {
    await _pump(tester, _body(), width: 1200);
    await tester.tap(find.text('Dana Reyes'));
    await tester.pumpAndSettle();

    expect(
      find.text(
        'Resets ${_fmt(_inDays(173))}, their work anniversary · 173 days away',
      ),
      findsOneWidget,
    );
    expect(
      find.text(
        'Earns 10 days a year · usable from 90 days after starting · '
        'unused days carry over',
      ),
      findsOneWidget,
    );
  });

  testWidgets('Jan 1 and the start of each period are said as themselves', (
    tester,
  ) async {
    await _pump(tester, _body(policy: {'reset': 'jan_1'}), width: 1200);
    await tester.tap(find.text('Dana Reyes'));
    await tester.pumpAndSettle();

    expect(
      find.text(
        'Resets ${_fmt(_inDays(173))}, every January 1 · 173 days away',
      ),
      findsOneWidget,
    );
    expect(find.textContaining('work anniversary'), findsNothing);

    await _pump(
      tester,
      _body(policy: {'reset': 'period', 'allowance_period': 'quarter'}),
      width: 1200,
    );
    await tester.tap(find.text('Dana Reyes'));
    await tester.pumpAndSettle();
    expect(
      find.text(
        'Resets ${_fmt(_inDays(173))}, at the start of each quarter · '
        '173 days away',
      ),
      findsOneWidget,
    );
  });

  testWidgets('with no reset in the policy it reads as HR’s default, Jan 1', (
    tester,
  ) async {
    await _pump(
      tester,
      _body()..['policy'] = (_policy({})..remove('reset')),
      width: 1200,
    );
    await tester.tap(find.text('Dana Reyes'));
    await tester.pumpAndSettle();

    expect(
      find.text(
        'Resets ${_fmt(_inDays(173))}, every January 1 · 173 days away',
      ),
      findsOneWidget,
    );
  });

  testWidgets('carried null draws no carried line', (tester) async {
    await _pump(tester, _body(), width: 1200);
    await tester.tap(find.text('Morgan Kay'));
    await tester.pumpAndSettle();

    expect(
      find.descendant(
        of: find.byKey(const Key('pto-tile-total')),
        matching: find.text('10'),
      ),
      findsOneWidget,
    );
    expect(find.textContaining('carried'), findsNothing);
  });

  testWidgets('a quarterly policy says a quarter', (tester) async {
    await _pump(
      tester,
      _body(policy: {'allowance_period': 'quarter', 'carry_over': false}),
      width: 1200,
    );

    expect(
      find.text('Earns 10 days a quarter · usable from 90 days after starting'),
      findsOneWidget,
    );
  });

  testWidgets('Change is only on the Used tile when can_edit_used is true', (
    tester,
  ) async {
    await _pump(tester, _body(), width: 1200);

    // Pat's own panel: the server did not say Pat may edit it.
    expect(find.byKey(const Key('pto-tile-used')), findsOneWidget);
    expect(find.byKey(const Key('pto-change-used')), findsNothing);
    expect(find.text('Change'), findsNothing);

    await tester.tap(find.text('Dana Reyes'));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('pto-change-used')), findsOneWidget);
  });

  testWidgets('Change opens a side panel, needs a reason, and PUTs both', (
    tester,
  ) async {
    final requests = <http.Request>[];
    await _pump(tester, _body(), width: 1200, requests: requests);
    await tester.tap(find.text('Dana Reyes'));
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const Key('pto-change-used')));
    await tester.pumpAndSettle();

    expect(find.text('Change Used'), findsOneWidget);
    expect(find.byType(Dialog), findsNothing);
    expect(find.byType(AlertDialog), findsNothing);

    await tester.enterText(find.byKey(const Key('pto-used-number')), '6');
    await tester.tap(find.byKey(const Key('pto-used-save')));
    await tester.pumpAndSettle();

    expect(find.text('Say why it changed.'), findsOneWidget);
    expect(
      requests.where((r) => r.method == 'PUT'),
      isEmpty,
      reason: 'The reason is required before anything is sent.',
    );

    await tester.enterText(
      find.byKey(const Key('pto-used-reason')),
      'One more sick day',
    );
    await tester.tap(find.byKey(const Key('pto-used-save')));
    await tester.pumpAndSettle();

    final put = requests.singleWhere((r) => r.method == 'PUT');
    expect(put.url.path, endsWith('/hr/people/u-2/pto/used'));
    expect(jsonDecode(put.body), {'used': 6, 'reason': 'One more sick day'});

    // Closed, and the tiles take the server's answer without reading the
    // list again.
    expect(find.text('Change Used'), findsNothing);
    expect(
      requests.where(
        (r) => r.method == 'GET' && r.url.path.endsWith('/hr/pto'),
      ),
      hasLength(1),
    );
    expect(
      find.descendant(
        of: find.byKey(const Key('pto-tile-used')),
        matching: find.text('6'),
      ),
      findsOneWidget,
    );
    expect(
      find.descendant(
        of: find.byKey(const Key('pto-tile-left')),
        matching: find.text('6'),
      ),
      findsOneWidget,
    );
    expect(find.text('6 left'), findsOneWidget, reason: 'The row too.');
    expect(
      find.text(
        'Resets ${_fmt(_inDays(170))}, their work anniversary · 170 days away',
      ),
      findsOneWidget,
    );
  });

  testWidgets('a refused change shows the server’s own sentence', (
    tester,
  ) async {
    await _pump(
      tester,
      _body(),
      width: 1200,
      put: () => _json({
        'message': 'The given data was invalid.',
        'errors': {
          'used': ['Used cannot be more than the total.'],
        },
      }, 422),
    );
    await tester.tap(find.text('Dana Reyes'));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('pto-change-used')));
    await tester.pumpAndSettle();

    await tester.enterText(find.byKey(const Key('pto-used-number')), '40');
    await tester.enterText(find.byKey(const Key('pto-used-reason')), 'Typo');
    await tester.tap(find.byKey(const Key('pto-used-save')));
    await tester.pumpAndSettle();

    expect(find.text('Used cannot be more than the total.'), findsOneWidget);
    expect(find.text('Change Used'), findsOneWidget, reason: 'Still open.');
  });

  testWidgets('a 403 shows the server’s sentence too', (tester) async {
    await _pump(
      tester,
      _body(),
      width: 1200,
      put: () => _json({
        'message': 'You cannot change paid time off for this person.',
      }, 403),
    );
    await tester.tap(find.text('Dana Reyes'));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('pto-change-used')));
    await tester.pumpAndSettle();

    await tester.enterText(find.byKey(const Key('pto-used-number')), '6');
    await tester.enterText(find.byKey(const Key('pto-used-reason')), 'Fix');
    await tester.tap(find.byKey(const Key('pto-used-save')));
    await tester.pumpAndSettle();

    expect(
      find.text('You cannot change paid time off for this person.'),
      findsOneWidget,
    );
    expect(find.text('Change Used'), findsOneWidget, reason: 'Still open.');
  });

  testWidgets('phone at 390×844: your tiles first, everyone below, a person '
      'full screen in place with a way back', (tester) async {
    await _pump(tester, _body(), width: 390, height: 844);
    expect(tester.takeException(), isNull);

    expect(find.text('You'), findsOneWidget);
    expect(find.byKey(const Key('pto-tile-total')), findsOneWidget);
    expect(
      find.text(
        'Resets ${_fmt(_inDays(80))}, your work anniversary · '
        '80 days away',
      ),
      findsOneWidget,
    );
    expect(
      tester.getTopLeft(find.byKey(const Key('pto-tile-total'))).dy,
      lessThan(tester.getTopLeft(find.text('Everyone you can see')).dy),
    );

    await tester.scrollUntilVisible(find.text('7 left'), 200);
    expect(find.text('7 left'), findsOneWidget);
    await tester.scrollUntilVisible(find.text('Start date unknown'), 200);
    expect(find.text('Start date unknown'), findsOneWidget);
    expect(tester.takeException(), isNull);

    await tester.ensureVisible(find.text('Dana Reyes'));
    await tester.tap(find.text('Dana Reyes'));
    await tester.pumpAndSettle();

    expect(find.byKey(const Key('pto-phone-person')), findsOneWidget);
    expect(find.text('Everyone you can see'), findsNothing);
    expect(find.text('10 this year + 2 carried'), findsOneWidget);
    expect(find.byKey(const Key('pto-change-used')), findsOneWidget);
    expect(tester.takeException(), isNull);
    expect(
      find.byType(Navigator).evaluate().length,
      1,
      reason: 'Drawn in place, not pushed.',
    );

    await tester.tap(find.byIcon(Icons.arrow_back));
    await tester.pumpAndSettle();
    expect(find.text('Everyone you can see'), findsOneWidget);
    expect(find.text('You'), findsOneWidget);
  });

  testWidgets('Expired: a fourth equal tile and "· N expired" on the row when '
      'days were lost, nothing at 0 or null', (tester) async {
    await _pump(tester, _expiredBody(me: 0), width: 1200);

    // Sam lost 3; Pat's is 0 (carry-over on) and Dana's is null.
    expect(find.text('0 left · 3 expired'), findsOneWidget);
    expect(find.text('12 left'), findsOneWidget);
    expect(find.text('7 left'), findsOneWidget);
    expect(find.textContaining('expired'), findsOneWidget);

    // Pat's own panel: 0 is no loss.
    expect(find.byKey(const Key('pto-tile-expired')), findsNothing);

    await tester.tap(find.text('Dana Reyes'));
    await tester.pumpAndSettle();
    expect(
      find.byKey(const Key('pto-tile-expired')),
      findsNothing,
      reason: 'null is no start date, not a loss.',
    );

    await tester.tap(find.text('Sam Ortiz'));
    await tester.pumpAndSettle();
    final tile = find.byKey(const Key('pto-tile-expired'));
    expect(
      find.descendant(of: tile, matching: find.text('Expired')),
      findsOneWidget,
    );
    expect(find.descendant(of: tile, matching: find.text('3')), findsOneWidget);
    expect(
      find.descendant(of: tile, matching: find.text('lost at past resets')),
      findsOneWidget,
    );

    // Same row, same size as the other three.
    final widths = {
      for (final k in ['total', 'used', 'left', 'expired'])
        tester.getSize(find.byKey(Key('pto-tile-$k'))).width,
    };
    expect(widths, hasLength(1));
    final tops = {
      for (final k in ['total', 'used', 'left', 'expired'])
        tester.getTopLeft(find.byKey(Key('pto-tile-$k'))).dy,
    };
    expect(tops, hasLength(1));
  });

  testWidgets('Expired on the phone: four tiles fit at 390×844 and the row '
      'says it', (tester) async {
    await _pump(tester, _expiredBody(me: 2), width: 390, height: 844);
    expect(tester.takeException(), isNull);

    expect(find.byKey(const Key('pto-tile-expired')), findsOneWidget);
    final widths = {
      for (final k in ['total', 'used', 'left', 'expired'])
        tester.getSize(find.byKey(Key('pto-tile-$k'))).width,
    };
    expect(widths, hasLength(1));

    await tester.scrollUntilVisible(find.text('0 left · 3 expired'), 200);
    expect(find.text('0 left · 3 expired'), findsOneWidget);
    expect(tester.takeException(), isNull);

    await tester.ensureVisible(find.text('Dana Reyes'));
    await tester.tap(find.text('Dana Reyes'));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('pto-tile-expired')), findsNothing);
    expect(tester.takeException(), isNull);
  });

  testWidgets('without the new fields the screen draws what it drew before', (
    tester,
  ) async {
    await _pump(tester, _legacyBody(), width: 1200);

    expect(find.byKey(const Key('pto-tile-total')), findsNothing);
    expect(find.byKey(const Key('pto-resets')), findsNothing);
    expect(find.textContaining('left'), findsNothing);
    expect(find.text('Change'), findsNothing);

    // The allowance headline and the when box, as before.
    expect(
      find.byKey(const ValueKey('tour-work_hours-pto-allowance')),
      findsOneWidget,
    );
    expect(find.text('6 days a year'), findsWidgets);
    expect(find.text('When you can use them'), findsOneWidget);
    expect(find.text('Start date unknown'), findsOneWidget);

    await _pump(tester, _legacyBody(), width: 390, height: 844);
    expect(tester.takeException(), isNull);
    expect(find.text('You'), findsOneWidget);
    expect(find.byKey(const Key('pto-tile-total')), findsNothing);
    expect(find.text('Everyone you can see'), findsOneWidget);
  });
}

DateTime _inDays(int days) {
  // Calendar days, not 24-hour steps: Duration(days: 80) from today crosses
  // the November clock change and lands an hour short of the date meant.
  final now = DateTime.now();
  return DateTime(now.year, now.month, now.day + days);
}

String _iso(DateTime at) =>
    '${at.year}-${at.month.toString().padLeft(2, '0')}-'
    '${at.day.toString().padLeft(2, '0')}';

/// The screen's own formatter: the reader set no preference, so the fallback.
String _fmt(DateTime at) => DisplayFormats.fallback.formatDate(at);

Future<void> _pump(
  WidgetTester tester,
  Map<String, dynamic> body, {
  required double width,
  double height = 1600,
  List<http.Request>? requests,
  http.Response Function()? put,
}) async {
  tester.view.physicalSize = Size(width, height);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(
    client: MockClient((request) async {
      requests?.add(request);
      if (request.url.path.endsWith('/me')) {
        return _json({'data': _me});
      }
      if (request.method == 'PUT' && request.url.path.endsWith('/pto/used')) {
        return put?.call() ??
            _json({
              'data': {
                'total': 12,
                'used': 6,
                'left': 6,
                'carried': 2,
                'resets_on': _iso(_inDays(170)),
              },
            });
      }
      if (request.url.path.endsWith('/hr/pto')) {
        return _json(body);
      }
      return _json({'message': 'Unexpected ${request.url.path}'}, 500);
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
}

Map<String, dynamic> _policy(Map<String, dynamic> overrides) => {
  'enabled': true,
  'allowance': 10,
  'allowance_period': 'year',
  'waiting': 90,
  'waiting_unit': 'day',
  'carry_over': true,
  'reset': 'anniversary',
  ...overrides,
};

Map<String, dynamic> _body({Map<String, dynamic> policy = const {}}) => {
  'policy': _policy(policy),
  'me': 'u-1',
  'data': [
    {
      'id': 'u-2',
      'name': 'Dana Reyes',
      'allowance': 10,
      'started_on': _iso(_inDays(-900)),
      'eligible_from': _iso(_inDays(-810)),
      'eligible': true,
      'total': 12,
      'used': 5,
      'left': 7,
      'carried': 2,
      'resets_on': _iso(_inDays(173)),
      'can_edit_used': true,
      'used_changes': [
        {
          'at': _iso(_inDays(-19)),
          'by_name': 'Pat Lin',
          'from': 3,
          'to': 5,
          'reason': 'Two sick days, Sep 29–30',
        },
        {
          'at': _iso(_inDays(-60)),
          'by_name': 'Pat Lin',
          'from': 0,
          'to': 3,
          'reason': 'Moved over from the old spreadsheet',
        },
      ],
    },
    {
      'id': 'u-1',
      'name': 'Pat Lin',
      'allowance': 10,
      'started_on': _iso(_inDays(-1015)),
      'eligible_from': _iso(_inDays(-925)),
      'eligible': true,
      'total': 12,
      'used': 0,
      'left': 12,
      'carried': 0,
      'resets_on': _iso(_inDays(80)),
      'can_edit_used': false,
      'used_changes': <Object>[],
    },
    {
      'id': 'u-3',
      'name': 'Sam Ortiz',
      'allowance': 10,
      'started_on': _iso(_inDays(-500)),
      'eligible_from': _iso(_inDays(-410)),
      'eligible': true,
      'total': 10,
      'used': 10,
      'left': 0,
      'resets_on': _iso(_inDays(40)),
    },
    {
      'id': 'u-4',
      'name': 'Morgan Kay',
      'allowance': 10,
      'started_on': _iso(_inDays(43)),
      'eligible_from': _iso(_inDays(133)),
      'eligible': false,
      'total': 10,
      'used': 0,
      'left': 10,
      // HR sends null when there is no start date to count a year from.
      'carried': null,
    },
    {
      'id': 'u-5',
      'name': 'Rae Moss',
      'allowance': 10,
      'started_on': null,
      'eligible_from': null,
      'eligible': false,
    },
  ],
};

/// [_body] with HR's `expired` (80655a5da): Sam lost 3 days, Pat [me],
/// Dana null.
Map<String, dynamic> _expiredBody({required num me}) {
  final body = _body(policy: {'carry_over': false});
  // Copied, because the literals in [_body] are typed to hold no null.
  final rows = [
    for (final row in body['data'] as List)
      Map<String, dynamic>.from(row as Map),
  ];
  body['data'] = rows;
  for (final person in rows) {
    switch (person['name']) {
      case 'Sam Ortiz':
        person['expired'] = 3;
      case 'Pat Lin':
        person['expired'] = me;
      case 'Dana Reyes':
        person['expired'] = null;
    }
  }
  return body;
}

/// Exactly the fields `HrPeopleController::pto` sends today.
Map<String, dynamic> _legacyBody() => {
  'policy': {
    'enabled': true,
    'allowance': 6,
    'allowance_period': 'year',
    'waiting': 90,
    'waiting_unit': 'day',
    'carry_over': false,
  },
  'me': 'u-1',
  'data': [
    {
      'id': 'u-1',
      'name': 'Pat Lin',
      'role': 'Office',
      'allowance': 6,
      'started_on': _iso(_inDays(-400)),
      'eligible_from': _iso(_inDays(-310)),
      'eligible': true,
      'taken': null,
    },
    {
      'id': 'u-5',
      'name': 'Rae Moss',
      'role': 'Technician',
      'allowance': 6,
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
  'name': 'Pat Lin',
  'email': 'pat@example.test',
  'has_avatar': false,
  'role': {'id': 'role-1', 'name': 'Office'},
  'permissions': ['hr.view'],
  'modules': [
    {
      'key': 'hr',
      'label': 'Human Resources',
      'available': true,
      'reachable': true,
    },
  ],
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
  'companies': <Map<String, Object?>>[],
  'franchises': <Map<String, Object?>>[],
};
