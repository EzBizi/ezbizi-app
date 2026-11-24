import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/deep_links.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/main.dart';
import 'package:ezbizi_client/widgets/module_page_header.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// The sidebar in the order somebody dragged it into (UI/UX desktop 3 and
/// mobile 4, Jose 2026-09-11).
///
/// ── The one that matters ─────────────────────────────────────────
///
/// A divider cuts the module rail into more than one NavigationRail, because a
/// rail cannot draw a rule between its own items. A rail reports the index of
/// the row that was tapped WITHIN ITSELF, so every group below the first one
/// hands back a number that means something else. Unless it is translated,
/// tapping a row opens a different module — silently, with no error, because
/// the wrong index is a perfectly valid index.
///
/// That is the defect the module filter already had to solve once, which is
/// why `tapping a row below a divider opens that row` is the load-bearing test
/// here rather than one more case.
void main() {
  testWidgets('tapping a row below a divider opens THAT row', (tester) async {
    _wide(tester);

    // Jobs is the second row of the second group. Untranslated, the rail hands
    // back 1, and 1 in the whole list is Customers.
    await _pumpShell(
      tester,
      modules: ['dashboard', 'customers', 'jobs'],
      order: ['dashboard', '--', 'customers', 'jobs'],
    );

    await tester.tap(_inSidebar('Jobs'));
    await tester.pumpAndSettle();

    expect(_open('Jobs'), findsOneWidget);
    expect(
      _open('Customers'),
      findsNothing,
      reason:
          'The rail reported an index inside its own group. Customers is what '
          'that index means in the whole list.',
    );
  });

  testWidgets('the saved order is the order on screen', (tester) async {
    _wide(tester);

    await _pumpShell(
      tester,
      modules: ['dashboard', 'jobs', 'scheduling'],
      order: ['scheduling', 'jobs'],
    );

    // All three sit under the Work heading, so their saved order rules.
    // Dashboard is not in the saved order at all. It goes last rather than
    // missing, which is what the day a module is added looks like to everybody
    // who has ever arranged their sidebar.
    expect(
      tester.getTopLeft(_inSidebar('Scheduling')).dy,
      lessThan(tester.getTopLeft(_inSidebar('Jobs')).dy),
    );
    expect(
      tester.getTopLeft(_inSidebar('Jobs')).dy,
      lessThan(tester.getTopLeft(_inSidebar('Dashboard')).dy),
    );
  });

  /// Filtering can empty a group, and the rule above it has to go too.
  ///
  /// Both directions, because they fail differently. Losing what is UNDER a
  /// rule leaves a line with a blank rail below it -- the defect the staff
  /// group avoids by disappearing with its own rule. Losing what is OVER one
  /// leaves a line under the search field. Only the first is reachable by
  /// breaking the group logic, so it is the one worth its own name.
  testWidgets('a rule whose group the filter emptied goes with it', (
    tester,
  ) async {
    _wide(tester);

    await _pumpShell(
      tester,
      modules: ['dashboard', 'customers', 'jobs'],
      order: ['dashboard', '--', 'customers', 'jobs'],
    );

    final withRule = _rules(tester);

    // Everything BELOW the rule is filtered out.
    await tester.enterText(find.byType(TextField).first, 'dash');
    await tester.pumpAndSettle();

    expect(_inSidebar('Dashboard'), findsOneWidget);
    expect(_inSidebar('Jobs'), findsNothing);
    expect(
      _rules(tester),
      withRule - 1,
      reason:
          'The group under the rule is empty. A rule with an empty rail below '
          'it draws a line across the sidebar for no reason, and this order '
          'was perfectly good when it was saved.',
    );

    // And the other way: everything above it.
    await tester.enterText(find.byType(TextField).first, 'job');
    await tester.pumpAndSettle();

    expect(_inSidebar('Jobs'), findsOneWidget);
    expect(_inSidebar('Dashboard'), findsNothing);
    expect(_rules(tester), withRule - 1);
  });

  testWidgets('rearranging is reached from the sidebar and saves once', (
    tester,
  ) async {
    _wide(tester);

    final saved = <List<dynamic>>[];
    await _pumpShell(
      tester,
      modules: ['dashboard', 'customers'],
      order: const [],
      onSave: saved.add,
    );

    await tester.tap(find.text('Rearrange'));
    await tester.pumpAndSettle();

    // The list, not the rail: a handle on every row, and the filter gone —
    // reordering a list that is hiding rows drops things between two that are
    // neighbours on screen and not in the order.
    expect(find.byIcon(Icons.drag_handle), findsNWidgets(2));
    expect(find.byType(TextField), findsNothing);

    await tester.tap(find.text('Add a divider'));
    await tester.pumpAndSettle();
    expect(find.byIcon(Icons.drag_handle), findsNWidgets(3));

    expect(saved, isEmpty, reason: 'Nothing is written until Done.');

    await tester.tap(find.text('Done'));
    await tester.pumpAndSettle();

    expect(saved, [
      ['dashboard', '--', 'customers'],
    ]);
    expect(
      find.text('Rearrange'),
      findsOneWidget,
      reason: 'Done puts the rail back.',
    );
  });

  testWidgets('Done without moving anything writes nothing', (tester) async {
    _wide(tester);

    final saved = <List<dynamic>>[];
    await _pumpShell(
      tester,
      modules: ['dashboard', 'customers'],
      order: const [],
      onSave: saved.add,
    );

    await tester.tap(find.text('Rearrange'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Done'));
    await tester.pumpAndSettle();

    expect(saved, isEmpty);
  });

  testWidgets('the phone drawer carries the same order and arranges in place', (
    tester,
  ) async {
    tester.view.physicalSize = const Size(375, 900);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);

    await _pumpShell(
      tester,
      modules: ['dashboard', 'customers', 'jobs'],
      order: ['jobs', '--', 'dashboard', 'customers'],
    );

    await tester.tap(find.byTooltip('Open navigation menu'));
    await tester.pumpAndSettle();

    expect(
      tester.getTopLeft(_inDrawer('Jobs')).dy,
      lessThan(tester.getTopLeft(_inDrawer('Dashboard')).dy),
    );

    // The drawer is already a list of rows, so arranging it is that list with
    // handles on. Three modules and the rule between them.
    //
    // Reached from the FOOT, beside the version — it was an icon in the header
    // until 2026-09-14, when Jose asked for it away from the top: "i dont like
    // the rearrange being on the top, perhaps move it next to the version
    // number instead." This test found the move, which is what it is for.
    final rearrange = find.descendant(
      of: find.byType(Drawer),
      matching: find.text('Rearrange'),
    );
    await tester.ensureVisible(rearrange);
    await tester.pumpAndSettle();
    await tester.tap(rearrange);
    await tester.pumpAndSettle();

    expect(find.byType(ReorderableListView), findsOneWidget);
    expect(find.byIcon(Icons.drag_handle), findsNWidgets(4));
  });

  testWidgets(
    'a phone has a bottom bar: Jobs first, and More opens the drawer',
    (tester) async {
      // Mobile review 2026-11-13, P7.
      tester.view.physicalSize = const Size(375, 900);
      tester.view.devicePixelRatio = 1.0;
      addTearDown(tester.view.reset);

      await _pumpShell(
        tester,
        modules: ['dashboard', 'customers', 'jobs'],
        order: ['dashboard', 'customers', 'jobs'],
      );

      final bar = find.byKey(const Key('phone-bottom-bar'));
      expect(bar, findsOneWidget);
      await tester.tap(find.descendant(of: bar, matching: find.text('Jobs')));
      await tester.pumpAndSettle();
      expect(_open('Jobs'), findsOneWidget);

      await tester.tap(find.descendant(of: bar, matching: find.text('More')));
      await tester.pumpAndSettle();
      expect(find.byType(Drawer), findsOneWidget);
    },
  );

  // Design 208: the role's own slots come first, a tab slot by its label,
  // and tapping it hands that module a tab link; the default fills the rest.
  testWidgets(
    "a role's bar puts its slots first; a tab slot sends a tab link",
    (tester) async {
      tester.view.physicalSize = const Size(375, 900);
      tester.view.devicePixelRatio = 1.0;
      addTearDown(tester.view.reset);
      addTearDown(() => DeepLinks.pending.value = null);

      await _pumpShell(
        tester,
        modules: ['dashboard', 'customers', 'jobs'],
        order: ['dashboard', 'customers', 'jobs'],
        mobileBar: [
          {'module': 'customers', 'tab': 'tags', 'label': 'Tags'},
        ],
      );

      final bar = find.byKey(const Key('phone-bottom-bar'));
      final tags = find.byKey(const ValueKey('phone-bottom-customers-tags'));
      expect(find.descendant(of: bar, matching: tags), findsOneWidget);
      expect(
        tester.getCenter(tags).dx,
        lessThan(
          tester.getCenter(find.byKey(const ValueKey('phone-bottom-jobs'))).dx,
        ),
        reason: 'the role slot is first, the default after it',
      );

      // Recorded as sent: the screen that owns it may take it at once.
      final sent = <DeepLink>[];
      void record() {
        if (DeepLinks.pending.value case final l?) sent.add(l);
      }

      DeepLinks.pending.addListener(record);
      addTearDown(() => DeepLinks.pending.removeListener(record));
      await tester.tap(tags);
      await tester.pumpAndSettle();
      expect(_open('Customers'), findsOneWidget);
      expect(
        [for (final l in sent) (l.module, l.type, l.id)],
        [('customers', 'tab', 'tags')],
      );
    },
  );

  testWidgets(
    'the phone bar keeps every label on one line at 320 px and large text',
    (tester) async {
      // Jose, 2026-11-16: "Communicati / on" on the iOS build.
      tester.view.physicalSize = const Size(320, 700);
      tester.view.devicePixelRatio = 1.0;
      tester.platformDispatcher.textScaleFactorTestValue = 1.3;
      addTearDown(tester.view.reset);
      addTearDown(tester.platformDispatcher.clearTextScaleFactorTestValue);

      await _pumpShell(
        tester,
        modules: ['jobs', 'scheduling', 'leads', 'phone'],
        order: ['jobs', 'scheduling', 'leads', 'phone'],
      );
      expect(tester.takeException(), isNull);

      final bar = find.byKey(const Key('phone-bottom-bar'));
      final labels = find.descendant(of: bar, matching: find.byType(Text));
      expect(labels, findsWidgets);
      expect(
        find.descendant(of: bar, matching: find.text('Communication')),
        findsNothing,
      );
      final one = tester
          .getSize(find.descendant(of: bar, matching: find.text('More')))
          .height;
      for (final e in labels.evaluate()) {
        expect(tester.getSize(find.byWidget(e.widget)).height, one);
      }
    },
  );
}

// ── harness ────────────────────────────────────────────────────────

void _wide(WidgetTester tester) {
  tester.view.physicalSize = const Size(1280, 900);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);
}

