import 'dart:async';
import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/customers_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// A newer load must win, and an older answer must not undo it.
///
/// ── Why this file exists ─────────────────────────────────────────
///
/// Making a refresh stop blanking the screen had a consequence nobody planned
/// for: the controls that trigger a refresh used to be hidden behind the
/// spinner, so only one request could ever be in flight. Once the content stays
/// up, two can.
///
/// The first attempt at that guarded it with a plain `if (_fetching) return;`,
/// which fixed duplicate rows and introduced something worse — a page-one load
/// arriving while another was open was DROPPED, permanently. Clearing the search
/// box mid-search left the box empty and the list still filtered, with nothing
/// scheduled to correct it. The screen simply lied until you navigated away.
///
/// Both halves are pinned here, because they pull in opposite directions and a
/// future fix for one is exactly how the other comes back.
void main() {
  testWidgets('clearing a search mid-request wins, rather than being dropped', (
    tester,
  ) async {
    tester.view.physicalSize = const Size(1280, 900);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);

    final searches = <String>[];

    // The search request is held open so the clear happens while it is still in
    // flight — the exact window in which the load used to be discarded.
    final held = Completer<void>();

    await _pump(tester, (request) async {
      if (request.url.path.endsWith('/me')) return _json({'data': _me});
      // The tag list (UI queue 174) is its own request, not a list load.
      if (request.url.path.endsWith('/customers/tags')) {
        return _json({'data': const []});
      }
      // The live feed's own read, not a list load.
      if (request.url.path.endsWith('/changes')) {
        return _json({
          'data': {'cursor': 1, 'reload': false, 'changes': const []},
        });
      }

      final term = request.url.queryParameters['search'] ?? '';
      searches.add(term);

      if (term == 'smith') {
        return held.future.then(
          (_) => _json({
            'data': [_customer('Smith')],
            'meta': {'total': 1, 'last_page': 1},
          }),
        );
      }

      return _json({
        'data': [_customer('Everyone')],
        'meta': {'total': 1, 'last_page': 1},
      });
    });

    expect(find.text('Everyone'), findsOneWidget);

    await tester.enterText(find.byType(TextField).first, 'smith');
    await tester.testTextInput.receiveAction(TextInputAction.done);
    await tester.pump();

    // Still open. Now clear it, which is what a person does when they change
    // their mind before the results arrive.
    await tester.tap(find.byIcon(Icons.clear));
    await tester.pump();

    held.complete();
    await tester.pumpAndSettle();

    expect(
      searches,
      ['', 'smith', ''],
      reason: 'The clear was dropped instead of superseding the search.',
    );

    // And the slower answer must not overwrite the newer one.
    expect(find.text('Everyone'), findsOneWidget);
    expect(
      find.text('Smith'),
      findsNothing,
      reason: 'A superseded response was applied on top of the newer one.',
    );
  });

  testWidgets('a second Load more while one is open does not double the rows', (
    tester,
  ) async {
    tester.view.physicalSize = const Size(1280, 900);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);

    var pageTwoRequests = 0;
    final held = Completer<void>();

    await _pump(tester, (request) async {
      if (request.url.path.endsWith('/me')) return _json({'data': _me});

      if (request.url.queryParameters['page'] == '2') {
        pageTwoRequests++;
        return held.future.then(
          (_) => _json({
            'data': [_customer('Second page')],
            'meta': {'total': 2, 'last_page': 2},
          }),
        );
      }

      return _json({
        'data': [_customer('First page')],
        'meta': {'total': 2, 'last_page': 2},
      });
    });

    final more = find.textContaining('Load more');
    expect(more, findsOneWidget);

    await tester.tap(more);
    await tester.pump();

    // The control is disabled while the append is open, so this cannot land —
    // but assert on the request count rather than on the button, because it is
    // the duplicate REQUEST that duplicates the rows.
    await tester.tap(find.textContaining('Loading'), warnIfMissed: false);
    await tester.pump();

    held.complete();
    await tester.pumpAndSettle();

    expect(pageTwoRequests, 1, reason: 'Page two was appended twice.');
    expect(find.text('Second page'), findsOneWidget);
  });
}

/// `/exports` is answered HERE rather than being passed down to [handler].
///
/// The Customers tab asks it once, on the way to drawing its Export and Import
/// controls — those moved into this tab when the "Import & export" destination
/// was removed. Both tests below reason about the requests this screen makes
/// for its ROWS: one records the search term of every GET, the other counts the
/// page-two fetches. A second endpoint arriving in either counter would make
/// those numbers about something else entirely, and the first test failed
/// exactly that way when the controls landed.
Future<void> _pump(WidgetTester tester, MockClientHandler handler) async {
  final api = ApiClient(
    client: MockClient((request) async {
      if (request.url.path.endsWith('/exports')) {
        return _json({
          'resources': [
            {
              'key': 'customers',
              'label': 'Customers',
              'columns': ['number', 'name'],
              'importable': true,
              'import_permission': 'customers.create',
              'import_columns': ['number', 'name'],
            },
          ],
          'organization': {
            'export_refusal': null,
            'inventory_refusal': null,
          },
        });
      }

      return handler(request);
    }),
  );
  final session = Session(api);

  await tester.runAsync(() => session.adopt('tok'));

  expect(
    session.signedIn,
    isTrue,
    reason: 'The /me stub did not produce a session.',
  );

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(body: CustomersScreen(api: api, session: session)),
    ),
  );
  await tester.pumpAndSettle();
}

http.Response _json(Object body) => http.Response(
  jsonEncode(body),
  200,
  headers: {'content-type': 'application/json'},
);

Map<String, dynamic> _customer(String name) => {
  'id': 'c-$name',
  'name': name,
  'number': 'C-1',
  'email': null,
  'phone': null,
  'is_active': true,
  'owning_franchise': {'id': 'fr-1', 'name': 'Connecticut'},
};

const _me = {
  'id': 'u-1',
  'name': 'Jose',
  'email': 'jose@example.com',
  'has_avatar': false,
  'role': {'id': 'r-1', 'name': 'Administrator'},
  'theme': 'light',
  'navigation': {'style': 'list', 'collapsed': false, 'width': 220},
  'organization': {
    'id': 'org-1',
    'name': 'A-1 Chimney',
    'franchise_label': 'Franchise',
    'has_logo': false,
    'is_read_only': false,
  },
  'companies': [
    {
      'id': 'co-1',
      'name': 'A-1 Chimney',
      'parent_company_id': null,
      'has_logo': false,
    },
  ],
  'scope': {
    'type': 'organization',
    'id': 'org-1',
    'label': 'A-1 Chimney',
    'path': ['A-1 Chimney'],
  },
  'franchises': [
    {'id': 'fr-1', 'name': 'Connecticut', 'company_id': 'co-1', 'is_active': true},
  ],
};
