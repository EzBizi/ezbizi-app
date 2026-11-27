import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/staff_session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/staff/suggestions_queue_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Development › Suggestions — the control-plane queue, §4 style, from the
/// staff side (plan Task 8). The author's own tab is `suggestions_screen.dart`;
/// this is the other half of the same table, read across every tenant.
///
/// Arrangement is `docs/previews/suggestions-queue-2.html` — a rail of
/// suggestions beside the full text of whichever one is open, with the answer
/// box in that pane's footer so the note is written with the suggestion still
/// on screen. Nothing here may pick a different arrangement; these tests only
/// check the behaviour the brief calls out as required regardless of it.
void main() {
  testWidgets('the queue names the organization and the author', (
    tester,
  ) async {
    await _pump(tester, _handler([_row(id: 'a')]));

    // Scoped to the rail rather than a bare `find.text`: the queue now opens
    // with the first suggestion preselected (see the next assertion), so
    // with only one row on screen its organization, author and body also
    // appear in the detail pane. An unscoped `findsOneWidget` would pass
    // even if the rail stopped rendering the row at all; scoped to the rail
    // it still fails the moment that row disappears from the list.
    expect(_inRail('A-1 Chimney'), findsOneWidget);
    expect(_inRail('Jose'), findsOneWidget);
    expect(_inRail('Let the board remember its filter.'), findsOneWidget);

    // The preselection itself: the pane shows the first suggestion's body
    // without anybody having clicked it, which is the entire point of the
    // arrangement layout 2 was chosen for.
    expect(
      find.descendant(
        of: find.byKey(const Key('suggestion-detail')),
        matching: find.text('Let the board remember its filter.'),
      ),
      findsOneWidget,
    );
  });

  // Jose, 2026-11-17: staff move an approved one along; the author sees it.
  testWidgets('an approved suggestion is moved along with its release', (
    tester,
  ) async {
    Map<String, dynamic>? sent;
    final base = _handler([
      {..._row(id: 'a', status: 'approved', note: 'Yes.'), 'progress': 'approved'},
    ]);
    await _pump(tester, (request) async {
      if (request.url.path.endsWith('/progress') && request.method == 'POST') {
        sent = jsonDecode(request.body) as Map<String, dynamic>;
        return _json({'data': <String, dynamic>{}});
      }
      return base(request);
    });

    await tester.tap(_inRail('A-1 Chimney'));
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('staff-suggestion-progress')), findsOneWidget);

    await tester.enterText(find.byKey(const ValueKey('progress-release')), '0.0.0584');
    await tester.tap(find.byKey(const ValueKey('progress-testing')));
    await tester.pumpAndSettle();

    expect(sent, {'progress': 'testing', 'release_version': '0.0.0584'});
  });

  testWidgets('status is shown in words, grouped by band', (tester) async {
    await _pump(
      tester,
      _handler([
        _row(id: 'a', status: 'new'),
        _row(
          id: 'b',
          status: 'reviewing',
          org: 'Bluepoint HVAC',
          author: 'Ray',
        ),
        _row(
          id: 'c',
          status: 'approved',
          org: 'Kearny Fireplace',
          author: 'Karen',
          note: 'Approved, thanks.',
        ),
        _row(
          id: 'd',
          status: 'denied',
          org: 'Summit Air',
          author: 'Angela',
          note: 'Not in that shape.',
        ),
      ]),
    );

    // Scoped to the rail: the first row ('a', status new) is preselected,
    // so its pill also renders in the detail header — an unscoped
    // `find.text('New')` would see two and fail for the wrong reason.
    // The raw key is never shown — each band and each row reads in words.
    expect(_inRail('Waiting'), findsOneWidget);
    expect(_inRail('Being read'), findsOneWidget);
    expect(_inRail('Answered'), findsOneWidget);

    expect(_inRail('New'), findsOneWidget);
    expect(_inRail('In review'), findsOneWidget);
    expect(_inRail('Approved'), findsOneWidget);
    expect(_inRail('Denied'), findsOneWidget);
  });

  testWidgets(
    'a new suggestion offers Mark in review, Approve and Deny, never New, '
    'and Deny stays refused until the note has content',
    (tester) async {
      await _pump(tester, _handler([_row(id: 'a')]));

      await tester.tap(_inRail('A-1 Chimney'));
      await tester.pumpAndSettle();

      expect(find.text('Mark in review'), findsOneWidget);
      expect(find.widgetWithText(FilledButton, 'Approve'), findsOneWidget);
      expect(find.widgetWithText(OutlinedButton, 'Deny'), findsOneWidget);

      // The server refuses `new` outright — there is no control offering it.
      expect(find.widgetWithText(FilledButton, 'New'), findsNothing);
      expect(find.widgetWithText(OutlinedButton, 'New'), findsNothing);

      final denyBefore = tester.widget<OutlinedButton>(
        find.widgetWithText(OutlinedButton, 'Deny'),
      );
      expect(
        denyBefore.onPressed,
        isNull,
        reason:
            'An empty note must not be a click away from a decision the '
            'server will refuse with a 422 — the button is the backstop, '
            'not the sentence.',
      );

      await tester.enterText(
        find.descendant(
          of: find.byKey(const Key('suggestion-detail')),
          matching: find.byType(TextField),
        ),
        'Say why, in a sentence.',
      );
      await tester.pumpAndSettle();

      final denyAfter = tester.widget<OutlinedButton>(
        find.widgetWithText(OutlinedButton, 'Deny'),
      );
      expect(denyAfter.onPressed, isNotNull);

      expect(
        find.textContaining('Nobody else at A-1 Chimney sees any of it.'),
        findsOneWidget,
      );
    },
  );

  testWidgets('denying sends the status and the sentence that was typed', (
    tester,
  ) async {
    Map<String, dynamic>? sent;

    await _pump(
      tester,
      _handler([_row(id: 'a')], onDecide: (_, body) => sent = body),
    );

    await tester.tap(_inRail('A-1 Chimney'));
    await tester.pumpAndSettle();

    await tester.enterText(
      find.descendant(
        of: find.byKey(const Key('suggestion-detail')),
        matching: find.byType(TextField),
      ),
      'Not in that shape, and here is why.',
    );
    await tester.pumpAndSettle();

    await tester.tap(find.widgetWithText(OutlinedButton, 'Deny'));
    await tester.pumpAndSettle();

    expect(sent, {
      'status': 'denied',
      'note': 'Not in that shape, and here is why.',
    });
  });

  testWidgets('approving needs no note at all', (tester) async {
    Map<String, dynamic>? sent;

    await _pump(
      tester,
      _handler([_row(id: 'a')], onDecide: (_, body) => sent = body),
    );

    await tester.tap(_inRail('A-1 Chimney'));
    await tester.pumpAndSettle();

    await tester.tap(find.widgetWithText(FilledButton, 'Approve'));
    await tester.pumpAndSettle();

    expect(sent, {'status': 'approved', 'note': null});
  });

  testWidgets(
    'marking in review sends no note, and is unavailable rather than silently '
    'throwing away one somebody typed',
    (tester) async {
      Map<String, dynamic>? sent;

      await _pump(
        tester,
        _handler([_row(id: 'a')], onDecide: (_, body) => sent = body),
      );

      await tester.tap(_inRail('A-1 Chimney'));
      await tester.pumpAndSettle();

      // A reviewer types a note and then reaches for "Mark in review". That
      // action is silent — no notification reaches the author — so nothing
      // typed may travel with it. The screen used to send the status and drop
      // the sentence with no message; now the action is simply not available
      // while there is something to drop, and says so.
      await tester.enterText(
        find.descendant(
          of: find.byKey(const Key('suggestion-detail')),
          matching: find.byType(TextField),
        ),
        'Good idea, scoping it for October.',
      );
      await tester.pumpAndSettle();

      final marking = tester.widget<OutlinedButton>(
        find.widgetWithText(OutlinedButton, 'Mark in review'),
      );

      expect(
        marking.onPressed,
        isNull,
        reason:
            'A note is in the box and this action sends none, so pressing it '
            'would discard what somebody wrote without a word.',
      );

      expect(
        find.textContaining('sends no note'),
        findsOneWidget,
        reason: 'The reason the action is unavailable is not on screen.',
      );

      await tester.tap(find.text('Mark in review'));
      await tester.pumpAndSettle();

      expect(sent, isNull, reason: 'A disabled control sent a decision.');

      // Clearing the box makes it available again, and it still sends nothing.
      await tester.enterText(
        find.descendant(
          of: find.byKey(const Key('suggestion-detail')),
          matching: find.byType(TextField),
        ),
        '',
      );
      await tester.pumpAndSettle();

      await tester.tap(find.text('Mark in review'));
      await tester.pumpAndSettle();

      expect(sent, {'status': 'reviewing', 'note': null});
    },
  );

  testWidgets('a refused decide shows the server\'s own sentence', (
    tester,
  ) async {
    await _pump(
      tester,
      _handler(
        [_row(id: 'a')],
        refuseWith: 'Say why, in a sentence. The person who sent it reads it.',
      ),
    );

    await tester.tap(_inRail('A-1 Chimney'));
    await tester.pumpAndSettle();

    await tester.enterText(
      find.descendant(
        of: find.byKey(const Key('suggestion-detail')),
        matching: find.byType(TextField),
      ),
      'x',
    );
    await tester.pumpAndSettle();

    await tester.tap(find.widgetWithText(OutlinedButton, 'Deny'));
    await tester.pumpAndSettle();

    expect(
      find.text('Say why, in a sentence. The person who sent it reads it.'),
      findsOneWidget,
    );
  });

  testWidgets(
    'opening an already-decided suggestion offers to change the answer, '
    'prefilled with what was said',
    (tester) async {
      await _pump(
        tester,
        _handler([
          _row(
            id: 'a',
            status: 'approved',
            note: 'Approved — it ships with the next build.',
          ),
        ]),
      );

      await tester.tap(_inRail('A-1 Chimney'));
      await tester.pumpAndSettle();

      expect(find.text('Change the answer'), findsOneWidget);
      expect(
        find.widgetWithText(FilledButton, 'Approve instead'),
        findsOneWidget,
      );
      expect(
        find.widgetWithText(OutlinedButton, 'Deny instead'),
        findsOneWidget,
      );

      // Nothing left to mark in review once it has been decided.
      expect(find.text('Mark in review'), findsNothing);

      // The note box, last: an approved one also has the Release box.
      final field = tester.widget<TextField>(
        find
            .descendant(
              of: find.byKey(const Key('suggestion-detail')),
              matching: find.byType(TextField),
            )
            .last,
      );
      expect(
        field.controller?.text,
        'Approved — it ships with the next build.',
      );
    },
  );
}

