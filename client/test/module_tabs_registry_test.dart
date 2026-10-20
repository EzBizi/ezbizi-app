import 'dart:convert';
import 'dart:io';

import 'package:ezbizi_client/screens/customer_experience_screen.dart';
import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/module_screens.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/widgets/tab_bar_like.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Every module screen's tab strip matches the registry's tabs, in order
/// (deploy desk, 2026-09-29).
///
/// Communication, Newsletter and Social Media drew tabs the registry never
/// declared, so the roles editor had nothing to grant. The screens come from
/// [moduleScreens], so a new screen is checked without anybody listing it; the
/// registry side is test/fixtures/module_tabs.json, which
/// tests/Feature/ModuleTabsFixtureTest.php keeps current.
///
/// The strip read is the FIRST [TabBarLike] in the tree, pumped with every
/// permission the registry declares — the module's own strip, not one drawn
/// inside a tab.
///
/// A parent screen (Marketing) is followed one level down: each pane in its
/// first strip is opened, and the strip inside it is compared with that child
/// module's tabs, or with the parent's own when the pane is the parent's.
void main() {
  final fixture =
      jsonDecode(File('test/fixtures/module_tabs.json').readAsStringSync())
          as Map<String, dynamic>;
  final registry = (fixture['modules'] as Map).cast<String, dynamic>();
  final permissions = (fixture['permissions'] as List).cast<String>();

  for (final entry in moduleScreens.entries) {
    testWidgets('${entry.key}: the tab strip is the registry tabs', (
      tester,
    ) async {
      final module = (registry[entry.key] as Map?)?.cast<String, dynamic>();
      final declared = ((module?['tabs'] as List?) ?? const []).cast<String>();

      // A parent screen draws its child modules (Jobs › Estimates) in the same
      // strip. Those are granted as modules, not tabs, so they are set aside.
      final children = {
        for (final MapEntry(key: key, value: other)
            in registry.entries.cast<MapEntry<String, dynamic>>())
          if ((other as Map)['parent'] == entry.key) ...[
            other['label'] as String,
            // A child drawn as more than one chip, on its own grant.
            ...?customerExperienceChildChips[key],
          ],
      };

      tester.view.physicalSize = const Size(1400, 1600);
      tester.view.devicePixelRatio = 1.0;
      addTearDown(tester.view.reset);

      final api = ApiClient(
        client: MockClient(_handler(registry, permissions)),
      );
      final session = Session(api);
      await tester.runAsync(() => session.adopt('tok'));

      await tester.pumpWidget(
        MaterialApp(
          theme: EzBiziTheme.light(),
          home: Scaffold(body: entry.value.build(api, session)),
        ),
      );
      await tester.pump(const Duration(seconds: 1));

      final first = _strip(tester, 0);
      final own = [
        for (final label in first)
          if (!children.contains(label)) label,
      ];

      // A parent screen's first strip is its panes: the child modules, and
      // usually one pane of its own. Each pane's tabs are the strip INSIDE it
      // (Marketing › Social Media › Posts · Accounts), read after opening it.
      final inner = <String, List<String>>{};
      if (first.any(children.contains)) {
        for (final pane in first) {
          await tester.tap(
            find.descendant(
              of: find.byType(TabBarLike).first,
              matching: find.widgetWithText(ChoiceChip, pane),
            ),
          );
          await tester.pump(const Duration(seconds: 1));
          inner[pane] = _strip(tester, 1);
        }

        for (final child in registry.values.cast<Map>()) {
          final label = child['label'] as String;
          if (child['parent'] != entry.key || !inner.containsKey(label)) {
            continue;
          }
          expect(
            _asTabs(inner[label]!),
            (child['tabs'] as List).cast<String>(),
            reason:
                'Tabs in the $label pane of ${entry.key} and its '
                'TabDefinitions in the registry disagree.',
          );
        }
      }

      // A one-chip strip is a screen, not a choice; the registry has no tab
      // to grant for it either way.
      final shown = own.length == 1 && inner.containsKey(own.single)
          ? _asTabs(inner[own.single]!)
          : _asTabs(own);
      final agrees = listEquals(shown, declared);

      final drift = _knownDrift[entry.key];
      if (drift != null) {
        expect(
          agrees,
          isFalse,
          reason:
              '${entry.key} now agrees with the registry — take it out of '
              '_knownDrift.',
        );
        return;
      }

      expect(
        shown,
        declared,
        reason:
            'Tabs on the ${entry.key} screen and TabDefinitions in the '
            'registry disagree. A drawn tab the registry lacks cannot be '
            'granted in Roles & permissions.',
      );
    });
  }
}

/// The labels of the [index]th strip on stage, or none.
List<String> _strip(WidgetTester tester, int index) {
  final strips = find.byType(TabBarLike);
  if (strips.evaluate().length <= index) return const [];

  return [
    for (final label in tester.widget<TabBarLike>(strips.at(index)).labels)
      label.$1,
  ];
}

/// A strip of fewer than two is a screen, not a choice of tabs.
List<String> _asTabs(List<String> strip) => strip.length < 2 ? const [] : strip;

/// Disagreements that exist today, each with whoever owns the fix. The list
/// may only shrink: a module here that starts agreeing fails until removed.
const _knownDrift = {
  // Not drift: Work and Materials are the job detail panel's panes (0051),
  // drawn as sections of the panel, not as a strip. The screen's own strip is
  // Jobs · Estimates, and Estimates is a child module. jobs_materials_test.dart
  // covers the Materials pane, which is drawn on the key the server sends.
  'jobs': 'panel panes, not a strip — see jobs_materials_test.dart',
  // Not drift: the strip is built from the sources the server sends, so it
  // is empty with no data. leads_screen_test.dart covers its chips.
  'leads': 'data-driven strip, not checkable here',
};

const _emptyLists = ['/newsletters', '/newsletter/audiences'];

MockClientHandler _handler(
  Map<String, dynamic> registry,
  List<String> permissions,
) => (request) async {
  if (request.url.path.endsWith('/me')) {
    return http.Response(
      jsonEncode({
        'data': {
          'id': 'u-1',
          'name': 'Admin',
          'email': 'admin@example.com',
          'has_avatar': false,
          'role': {'id': 'role-1', 'name': 'Administrator'},
          'permissions': permissions,
          // Every module, so a parent screen draws its children.
          'modules': [
            for (final MapEntry(:key, :value) in registry.entries)
              {
                'key': key,
                'label': (value as Map)['label'],
                'parent': value['parent'],
                'available': true,
                'reachable': true,
              },
          ],
          'preferences': <String, dynamic>{},
          'organization': {
            'id': 'org-1',
            'name': 'Org',
            'franchise_label': 'Franchise',
            'is_read_only': false,
          },
          'scope': {
            'type': 'organization',
            'id': 'org-1',
            'label': 'Org',
            'path': ['Org'],
          },
          'companies': <Object>[],
          'franchises': <Object>[],
        },
      }),
      200,
      headers: {'content-type': 'application/json'},
    );
  }

  // Panes that draw their strip only after a successful load, given the
  // smallest answer that loads. Add one here rather than exempting a module.
  if (_emptyLists.any(request.url.path.endsWith)) {
    return http.Response(
      jsonEncode({'data': <Object>[]}),
      200,
      headers: {'content-type': 'application/json'},
    );
  }

  // Everything else is a 404: an invented payload only crashes the loaders,
  // and a pane that draws its strip after loading (Newsletter) has to handle
  // a failed load anyway.
  return http.Response(
    jsonEncode({'message': 'Not found.'}),
    404,
    headers: {'content-type': 'application/json'},
  );
};
