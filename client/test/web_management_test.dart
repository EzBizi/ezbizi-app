import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/main.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Web Management: the tab opens the SEO panel, and the panel obeys the rules.
///
/// Web Management left the coming-soon set on 2026-08-28, so the two tests that
/// asserted it drew a placeholder moved here and became tests of what it draws
/// instead. The layout test for the placeholder widget itself stayed behind in
/// coming_soon_tabs_test.dart, because that widget is still the answer for the
/// next module granted before it is built.
///
/// Two of these are about house rules rather than about websites: §6 forbids
/// pop-ups, and §3 says a screen files on the VIEW rather than on reach. Both
/// are the kind of rule that passes review by inspection and then regresses, so
/// each has a test that fails when it does.
void main() {
  testWidgets('the tab opens the SEO panel and lists the company websites', (
    tester,
  ) async {
    await _pumpDestination(tester, permissions: ['web_management.view']);

    expect(find.text('SEO'), findsOneWidget);
    expect(find.text('Websites'), findsOneWidget);
    expect(find.text('a-1chimney.com'), findsOneWidget);
    expect(find.text('nj.a-1chimney.com'), findsOneWidget);
  });

  testWidgets('a subdomain is listed as its own website, connected or not', (
    tester,
  ) async {
    await _pumpDestination(tester, permissions: ['web_management.view']);

    expect(find.textContaining('Connected'), findsOneWidget);
    expect(find.textContaining('Waiting for the plugin'), findsOneWidget);
  });

  testWidgets('view alone cannot add a website', (tester) async {
    await _pumpDestination(tester, permissions: ['web_management.view']);

    expect(find.text('Add a website'), findsNothing);
  });

  testWidgets('opening a website shows its fields and their shortcodes', (
    tester,
  ) async {
    await _pumpDestination(
      tester,
      permissions: ['web_management.view', 'web_management.edit'],
    );

    await tester.tap(find.text('a-1chimney.com'));
    await tester.pumpAndSettle();

    expect(find.text('Phone number'), findsOneWidget);
    expect(find.textContaining('[ezbizi_phone]'), findsOneWidget);
  });

  /*
   * The first-run deadlock, from the client's side.
   *
   * PHP encodes an empty associative array as `[]`, so a site with no values
   * answered with `"values":[]` where a populated one answered `{...}`. This
   * screen cast that field to a Map, the cast threw, and the throw escaped
   * before the loading flag was cleared — so the panel span forever. You could
   * not open it until the site had values and could not add values without
   * opening it. Every newly registered site hit it.
   *
   * The fixture above had always returned a POPULATED map, which is why the
   * suite was green while the feature was unusable. This serves the shape the
   * old server really sent.
   */
  testWidgets('a website with no values yet still opens', (tester) async {
    await _pumpDestination(
      tester,
      permissions: ['web_management.view', 'web_management.edit'],
      emptyValues: true,
    );

    await tester.tap(find.text('a-1chimney.com'));
    await tester.pumpAndSettle();

    // The panel is open and usable rather than spinning.
    expect(find.byType(CircularProgressIndicator), findsNothing);
    expect(find.text('Phone number'), findsOneWidget);
  });

  /// The panel must SHOW the browser key, and must not let it be confused with
  /// the plugin token.
  ///
  /// This test exists because of a real incident: the public key shipped in the
  /// API with no UI behind it, so the only credential this screen displayed was
  /// the secret `ezb_` token. Asked for the browser key, the only reasonable
  /// thing to hand over was the token — which is a screen defect, not a user
  /// mistake, and it put a live secret somewhere it should never have gone.
  testWidgets('a site offers its browser key, distinguished from the token', (
    tester,
  ) async {
    await _pumpDestination(
      tester,
      permissions: ['web_management.view', 'web_management.edit'],
    );

    await tester.tap(find.text('a-1chimney.com'));
    await tester.pumpAndSettle();

    expect(find.textContaining('pk_fixture_browser_key'), findsWidgets);

    // The snippet points at the route that is actually served — public/ is not
    // a web root on this host, so /ezbizi.js at the domain root is not it.
    expect(find.textContaining('/api/v1/web/ezbizi.js'), findsOneWidget);

    // And it says which key is safe in public, because that is the whole
    // distinction somebody has to get right.
    expect(find.textContaining('ezb_'), findsOneWidget);
  });

  /// The browser key must be rotatable FROM THE SCREEN, not only from the API.
  ///
  /// The doc promised the public key "rotates independently" before any
  /// endpoint existed; the endpoint was then built and still had no control
  /// behind it. Same defect, one layer up — a capability nobody can reach is
  /// indistinguishable from one that does not exist.
  testWidgets('a browser key can be reissued, inline and never in a dialog', (
    tester,
  ) async {
    await _pumpDestination(
      tester,
      permissions: ['web_management.view', 'web_management.edit'],
    );

    await tester.tap(find.text('a-1chimney.com'));
    await tester.pumpAndSettle();

    await tester.tap(find.text('New key'));
    await tester.pumpAndSettle();

    expect(find.byType(Dialog), findsNothing);
    expect(find.byType(AlertDialog), findsNothing);
    expect(find.textContaining('Issue a new browser key?'), findsOneWidget);
    expect(find.text('Cancel'), findsOneWidget);
  });

  testWidgets('view alone cannot reissue a browser key', (tester) async {
    await _pumpDestination(tester, permissions: ['web_management.view']);

    await tester.tap(find.text('a-1chimney.com'));
    await tester.pumpAndSettle();

    expect(find.text('New key'), findsNothing);
  });

  // §6 — no pop-ups, ever. Adding is a form in place, not a dialog.
  testWidgets('adding a website opens a form in place and never a dialog', (
    tester,
  ) async {
    await _pumpDestination(
      tester,
      permissions: ['web_management.view', 'web_management.edit'],
    );

    await tester.tap(find.text('Add a website'));
    await tester.pumpAndSettle();

    expect(find.byType(Dialog), findsNothing);
    expect(find.byType(AlertDialog), findsNothing);
    expect(find.text('Website address'), findsOneWidget);
  });

  // §6 again — removing asks in a row inside the card, not in a dialog.
  testWidgets('removing a website confirms inline and never in a dialog', (
    tester,
  ) async {
    await _pumpDestination(
      tester,
      permissions: ['web_management.view', 'web_management.edit'],
    );

    await tester.tap(find.text('a-1chimney.com'));
    await tester.pumpAndSettle();

    await tester.tap(find.text('Remove website'));
    await tester.pumpAndSettle();

    expect(find.byType(Dialog), findsNothing);
    expect(find.byType(AlertDialog), findsNothing);
    expect(find.textContaining('Remove a-1chimney.com?'), findsOneWidget);
  });

  /// §3 — file on the view, not on reach.
  ///
  /// The `/me` payload here really does carry a second company the person can
  /// reach, and the view really is scoped to the first. The load-bearing part
  /// is that the sibling is PRESENT and still never drawn: a fixture with one
  /// company would pass this test while the screen looped over raw reach.
  testWidgets('a reachable sibling company is not offered under a company view', (
    tester,
  ) async {
    await _pumpDestination(
      tester,
      permissions: ['web_management.view', 'web_management.edit'],
      scopedToCompany: true,
    );

    await tester.tap(find.text('Add a website'));
    await tester.pumpAndSettle();

    // The form really did open — without this, the two findsNothing below
    // would pass just as happily against a screen that rendered nothing at all.
    expect(find.text('Website address'), findsOneWidget);

    expect(find.text('Everdoors Roofing'), findsNothing);
    // One company in view leaves no choice to offer, so no picker is drawn.
    expect(find.text('Company'), findsNothing);
  });
}