/// Copy of the real shape in client_versions_screen_test.dart and
/// staff_phone_requests_screen_test.dart. `StaffMe.fromJson` reads `id`,
/// `name`, `email` and `tier` with a non-nullable cast, so a smaller stub
/// throws while adopting the token rather than merely failing to sign in —
/// this is the shape that actually produces a session.
const _me = {
  'id': 'staff-1',
  'name': 'Owner',
  'email': 'owner@ezbizi.test',
  'tier': 'lead_developer',
  'may_enter_without_approval': true,
  'recovery_code_count': 8,
};

/// Scopes a text lookup to the rail — see the class comment on why an
/// unscoped `find.text` is the wrong tool once the first suggestion opens on
/// its own.
Finder _inRail(String text) => find.descendant(
  of: find.byKey(const Key('suggestions-rail')),
  matching: find.text(text),
);

Map<String, dynamic> _row({
  required String id,
  String status = 'new',
  String org = 'A-1 Chimney',
  String author = 'Jose',
  String body = 'Let the board remember its filter.',
  String? note,
}) => {
  'id': id,
  'organization': org,
  'organization_id': 'org-$id',
  'author': author,
  'body': body,
  'status': status,
  'decision_note': note,
  'decided_at': note == null ? null : '2026-09-01T10:00:00+00:00',
  'created_at': '2026-09-02T10:00:00+00:00',
};

