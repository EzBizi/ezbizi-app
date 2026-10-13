import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/scheduling_screen.dart';
import 'package:ezbizi_client/widgets/tab_bar_like.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Scheduling's tabs are granted one at a time (Jose, 2026-09-17).
///
/// Nothing pumped this screen before today — the capacity tests build
/// `CapacitySettings` directly, so the tab bar itself had no coverage at all.
/// That is the half where a permission filter lives.
void main() {
  testWidgets('a dispatcher with Availability sees it and not Settings', (
    tester,
  ) async {
    await _pump(tester, const [
      'scheduling.view',
      'scheduling.availability.view',
    ]);

    expect(find.text('Availability'), findsOneWidget);
    expect(find.text('Settings'), findsNothing);
    expect(find.text('Schedule'), findsNothing);
  });

  /// The index trap, and the reason `_visible` compares a KEY.
  ///
  /// With Availability hidden, Settings is index 0. A visibility check written
  /// as `_tab == 2` would tell Settings it was off-screen while it was the only
  /// tab drawn — it would render and never load, which looks like an empty
  /// screen rather than a permission.
  testWidgets(
    'a manager with Settings alone gets Settings, loaded, at index 0',
    (tester) async {
      var askedForAreas = false;

      await _pump(
        tester,
        const ['scheduling.view', 'scheduling.settings.view'],
        onRequest: (request) {
          if (request.url.path.endsWith('/scheduling/areas')) {
            askedForAreas = true;
          }
          return null;
        },
      );

      expect(find.text('Settings'), findsOneWidget);
      // The TOP bar: Settings has its own "Availability" sub-tab (Jose,
      // 2026-09-25), which is not the Availability tab.
      expect(
        find.descendant(
          of: find.byType(TabBarLike).first,
          matching: find.text('Availability'),
        ),
        findsNothing,
      );

      // The pane it drew is the one it thinks is visible: it loaded.
      expect(
        askedForAreas,
        isTrue,
        reason: 'Settings at index 0 must know it is the visible tab.',
      );
    },
  );

  testWidgets('every tab granted draws all three', (tester) async {
    await _pump(tester, const [
      'scheduling.view',
      'scheduling.availability.view',
      'scheduling.schedule.view',
      'scheduling.settings.view',
    ]);

    expect(find.text('Availability'), findsOneWidget);
    expect(find.text('Schedule'), findsOneWidget);
    expect(find.text('Settings'), findsOneWidget);
  });

  // UI queue 49: Locations, and its notes in Settings, come with their grant.
  // Without it (a server from before the tab) neither is drawn.
  testWidgets('Locations and its Settings notes need the Locations grant', (
    tester,
  ) async {
    const without = [
      'scheduling.view',
      'scheduling.availability.view',
      'scheduling.settings.view',
    ];
    await _pump(tester, without);
    expect(find.text('Locations'), findsNothing);
    await tester.tap(find.text('Settings'));
    await tester.pumpAndSettle();
    expect(find.text('Locations'), findsNothing);

    await _pump(tester, [...without, 'scheduling.locations.view']);
    expect(find.text('Locations'), findsOneWidget);
    await tester.tap(find.text('Settings'));
    await tester.pumpAndSettle();
    expect(find.text('Locations'), findsNWidgets(2));
  });

  testWidgets('Settings › Suggestions holds the nearby radius', (tester) async {
    // Jose, 2026-10-13: "make it so i can change that in the app as well on
    // the scheduling settings". The rows are Settings' own, group scheduling.
    await _pump(
      tester,
      ['scheduling.view', 'scheduling.settings.view'],
      onRequest: (request) => request.url.path.endsWith('/settings')
          ? _json({
              'data': [
                for (final (key, label, value) in [
                  (
                    'scheduling.suggest_radius_miles',
                    'Suggested availability: nearby radius',
                    30,
                  ),
                  (
                    'scheduling.suggest_days',
                    'Suggested availability: look ahead',
                    14,
                  ),
                  ('time_format', 'Time format', '12h'),
                ])
                  {
                    'key': key,
                    'label': label,
                    'type': 'enum',
                    'group': key.startsWith('scheduling.')
                        ? 'scheduling'
                        : 'general',
                    'group_label': key.startsWith('scheduling.')
                        ? 'Scheduling'
                        : 'General',
                    'value': value,
                    'is_sensitive': false,
                    'options': [value],
                    'help': null,
                    'not_yet_in_effect': null,
                    'editable_here': true,
                    'may_edit': true,
                    'may_edit_reason': null,
                    'allowed_scopes': ['organization', 'franchise'],
                    'source': null,
                  },
              ],
            })
          : null,
    );
    await tester.tap(find.text('Settings'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Suggestions'));
    await tester.pumpAndSettle();

    expect(find.text('Suggested availability: nearby radius'), findsOneWidget);
    expect(find.text('Suggested availability: look ahead'), findsOneWidget);
    // Only Scheduling's group: the rest of Settings stays in Settings.
    expect(find.text('Time format'), findsNothing);
  });

  /// The clamp has to live in ONE place — and this test does NOT prove a blank
  /// screen, which is worth saying because I claimed one before measuring.
  ///
  /// HR's version of this shape rendered blank. Scheduling's does not:
  /// `CapacitySettings` loads in `initState`, so a freshly built pane fetches
  /// whatever `visible` says, and the flag only drives the reload in
  /// `didUpdateWidget`. The disagreement between the two clamps cost a stale
  /// pane after a revocation, not an empty one.
  ///
  /// What this holds is the thing that stays true either way: the tab that is
  /// DRAWN is the tab that believes it is visible. If somebody later makes the
  /// pane's first load conditional on `visible` — which is the natural tidy-up
  /// — this goes red, and HR's blank screen becomes reachable here.
  ///
  /// Selection is an index. Revoke a grant and the list gets shorter, so a
  /// selection made when two tabs were drawn can point past the end of one.
  /// `build` clamped that to 0 and drew tabs[0] — but `_visible` compared the
  /// UNCLAMPED index, so the pane it drew was told it was off-screen. It
  /// renders and never loads: a blank pane that reads as a broken screen
  /// rather than as a permission.
  ///
  /// Settings hit this shape in HR and it cost them two commits, the second
  /// because the fallback lived where only one of the two readers saw it.
  ///
  /// SETTINGS IS THE TAB LEFT STANDING ON PURPOSE: it fetches when it becomes
  /// visible, so "did the drawn pane believe it was visible" is observable.
  /// Availability waits for a search and would have looked identical either
  /// way.
  testWidgets('a selection past the end still loads the tab that is drawn', (
    tester,
  ) async {
    var permissions = <String>[
      'scheduling.view',
      'scheduling.availability.view',
      'scheduling.settings.view',
    ];
    var askedForAreas = false;

    tester.view.physicalSize = const Size(1280, 900);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);

    final api = ApiClient(
      client: MockClient((request) async {
        final path = request.url.path;

        if (path.endsWith('/me')) {
          return _json({
            'data': {..._me, 'permissions': permissions},
          });
        }

        if (path.endsWith('/scheduling/areas')) {
          askedForAreas = true;
          return _json({'data': const [], 'pattern': null});
        }

        return _json({'data': const [], 'pattern': null});
      }),
    );

    final session = Session(api);
    await tester.runAsync(() => session.adopt('tok'));

    // Session is a ChangeNotifier and the real app rebuilds on it; a bare
    // pump would hold the first permission set for ever and this test would
    // pass against any implementation.
    await tester.pumpWidget(
      MaterialApp(
        theme: EzBiziTheme.light(),
        home: Scaffold(
          body: ListenableBuilder(
            listenable: session,
            builder: (context, _) =>
                SchedulingScreen(api: api, session: session),
          ),
        ),
      ),
    );
    await tester.pumpAndSettle();

    // Stand on Settings, which is index 2 of three.
    await tester.tap(find.text('Settings'));
    await tester.pumpAndSettle();

    askedForAreas = false;

    // Lose every tab except Settings. The list shortens to one under a
    // selection of 2.
    permissions = <String>['scheduling.view', 'scheduling.settings.view'];
    await tester.runAsync(() => session.refresh());
    await tester.pumpAndSettle();

    expect(find.text('Settings'), findsOneWidget);
    // The TOP bar: Settings has its own "Availability" sub-tab (Jose,
    // 2026-09-25), which is not the Availability tab.
    expect(
      find.descendant(
        of: find.byType(TabBarLike).first,
        matching: find.text('Availability'),
      ),
      findsNothing,
    );

    expect(
      askedForAreas,
      isTrue,
      reason: 'The tab that is drawn must believe it is the visible one.',
    );
  });

  /// Reachable: the rail draws Scheduling for anybody holding the module, and
  /// the tab grants are separate from it.
  testWidgets('the module without any tab says so rather than drawing blank', (
    tester,
  ) async {
    await _pump(tester, const ['scheduling.view']);

    expect(find.text('Availability'), findsNothing);
    expect(find.text('Settings'), findsNothing);
    expect(find.textContaining('do not have access'), findsOneWidget);
  });
}

Future<void> _pump(
  WidgetTester tester,
  List<String> permissions, {
  http.Response? Function(http.Request request)? onRequest,
}) async {
  tester.view.physicalSize = const Size(1280, 900);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(
    client: MockClient((request) async {
      final override = onRequest?.call(request);
      if (override != null) return override;

      final path = request.url.path;

      if (path.endsWith('/me')) {
        return _json({
          'data': {..._me, 'permissions': permissions},
        });
      }

      if (path.endsWith('/scheduling/areas')) {
        return _json({'data': const [], 'pattern': null});
      }

      return _json({'data': const [], 'pattern': null});
    }),
  );

  final session = Session(api);
  await tester.runAsync(() => session.adopt('tok'));

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(
        body: SchedulingScreen(api: api, session: session),
      ),
    ),
  );

  await tester.pumpAndSettle();
}

http.Response _json(Object body, [int status = 200]) => http.Response(
  jsonEncode(body),
  status,
  headers: const {'content-type': 'application/json'},
);

const _me = {
  'id': 'u-1',
  'name': 'Jose',
  'email': 'jose@example.com',
  'role': {'name': 'Manager'},
  'has_avatar': false,
  'preferences': <String, dynamic>{},
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
  'companies': [
    {'id': 'co-1', 'name': 'Everdoors', 'parent_company_id': null},
  ],
  'franchises': [
    {'id': 'fr-1', 'name': 'Connecticut', 'company_id': 'co-1'},
  ],
};
