import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/files.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/widgets/suggestion_files.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Rodrigo, 2026-11-18: a suggestion's screenshots show inline, open full
/// screen, and the notes beside them stay links. Shaped like the suggestion
/// he named: three PNGs and two text files.
void main() {
  final files = [
    for (final (i, n) in ['one.png', 'two.png', 'three.png'].indexed)
      {'index': i, 'name': n, 'mime': 'image/png', 'size': 70},
    {'index': 3, 'name': 'notes.md', 'mime': 'text/markdown', 'size': 9},
    {'index': 4, 'name': 'log.txt', 'mime': 'text/plain', 'size': 9},
  ];

  Future<List<String>> pump(
    WidgetTester tester, {
    Size size = const Size(1200, 900),
    Set<int> refuse = const {},
  }) async {
    final asked = <String>[];
    tester.view.physicalSize = size;
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);
    final api = ApiClient(
      client: MockClient((request) async {
        asked.add(request.url.path);
        final index = int.parse(request.url.pathSegments.last);
        if (refuse.contains(index)) {
          return http.Response(
            jsonEncode({'message': 'Not yours.'}),
            404,
            headers: {'content-type': 'application/json'},
          );
        }
        return http.Response.bytes(index < 3 ? _png : utf8.encode('text'), 200);
      }),
    );
    await tester.pumpWidget(
      MaterialApp(
        theme: EzBiziTheme.light(),
        home: Scaffold(
          body: SingleChildScrollView(
            child: Column(
              children: [
                const Text('the suggestion', key: Key('the-suggestion')),
                SuggestionAbout(
                  api: api,
                  base: '/suggestions/s1',
                  row: {'module_label': 'Jobs', 'files': files},
                ),
              ],
            ),
          ),
        ),
      ),
    );
    await tester.pumpAndSettle();
    return asked;
  }

  testWidgets('the three pictures show on open; the two notes stay links', (
    tester,
  ) async {
    final asked = await pump(tester);

    for (final i in [0, 1, 2]) {
      expect(find.byKey(ValueKey('suggestion-thumb-image-$i')), findsOneWidget);
      expect(find.byKey(ValueKey('suggestion-file-$i')), findsNothing);
    }
    for (final i in [3, 4]) {
      expect(find.byKey(ValueKey('suggestion-file-$i')), findsOneWidget);
    }
    // Pictures on the same scoped route as Download; notes only when asked.
    expect(asked..sort(), [
      for (final i in [0, 1, 2]) '/api/v1/suggestions/s1/files/$i',
    ]);
  });

  testWidgets('the viewer moves by arrow and button, downloads, and closes '
      'back on the same suggestion', (tester) async {
    final downloads = <String>[];
    Files.debugOnDownload = (name, _) => downloads.add(name);
    addTearDown(() => Files.debugOnDownload = null);
    await pump(tester);

    await tester.tap(find.byKey(const ValueKey('suggestion-thumb-1')));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('suggestion-image-viewer')), findsOneWidget);
    expect(find.text('two.png  ·  2 of 3'), findsOneWidget);

    await tester.sendKeyEvent(LogicalKeyboardKey.arrowRight);
    await tester.pumpAndSettle();
    expect(find.text('three.png  ·  3 of 3'), findsOneWidget);
    expect(find.byKey(const Key('suggestion-image-next')), findsNothing);

    await tester.tap(find.byKey(const Key('suggestion-image-previous')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('suggestion-image-previous')));
    await tester.pumpAndSettle();
    expect(find.text('one.png  ·  1 of 3'), findsOneWidget);

    await tester.tap(find.byKey(const Key('suggestion-image-zoom-in')));
    await tester.pumpAndSettle();
    final zoomOut = tester.widget<IconButton>(
      find.byKey(const Key('suggestion-image-zoom-out')),
    );
    expect(zoomOut.onPressed, isNotNull, reason: 'zoomed in, so out works');

    await tester.tap(find.byKey(const Key('suggestion-image-download')));
    await tester.pumpAndSettle();
    expect(downloads, ['one.png']);

    await tester.sendKeyEvent(LogicalKeyboardKey.escape);
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('suggestion-image-viewer')), findsNothing);
    expect(find.byKey(const Key('the-suggestion')), findsOneWidget);
  });

  testWidgets(
    'a picture that is refused shows no image, and Retry asks again',
    (tester) async {
      final asked = await pump(tester, refuse: {1});

      expect(
        find.byKey(const ValueKey('suggestion-thumb-image-1')),
        findsNothing,
      );
      final retry = find.byKey(const ValueKey('suggestion-thumb-retry-1'));
      expect(retry, findsOneWidget);
      final before = asked.length;
      await tester.tap(retry);
      await tester.pumpAndSettle();
      expect(asked.length, before + 1);
      expect(asked.last, '/api/v1/suggestions/s1/files/1');
    },
  );

  testWidgets('on a phone the gallery wraps without overflow', (tester) async {
    await pump(tester, size: const Size(390, 844));
    expect(tester.takeException(), isNull);
    final first = tester.getTopLeft(
      find.byKey(const ValueKey('suggestion-thumb-0')),
    );
    final third = tester.getTopLeft(
      find.byKey(const ValueKey('suggestion-thumb-2')),
    );
    expect(third.dy, greaterThan(first.dy), reason: 'wrapped to a new row');
    expect(
      tester
          .getBottomRight(find.byKey(const ValueKey('suggestion-thumb-2')))
          .dx,
      lessThanOrEqualTo(390),
    );

    await tester.tap(find.byKey(const ValueKey('suggestion-thumb-0')));
    await tester.pumpAndSettle();
    expect(tester.takeException(), isNull);
    expect(find.byKey(const Key('suggestion-image-close')), findsOneWidget);
  });
}

final _png = base64Decode(
  'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8/5+hHgAHggJ/PchI7wAAAABJRU5ErkJggg==',
);