Finder _inSidebar(String label) => find.descendant(
  of: find.byType(NavigationSidebar),
  matching: find.text(label),
);

Finder _inDrawer(String label) =>
    find.descendant(of: find.byType(Drawer), matching: find.text(label));

/// The module whose page is showing. Not the sidebar's copy of the word — the
/// sidebar says every label, and the question is which screen opened.
Finder _open(String label) => find.descendant(
  of: find.byType(ModulePageHeader),
  matching: find.text(label),
);

/// Rules drawn inside the sidebar, however many groups they separate.
int _rules(WidgetTester tester) => find
    .descendant(
      of: find.byType(NavigationSidebar),
      matching: find.byType(Divider),
    )
    .evaluate()
    .length;

Future<void> _pumpShell(
  WidgetTester tester, {
  required List<String> modules,
  required List<String> order,
  ValueChanged<List<dynamic>>? onSave,
  List<Map<String, dynamic>>? mobileBar,
}) async {
  final api = ApiClient(
    client: MockClient((request) async {
      if (request.url.path.endsWith('/me/preferences')) {
        final body =
            jsonDecode(request.body)['preferences'] as Map<String, dynamic>;
        if (body['navigation.order'] case final List<dynamic> value) {
          onSave?.call(value);
        }

        return _json({'data': _me(modules, order)});
      }
      if (request.url.path.endsWith('/me')) {
        return _json({
          'data': {..._me(modules, order), 'mobile_bar': mobileBar},
        });
      }

      // Everything else is a screen fetching its own data. These tests are
      // about the sidebar, so the bodies render their error state.
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

Map<String, dynamic> _me(List<String> modules, List<String> order) => {
  'id': 'u-1',
  'name': 'Jose Silva',
  'email': 'jose@example.com',
  'role': {'name': 'Administrator'},
  'has_avatar': false,
  'permissions': <String>[],
  'modules': [
    for (final key in modules)
      {
        'key': key,
        'label': key[0].toUpperCase() + key.substring(1),
        'available': true,
        'reachable': true,
      },
  ],
  'preferences': <String, dynamic>{'navigation.order': order},
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