Future<void> _pumpDestination(
  WidgetTester tester, {
  required List<String> permissions,
  bool scopedToCompany = false,
  bool emptyValues = false,
}) async {
  tester.view.physicalSize = const Size(1280, 900);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(
    client: MockClient(_respond(permissions, scopedToCompany, emptyValues)),
  );
  final session = Session(api);

  await tester.runAsync(() => session.adopt('tok'));

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: ListenableBuilder(
        listenable: session,
        builder: (_, _) => HomeShell(api: api, session: session),
      ),
    ),
  );
  await tester.pumpAndSettle();

  await tester.tap(
    find.descendant(
      of: find.byType(NavigationSidebar),
      matching: find.text('Web Management'),
    ),
  );
  await tester.pumpAndSettle();
}

Future<http.Response> Function(http.Request) _respond(
  List<String> permissions,
  bool scopedToCompany,
  bool emptyValues,
) {
  return (request) async {
    Map<String, Object?>? body;

    /*
     * Matched on the WHOLE path, not on a suffix. A suffix match passed
     * happily while the screen was asking for `/api/v1/api/v1/web/sites` —
     * ApiClient already prefixes `/api/v1`, and every path in this module was
     * doubled. A mock that cannot tell those apart is not a test of anything.
     */
    final path = request.url.path;

    if (path == '/api/v1/me') {
      body = {'data': _me(permissions, scopedToCompany)};
    } else if (path == '/api/v1/web/sites') {
      body = {'data': _sites};
    } else if (RegExp(r'^/api/v1/web/sites/[^/]+/values$').hasMatch(path)) {
      body = {
        'data': {
          'fields': [
            {
              'key': 'phone',
              'label': 'Phone number',
              'hint': 'The number this site should show.',
              'shortcode': '[ezbizi_phone]',
            },
          ],
          // An empty map arrives from PHP as `[]`, not `{}` — the shape that
          // used to deadlock this panel. Serving the real thing is the only
          // way this test means anything.
          'values': emptyValues ? <Object?>[] : {'phone': '555-0100'},
          'pages': emptyValues ? <Object?>[] : <String, Object?>{},
        },
      };
    }

    return http.Response(
      jsonEncode(body ?? {'message': 'Unexpected $path'}),
      body == null ? 500 : 200,
      headers: {'content-type': 'application/json'},
    );
  };
}