MockClientHandler _handler(
  List<Map<String, dynamic>> rows, {
  void Function(String id, Map<String, dynamic> body)? onDecide,
  String? refuseWith,
}) {
  return (http.Request request) async {
    final path = request.url.path;

    if (path.endsWith('/staff/me')) return _json({'data': _me});

    if (path.endsWith('/staff/suggestions') && request.method == 'GET') {
      return _json({'data': rows});
    }

    if (path.contains('/staff/suggestions/') &&
        path.endsWith('/decide') &&
        request.method == 'POST') {
      final id = path.split('/')[path.split('/').length - 2];
      final body = jsonDecode(request.body) as Map<String, dynamic>;
      onDecide?.call(id, body);

      if (refuseWith != null) {
        return _json({
          'message': 'The given data was invalid.',
          'errors': {
            'note': [refuseWith],
          },
        }, 422);
      }

      return _json({
        'data': {
          ...rows.firstWhere((r) => r['id'] == id),
          'status': body['status'],
          'decision_note': body['note'],
          'decided_at': '2026-09-02T11:00:00+00:00',
        },
      });
    }

    return _json({'message': 'unexpected ${request.method} $path'}, 404);
  };
}

http.Response _json(Map<String, dynamic> body, [int status = 200]) =>
    http.Response(
      jsonEncode(body),
      status,
      headers: {'content-type': 'application/json'},
    );

Future<void> _pump(WidgetTester tester, MockClientHandler handler) async {
  tester.view.physicalSize = const Size(1600, 2200);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(client: MockClient(handler));
  final staff = StaffSession(api);

  await tester.runAsync(() => staff.adopt('tok'));

  expect(
    staff.signedIn,
    isTrue,
    reason: 'The /staff/me stub did not produce a session.',
  );

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(body: SuggestionsQueueScreen(staff: staff)),
    ),
  );
  await tester.pumpAndSettle();
}
