import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/core/timestamps.dart';
import 'package:ezbizi_client/screens/suggestions_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// The author's own tab — arrangement is `docs/previews/suggestions-2.html`
/// #2, "what happened to what you sent": a rail beside a detail pane, rather
/// than layout 1's compose-box-then-list this screen shipped with. Nothing
/// here may pick a different arrangement; these tests only check the
/// behaviour the brief calls out as required regardless of it.
void main() {
  testWidgets('the rail renders every suggestion, newest first as the server sends it', (
    tester,
  ) async {
    await _pump(
      tester,
      _listing([
        _row(id: 'a', status: 'new', body: 'Let the board remember its filter.'),
        _row(id: 'b', status: 'denied', body: 'Print a route sheet.', note: 'Not this one.'),
      ]),
    );

    // Scoped to the rail rather than a bare `find.text`: the rail now opens
    // with the first suggestion preselected (see the next test), so with the
    // detail pane also on screen its body appears there too. An unscoped
    // `findsOneWidget` would pass even if the rail stopped rendering the row
    // at all; scoped to the rail it still fails the moment that row
    // disappears from the list.
    expect(_inRail('Let the board remember its filter.'), findsOneWidget);
    expect(_inRail('Print a route sheet.'), findsOneWidget);
  });

  testWidgets('the newest suggestion is preselected, open in the detail pane', (
    tester,
  ) async {
    await _pump(
      tester,
      _listing([_row(id: 'a', status: 'new', body: 'Let the board remember its filter.')]),
    );

    // The entire point of the master-detail arrangement: the pane shows the
    // first suggestion's body without anybody having clicked it.
    expect(
      find.descendant(
        of: find.byKey(const Key('suggestion-detail')),
        matching: find.text('Let the board remember its filter.'),
      ),
      findsOneWidget,
    );
  });

  testWidgets('a new suggestion offers edit and withdraw', (tester) async {
    await _pump(tester, _listing([_row(id: 'a', status: 'new')]));

    expect(find.byIcon(Icons.edit_outlined), findsOneWidget);
    expect(find.byIcon(Icons.delete_outline), findsOneWidget);
  });

  /// The client half of the server rule: a control the server will refuse must
  /// not be offered. See SuggestionsController::frozen.
  testWidgets('a decided suggestion shows the note and no controls', (
    tester,
  ) async {
    await _pump(tester, _listing([
      _row(id: 'a', status: 'denied', note: 'It is in Settings already.'),
    ]));

    expect(find.text('It is in Settings already.'), findsOneWidget);
    expect(find.byIcon(Icons.edit_outlined), findsNothing);
    expect(find.byIcon(Icons.delete_outline), findsNothing);
  });

  /// `SuggestionsController::frozen` is the server's own sentence for each of
  /// the three states Edit/Withdraw vacate. Matched, not paraphrased.
  testWidgets('the frozen sentence is exact for a suggestion being read', (
    tester,
  ) async {
    await _pump(tester, _listing([_row(id: 'a', status: 'reviewing')]));

    expect(
      find.text(
        'Somebody at EzBizi is reading this one, so it is fixed while they '
        'do. Send another if there is more to say.',
      ),
      findsOneWidget,
    );
  });

  testWidgets('the frozen sentence is exact for a decided suggestion', (
    tester,
  ) async {
    await _pump(tester, _listing([
      _row(id: 'a', status: 'approved', note: 'Shipping next release.'),
    ]));

    expect(
      find.text(
        'This one has been answered, so it stays as it was written. Send '
        'another if there is more to say.',
      ),
      findsOneWidget,
    );
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
      _listing([_row(id: 'a', status: 'denied', note: 'It is in Settings already.')]),
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
      find.text('Sent ${shown('2026-09-02T10:00:00+00:00')}'),
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

  testWidgets('the first visit shows the empty state and the compose box, not a blank pane', (
    tester,
  ) async {
    await _pump(tester, _listing([]));

    expect(find.text('Tell EzBizi what to do differently'), findsOneWidget);
    expect(
      find.text('What should EzBizi do differently?'),
      findsOneWidget,
      reason: 'The empty state IS the compose box — nearly every first visit '
          'is somebody with nothing sent yet.',
    );
  });

  testWidgets('sending a suggestion refetches and opens on what was just sent', (
    tester,
  ) async {
    var suggestions = <Map<String, dynamic>>[];

    await _pump(
      tester,
      (request) async {
        if (request.url.path.endsWith('/suggestions') &&
            request.method == 'GET') {
          return _json({'data': suggestions});
        }

        if (request.url.path.endsWith('/suggestions') &&
            request.method == 'POST') {
          suggestions = [
            _row(id: 'new-1', status: 'new', body: 'A brand new idea.'),
            ...suggestions,
          ];
          return _json({'data': suggestions.first});
        }

        return _json({'message': 'unexpected'}, 404);
      },
    );

    expect(find.text('Tell EzBizi what to do differently'), findsOneWidget);

    await tester.enterText(
      find.byType(TextField),
      'A brand new idea.',
    );
    await tester.pumpAndSettle();

    await tester.tap(find.widgetWithText(FilledButton, 'Send it'));
    await tester.pumpAndSettle();

    expect(
      find.descendant(
        of: find.byKey(const Key('suggestion-detail')),
        matching: find.text('A brand new idea.'),
      ),
      findsOneWidget,
      reason: 'The detail pane should now show the suggestion just sent.',
    );
  });

  testWidgets('a refused send shows the server\'s own message, never a hardcoded one', (
    tester,
  ) async {
    await _pump(
      tester,
      (request) async {
        if (request.url.path.endsWith('/suggestions') &&
            request.method == 'GET') {
          return _json({'data': []});
        }

        if (request.url.path.endsWith('/suggestions') &&
            request.method == 'POST') {
          return _json({
            'message': 'Write the suggestion first.',
            'errors': {
              'body': ['Write the suggestion first.'],
            },
          }, 422);
        }

        return _json({'message': 'unexpected'}, 404);
      },
    );

    await tester.enterText(find.byType(TextField), 'x');
    await tester.pumpAndSettle();

    await tester.tap(find.widgetWithText(FilledButton, 'Send it'));
    await tester.pumpAndSettle();

    expect(find.text('Write the suggestion first.'), findsOneWidget);
  });

  testWidgets('a refused edit shows the server\'s own message', (tester) async {
    await _pump(
      tester,
      (request) async {
        final path = request.url.path;

        if (path.endsWith('/suggestions') && request.method == 'GET') {
          return _json({
            'data': [_row(id: 'a', status: 'new')],
          });
        }

        if (path.endsWith('/suggestions/a') && request.method == 'PATCH') {
          return _json({
            'message':
                'Somebody at EzBizi is reading this one, so it is fixed '
                'while they do. Send another if there is more to say.',
            'errors': {
              'body': [
                'Somebody at EzBizi is reading this one, so it is fixed '
                    'while they do. Send another if there is more to say.',
              ],
            },
          }, 422);
        }

        return _json({'message': 'unexpected ${request.method} $path'}, 404);
      },
    );

    await tester.tap(find.widgetWithText(OutlinedButton, 'Edit'));
    await tester.pumpAndSettle();

    await tester.enterText(find.byType(TextField), 'Changed my mind.');
    await tester.pumpAndSettle();

    await tester.tap(find.widgetWithText(FilledButton, 'Save'));
    await tester.pumpAndSettle();

    expect(
      find.text(
        'Somebody at EzBizi is reading this one, so it is fixed while they '
        'do. Send another if there is more to say.',
      ),
      findsOneWidget,
    );
  });

  testWidgets('a refused withdraw shows the server\'s own message', (
    tester,
  ) async {
    await _pump(
      tester,
      (request) async {
        final path = request.url.path;

        if (path.endsWith('/suggestions') && request.method == 'GET') {
          return _json({
            'data': [_row(id: 'a', status: 'new')],
          });
        }

        if (path.endsWith('/suggestions/a') && request.method == 'DELETE') {
          return _json({
            'message':
                'This one has been answered, so it stays as it was written. '
                'Send another if there is more to say.',
          }, 422);
        }

        return _json({'message': 'unexpected ${request.method} $path'}, 404);
      },
    );

    await tester.tap(find.widgetWithText(OutlinedButton, 'Withdraw'));
    await tester.pumpAndSettle();

    expect(
      find.text(
        'This one has been answered, so it stays as it was written. Send '
        'another if there is more to say.',
      ),
      findsOneWidget,
    );
  });
}

/// Scopes a text lookup to the rail — an unscoped `find.text` is the wrong
/// tool once the preselected suggestion's body is also on screen in the
/// detail pane. Same convention as `suggestions_queue_screen_test.dart`.
Finder _inRail(String text) => find.descendant(
  of: find.byKey(const Key('suggestions-rail')),
  matching: find.text(text),
);

Map<String, dynamic> _row({
  required String id,
  required String status,
  String body = 'Let the board remember its filter.',
  String? note,
}) => {
  'id': id,
  'body': body,
  'status': status,
  'decision_note': note,
  'decided_at': note == null ? null : '2026-09-02T11:00:00+00:00',
  'created_at': '2026-09-02T10:00:00+00:00',
};

MockClientHandler _listing(List<Map<String, dynamic>> rows) {
  return (http.Request request) async {
    if (request.url.path.endsWith('/suggestions') && request.method == 'GET') {
      return _json({'data': rows});
    }

    return _json({'message': 'unexpected ${request.method} ${request.url.path}'}, 404);
  };
}

http.Response _json(Map<String, dynamic> body, [int status = 200]) =>
    http.Response(
      jsonEncode(body),
      status,
      headers: {'content-type': 'application/json'},
    );

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
