import 'dart:async';
import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/dashboard_panels.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/dashboard_screen.dart';
import 'package:ezbizi_client/widgets/dashboard_card.dart';
import 'package:ezbizi_client/widgets/dashboard_grid.dart';
import 'package:flutter/gestures.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// UI queue 50, Jose 2026-10-05 ("go ahead with ur recommendations": desktop
/// 1, phone 4): boxes in Small, Medium and Large on four columns, in sections
/// cut where the person's sidebar is cut, arranged in Customize.
void main() {
  group('sections follow the sidebar', () {
    test('nobody who never added a divider has more than one section', () {
      expect(dashboardSections(['jobs', 'hr', 'customers'], const []), [
        ['jobs', 'hr', 'customers'],
      ]);
    });

    test("the sidebar's groups in its order, boxes in the dashboard's", () {
      expect(
        dashboardSections(
          ['jobs', 'hr', 'customers', 'phone'],
          ['hr', '--', 'phone', 'customers', 'jobs'],
        ),
        [
          ['hr'],
          ['jobs', 'customers', 'phone'],
        ],
      );
    });

    test('an unnamed module joins the last group, or its own after a closing '
        'divider, as in the sidebar', () {
      expect(
        dashboardSections(
          ['jobs', 'hr', 'fleet_management'],
          ['hr', '--', 'jobs'],
        ),
        [
          ['hr'],
          ['jobs', 'fleet_management'],
        ],
      );
      expect(
        dashboardSections(
          ['jobs', 'hr', 'fleet_management'],
          ['hr', '--', 'jobs', '--'],
        ),
        [
          ['hr'],
          ['jobs'],
          ['fleet_management'],
        ],
      );
    });

    test('a leading or doubled divider makes no empty section', () {
      expect(
        dashboardSections(['jobs', 'hr'], ['--', 'hr', '--', '--', 'jobs']),
        [
          ['hr'],
          ['jobs'],
        ],
      );
    });
  });

  group('sizes', () {
    testWidgets('a box takes one, two or four of four columns, and one that '
        'does not fit starts the next row', (tester) async {
      await _pumpScreen(
        tester,
        _server([
          _glance('a', 'Alpha', size: 'small'),
          _glance('b', 'Bravo', size: 'small'),
          _glance('c', 'Charlie', size: 'medium'),
          _glance('d', 'Delta', size: 'large'),
          _glance('e', 'Echo', size: 'small'),
        ]),
      );

      // 1400 wide, 24 either side, three 16 gaps: a column is 326.
      expect(_rect(tester, 'Alpha').width, 326);
      expect(_rect(tester, 'Charlie').width, 668);
      expect(_rect(tester, 'Delta').width, 1352);
      // Echo is alone on the last row, so it stretches over it: no hole.
      expect(_rect(tester, 'Echo').width, 1352);

      // Alpha, Bravo and Charlie fill a row; Delta and Echo each start one.
      expect(_rect(tester, 'Bravo').top, _rect(tester, 'Alpha').top);
      expect(_rect(tester, 'Charlie').top, _rect(tester, 'Alpha').top);
      expect(_rect(tester, 'Charlie').left, _rect(tester, 'Bravo').right + 16);
      expect(
        _rect(tester, 'Delta').top,
        greaterThan(_rect(tester, 'Charlie').bottom),
      );
      expect(
        _rect(tester, 'Echo').top,
        greaterThan(_rect(tester, 'Delta').bottom),
      );
      expect(_rect(tester, 'Echo').left, _rect(tester, 'Alpha').left);
    });

    testWidgets(
      'on a phone Small is half the width and the rest is all of it',
      (tester) async {
        await _pumpScreen(
          tester,
          _server([
            _glance('a', 'Alpha', size: 'small'),
            _glance('b', 'Bravo', size: 'small'),
            _glance('c', 'Charlie', size: 'medium'),
            _glance('d', 'Delta', size: 'large'),
          ]),
          size: const Size(375, 2000),
        );

        // 375 less a 16 gutter either side is 343; two columns of 163.5.
        expect(_rect(tester, 'Alpha').width, 163.5);
        expect(_rect(tester, 'Bravo').top, _rect(tester, 'Alpha').top);
        expect(_rect(tester, 'Charlie').width, 343);
        expect(_rect(tester, 'Delta').width, 343);
        expect(tester.takeException(), isNull);
      },
    );

    testWidgets('Customize offers a size only when the server keeps one', (
      tester,
    ) async {
      await _pumpScreen(tester, _server([_glance('jobs', 'Jobs')]));
      await _customize(tester);

      // Control: Customize is open, its handle is there.
      expect(
        find.byTooltip('Drag “Jobs” onto the card whose place it should take'),
        findsOneWidget,
      );
      expect(find.byTooltip('Make “Jobs” large'), findsNothing);

      await _pumpScreen(
        tester,
        _server([_glance('jobs', 'Jobs', size: 'medium')]),
      );
      await _customize(tester);

      expect(find.byTooltip('Make “Jobs” large'), findsOneWidget);
    });

    testWidgets('a size changes on the spot, is saved with every box, and goes '
        'back if the save fails', (tester) async {
      final sent = <Map<String, dynamic>>[];
      final answered = Completer<void>();

      await _pumpScreen(
        tester,
        _server(
          [
            _glance('jobs', 'Jobs', size: 'medium'),
            _glance('hr', 'Human Resources', size: 'small'),
          ],
          record: sent,
          saveStatus: 500,
          held: answered,
        ),
      );
      await _customize(tester);

      expect(_rect(tester, 'Jobs').width, 668);

      await tester.tap(find.byTooltip('Make “Jobs” large'));
      await tester.pump();

      expect(
        _rect(tester, 'Jobs').width,
        1352,
        reason: 'The box waited for the network.',
      );
      expect(
        [for (final g in sent.single['glances'] as List) (g['key'], g['size'])],
        [('jobs', 'large'), ('hr', 'small')],
      );

      answered.complete();
      await tester.pumpAndSettle();

      expect(_rect(tester, 'Jobs').width, 668);
      expect(find.text('Nothing doing.'), findsOneWidget);
    });

    testWidgets('Done puts the period back and takes the handles away', (
      tester,
    ) async {
      await _pumpScreen(
        tester,
        _server([_glance('jobs', 'Jobs', size: 'medium')]),
      );

      expect(find.text('Week'), findsOneWidget);
      expect(find.byTooltip('Move “Jobs” later'), findsNothing);

      await _customize(tester);

      expect(find.text('Week'), findsNothing);
      expect(
        find.text('Drag a box to move it, or pick its size.'),
        findsOneWidget,
      );
      expect(find.byTooltip('Move “Jobs” later'), findsOneWidget);

      await tester.tap(find.text('Done'));
      await tester.pumpAndSettle();

      expect(find.text('Week'), findsOneWidget);
      expect(find.byTooltip('Move “Jobs” later'), findsNothing);
    });
  });

  testWidgets('a phone picks the period from one chip, beside Customize', (
    tester,
  ) async {
    final sent = <Map<String, dynamic>>[];

    await _pumpScreen(
      tester,
      _server([_glance('jobs', 'Jobs', size: 'medium')], record: sent),
      size: const Size(390, 900),
    );

    // One chip saying the period, and the five only once it is opened.
    expect(find.text('Day'), findsOneWidget);
    expect(find.text('Week'), findsNothing);
    expect(find.byTooltip('Customize'), findsOneWidget);

    final chip = tester.getRect(find.text('Day'));
    final customize = tester.getRect(find.byTooltip('Customize'));
    expect(
      (chip.center.dy - customize.center.dy).abs(),
      lessThan(8),
      reason: 'The period and Customize are no longer on one line.',
    );

    await tester.tap(find.text('Day'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Week').last);
    await tester.pumpAndSettle();

    expect(sent.first['period'], 'week');
  });

  group('hiding a box', () {
    Map<String, dynamic> shown(String key, String label) => {
      ..._glance(key, label, size: 'medium'),
      'hidden': false,
    };

    testWidgets('a hidden box is off the dashboard, and Add a box brings it '
        'back with its data', (tester) async {
      final sent = <Map<String, dynamic>>[];
      var loads = 0;

      await _pumpScreen(
        tester,
        (request) async {
          if (request.url.path.endsWith('/dashboard') && request.method == 'GET') {
            loads++;
          }
          return _server(
            [
              shown('jobs', 'Jobs'),
              {
                'key': 'hr',
                'label': 'Human Resources',
                'hidden': true,
                'size': 'medium',
                'presentation': 'text',
              },
            ],
            record: sent,
          )(request);
        },
      );

      expect(_titles(tester), ['Jobs']);

      await _customize(tester);
      await tester.tap(find.text('Add a box'));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Human Resources'));
      await tester.pumpAndSettle();

      final entries = {
        for (final g in sent.single['glances'] as List) g['key']: g['hidden'],
      };
      expect(entries, {'jobs': false, 'hr': false});
      expect(loads, 2, reason: 'A box that came back has no data until it is read.');
    });

    testWidgets('hiding a box takes it away on the spot and saves it hidden', (
      tester,
    ) async {
      final sent = <Map<String, dynamic>>[];

      await _pumpScreen(
        tester,
        _server([shown('jobs', 'Jobs'), shown('hr', 'Human Resources')], record: sent),
      );
      await _customize(tester);

      await tester.tap(find.byTooltip('Hide “Jobs”'));
      await tester.pumpAndSettle();

      expect(_titles(tester), ['Human Resources']);
      expect(
        {for (final g in sent.single['glances'] as List) g['key']: g['hidden']},
        {'jobs': true, 'hr': false},
      );
    });

    testWidgets('no hide until the server keeps a box hidden', (tester) async {
      await _pumpScreen(tester, _server([_glance('jobs', 'Jobs', size: 'medium')]));
      await _customize(tester);

      // Control: Customize is open.
      expect(find.byTooltip('Make “Jobs” large'), findsOneWidget);
      expect(find.byTooltip('Hide “Jobs”'), findsNothing);
      expect(find.text('Add a box'), findsNothing);
    });

    testWidgets('every box hidden says how to bring one back', (tester) async {
      await _pumpScreen(
        tester,
        _server([
          {
            'key': 'jobs',
            'label': 'Jobs',
            'hidden': true,
            'size': 'medium',
            'presentation': 'text',
          },
        ]),
      );

      expect(
        find.text('Every box is hidden. Customize, then Add a box, brings one back.'),
        findsOneWidget,
      );
    });
  });

  group('sections on the screen', () {
    testWidgets('the sidebar’s groups are sections, with a rule between', (
      tester,
    ) async {
      await _pumpScreen(
        tester,
        _server(
          [
            _glance('jobs', 'Jobs'),
            _glance('hr', 'Human Resources'),
            _glance('customers', 'Customers'),
          ],
          order: ['hr', '--', 'jobs', 'customers'],
        ),
      );

      expect(find.byType(DashboardGrid), findsNWidgets(2));
      expect(_titles(tester), ['Human Resources', 'Jobs', 'Customers']);

      final rule = tester.getRect(find.byType(Divider));
      expect(
        rule.top,
        greaterThanOrEqualTo(_rect(tester, 'Human Resources').bottom),
      );
      expect(rule.bottom, lessThanOrEqualTo(_rect(tester, 'Jobs').top));
    });

    testWidgets('moving a box inside its section leaves the other section '
        'where it was', (tester) async {
      final sent = <Map<String, dynamic>>[];

      await _pumpScreen(
        tester,
        _server(
          [
            _glance('jobs', 'Jobs'),
            _glance('hr', 'Human Resources'),
            _glance('customers', 'Customers'),
          ],
          order: ['hr', '--', 'jobs', 'customers'],
          record: sent,
        ),
      );
      await _customize(tester);

      await tester.tap(find.byTooltip('Move “Customers” earlier'));
      await tester.pumpAndSettle();

      expect(_titles(tester), ['Human Resources', 'Customers', 'Jobs']);
      expect(
        [for (final g in sent.single['glances'] as List) g['key']],
        ['customers', 'hr', 'jobs'],
      );
    });

    testWidgets('a box cannot be dropped into another section', (tester) async {
      final sent = <Map<String, dynamic>>[];

      await _pumpScreen(
        tester,
        _server(
          [
            _glance('jobs', 'Jobs'),
            _glance('hr', 'Human Resources'),
            _glance('customers', 'Customers'),
          ],
          order: ['hr', '--', 'jobs', 'customers'],
          record: sent,
        ),
      );
      await _customize(tester);

      final grip = find.byTooltip(
        'Drag “Customers” onto the card whose place it should take',
      );

      await tester.drag(
        grip,
        tester.getCenter(find.byType(DashboardCard).first) -
            tester.getCenter(grip),
        kind: PointerDeviceKind.mouse,
      );
      await tester.pumpAndSettle();

      expect(_titles(tester), ['Human Resources', 'Jobs', 'Customers']);
      expect(sent, isEmpty);

      // Control: the same drag inside its own section does move it.
      await tester.drag(
        grip,
        tester.getCenter(find.byType(DashboardCard).at(1)) -
            tester.getCenter(grip),
        kind: PointerDeviceKind.mouse,
      );
      await tester.pumpAndSettle();

      expect(_titles(tester), ['Human Resources', 'Customers', 'Jobs']);
    });
  });

  group('what a box says, by size', () {
    Map<String, dynamic> jobs(String size) => _glance(
      'jobs',
      'Jobs',
      size: size,
      data: {
        'empty': false,
        'sentences': ['181 jobs today and 977 coming up.'],
        'notes': ['3 have no technician.'],
        'figures': [
          {'key': 'today', 'label': 'today', 'value': 181, 'previous': 160},
          {
            'key': 'upcoming',
            'label': 'coming up',
            'value': 977,
            'previous': null,
          },
        ],
        'attention': [
          {'tone': 'bad', 'text': '6 jobs past their window'},
          {'tone': 'caution', 'text': '11 jobs with no technician'},
        ],
      },
    );

    testWidgets('Small is the headline and a count of what needs somebody', (
      tester,
    ) async {
      await _pumpScreen(tester, _server([jobs('small')]));

      expect(find.text('181'), findsOneWidget);
      expect(find.text('today'), findsOneWidget);
      expect(find.text('▲ 13%'), findsOneWidget);
      expect(find.text('977'), findsNothing);
      expect(find.text('3 have no technician.'), findsNothing);
      expect(find.text('2 need attention'), findsOneWidget);
      expect(find.text('6 jobs past their window'), findsNothing);
    });

    testWidgets('Medium is every figure and the notes, never the sentences '
        'that repeat them', (tester) async {
      await _pumpScreen(tester, _server([jobs('medium')]));

      expect(find.text('181'), findsOneWidget);
      expect(find.text('977'), findsOneWidget);
      expect(find.text('coming up'), findsOneWidget);
      expect(find.text('3 have no technician.'), findsOneWidget);
      expect(find.text('181 jobs today and 977 coming up.'), findsNothing);
      expect(find.text('2 need attention'), findsOneWidget);
    });

    testWidgets('Large lists what needs somebody', (tester) async {
      await _pumpScreen(tester, _server([jobs('large')]));

      expect(find.text('6 jobs past their window'), findsOneWidget);
      expect(find.text('11 jobs with no technician'), findsOneWidget);
      expect(find.text('2 need attention'), findsNothing);
    });

    testWidgets(
      'a box without figures still says every sentence, at any size',
      (tester) async {
        await _pumpScreen(
          tester,
          _server([
            _glance(
              'customers',
              'Customers',
              size: 'small',
              data: {
                'empty': false,
                'sentences': ['2 new customers this month.', '43,968 listed.'],
              },
            ),
          ]),
        );

        expect(find.text('2 new customers this month.'), findsOneWidget);
        expect(find.text('43,968 listed.'), findsOneWidget);
      },
    );

    testWidgets('the change since the period before, and none without one '
        'or from a zero', (tester) async {
      Map<String, dynamic> one(String key, int value, int? previous) => _glance(
        key,
        key,
        size: 'small',
        data: {
          'empty': false,
          'figures': [
            {
              'key': key,
              'label': 'of $key',
              'value': value,
              'previous': previous,
            },
          ],
        },
      );

      await _pumpScreen(
        tester,
        _server([
          one('down', 999, 1000),
          one('same', 37, 37),
          one('fresh', 15, 0),
          one('snapshot', 40, null),
        ]),
      );

      expect(find.text('▼ <1%'), findsOneWidget);
      expect(find.text('= 0%'), findsOneWidget);

      // The Dashboard module's contract: no arrow against a previous zero
      // (a window with no history reads zero too) and none without one.
      // Control: both figures are drawn, so the absence is the arrow's.
      expect(find.text('15'), findsOneWidget);
      expect(find.text('40'), findsOneWidget);
      expect(find.textContaining('▲'), findsNothing);
      expect(find.textContaining('▼'), findsOneWidget);
    });

    testWidgets('money is drawn from a figure that carries a currency', (
      tester,
    ) async {
      await _pumpScreen(
        tester,
        _server([
          _glance(
            'jobs',
            'Jobs',
            size: 'small',
            data: {
              'empty': false,
              'figures': [
                {
                  'key': 'earned',
                  'label': 'earned today',
                  'value': 1234000,
                  'previous': 1000000,
                  'currency': 'USD',
                },
              ],
            },
          ),
        ]),
      );

      expect(find.text(r'$12,340'), findsOneWidget);
      expect(find.text('▲ 23%'), findsOneWidget);
    });

    testWidgets('a box that could not load says so as a fault', (tester) async {
      await _pumpScreen(
        tester,
        _server([
          {
            ..._glance(
              'jobs',
              'Jobs',
              data: {
                'empty': true,
                'sentence': 'This panel could not load right now.',
              },
            ),
            'failed': true,
          },
        ]),
      );

      final text = tester.widget<Text>(
        find.text('This panel could not load right now.'),
      );
      final context = tester.element(
        find.text('This panel could not load right now.'),
      );

      expect(text.style?.color, Theme.of(context).colorScheme.error);
      expect(find.byIcon(Icons.error_outline), findsOneWidget);
    });
  });
}

// ── reading the screen ─────────────────────────────────────────────

Rect _rect(WidgetTester tester, String title) => tester.getRect(
  find
      .ancestor(of: find.text(title), matching: find.byType(DashboardCard))
      .first,
);

List<String> _titles(WidgetTester tester) => [
  for (final card in tester.widgetList<DashboardCard>(
    find.byType(DashboardCard),
  ))
    card.title,
];

Future<void> _customize(WidgetTester tester) async {
  await tester.tap(find.byIcon(Icons.dashboard_customize_outlined));
  await tester.pumpAndSettle();
}

// ── driving it ─────────────────────────────────────────────────────

Future<void> _pumpScreen(
  WidgetTester tester,
  MockClientHandler handler, {
  Size size = const Size(1400, 3000),
}) async {
  tester.view.physicalSize = size;
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(client: MockClient(handler));
  final session = Session(api);

  await tester.runAsync(() => session.adopt('tok'));

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(
        body: KeyedSubtree(
          key: UniqueKey(),
          child: DashboardScreen(api: api, session: session),
        ),
      ),
    ),
  );
  await tester.pumpAndSettle();
}

