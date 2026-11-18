import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/files.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/core/timestamps.dart';
import 'package:ezbizi_client/screens/suggestions_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

import 'pasted_image_test.dart' show windowsClipboardBmp;

/// "Which tab is it about?" and the screenshots/notes that go with it.
void main() {
  testWidgets('the chosen tab and an attached screenshot go in one request', (
    tester,
  ) async {
    http.Request? sent;

    await _pump(
      tester,
      (request) async {
        if (request.method == 'POST') {
          sent = request;
          return _json({'data': _row()}, 201);
        }
        return _json({
          'data': sent == null ? [] : [_row()],
        });
      },
      pick: ({String accept = ''}) async {
        expect(accept, contains('.md'));
        return const PickedBytes('screen.png', [1, 2, 3]);
      },
    );

    await tester.tap(find.byKey(const Key('suggestion-tab')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Jobs').last);
    await tester.pumpAndSettle();

    await tester.tap(find.text('Attach a screenshot or file'));
    await tester.pumpAndSettle();
    expect(find.widgetWithText(InputChip, 'screen.png'), findsOneWidget);

    await tester.enterText(find.byType(TextField).last, 'See the picture.');
    await tester.pumpAndSettle();
    await tester.tap(find.widgetWithText(FilledButton, 'Send it'));
    await tester.pumpAndSettle();

    final body = latin1.decode(sent!.bodyBytes);
    expect(sent!.headers['content-type'], startsWith('multipart/form-data'));
    expect(body, contains('name="module"\r\n\r\njobs'));
    expect(body, contains('name="body"\r\n\r\nSee the picture.'));
    expect(body, contains('name="files[]"; filename="screen.png"'));
  });

  // Jose, 2026-11-02: "paste images that may be in their clipboard".
  // 0.0.0562: on Windows the clipboard hands back BMP, which went up as
  // pasted-1.png and the server's content check refused it. The part sent
  // must be a real PNG.
  testWidgets('Ctrl+V with a picture on the clipboard attaches it as PNG', (
    tester,
  ) async {
    http.Request? sent;

    await _pump(tester, (request) async {
      if (request.method == 'POST') {
        sent = request;
        return _json({'data': _row()}, 201);
      }
      return _json({
        'data': sent == null ? [] : [_row()],
      });
    }, clipboard: () async => windowsClipboardBmp());

    await tester.tap(find.byType(TextField).last);
    await tester.sendKeyDownEvent(LogicalKeyboardKey.controlLeft);
    await tester.sendKeyEvent(LogicalKeyboardKey.keyV);
    await tester.sendKeyUpEvent(LogicalKeyboardKey.controlLeft);
    // The re-encode runs in an isolate (compute), which needs real time.
    final chip = find.widgetWithText(InputChip, 'pasted-1.png');
    for (var i = 0; i < 100 && chip.evaluate().isEmpty; i++) {
      await tester.runAsync(
        () => Future<void>.delayed(const Duration(milliseconds: 20)),
      );
      await tester.pump();
    }
    await tester.pumpAndSettle();
    expect(chip, findsOneWidget);

    await tester.enterText(find.byType(TextField).last, 'Pasted.');
    await tester.pumpAndSettle();
    await tester.tap(find.widgetWithText(FilledButton, 'Send it'));
    await tester.pumpAndSettle();

    final body = sent!.bodyBytes;
    final header = latin1.encode('name="files[]"; filename="pasted-1.png"');
    final at = _indexOf(body, header);
    expect(at, isNonNegative);
    final blank = _indexOf(body, latin1.encode('\r\n\r\n'), at);
    final part = body.sublist(blank + 4);
    expect(part.sublist(0, 8), [
      0x89,
      0x50,
      0x4E,
      0x47,
      0x0D,
      0x0A,
      0x1A,
      0x0A,
    ]);
  });

  testWidgets('Ctrl+V with bytes that are no picture attaches nothing', (
    tester,
  ) async {
    await _pump(
      tester,
      (request) async => _json({'data': []}),
      clipboard: () async => [1, 2, 3],
    );

    await tester.tap(find.byType(TextField).last);
    await tester.sendKeyDownEvent(LogicalKeyboardKey.controlLeft);
    await tester.sendKeyEvent(LogicalKeyboardKey.keyV);
    await tester.sendKeyUpEvent(LogicalKeyboardKey.controlLeft);
    for (var i = 0; i < 10; i++) {
      await tester.runAsync(
        () => Future<void>.delayed(const Duration(milliseconds: 20)),
      );
      await tester.pump();
    }
    await tester.pumpAndSettle();

    expect(find.byType(InputChip), findsNothing);
  });

  testWidgets('Ctrl+V with only text on the clipboard attaches nothing', (
    tester,
  ) async {
    await _pump(tester, (request) async => _json({'data': []}));

    await tester.tap(find.byType(TextField).last);
    await tester.sendKeyDownEvent(LogicalKeyboardKey.controlLeft);
    await tester.sendKeyEvent(LogicalKeyboardKey.keyV);
    await tester.sendKeyUpEvent(LogicalKeyboardKey.controlLeft);
    await tester.pumpAndSettle();

    expect(find.byType(InputChip), findsNothing);
  });

  testWidgets(
    'with no file and no tab chosen, it is plain JSON with no module',
    (tester) async {
      http.Request? sent;

      await _pump(tester, (request) async {
        if (request.method == 'POST') {
          sent = request;
          return _json({'data': _row()}, 201);
        }
        return _json({
          'data': sent == null ? [] : [_row()],
        });
      });

      await tester.enterText(find.byType(TextField).last, 'An idea.');
      await tester.pumpAndSettle();
      await tester.tap(find.widgetWithText(FilledButton, 'Send it'));
      await tester.pumpAndSettle();

      expect(jsonDecode(sent!.body), {'body': 'An idea.'});
    },
  );

  testWidgets('the detail names the tab and offers each file', (tester) async {
    await _pump(
      tester,
      (request) async => _json({
        'data': [_row()],
      }),
    );

    final detail = find.byKey(const Key('suggestion-detail'));
    expect(
      find.descendant(of: detail, matching: find.text('About: Jobs')),
      findsOneWidget,
    );
    // Rodrigo, 2026-11-18: a picture shows itself; it is not a chip.
    expect(
      find.descendant(
        of: detail,
        matching: find.byKey(const ValueKey('suggestion-thumb-0')),
      ),
      findsOneWidget,
    );
    expect(find.widgetWithText(ActionChip, 'screen.png'), findsNothing);
  });
}

Map<String, dynamic> _row() => {
  'id': 's1',
  'body': 'See the picture.',
  'status': 'new',
  'module': 'jobs',
  'module_label': 'Jobs',
  'files': [
    {'index': 0, 'name': 'screen.png', 'mime': 'image/png', 'size': 3},
  ],
  'decision_note': null,
  'decided_at': null,
  'created_at': '2026-09-30T10:00:00+00:00',
};

int _indexOf(List<int> haystack, List<int> needle, [int from = 0]) {
  outer:
  for (var i = from; i <= haystack.length - needle.length; i++) {
    for (var j = 0; j < needle.length; j++) {
      if (haystack[i + j] != needle[j]) continue outer;
    }
    return i;
  }
  return -1;
}

http.Response _json(Map<String, dynamic> body, [int status = 200]) =>
    http.Response(
      jsonEncode(body),
      status,
      headers: {'content-type': 'application/json'},
    );

Future<void> _pump(
  WidgetTester tester,
  MockClientHandler handler, {
  Future<PickedBytes?> Function({String accept})? pick,
  Future<List<int>?> Function()? clipboard,
}) async {
  tester.view.physicalSize = const Size(1600, 2200);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(
        body: SuggestionsScreen(
          api: ApiClient(client: MockClient(handler)),
          formats: const DisplayFormats(
            dateFormat: 'M/D/YYYY',
            timeFormat: '12h',
          ),
          tabs: const [
            (key: 'jobs', label: 'Jobs'),
            (key: 'settings', label: 'Settings'),
          ],
          pickFile: pick ?? ({String accept = ''}) async => null,
          clipboardImage: clipboard ?? () async => null,
        ),
      ),
    ),
  );
  await tester.pumpAndSettle();
}
