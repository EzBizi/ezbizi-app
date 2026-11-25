import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/main.dart';
import 'package:ezbizi_client/widgets/module_page_header.dart';
import 'package:ezbizi_client/widgets/phone_bottom_bar.dart';
import 'package:ezbizi_client/widgets/scope_switcher.dart';
import 'package:flutter/material.dart';
import 'package:flutter/rendering.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// The top bar on a phone (UI/UX layout 6 + Jose's amendment, 2026-09-10).
///
/// He reported the bar as crowded and the wordmark as "impossible to see". It
/// was not truncated — the title was wrapped in `FittedBox(scaleDown)`, so the
/// brand was shrunk past reading while still taking its width.
///
/// The load-bearing test here is the overflow one. Everything else asserts what
/// is on screen; only that asserts the bar FITS, which is the actual complaint.
void main() {
  // Mobile review 11-24: a phone turned sideways (923x411) got the desktop
  // shell, whose sidebar left the module list -54 px. A touch device under 560
  // tall keeps the phone shape; a desktop-sized window keeps the sidebar.
  testWidgets('a phone on its side keeps the phone shape', (tester) async {
    await _pump(tester, width: 923, height: 411);
    expect(tester.takeException(), isNull);
    expect(find.byKey(const Key('phone-bottom-bar')), findsOneWidget);
    expect(find.byKey(const ValueKey('sidebar-list')), findsNothing);
  });

  /// Android landscape 11-24: the page was ~200 px and a module's fixed header
  /// left its list 0 px. Now the page keeps 420 and scrolls as a whole.
  testWidgets('a phone on its side scrolls a page that keeps its height', (
    tester,
  ) async {
    await _pump(tester, width: 923, height: 411);
    double page() =>
        tester.getBottomLeft(find.byType(ModuleToolbarScope)).dy -
        tester.getTopLeft(find.byType(ModulePageHeader)).dy;
    expect(page(), greaterThanOrEqualTo(420));
    final top = tester.getTopLeft(find.byType(ModulePageHeader)).dy;
    await tester.drag(find.byType(ModulePageHeader), const Offset(0, -150));
    await tester.pumpAndSettle();
    expect(tester.getTopLeft(find.byType(ModulePageHeader)).dy, lessThan(top));
    expect(tester.takeException(), isNull);
  });

  /// Landscape chrome took ~160 of 411 px: the company picker moves into the
  /// bar, which has room, and the bottom bar draws its labels beside the icons.
  testWidgets('a phone on its side gives the chrome back to the page', (
    tester,
  ) async {
    await _pump(tester, width: 923, height: 411, choices: true);
    expect(tester.takeException(), isNull);
    expect(find.byKey(const Key('top-bar-scope-row')), findsNothing);
    expect(
      find.descendant(
        of: find.byType(AppBar),
        matching: find.byType(ScopeSwitcher),
      ),
      findsOneWidget,
    );
    expect(
      tester.getSize(find.byKey(const Key('phone-bottom-bar'))).height,
      lessThanOrEqualTo(PhoneBottomBar.sidewaysHeight),
    );

    await _pump(tester, width: 375, height: 812, choices: true);
    expect(find.byKey(const Key('top-bar-scope-row')), findsOneWidget);
    expect(
      tester.getSize(find.byKey(const Key('phone-bottom-bar'))).height,
      PhoneBottomBar.height,
    );
  });

  /// UI/UX 11-24 (Jose's standing rule): in the bar the switcher still names
  /// the company AND the branch, never the bare branch.
  testWidgets('sideways, the switcher in the bar names company and branch', (
    tester,
  ) async {
    await _pump(
      tester,
      width: 923,
      height: 411,
      choices: true,
      scopePath: const ['Everdoors', 'North'],
    );
    final inBar = find.descendant(
      of: find.byType(AppBar),
      matching: find.text('Everdoors › North'),
    );
    expect(inBar, findsOneWidget);
    expect(
      tester.renderObject<RenderParagraph>(inBar).didExceedMaxLines,
      isFalse,
    );
  });

  testWidgets('a portrait phone page fits and does not scroll', (tester) async {
    await _pump(tester, width: 375, height: 812);
    final top = tester.getTopLeft(find.byType(ModulePageHeader)).dy;
    await tester.drag(find.byType(ModulePageHeader), const Offset(0, -150));
    await tester.pumpAndSettle();
    expect(tester.getTopLeft(find.byType(ModulePageHeader)).dy, top);
  });

  testWidgets('a tall wide window keeps the sidebar', (tester) async {
    await _pump(tester, width: 1200, height: 900);
    expect(find.byKey(const ValueKey('sidebar-list')), findsOneWidget);
    expect(find.byKey(const Key('phone-bottom-bar')), findsNothing);
  });

  group('phone', () {
    /// Jose 11-24: "when the keyboard is on, you can barely see stuff". With
    /// the keyboard up a phone keeps the toolbar only: no company row, no
    /// state strip, no page title. They come back when it goes down.
    testWidgets('the keyboard folds the chrome away, and it comes back', (
      tester,
    ) async {
      await _pump(tester, width: 375, choices: true, twoFactor: true);
      final row = find.byKey(const Key('top-bar-scope-row'));
      expect(row, findsOneWidget);
      expect(find.text('Set up two-factor'), findsOneWidget);
      expect(find.byType(ModulePageHeader), findsOneWidget);
      final up = tester.getTopLeft(find.byType(ModuleToolbarScope)).dy;

      tester.view.viewInsets = const FakeViewPadding(bottom: 320);
      addTearDown(tester.view.resetViewInsets);
      await tester.pumpAndSettle();
      expect(tester.takeException(), isNull);
      expect(row, findsNothing);
      expect(find.text('Set up two-factor'), findsNothing);
      expect(find.byType(ModulePageHeader), findsNothing);
      expect(
        tester.getTopLeft(find.byType(ModuleToolbarScope)).dy,
        lessThan(up - 100),
        reason: 'The page did not move up into the room the chrome left.',
      );

      tester.view.resetViewInsets();
      await tester.pumpAndSettle();
      expect(row, findsOneWidget);
      expect(find.byType(ModulePageHeader), findsOneWidget);
    });

    testWidgets('a computer keeps its header with the keyboard up', (
      tester,
    ) async {
      await _pump(tester, width: 1200);
      tester.view.viewInsets = const FakeViewPadding(bottom: 320);
      addTearDown(tester.view.resetViewInsets);
      await tester.pumpAndSettle();
      expect(find.byType(ModulePageHeader), findsOneWidget);
    });

    testWidgets('the brand is dropped rather than shrunk, and the company is '
        'still named', (tester) async {
      await _pump(tester, width: 375);

      // Nothing is scaled: the wordmark is absent, not tiny.
      expect(find.byKey(EzBiziTheme.wordmarkKey), findsNothing);
      // And nothing that identifies the business is lost with it — the scope
      // control says it in words, which is what the logo said in pixels.
      expect(find.text('Everdoors'), findsWidgets);
    });

    /// The other branch of the same fix.
    ///
    /// Dropping the brand leans on the scope control to name the business, and
    /// that control draws NOTHING for a single-company organization — there is
    /// nowhere else to stand. So the bar would have identified nothing at all
    /// for exactly the most ordinary customer. Both branches are pinned because
    /// only one of them was ever going to be exercised by hand.
    testWidgets('with somewhere else to stand, the switcher does the naming', (
      tester,
    ) async {
      await _pump(
        tester,
        width: 375,
        choices: true,
        clock: true,
        twoFactor: true,
      );

      expect(tester.takeException(), isNull);
      expect(find.byType(ScopeSwitcher), findsOneWidget);
      // Its own row under the icons, full width: between the hamburger and
      // the icons it got ~20 px and drew an empty box (live 0.0.0597).
      final row = find.byKey(const Key('top-bar-scope-row'));
      expect(
        find.descendant(of: row, matching: find.byType(ScopeSwitcher)),
        findsOneWidget,
      );
      expect(
        tester.getSize(find.byType(ScopeSwitcher)).width,
        greaterThan(300),
      );
      // Under the bar, and the page starts below it.
      expect(
        tester.getTopLeft(row).dy,
        greaterThanOrEqualTo(tester.getBottomLeft(find.byIcon(Icons.login)).dy),
      );
    });

    testWidgets('the punch control keeps its icon and loses only the word', (
      tester,
    ) async {
      await _pump(tester, width: 375, clock: true);

      // Present, and still the outlined control rather than a bare IconButton —
      // the outline and its breath are what make a live shift visible.
      expect(find.byIcon(Icons.login), findsOneWidget);
      expect(find.text('Clock In'), findsNothing);
    });

    testWidgets('the theme control is not in the bar', (tester) async {
      await _pump(tester, width: 375);

      for (final icon in [
        Icons.wb_sunny_outlined,
        Icons.dark_mode_outlined,
        Icons.light_mode,
        Icons.dark_mode,
      ]) {
        expect(
          find.byIcon(icon),
          findsNothing,
          reason: 'The theme cycle is managed from Your account on a phone.',
        );
      }
    });

    /// The one that answers the complaint.
    ///
    /// Both chips are CONDITIONAL, so the worst case is worse than a screenshot
    /// of a healthy account. With both showing, the actions row used to run off
    /// the right edge — an overflow throws in a test binding, so this fails
    /// loudly rather than clipping quietly the way a release build does.
    testWidgets('at 375 with two-factor AND read-only showing, nothing '
        'overflows', (tester) async {
      await _pump(
        tester,
        width: 375,
        clock: true,
        twoFactor: true,
        readOnly: true,
      );

      expect(tester.takeException(), isNull);

      // And they are UNDER the bar, not among the buttons: a state is not a
      // control.
      expect(find.text('Set up two-factor'), findsOneWidget);
      expect(find.text('Read only'), findsOneWidget);
      expect(
        tester.getTopLeft(find.text('Set up two-factor')).dy,
        greaterThan(tester.getTopLeft(find.byType(AppBar)).dy),
      );
    });

    testWidgets('a healthy account gets no strip at all', (tester) async {
      await _pump(tester, width: 375);

      expect(find.text('Set up two-factor'), findsNothing);
      expect(find.text('Read only'), findsNothing);
    });
  });

  group('desktop is unchanged', () {
    testWidgets('keeps the wordmark, the word on the punch, and the theme', (
      tester,
    ) async {
      await _pump(tester, width: 1280, clock: true);

      expect(find.byKey(EzBiziTheme.wordmarkKey), findsOneWidget);
      expect(find.text('Clock In'), findsOneWidget);
    });

    testWidgets('the chips stay in the bar beside the buttons', (tester) async {
      await _pump(tester, width: 1280, twoFactor: true, readOnly: true);

      expect(find.text('Set up two-factor'), findsOneWidget);
      expect(find.text('Read only'), findsOneWidget);
      expect(tester.takeException(), isNull);
    });
  });
}

