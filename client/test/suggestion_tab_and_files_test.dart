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
  testWidgets('Ctrl+V with a picture on the clipboard attaches it', (
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
    await tester.pumpAndSettle();

    expect(find.widgetWithText(InputChip, 'pasted-1.png'), findsOneWidget);
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
    expect(
      find.descendant(
        of: detail,
        matching: find.widgetWithText(ActionChip, 'screen.png'),
      ),
      findsOneWidget,
    );
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
