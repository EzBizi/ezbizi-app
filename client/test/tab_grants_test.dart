import 'dart:convert';
import 'dart:io';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/module_screens.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/widgets/tab_bar_like.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Every tab opens on the grants the roles editor shows for it, and on
/// nothing else (roles audit, 2026-11-24).
///
/// A role had Inspection › "New Inspection and Review QR" ticked, and Review QR
/// never appeared until Customer Experience was ticked too. The editor never
/// said so: the screen asked for a key the registry did not declare. That is a
/// HIDDEN dependency, and this test fails on the next one.
///
/// For each registry tab, the screen is pumped with ONLY the keys
/// test/fixtures/module_tabs.json lists under `grants` for it (the module's
/// view, the tab's key, and every `actionRequires` key, followed through), plus
/// the grants of one sibling tab so the strip has two chips to draw. The tab
/// must be on screen. A tab that needs more must declare it in the registry
/// (actionRequires, which the editor prints as "requires"), or stop needing it.
///
/// Regenerate the fixture after a registry change:
///     WRITE_MODULE_TABS=1 php artisan test tests/Feature/ModuleTabsFixtureTest.php
void main() {
  final fixture =
      jsonDecode(File('test/fixtures/module_tabs.json').readAsStringSync())
          as Map<String, dynamic>;
  final registry = (fixture['modules'] as Map).cast<String, dynamic>();

  for (final MapEntry(key: moduleKey, value: raw) in registry.entries) {
    final module = (raw as Map).cast<String, dynamic>();
    final grants = (module['grants'] as Map?)?.cast<String, dynamic>();
    if (grants == null) continue;
    final parent = module['parent'] as String?;
    final screenKey = moduleScreens.containsKey(moduleKey) ? moduleKey : parent;
    final screen = moduleScreens[screenKey];
    if (screen == null) continue;
    final within =
        (module['within'] as Map?)?.cast<String, String>() ?? const {};
    final labels = grants.keys.toList();

    for (final tab in labels) {
      final allowed =
          _dataDriven['$moduleKey › $tab'] ?? _dataDriven[moduleKey];
      testWidgets('$moduleKey › $tab opens on its declared grants', (
        tester,
      ) async {
        Set<String> keysOf(String label) =>
            (grants[label] as List).cast<String>().toSet();

        // One sibling's grants beside the tab's own, so a screen that hides a
        // one-chip strip still draws one to read.
        final sibling = labels.firstWhere(
          (l) => l != tab && within[l] == within[tab],
          orElse: () => labels.firstWhere((l) => l != tab, orElse: () => tab),
        );
        final drawn = await _drawn(
          tester,
          screen,
          registry,
          {...keysOf(tab), ...keysOf(sibling)},
          openFirst: [
            if (screenKey != moduleKey) module['label'] as String,
            if (within[tab] != null) within[tab]!,
          ],
          label: tab,
          tool: _paneTools['$moduleKey › $tab'],
        );

        if (allowed != null) {
          expect(
            drawn,
            isFalse,
            reason:
                '$moduleKey › $tab now opens on its declared grants — take it '
                'out of _dataDriven.',
          );
          return;
        }
        expect(
          drawn,
          isTrue,
          reason:
              '$moduleKey › $tab is not drawn for a role holding only '
              '${keysOf(tab).join(', ')} (+ sibling $sibling). The screen '
              'needs a key the registry does not declare: add it to the '
              "module's actionRequires for this tab, or drop it from the "
              'screen.',
        );
      });
    }
  }
}

/// Tabs that cannot open on grants alone, each with why. Shrink-only: one that
/// starts opening fails until it is taken out.
/// A key with no " › " covers every tab of that module.
const _dataDriven = <String, String>{
  // Every source chip is drawn from the counts /leads sends, so with no data
  // there is no strip to read; leads_screen_test.dart covers the chips, and
  // Hidden is inside the Manage pane, which needs Manage (declared).
  'leads': 'data-driven strip: built from the sources /leads sends',
  // The module's only tab. Alone it is the screen itself, not a chip: Jobs
  // draws no strip until Estimates sits beside it, and Estimates is a child
  // module (a module grant, not a tab).
  'jobs › Jobs': 'only tab; a one-chip strip is not drawn',
};

/// Grants drawn as tools beside a page rather than as a chip (Marketing ›
/// Social Media Management): the key of the control each one draws.
const _paneTools = {
  'marketing › Dashboard': 'tour-marketing-month',
  'marketing › Calendar': 'tour-marketing-month',
  'marketing › Compose': 'tour-marketing-new-post',
  'marketing › Accounts': 'social-connections',
  'marketing › Comments': 'social-comments',
  'marketing › QR Codes': 'social-qr',
};

Future<bool> _drawn(
  WidgetTester tester,
  ModuleScreen screen,
  Map<String, dynamic> registry,
  Set<String> permissions, {
  required String label,
  required List<String> openFirst,
  String? tool,
}) async {
  tester.view.physicalSize = const Size(1400, 1600);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(client: MockClient(_handler(registry, permissions)));
  final session = Session(api);
  await tester.runAsync(() => session.adopt('tok'));

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(body: screen.build(api, session)),
    ),
  );
  await tester.pump(const Duration(seconds: 1));
  await tester.pump(const Duration(seconds: 1));

  for (final chip in openFirst) {
    final found = find.widgetWithText(ChoiceChip, chip);
    if (found.evaluate().isEmpty) continue;
    tester.widget<ChoiceChip>(found.first).onSelected!(true);
    await tester.pump(const Duration(seconds: 1));
    await tester.pump(const Duration(seconds: 1));
  }

  if (tool != null && find.byKey(ValueKey(tool)).evaluate().isNotEmpty) {
    return true;
  }
  return {
    for (final strip in tester.widgetList<TabBarLike>(find.byType(TabBarLike)))
      for (final chip in strip.labels) chip.$1,
  }.contains(label);
}

const _emptyLists = ['/newsletters', '/newsletter/audiences'];

MockClientHandler _handler(
  Map<String, dynamic> registry,
  Set<String> permissions,
) => (request) async {
  if (request.url.path.endsWith('/me')) {
    return http.Response(
      jsonEncode({
        'data': {
          'id': 'u-1',
          'name': 'Somebody',
          'email': 'somebody@example.com',
          'has_avatar': false,
          'role': {'id': 'role-1', 'name': 'Narrow'},
          'permissions': permissions.toList(),
          // Reachable as the server decides it: available AND the view held.
          'modules': [
            for (final MapEntry(:key, :value) in registry.entries)
              {
                'key': key,
                'label': (value as Map)['label'],
                'parent': value['parent'],
                'available': true,
                'reachable': permissions.contains('$key.view'),
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
  if (_emptyLists.any(request.url.path.endsWith)) {
    return http.Response(
      jsonEncode({'data': <Object>[]}),
      200,
      headers: {'content-type': 'application/json'},
    );
  }
  return http.Response(
    jsonEncode({'message': 'Not found.'}),
    404,
    headers: {'content-type': 'application/json'},
  );
};