const List<Map<String, Object?>> _sites = [
  {
    'id': 'site-1',
    'host': 'a-1chimney.com',
    'label': null,
    'company': {'id': 'co-1', 'name': 'A-1 Chimney'},
    'connected_at': '2026-08-28T10:00:00+00:00',
    'last_seen_at': '2026-08-28T11:00:00+00:00',
    'plugin_version': '1.0.0',
    'public_key': 'pk_fixture_browser_key',
  },
  {
    'id': 'site-2',
    'host': 'nj.a-1chimney.com',
    'label': 'New Jersey',
    'company': {'id': 'co-1', 'name': 'A-1 Chimney'},
    'connected_at': null,
    'last_seen_at': null,
    'plugin_version': null,
    'public_key': 'pk_fixture_branch_key',
  },
];

Map<String, Object?> _me(List<String> permissions, bool scopedToCompany) => {
  'id': 'u-1',
  'name': 'Jose Silva',
  'email': 'jose@example.com',
  'has_avatar': false,
  'role': {'id': 'role-1', 'name': 'Administrator'},
  'permissions': permissions,
  'modules': [
    {
      'key': 'web_management',
      'label': 'Web Management',
      'available': true,
      'reachable': true,
    },
  ],
  'preferences': <String, dynamic>{},
  'organization': {
    'id': 'org-1',
    'name': 'A-1 Chimney Group',
    'franchise_label': 'Franchise',
    'is_read_only': false,
  },
  'scope': scopedToCompany
      ? {
          'type': 'company',
          'id': 'co-1',
          'label': 'A-1 Chimney',
          'path': ['A-1 Chimney Group', 'A-1 Chimney'],
        }
      : {
          'type': 'organization',
          'id': 'org-1',
          'label': 'A-1 Chimney Group',
          'path': ['A-1 Chimney Group'],
        },
  // Two top-level companies, both reachable. The view names one of them.
  'companies': [
    {'id': 'co-1', 'name': 'A-1 Chimney', 'parent_company_id': null},
    {'id': 'co-2', 'name': 'Everdoors Roofing', 'parent_company_id': null},
  ],
  'franchises': <Map<String, Object?>>[],
};
