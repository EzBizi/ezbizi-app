import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/main.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// The developer view switch (Jose, 2026-09-14: desktop layout 1, mobile 4).
///
/// ── The one that matters ─────────────────────────────────────────
///
/// This shell asks `width >= 900`, and it is not the only thing that asks.
/// Nine reads of the viewport live across five files at THREE breakpoints —
/// 900 here and in `staff_shell` and Web widgets, 600 in `lead_thread`, 480 in
/// `auth_shell`, and `side_panel` clamping itself to the window. A switch that
/// flipped this shell's own boolean would give a narrow shell with DESKTOP
/// screens inside it: a mobile view that is wrong in exactly the places
/// somebody would be using it to check.
///
/// So `the app is TOLD it is on a phone` is the load-bearing test. It is the
/// one that goes red if this is ever "simplified" into a flag, and it is the
/// only one here that says anything about the eight readers outside this file.
void main() {
  testWidgets('the app is TOLD it is on a phone, not just drawn narrow', (
    tester,
  ) async {
    await _pump(tester, staff: true);

    expect(_reportedWidth(tester), 1280);

    await tester.tap(find.byTooltip('Mobile view'));
    await tester.pumpAndSettle();

    expect(
      _reportedWidth(tester),
      390,
      reason:
          'Every widget that asks the viewport its width has to get the phone '
          'answer, not only the shell. A flag would leave this at 1280 and '
          'lead_thread, auth_shell and side_panel in their desktop shapes.',
    );
  });

  testWidgets('and the shell follows it: rail out, drawer in', (tester) async {
    await _pump(tester, staff: true);

    expect(find.byType(NavigationSidebar), findsOneWidget);

    await tester.tap(find.byTooltip('Mobile view'));
    await tester.pumpAndSettle();

    expect(find.byType(NavigationSidebar), findsNothing);
    expect(find.byTooltip('Open navigation menu'), findsOneWidget);
  });

  /// Layout 4's whole premise, and the risk that came with it.
  ///
  /// The control stays in the bar in mobile view rather than moving somewhere
  /// roomier — so it is the way BACK as well as the way in. That bar was
  /// measured at 266px of overflow on 2026-09-10 with both conditional chips
  /// showing, which is why the brand is dropped and the theme cycle is gone on
  /// a phone. A way back that overflows off the right edge is no way back.
  testWidgets('the way back is on screen, and nothing overflows getting there', (
    tester,
  ) async {
    await _pump(tester, staff: true, clock: true, twoFactor: true);

    await tester.tap(find.byTooltip('Mobile view'));
    await tester.pumpAndSettle();

    expect(tester.takeException(), isNull);

    final back = find.byTooltip('Back to desktop view');
    expect(back, findsOneWidget);
    expect(
      tester.getTopRight(back).dx,
      lessThanOrEqualTo(390),
      reason: 'Off the right edge is the same as absent.',
    );

    await tester.tap(back);
    await tester.pumpAndSettle();

    expect(_reportedWidth(tester), 1280);
    expect(find.byType(NavigationSidebar), findsOneWidget);
  });

  testWidgets('it is not there for somebody who is not EzBizi staff', (
    tester,
  ) async {
    await _pump(tester, staff: false);

    expect(
      find.byTooltip('Mobile view'),
      findsNothing,
      reason:
          'Same gate as the Development rail (§4.2). This is a tool for '
          'building the product, not part of it.',
    );
  });

  testWidgets('an administrator who IS staff is offered it', (tester) async {
    // The other half of the gate. A control nobody is offered and a control
    // gated correctly look identical from the outside.
    await _pump(tester, staff: true);

    expect(find.byTooltip('Mobile view'), findsOneWidget);
  });
}

// ── harness ────────────────────────────────────────────────────────

/// What the app itself is told the viewport is — read from inside the shell,
/// which is where every other widget reads it from.
double _reportedWidth(WidgetTester tester) =>
    MediaQuery.of(tester.element(find.byType(Scaffold).first)).size.width;

Future<void> _pump(
  WidgetTester tester, {
  required bool staff,
  bool clock = false,
  bool twoFactor = false,
}) async {
  tester.view.physicalSize = const Size(1280, 900);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(
    client: MockClient((request) async {
      if (request.url.path.endsWith('/me')) {
        return _json({
          'data': _me(staff: staff, clock: clock, twoFactor: twoFactor),
        });
      }

      // The screens fetch their own data; this is about the chrome above them.
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

Map<String, dynamic> _me({
  required bool staff,
  required bool clock,
  required bool twoFactor,
}) => {
  'id': 'u-1',
  'name': 'Jose Silva',
  'email': 'jose@example.com',
  'role': {'name': 'Administrator'},
  'has_avatar': false,
  'permissions': <String>[],
  'modules': [
    {'key': 'dashboard', 'label': 'Dashboard', 'available': true,
      'reachable': true},
  ],
  'preferences': <String, dynamic>{},
  'two_factor_enrolment_required': twoFactor,
  'time_clock': {'show_button': clock, 'open': null},
  if (staff)
    'development': {
      'tier': 'engineer',
      'staff_name': 'Jose',
      'focused_at': 'https://dev.ezbizi.com',
    },
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