Map<String, dynamic> _glance(
  String key,
  String label, {
  String? size,
  Map<String, dynamic>? data,
}) => {
  'key': key,
  'label': label,
  'presentation': 'text',
  'allowed_presentations': ['text'],
  'snapshot': false,
  'coming_soon': false,
  'options': <String, dynamic>{},
  'size': ?size,
  'data': data ?? {'empty': false, 'sentence': '$label says something.'},
};

MockClientHandler _server(
  List<Map<String, dynamic>> glances, {
  List<String> order = const [],
  List<Map<String, dynamic>>? record,
  int saveStatus = 200,
  Completer<void>? held,
}) {
  return (request) async {
    final path = request.url.path;

    if (path.endsWith('/me')) {
      return _json({
        'data': {
          ..._me,
          'preferences': {'navigation.order': order},
        },
      });
    }

    if (request.method == 'PUT' && path.endsWith('/dashboard/layout')) {
      record?.add(jsonDecode(request.body) as Map<String, dynamic>);
      if (held != null) await held.future;
      if (saveStatus != 200) {
        return _json({'message': 'Nothing doing.'}, saveStatus);
      }

      return _json({'ok': true});
    }

    if (path.endsWith('/dashboard')) {
      return _json({'period': 'day', 'glances': glances});
    }

    return _json({'message': 'Unexpected ${request.method} $path'}, 500);
  };
}

http.Response _json(Object body, [int status = 200]) => http.Response(
  jsonEncode(body),
  status,
  headers: {'content-type': 'application/json'},
);

const _me = {
  'id': 'u-1',
  'name': 'Dana Reyes',
  'email': 'dana@example.com',
  'has_avatar': false,
  'role': {'id': 'role-1', 'name': 'Administrator'},
  'permissions': ['hr.view', 'jobs.view', 'customers.view'],
  'modules': [
    {
      'key': 'hr',
      'label': 'Human Resources',
      'available': true,
      'reachable': true,
    },
  ],
  'organization': {
    'id': 'org-1',
    'name': 'Harbor Group',
    'franchise_label': 'Franchise',
    'is_read_only': false,
  },
  'scope': {
    'type': 'organization',
    'id': 'org-1',
    'label': 'Harbor Group',
    'path': ['Harbor Group'],
  },
  'companies': <Map<String, Object?>>[],
  'franchises': <Map<String, Object?>>[],
};
