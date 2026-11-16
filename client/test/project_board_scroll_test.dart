import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/project_board_screen.dart';
import 'package:flutter/gestures.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Jose, 2026-11-16: the sideways bar was pushed away as rows loaded, the
/// button column was cut at "Ready to sch", and he wants middle-button
/// panning. Many columns so the table is wider than the window.
void main() {
  const keys = [
    'number',
    'workiz_id',
    'customer',
    'area',
    'service_area',
    'status',
    'status_days',
    'technician',
    'part_status',
    'required',
    'tags',
    'paid',
    'due',
    'total',
    'notes',
  ];

  Future<Session> app(WidgetTester tester) async {
    final api = ApiClient(
      client: MockClient((request) async {
        final path = request.url.path;
        if (path.endsWith('/me')) {
          return _json({
            'data': {
              'id': 'u-1',
              'name': 'Dana',
              'email': 'dana@brightside.example',
              'permissions': ['project_board.view', 'jobs.view'],
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
        if (path.endsWith('/settings')) return _json({'data': <dynamic>[]});
        if (path.endsWith('/project-board/options')) {
          return _json({'data': <String, dynamic>{}});
        }
        final page =
            int.tryParse(request.url.queryParameters['page'] ?? '') ?? 1;
        return _json({
          'data': [
            for (var i = 0; i < 50; i++)
              {
                'id': 'j-$page-$i',
                'number': 'J-$page$i',
                'customer': 'Person $page-$i',
                'status': 'Pending',
                'required': 'One\nTwo\nThree\nFour\nFive',
                'ready_missing': <String>[],
                'tags': <String>[],
              },
          ],
          'meta': {
            'total': 100,
            'last_page': 2,
            'counts': {'unscheduled': 100},
            'places': <dynamic>[],
            'columns': [
              for (final k in keys) {'key': k, 'label': k, 'visible': true},
            ],
            'labels': <String, dynamic>{},
            'can_edit': false,
            'sortable': <String>[],
          },
        });
      }),
    );
    final session = Session(api);
    await tester.runAsync(() => session.adopt('tok'));
    tester.view.physicalSize = const Size(1440, 900);
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
    return session;
  }

  Finder sidewaysBar() => find.byWidgetPredicate(
    (w) => w is Scrollbar && w.thumbVisibility == true,
  );
  ScrollPosition sideways(WidgetTester tester) => tester
      .widget<SingleChildScrollView>(
        find
            .byWidgetPredicate(
              (w) =>
                  w is SingleChildScrollView &&
                  w.scrollDirection == Axis.horizontal,
            )
            .first,
      )
      .controller!
      .position;

  testWidgets('the sideways bar stays put as more rows load', (tester) async {
    await app(tester);
    final before = tester.getRect(sidewaysBar().first).bottom;
    expect(before, lessThanOrEqualTo(900));
    await tester.tap(find.byKey(const Key('project-board-more')));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('project-board-more')), findsNothing);
    expect(tester.getRect(sidewaysBar().first).bottom, before);
  });

  testWidgets('the Action column is on screen without scrolling', (
    tester,
  ) async {
    await app(tester);
    final heading = tester.getRect(find.text('ACTION'));
    expect(heading.right, lessThan(1440));
    final button = tester.getRect(find.text('Ready to schedule').first);
    expect(button.left, greaterThanOrEqualTo(0));
    expect(button.right, lessThan(1440));
    expect(find.textContaining('+3 more'), findsWidgets);
  });

  testWidgets('a middle-button drag pans sideways', (tester) async {
    await app(tester);
    expect(sideways(tester).pixels, 0);
    final g = await tester.startGesture(
      tester.getCenter(find.text('Person 1-0')),
      kind: PointerDeviceKind.mouse,
      buttons: kMiddleMouseButton,
    );
    for (var i = 0; i < 10; i++) {
      await g.moveBy(const Offset(-20, 0));
      await tester.pump();
    }
    await g.up();
    await tester.pumpAndSettle();
    expect(sideways(tester).pixels, closeTo(200, 1));
  });
}

http.Response _json(Object body) => http.Response(
  jsonEncode(body),
  200,
  headers: {'content-type': 'application/json'},
);
