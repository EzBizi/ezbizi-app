import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/deep_links.dart';
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

  /// `GET /suggestions` is `Suggestion::query()->latest('created_at')` —
  /// newest-first, never by status — and the Waiting/Answered bands are
  /// this client's own presentation grouping, not a re-sort. Both fixtures
  /// here are in the SAME band (`new` and `reviewing` both land in
  /// "Waiting") with distinguishable `created_at` values, so the server's
  /// order is actually observable: if somebody adds a
  /// `waiting.sort(...)` to "group the new ones first" (or any other
  /// reordering) inside that band, this fails even though every
  /// `findsOneWidget` assertion elsewhere on this screen still passes.
  testWidgets('within a band, the rail preserves the server\'s own order', (
    tester,
  ) async {
    await _pump(
      tester,
      _listing([
        _row(
          id: 'newer',
          status: 'new',
          body: 'The server put this one first.',
          createdAt: '2026-09-02T10:00:00+00:00',
        ),
        _row(
          id: 'older',
          status: 'reviewing',
          body: 'The server put this one second.',
          createdAt: '2026-08-30T10:00:00+00:00',
        ),
      ]),
    );

    final rail = find.byKey(const Key('suggestions-rail'));
    final texts = tester
        .widgetList<Text>(find.descendant(of: rail, matching: find.byType(Text)))
        .map((t) => t.data)
        .whereType<String>()
        .toList();

    final newerIndex = texts.indexOf('The server put this one first.');
    final olderIndex = texts.indexOf('The server put this one second.');

    expect(newerIndex, isNot(-1));
    expect(olderIndex, isNot(-1));
    expect(
      newerIndex,
      lessThan(olderIndex),
      reason:
          'The rail must render suggestions in the order the server sent '
          'them (newest first) within a band, not re-sorted by anything '
          'client-side.',
    );
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

  // Bar, 2026-10-12: a tapped notification opens "the exact thing". The
  // distinguishing case: the linked suggestion is NOT the newest, which is
  // what would be preselected anyway.
  testWidgets('a notification about a suggestion opens that one, not the newest', (
    tester,
  ) async {
    addTearDown(() => DeepLinks.pending.value = null);
    DeepLinks.open(const DeepLink(module: 'suggestions', type: 'suggestion', id: 'b'));

    await _pump(
      tester,
      _listing([
        _row(id: 'a', status: 'new', body: 'Let the board remember its filter.'),
        _row(id: 'b', status: 'approved', body: 'Print a route sheet.'),
      ]),
    );

    expect(
      find.descendant(
        of: find.byKey(const Key('suggestion-detail')),
        matching: find.text('Print a route sheet.'),
      ),
      findsOneWidget,
    );
    expect(DeepLinks.pending.value, isNull, reason: 'taken, so a rebuild cannot reopen it');
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

  // Jose, 2026-11-17: "so people know their stuff is being implemented".
  testWidgets('an approved suggestion shows how far along it is; a denied one does not', (
    tester,
  ) async {
    await _pump(tester, _listing([
      {..._row(id: 'a', status: 'approved', note: 'Yes.'), 'progress': 'building'},
      _row(id: 'b', status: 'denied', note: 'No.'),
    ]));

    expect(find.byKey(const ValueKey('suggestion-progress-a')), findsWidgets);
    expect(find.text('Being built · step 2 of 5'), findsWidgets);
    expect(find.byKey(const ValueKey('suggestion-progress-b')), findsNothing);
  });

  testWidgets('releasing in the release on its way shows its live percent', (
    tester,
  ) async {
    final rows = [
      {
        ..._row(id: 'a', status: 'approved', note: 'Yes.'),
        'progress': 'releasing',
        'release_version': '0.0.0584',
      },
    ];
    await _pump(tester, (request) async {
      if (request.url.path.endsWith('/release/upcoming')) {
        return _json({'data': {'version': '0.0.0584', 'percent': 40}});
      }
      return _listing(rows)(request);
    });

    expect(find.text('v0.0.0584 is on its way · 40%'), findsWidgets);

    await tester.pumpWidget(const SizedBox()); // stops the percent poll
  });

  testWidgets('live names its release', (tester) async {
    await _pump(tester, _listing([
      {
        ..._row(id: 'a', status: 'approved', note: 'Yes.'),
        'progress': 'live',
        'release_version': '0.0.0584',
      },
    ]));

    expect(find.text('Live in v0.0.0584'), findsWidgets);
    expect(find.textContaining('step'), findsNothing);
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
    // its own, and it appears twice — once in the rail row, once in the
    // detail pane's reply callout — so this is scoped to each rather than
    // an unscoped `findsOneWidget` that two correct copies would fail.
    final answeredStamp = 'EzBizi answered · ${shown('2026-09-02T11:00:00+00:00')}';
    expect(
      find.descendant(
        of: find.byKey(const Key('suggestions-rail')),
        matching: find.text(answeredStamp),
      ),
      findsOneWidget,
      reason:
          'The rail row\'s answered stamp ignores the organization\'s date '
          'and time format.',
    );
    expect(
      find.descendant(
        of: find.byKey(const Key('suggestion-detail')),
        matching: find.text(answeredStamp),
      ),
      findsOneWidget,
      reason:
          'The detail pane\'s answered stamp ignores the organization\'s '
          'date and time format.',
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
      find.byType(TextField).last,
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

    await tester.enterText(find.byType(TextField).last, 'x');
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

    await tester.enterText(find.byType(TextField).last, 'Changed my mind.');
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

  // Narrow layout (below `_splitAbove`, 860): every test above this point
  // pumps at 1600x2200 and so never reaches `_narrowList`, `_narrowSegment`,
  // `_openNarrow`, the back button, or the "Nothing in this one yet."
  // branch — zero coverage for the entire phone-width layout. This group
  // is that coverage.
  group('narrow layout (375px)', () {
    const narrowSize = Size(375, 800);

    /// The regression this whole fix round exists for: layout 1 was
    /// compose-first at every width, so a first-time phone visitor got the
    /// box immediately. `_openNarrow` used to stay `false` on a first visit
    /// (nothing set it), so `_narrow` rendered `_narrowList` — "Nothing
    /// here yet." behind a Write-a-suggestion button — instead of the hero
    /// and compose box. Pinned here at phone width so that regression
    /// cannot return silently.
    testWidgets(
      'a first-time phone visitor sees the compose box, not "Nothing here yet."',
      (tester) async {
        await _pump(tester, _listing([]), size: narrowSize);

        expect(find.text('Tell EzBizi what to do differently'), findsOneWidget);
        expect(
          find.text('What should EzBizi do differently?'),
          findsOneWidget,
        );
        expect(
          find.text('Nothing here yet.'),
          findsNothing,
          reason:
              'A brand-new phone visitor must open on the compose box, not '
              'the empty rail list behind it.',
        );
        // Nothing sent yet: an arrow here led back to an empty list.
        expect(find.byTooltip('Back to the list'), findsNothing);
      },
    );

    /// The trap `Key('suggestions-rail')` used to be reused for the narrow
    /// list, which shows only one band at a time — a future `_inRail`-style
    /// finder written at a narrow width would quietly miss whichever band
    /// is not currently selected. This asserts the narrow list carries its
    /// own key and that switching the segmented control actually changes
    /// what it shows.
    testWidgets(
      'the narrow list has its own key and the segmented control switches bands',
      (tester) async {
        await _pump(
          tester,
          _listing([
            _row(id: 'a', status: 'new', body: 'Waiting-band suggestion.'),
            _row(
              id: 'b',
              status: 'approved',
              body: 'Answered-band suggestion.',
              note: 'Shipped.',
            ),
          ]),
          size: narrowSize,
        );

        // A fresh load with rows present opens on the list at narrow width
        // (nothing has set `_openNarrow` yet — only tapping a row does), so
        // the narrow list is already what is on screen.
        expect(find.byKey(const Key('suggestions-rail')), findsNothing);
        final narrowList = find.byKey(const Key('suggestions-narrow-list'));
        expect(narrowList, findsOneWidget);

        // Defaults to the Waiting segment.
        expect(
          find.descendant(
            of: narrowList,
            matching: find.text('Waiting-band suggestion.'),
          ),
          findsOneWidget,
        );
        expect(
          find.descendant(
            of: narrowList,
            matching: find.text('Answered-band suggestion.'),
          ),
          findsNothing,
        );

        await tester.tap(find.text('Answered · 1'));
        await tester.pumpAndSettle();

        expect(
          find.descendant(
            of: find.byKey(const Key('suggestions-narrow-list')),
            matching: find.text('Answered-band suggestion.'),
          ),
          findsOneWidget,
        );
        expect(
          find.descendant(
            of: find.byKey(const Key('suggestions-narrow-list')),
            matching: find.text('Waiting-band suggestion.'),
          ),
          findsNothing,
        );
      },
    );

    testWidgets('the back button returns from the detail pane to the list', (
      tester,
    ) async {
      await _pump(
        tester,
        _listing([_row(id: 'a', status: 'new', body: 'A phone-width suggestion.')]),
        size: narrowSize,
      );

      // A fresh load opens on the list at narrow width; tapping a row is
      // what switches to the detail pane (`_select` sets `_openNarrow`).
      expect(find.byKey(const Key('suggestion-detail')), findsNothing);

      await tester.tap(find.text('A phone-width suggestion.'));
      await tester.pumpAndSettle();

      expect(find.byKey(const Key('suggestion-detail')), findsOneWidget);

      await tester.tap(find.byIcon(Icons.arrow_back));
      await tester.pumpAndSettle();

      expect(find.byKey(const Key('suggestion-detail')), findsNothing);
      expect(find.byKey(const Key('suggestions-narrow-list')), findsOneWidget);
    });

    testWidgets(
      '"Nothing in this one yet." shows for an empty band without emptying the other',
      (tester) async {
        await _pump(
          tester,
          _listing([_row(id: 'a', status: 'new', body: 'Only a waiting one.')]),
          size: narrowSize,
        );

        await tester.tap(find.text('Answered · 0'));
        await tester.pumpAndSettle();

        expect(find.text('Nothing in this one yet.'), findsOneWidget);
      },
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
  String createdAt = '2026-09-02T10:00:00+00:00',
}) => {
  'id': id,
  'body': body,
  'status': status,
  'decision_note': note,
  'decided_at': note == null ? null : '2026-09-02T11:00:00+00:00',
  'created_at': createdAt,
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
///
/// [size] defaults to a width above `_splitAbove` (860) so most of this
/// file exercises the wide rail-plus-detail layout. Pass a narrow [size] to
/// reach `_narrowList`/`_openNarrow` instead — see the "narrow layout"
/// group below, which is the only coverage those branches have.
Future<void> _pump(
  WidgetTester tester,
  MockClientHandler handler, {
  DisplayFormats formats = const DisplayFormats(
    dateFormat: 'M/D/YYYY',
    timeFormat: '12h',
  ),
  Size size = const Size(1600, 2200),
}) async {
  tester.view.physicalSize = size;
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
