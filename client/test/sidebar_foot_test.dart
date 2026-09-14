import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/build_info.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/main.dart';
import 'package:ezbizi_client/widgets/build_stamp.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// The foot of the sidebar: which build you are on, and the way into
/// rearranging (Jose, 2026-09-14 — version layouts 1 and 5, plus *"i dont like
/// the rearrange being on the top, perhaps move it next to the version number
/// instead"*).
///
/// ── The one he reported ──────────────────────────────────────────
///
/// A phone had the version NOWHERE. `BuildStamp` was used at the foot of the
/// sidebar and on the sign-in screen; a phone has no sidebar and is signed in.
/// Its own docblock called those "the two places somebody already is when they
/// wonder whether the app has updated" — true when written, false the day the
/// drawer shipped, and found by somebody using the app rather than by anybody
/// auditing it.
void main() {
  testWidgets('a phone can see which build it is on', (tester) async {
    await _pump(tester, width: 375);

    await tester.tap(find.byTooltip('Open navigation menu'));
    await tester.pumpAndSettle();

    expect(
      find.descendant(
        of: find.byType(Drawer),
        matching: find.byType(BuildStamp),
      ),
      findsOneWidget,
    );
  });

  testWidgets('rearranging is at the foot, under the modules, not above them', (
    tester,
  ) async {
    await _pump(tester, width: 1280);

    final rearrange = find.descendant(
      of: find.byType(NavigationSidebar),
      matching: find.text('Rearrange'),
    );
    expect(rearrange, findsOneWidget);

    expect(
      tester.getTopLeft(rearrange).dy,
      greaterThan(tester.getTopLeft(_inSidebar('Dashboard')).dy),
      reason:
          'It sat under the search field, above every module. It is a place '
          'you go to change how the sidebar behaves rather than a place you go '
          'to work — and the top of a list of modules is the one spot where it '
          'competes with the modules.',
    );

    // "Next to the version number" — a pair at the foot with nothing between
    // them. Not the same ROW: `BuildStamp` is 160px wide on a desktop platform
    // (the version plus the update control) and this sidebar drags down to
    // 180, so side by side overflowed by 86px at the default width. Stacked
    // and close is the shape that fits at every width.
    final stamp = find.descendant(
      of: find.byType(NavigationSidebar),
      matching: find.byType(BuildStamp),
    );
    final gap =
        tester.getTopLeft(stamp).dy - tester.getBottomLeft(rearrange).dy;
    expect(
      gap,
      inInclusiveRange(-4, 24),
      reason:
          'Adjacent. A large gap would mean something got between them, which '
          'is how "next to the version" quietly stops being true.',
    );
  });

  testWidgets('collapsed shows the whole version, never half a build id', (
    tester,
  ) async {
    await _pump(tester, width: 1280, collapsed: true);

    expect(_inSidebar(BuildInfo.version), findsOneWidget);
    expect(
      _inSidebar(BuildInfo.short),
      findsNothing,
      reason:
          'The long form is what does not fit at 80px. Dropping a whole field '
          'is not the same as truncating one, and the objection this answers '
          'was about comparing the wrong characters.',
    );
  });

  /// The risk that comes with drawing anything in an 80px column.
  ///
  /// On a desktop platform the stamp has the update control beside it, so the
  /// collapsed rail is carrying two things in the width of one. An overflow
  /// throws in a test binding and clips silently in a release build.
  testWidgets('and nothing overflows in an 80px rail', (tester) async {
    await _pump(tester, width: 1280, collapsed: true);

    expect(tester.takeException(), isNull);

    expect(
      tester.getSize(find.byType(NavigationSidebar)).width,
      NavigationSidebar.collapsedWidth,
      reason: 'If this is not 80 the test is not asking the question.',
    );
  });

  testWidgets('expanded still shows the long form', (tester) async {
    await _pump(tester, width: 1280);

    expect(_inSidebar(BuildInfo.short), findsOneWidget);
  });
}

// ── harness ────────────────────────────────────────────────────────

Finder _inSidebar(String text) => find.descendant(
  of: find.byType(NavigationSidebar),
  matching: find.text(text),
);

Future<void> _pump(
  WidgetTester tester, {
  required double width,
  bool collapsed = false,
}) async {
  tester.view.physicalSize = Size(width, 900);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(
    client: MockClient((request) async {
      if (request.url.path.endsWith('/me')) {
        return _json({'data': _me(collapsed: collapsed)});
      }

      return _json({'message': 'Nothing stubbed for this screen.'}, 500);
    }),
  );

  final session = Session(api);
  await tester.runAsync(() => session.adopt('tok'));
  expect(session.signedIn, isTrue, reason: 'The /me stub gave no session.');

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
}

http.Response _json(Object body, [int status = 200]) => http.Response(
  jsonEncode(body),
  status,
  headers: {'content-type': 'application/json'},
);

Map<String, dynamic> _me({required bool collapsed}) => {
  'id': 'u-1',
  'name': 'Jose Silva',
  'email': 'jose@example.com',
  'role': {'name': 'Administrator'},
  'has_avatar': false,
  'permissions': <String>[],
  'modules': [
    {
      'key': 'dashboard',
      'label': 'Dashboard',
      'available': true,
      'reachable': true,
    },
    {
      'key': 'customers',
      'label': 'Customers',
      'available': true,
      'reachable': true,
    },
  ],
  'preferences': <String, dynamic>{'navigation.collapsed': collapsed},
  'organization': {
    'id': 'org-1',
    'name': 'Everdoors',
    'franchise_label': 'Franchise',
    'is_read_only': false,
  },
  'scope': {
    'type': 'organization',
    'id': 'org-1',
    'label': 'Everdoors',
    'path': ['Everdoors'],
  },
  'companies': <dynamic>[],
  'franchises': <dynamic>[],
};
