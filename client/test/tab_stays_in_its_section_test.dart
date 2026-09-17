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

    final visited = <bool>[];
    for (var press = 0; press < 24; press++) {
      await tester.sendKeyEvent(LogicalKeyboardKey.tab);
      await tester.pump();
      visited.add(_focusIsInside(tester, find.byType(NavigationSidebar)));
    }

    expect(
      visited.contains(true),
      isTrue,
      reason:
          'Tab never reached the sidebar in 24 presses, so this test is not '
          'looking at the thing it claims to measure. Grouping ORDERS the '
          'regions; it must not trap focus in one of them. '
          'focusables=${tester.widgetList(find.byType(Focus)).length} '
          'sidebars=${find.byType(NavigationSidebar).evaluate().length} '
          'focused=${FocusManager.instance.primaryFocus?.debugLabel} '
          'visited=$visited',
    );

    // Runs of consecutive stops on the same side. The FIRST and LAST are cut
    // off by the press limit and say nothing; every run BETWEEN them is a
    // complete visit to one region.
    final runs = <int>[1];
    for (var i = 1; i < visited.length; i++) {
      if (visited[i] == visited[i - 1]) {
        runs[runs.length - 1]++;
      } else {
        runs.add(1);
      }
    }

    final interior = runs.sublist(1, runs.length - 1);

    // A COMPLETE visit of length one is the bug, exactly as he described it:
    // one field, one sidebar row, one more sidebar row, back to the fields.
    // Grouped, a visit is the whole region — here 10 and 5 — so this is a
    // difference in kind rather than a threshold somebody has to tune.
    expect(
      interior,
      everyElement(greaterThan(1)),
      reason:
          'Focus visited a region for a SINGLE stop before crossing back, '
          'which is the alternation. Runs: $runs, from: $visited',
    );
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
