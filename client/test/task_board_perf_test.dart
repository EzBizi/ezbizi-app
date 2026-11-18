import 'dart:convert';
import 'dart:io';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/device_key_store_io.dart' as store;
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/task_board_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter/gestures.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// A real board (A-1 Illinois, 1,565 tasks, 11 columns, 13 groups, saved
/// 2026-11-16 and anonymised: names, phones and text replaced) opens,
/// scrolls, hovers and switches without freezing (Jose 11-16: "lagging out
/// or crashing every second").
void main() {
  late Map<String, dynamic> big;
  setUpAll(() {
    big =
        jsonDecode(
              File('test/fixtures/task_board_large.json').readAsStringSync(),
            )
            as Map<String, dynamic>;
  });
  setUp(() {
    store.debugStoreDirOverride = Directory.systemTemp.createTempSync('tbp');
  });
  tearDown(() => store.debugStoreDirOverride = null);

  http.Response json(Object body) => http.Response(
    jsonEncode(body),
    200,
    headers: const {'content-type': 'application/json'},
  );

  final calls = <String>[];
  ApiClient fakeServer() => ApiClient(
    client: MockClient((request) async {
      final path = request.url.path.replaceFirst('/api/v1', '');
      calls.add(path);
      if (path.endsWith('/avatar')) return http.Response('', 404);
      if (path.endsWith('/me')) {
        return json({
          'data': {
            'id': 'me',
            'name': 'Maya Lopez',
            'email': 'maya@example.test',
            'permissions': ['tasks.view', 'tasks.edit'],
            'modules': <dynamic>[],
            'preferences': <String, dynamic>{},
            'display': {'date_format': 'M/D/YYYY', 'time_format': '12h'},
            'organization': {
              'id': 'org-1',
              'name': 'A-1',
              'franchise_label': 'Franchise',
              'is_read_only': false,
            },
            'companies': <dynamic>[],
            'franchises': <dynamic>[],
          },
        });
      }
      if (path == '/task-boards') {
        return json({
          'data': [
            {
              'id': 'big',
              'kind': 'franchise',
              'name': 'Big',
              'section': 'shared',
              'can_edit': true,
            },
            {
              'id': 'big2',
              'kind': 'franchise',
              'name': 'Big 2',
              'section': 'shared',
              'can_edit': true,
            },
          ],
        });
      }
      if (path == '/task-boards/big' || path == '/task-boards/big2') {
        final data = big['data'] as Map<String, dynamic>;
        return json({
          'data': {...data, 'id': path.split('/').last, 'cursor': 'c0'},
        });
      }
      return json({'data': <dynamic>[]});
    }),
  );

  int rowsBuilt(WidgetTester tester) => tester
      .widgetList(
        find.byWidgetPredicate((w) {
          final k = w.key;
          return k is ValueKey<String> && k.value.startsWith('task-01');
        }, skipOffstage: false),
      )
      .length;

  Future<int> timed(Future<void> Function() step) async {
    final sw = Stopwatch()..start();
    await step();
    return sw.elapsedMilliseconds;
  }

  Future<void> openBoard(WidgetTester tester) async {
    for (var i = 0; i < 200; i++) {
      await tester.runAsync(
        () => Future<void>.delayed(const Duration(milliseconds: 20)),
      );
      await tester.pump();
      if (rowsBuilt(tester) > 0) return;
    }
  }

  Future<void> pumpScreen(WidgetTester tester, ApiClient api) async {
    tester.view.physicalSize = const Size(1600, 1000);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);
    final session = Session(api);
    await session.adopt('tok');
    await tester.pumpWidget(
      MaterialApp(
        theme: EzBiziTheme.light(),
        home: Scaffold(
          body: TaskBoardScreen(api: api, session: session),
        ),
      ),
    );
  }

  testWidgets('a 1,565-task real board opens, scrolls and hovers with a '
      'bounded row count and light rows', (tester) async {
    calls.clear();
    await pumpScreen(tester, fakeServer());
    final open = Stopwatch()..start();
    await openBoard(tester);
    open.stop();
    expect(rowsBuilt(tester), greaterThan(5));
    // Debug-mode test time, not the app's: with the filter fields rebuilt on
    // every lookup the first frame of rows alone took 12 s here.
    expect(open.elapsed.inSeconds, lessThan(20), reason: '${open.elapsed}');

    // One row's widgets: each cell had a menu, a focus node and an overlay
    // up front, 1,056 elements a row; now ~550.
    final row = find
        .byWidgetPredicate((w) {
          final k = w.key;
          return k is ValueKey<String> && k.value.startsWith('task-01');
        }, skipOffstage: false)
        .evaluate()
        .first;
    var elements = 0;
    void walk(Element e) {
      elements++;
      e.visitChildren(walk);
    }

    walk(row);
    expect(elements, lessThan(700), reason: '$elements elements in a row');

    final table = find.byType(CustomScrollView).first;
    final frames = <int>[];
    for (var i = 0; i < 10; i++) {
      await tester.drag(table, const Offset(0, -900));
      frames.add(await timed(() => tester.pump()));
      final built = rowsBuilt(tester);
      expect(built, lessThan(80), reason: 'scroll $i built $built rows');
    }

    final gesture = await tester.createGesture(kind: PointerDeviceKind.mouse);
    await gesture.addPointer(location: const Offset(800, 500));
    addTearDown(gesture.removePointer);
    final hovers = <int>[];
    for (var y = 300.0; y < 900; y += 19) {
      await gesture.moveTo(Offset(800, y));
      hovers.add(await timed(() => tester.pump()));
    }
    // ignore: avoid_print
    print(
      'task board perf (debug ms): open ${open.elapsedMilliseconds}, '
      'scroll $frames, worst hover ${hovers.reduce((a, b) => a > b ? a : b)}',
    );
    expect(tester.takeException(), isNull);
    await tester.pumpWidget(const SizedBox());
  });

  testWidgets('switching boards: no exception, only the open board is '
      'polled, and nothing polls after the screen goes', (tester) async {
    calls.clear();
    await pumpScreen(tester, fakeServer());
    await openBoard(tester);
    await tester.tap(find.text('Big 2').first);
    for (var i = 0; i < 100; i++) {
      await tester.runAsync(
        () => Future<void>.delayed(const Duration(milliseconds: 20)),
      );
      await tester.pump();
      if (calls.contains('/task-boards/big2') && rowsBuilt(tester) > 0) break;
    }
    expect(calls, contains('/task-boards/big2'));
    expect(tester.takeException(), isNull);

    calls.clear();
    await tester.pump(const Duration(seconds: 11));
    await tester.runAsync(
      () => Future<void>.delayed(const Duration(milliseconds: 50)),
    );
    await tester.pump();
    expect(calls, contains('/task-boards/big2/changes'));
    expect(calls.where((c) => c.startsWith('/task-boards/big/')), isEmpty);

    await tester.pumpWidget(const SizedBox());
    calls.clear();
    await tester.pump(const Duration(seconds: 35));
    expect(
      calls.where((c) => c.endsWith('/changes')),
      isEmpty,
      reason: 'a poll outlived the screen',
    );
    expect(tester.takeException(), isNull);
  });
}
