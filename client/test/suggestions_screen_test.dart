import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/core/timestamps.dart';
import 'package:ezbizi_client/screens/suggestions_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

void main() {
  testWidgets('a new suggestion offers edit and withdraw', (tester) async {
    await _pump(tester, _listing([_row(status: 'new')]));

    expect(find.text('Let the board remember its filter.'), findsOneWidget);
    expect(find.byIcon(Icons.edit_outlined), findsOneWidget);
    expect(find.byIcon(Icons.delete_outline), findsOneWidget);
  });

  /// The client half of the server rule: a control the server will refuse must
  /// not be offered. See SuggestionsController::frozen.
  testWidgets('a decided suggestion shows the note and no controls', (
    tester,
  ) async {
    await _pump(tester, _listing([
      _row(status: 'denied', note: 'It is in Settings already.'),
    ]));

    expect(find.text('It is in Settings already.'), findsOneWidget);
    expect(find.byIcon(Icons.edit_outlined), findsNothing);
    expect(find.byIcon(Icons.delete_outline), findsNothing);
  });

  /// Every stamp on this screen is the ORGANIZATION's, not the reader's
  /// machine's and not ISO.
  ///
  /// This screen shipped passing `DisplayFormats.fallback` to both of its
  /// `formatStamp` calls, which is verbatim the failure that function's
  /// docblock says the required parameter exists to prevent — it compiles, it
  /// looks right, and an owner who sets 12h sees 24h forever. Written against
  /// a setting that differs from the fallback in BOTH fields, so it fails if
  /// either half regresses.
  testWidgets('the stamps are written in the organization\'s own format', (
    tester,
  ) async {
    const formats = DisplayFormats(dateFormat: 'M/D/YYYY', timeFormat: '12h');

    await _pump(
      tester,
      _listing([_row(status: 'denied', note: 'It is in Settings already.')]),
      formats: formats,
    );

    // Built through the same DisplayFormats rather than typed out, so the
    // assertion survives whatever zone the machine running it is in —
    // `formatStamp` renders in the reader's local zone by design.
    String shown(String iso) {
      final at = DateTime.parse(iso).toLocal();

      return '${formats.formatDate(at)} ${formats.formatTime(at.hour, at.minute)}';
    }

    expect(
      find.text(shown('2026-09-02T10:00:00+00:00')),
      findsOneWidget,
      reason: 'The sent stamp ignores the organization\'s date and time format.',
    );

    // The answered stamp is written into a sentence rather than standing on
    // its own, so this matches the sentence the screen actually renders.
    expect(
      find.text('EzBizi answered · ${shown('2026-09-02T11:00:00+00:00')}'),
      findsOneWidget,
      reason:
          'The answered stamp ignores the organization\'s date and time format.',
    );

    // And the fallback's own rendering is nowhere on screen — the assertions
    // above would still pass if one stamp were right and the other ISO.
    expect(
      find.textContaining('2026-09-02 '),
      findsNothing,
      reason:
          'An ISO stamp is on this screen, so something is still passing '
          'DisplayFormats.fallback.',
    );
  });
}

Map<String, dynamic> _row({required String status, String? note}) => {
  'id': 'a',
  'body': 'Let the board remember its filter.',
  'status': status,
  'decision_note': note,
  'decided_at': note == null ? null : '2026-09-02T11:00:00+00:00',
  'created_at': '2026-09-02T10:00:00+00:00',
};

MockClientHandler _listing(List<Map<String, dynamic>> rows) {
  return (http.Request request) async {
    if (request.url.path.endsWith('/suggestions')) {
      return http.Response(
        jsonEncode({'data': rows}),
        200,
        headers: {'content-type': 'application/json'},
      );
    }

    return http.Response('{}', 404, headers: {'content-type': 'application/json'});
  };
}

/// [formats] defaults to a setting that is NOT `DisplayFormats.fallback` in
/// either field, so nothing in this file can pass by accidentally agreeing
/// with the value a screen falls back to.
Future<void> _pump(
  WidgetTester tester,
  MockClientHandler handler, {
  DisplayFormats formats = const DisplayFormats(
    dateFormat: 'M/D/YYYY',
    timeFormat: '12h',
  ),
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
          formats: formats,
        ),
      ),
    ),
  );
  await tester.pumpAndSettle();
}
