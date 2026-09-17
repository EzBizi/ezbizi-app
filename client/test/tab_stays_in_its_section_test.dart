import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/main.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Where Tab goes (Jose, 2026-09-17).
///
/// > *"im on the name question field, and i press tab it should go to the field
/// > below, but instead it's going to the sidebar, then the item below on the
/// > sidebar, then the left bar where the websites are, then back to the
/// > fields, etc / so it's basically getting everything, instead of keeping on
/// > it's own section."*
///
/// ── What the bug actually is, because it is not a missing property ─
///
/// With no [FocusTraversalGroup], Flutter's reading-order policy sorts every
/// focusable in the tree by GEOMETRY. A sidebar row and a form field at the
/// same height are the same band, so the two columns ALTERNATE — which is
/// precisely the sequence he describes, one from each side, over and over.
///
/// So the assertion is about ALTERNATION, not about where Tab lands. "Focus
/// ends up in the page" would pass on the broken build every other press.
/// What cannot happen once the regions are grouped is going back and forth:
/// the sidebar's stops are one run and the page's are another.
void main() {
  testWidgets('Tab does not alternate between the page and the sidebar', (
    tester,
  ) async {
    await _pumpShell(tester);

    // WHICH REGION, not "is it the sidebar". The deploy desk's point, and
    // Automations' before that: a type-targeted finder does not fail when its
    // subject is replaced, it silently re-aims. Worse here than in general —
    // measuring "not the sidebar" counts a TOP BAR stop as a page stop, so the
    // sequence would be wrong in a direction that reads as correct.
    final visited = <String>[];
    for (var press = 0; press < 24; press++) {
      await tester.sendKeyEvent(LogicalKeyboardKey.tab);
      await tester.pump();
      visited.add(
        _focusIsInside(tester, find.byKey(shellSidebarGroup))
            ? 'sidebar'
            : _focusIsInside(tester, find.byKey(shellPageGroup))
            ? 'page'
            : 'chrome',
      );
    }

    // Both regions reached. Two positive controls rather than one: a fix that
    // TRAPPED focus in the page would satisfy the complaint and break the
    // keyboard, and a test that only knew about the sidebar could not see a
    // page that had stopped being reachable at all.
    expect(
      visited,
      contains('sidebar'),
      reason:
          'Tab never reached the sidebar in 24 presses. Grouping ORDERS the '
          'regions; it must not trap focus in one of them. $visited',
    );
    expect(
      visited,
      contains('page'),
      reason: 'Tab never reached the page in 24 presses: $visited',
    );

    // Collapse to the ORDER the regions were visited in, which is the thing
    // grouping decides. Run lengths are not: this fixture's page has exactly
    // one focusable, so a page run of one is honest here and would be honest
    // on a screen with a single button.
    final order = <String>[visited.first];
    for (final region in visited.skip(1)) {
      if (region != order.last) order.add(region);
    }

    final regions = visited.toSet();

    // COMING BACK TO A REGION MEANS HAVING LEFT EVERY OTHER ONE FIRST.
    //
    // That is what "keeping on its own section" means, and it needs no
    // threshold. Grouped, the order laps cleanly —
    //   chrome, sidebar, page, chrome, sidebar
    // Ungrouped it reads
    //   chrome, sidebar, page, sidebar, chrome, sidebar
    // where the sidebar is returned to with only the page in between: the page
    // stop sits INSIDE the run of sidebar rows, which is exactly the sentence
    // Jose wrote — a field, then the sidebar, then the item below it.
    for (var i = 1; i < order.length; i++) {
      final previous = order.lastIndexOf(order[i], i - 1);
      if (previous < 0) continue;

      final between = order.sublist(previous + 1, i).toSet();
      final others = regions.difference({order[i]});

      expect(
        between,
        containsAll(others),
        reason:
            'Focus returned to "${order[i]}" without having visited '
            '${others.difference(between)} in between, so that region is '
            'split around stops from another one. Order: $order, from: '
            '$visited',
      );
    }
  });
}

/// Whether the focused element is inside [finder]'s subtree.
///
/// The same shape as `structure_canvas_test.dart`'s, which is the only other
/// test in the client that presses Tab — and it lives beside the client's only
/// other [FocusTraversalGroup], which is not a coincidence: the place that
/// scoped traversal is the place that tested it.
bool _focusIsInside(WidgetTester tester, Finder finder) {
  final focused = FocusManager.instance.primaryFocus?.context;
  final targets = finder.evaluate().toSet();

  if (focused == null || targets.isEmpty) return false;

  var inside = false;

  focused.visitAncestorElements((element) {
    if (!targets.contains(element)) return true;

    inside = true;

    return false;
  });

  return inside;
}

Future<void> _pumpShell(WidgetTester tester) async {
  tester.view.physicalSize = const Size(1280, 900);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(
    client: MockClient((request) async {
      if (request.url.path.endsWith('/me')) {
        return _json({'data': _me});
      }

      // Every screen renders its error state. This test is about the order Tab
      // walks the chrome in, and an error state still has a Retry button —
      // which is a focusable in the page, which is all it needs to be.
      return _json({'message': 'Nothing stubbed for this screen.'}, 500);
    }),
  );

  final session = Session(api);
  await tester.runAsync(() => session.adopt('tok'));
  expect(session.signedIn, isTrue, reason: 'the /me stub made no session');

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

/// Enough modules that the sidebar has several stops of its own to offer.
///
/// The same shape as `module_navigation_test.dart`'s, which is where the fields
/// a session actually needs are written down: a `/me` missing `organization`
/// or `scope` parses into no session at all, and the shell then renders
/// nothing — which reads exactly like a traversal result and is not one.
final Map<String, dynamic> _me = {
  'id': 'u-1',
  'name': 'Jose Silva',
  'email': 'jose@example.com',
  'role': {'name': 'Administrator'},
  'has_avatar': false,
  'permissions': <String>[],
  'modules': [
    for (final m in const [
      ('dashboard', 'Dashboard'),
      ('customers', 'Customers'),
      ('jobs', 'Jobs'),
      ('inventory', 'Inventory'),
      ('fleet', 'Fleet Management'),
    ])
      {'key': m.$1, 'label': m.$2, 'available': true, 'reachable': true},
  ],
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
  'companies': <dynamic>[],
  'franchises': <dynamic>[],
};