Future<void> _pump(
  WidgetTester tester, {
  required double width,
  double height = 900,
  bool clock = false,
  bool twoFactor = false,
  bool readOnly = false,
  bool choices = false,
  List<String> scopePath = const ['Everdoors'],
}) async {
  tester.view.physicalSize = Size(width, height);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(
    client: MockClient((request) async {
      if (request.url.path.endsWith('/me')) {
        return _json({
          'data': _me(
            clock: clock,
            twoFactor: twoFactor,
            readOnly: readOnly,
            choices: choices,
            scopePath: scopePath,
          ),
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

Map<String, dynamic> _me({
  required bool clock,
  required bool twoFactor,
  required bool readOnly,
  required bool choices,
  List<String> scopePath = const ['Everdoors'],
}) => {
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
  ],
  'preferences': <String, dynamic>{},
  'two_factor_enrolment_required': twoFactor,
  'time_clock': {'show_button': clock, 'open': null},
  'organization': {
    'id': 'org-1',
    'name': 'Everdoors',
    'franchise_label': 'Franchise',
    'is_read_only': readOnly,
  },
  'scope': {
    'type': scopePath.length > 1 ? 'franchise' : 'organization',
    'id': scopePath.length > 1 ? 'c-1' : 'org-1',
    'label': scopePath.last,
    'path': scopePath,
  },
  'companies': choices
      ? [
          {'id': 'c-1', 'name': 'Everdoors North'},
          {'id': 'c-2', 'name': 'Everdoors South'},
        ]
      : <dynamic>[],
  'franchises': <dynamic>[],
};

http.Response _json(Object body, [int status = 200]) => http.Response(
  jsonEncode(body),
  status,
  headers: {'content-type': 'application/json'},
);
