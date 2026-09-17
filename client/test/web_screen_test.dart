import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/web_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Web Management's tab strip, and who sees which half of it.
///
/// Jose, 2026-09-17: *"i should be able to grant access by sub tab as well ...
/// so this applies to every tab and it's sub tabs"*. The two tabs became
/// `web_management.seo.view` and `web_management.widgets.view`.
///
/// ── What is worth asserting here, and what is not ────────────────
///
/// The SERVER refusing a tab's endpoints is pinned in
/// `tests/Feature/Web/WebTabPermissionsTest.php`, because a hidden tab whose
/// URL still answers is a decoration rather than a permission. This file
/// asserts only the half that lives here: that the strip draws what the role
/// holds, that the body follows the strip, and that a role holding the module
/// and neither tab is told which grant is missing rather than shown a blank
/// rectangle.
void main() {
  Future<void> pump(WidgetTester tester, List<String> permissions) async {
    final client = MockClient((request) async {
      // Matched on the WHOLE path, the way the other web suites do: a suffix
      // match once let a typo'd path fall through to a fixture that happened
      // to end the same way.
      final path = request.url.path;
      Map<String, Object?> body;

      if (path == '/api/v1/me') {
        body = {
          'data': {
            'id': 'u-1',
            'name': 'Jose Silva',
            'email': 'jose@example.com',
            'has_avatar': false,
            'role': {'id': 'role-1', 'name': 'Web Person'},
            'permissions': permissions,
            'modules': [
              {
                'key': 'web_management',
                'label': 'Web Management',
                'available': true,
                'reachable': true,
              },
            ],
            'preferences': const <String, Object?>{},
            'organization': {
              'id': 'org-1',
              'name': 'A-1 Chimney Group',
              'franchise_label': 'Franchise',
              'is_read_only': false,
            },
            'scope': null,
          },
        };
      } else if (path == '/api/v1/web/sites') {
        body = {
          'data': [
            {
              'id': 'site-1',
              'host': 'a-1chimney.com',
              'label': null,
              'is_primary': true,
              'public_key': 'pk_x',
              'company': {'id': 'c1', 'name': 'A-1'},
              'franchise': null,
            },
          ],
          'fields': const <Object?>[],
          'sections': const <Object?>[],
        };
      } else {
        // Anything either tab asks for after it opens. Empty rather than a 404,
        // so a failure here is about the strip and not about a pane erroring.
        body = {
          'data': const <String, Object?>{},
          'own': false,
          'config': const <String, Object?>{},
          'values': const <String, Object?>{},
          'fields': const <Object?>[],
        };
      }

      return http.Response(
        jsonEncode(body),
        200,
        headers: {'content-type': 'application/json'},
      );
    });

    tester.view.physicalSize = const Size(1400, 1600);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);

    final api = ApiClient(client: client);
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
        home: Scaffold(body: WebScreen(api: api, session: session)),
      ),
    );
    await tester.pumpAndSettle();
  }

  /// The control for every filtered case below: both tabs, both drawn.
  ///
  /// Without it a suite of "this tab is absent" assertions would pass just as
  /// well against a strip that draws nothing at all.
  testWidgets('a role holding both tabs sees both', (tester) async {
    await pump(tester, const [
      'web_management.view',
      'web_management.seo.view',
      'web_management.widgets.view',
    ]);

    expect(find.text('SEO'), findsOneWidget);
    expect(find.text('Widgets'), findsOneWidget);
  });

  testWidgets('SEO alone is the only tab drawn', (tester) async {
    await pump(tester, const [
      'web_management.view',
      'web_management.seo.view',
    ]);

    expect(find.text('SEO'), findsOneWidget);
    expect(find.text('Widgets'), findsNothing);
  });

  /// The one that would break if the strip were filtered and the BODY were not.
  ///
  /// `_tab` starts on 'seo', so a role without it opens on an index that is no
  /// longer SEO. Asserting the strip alone would pass while the pane below
  /// showed a tab this person cannot open — which is the same hole as a hidden
  /// tab whose endpoints answer, one layer up.
  testWidgets('widgets alone opens ON widgets, not on a missing SEO', (
    tester,
  ) async {
    await pump(tester, const [
      'web_management.view',
      'web_management.widgets.view',
    ]);

    expect(find.text('SEO'), findsNothing);
    expect(find.text('Widgets'), findsOneWidget);

    // Drawn by WidgetsTab and by nothing else on this screen, so it says the
    // body followed the strip rather than that the strip looks right.
    expect(find.textContaining('Questions'), findsWidgets);
  });

  /// Reachable: `web_management.view` draws the sidebar row, and an
  /// administrator can narrow both tabs away.
  testWidgets('the module without either tab says which grant is missing', (
    tester,
  ) async {
    await pump(tester, const ['web_management.view']);

    expect(find.text('SEO'), findsNothing);
    expect(find.text('Widgets'), findsNothing);

    expect(
      find.text('No part of Web Management has been opened to you yet.'),
      findsOneWidget,
    );

    // Names the place, and says it is not theirs to change — the two things a
    // person in this state cannot work out from an empty screen.
    expect(find.textContaining('Settings › Roles & permissions'), findsOneWidget);
    expect(find.textContaining('nothing here is broken'), findsOneWidget);
  });

  /// Absent `/me` answers NO, not yes.
  ///
  /// A null session here is a load that has not finished or a state nobody
  /// designed, and defaulting to "draw everything" would show both panes for
  /// as long as it lasted.
  testWidgets('no permissions at all draws no tabs', (tester) async {
    await pump(tester, const []);

    expect(find.text('SEO'), findsNothing);
    expect(find.text('Widgets'), findsNothing);
  });
}
