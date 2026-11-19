import 'dart:convert';
import 'dart:io';

import 'package:ezbizi_client/core/module_screens.dart';
import 'package:ezbizi_client/core/tab_tours.dart';
import 'package:ezbizi_client/widgets/tab_bar_like.dart';
import 'package:ezbizi_client/widgets/tab_help.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

/// Every walkthrough step points at something that exists (UI queue 81).
///
/// A step whose key is renamed is skipped without a word, as a control the
/// person cannot see is meant to be, so a typo or a refactor would quietly
/// shorten a tour to nothing. This reads the source for each anchor as a
/// key: `Key('x')`, `ValueKey('x')` or `ValueKey<String>('x')`.
void main() {
  final source = [
    for (final f in Directory('lib').listSync(recursive: true))
      if (f is File &&
          f.path.endsWith('.dart') &&
          !f.path.endsWith('tab_tours.dart'))
        f.readAsStringSync(),
  ].join('\n');

  for (final MapEntry(key: module, value: tour) in tabTours.entries) {
    test('$module: a real tab, and every step names a key in the code', () {
      // "reports/Report Builder": the module, then the tabs open in it.
      final root = module.split('/').first;
      // The shell's own pages, drawn under the same header (UI queue 99).
      const shell = {'settings', 'suggestions', 'development', 'account'};
      expect(
        moduleScreens.containsKey(root) || shell.contains(root),
        isTrue,
        reason: '"$root" is not a key in module_screens.dart',
      );
      expect(tour.steps, isNotEmpty);
      for (final step in tour.steps) {
        final quoted = RegExp(
          "Key(<String>)?\\(\\s*'${RegExp.escape(step.anchor)}'\\s*\\)",
        );
        expect(
          quoted.hasMatch(source),
          isTrue,
          reason: '${step.anchor} (${step.title}) is no widget\'s key',
        );
        expect(step.since, lessThanOrEqualTo(tour.version));
      }
    });
  }

  /// Every tab the server registers has a walkthrough of its own (Jose,
  /// 2026-10-14: "right now they only cover the first tab of each of the
  /// main modules, when it should cover everything, all the sub tabs").
  ///
  /// Keyed as (?) looks it up (`tourKeyFor`): `module/Tab`, and a child
  /// module drawn in its parent's strip as `parent/Child label/Tab`. A
  /// module's first tab may use the module's own tour, which is what opens
  /// there.
  test('every registered tab has a walkthrough of its own', () {
    final fixture =
        jsonDecode(File('test/fixtures/module_tabs.json').readAsStringSync())
            as Map<String, dynamic>;
    final modules = (fixture['modules'] as Map).cast<String, dynamic>();
    final missing = <String>[];
    for (final MapEntry(key: module, value: m) in modules.entries) {
      final parent = m['parent'] as String?;
      if (!moduleScreens.containsKey(parent ?? module)) continue;
      final base = parent == null ? module : '$parent/${m['label']}';
      if (parent != null && !tabTours.containsKey(base)) missing.add(base);
      final within = (m['within'] as Map?)?.cast<String, String>() ?? {};
      for (final (i, tab) in (m['tabs'] as List).indexed) {
        final key = '$base/$tab';
        // A grant drawn as a tool inside a pane with no strip of its own
        // (Marketing › Social, UI queue 199) is walked by that pane's tour.
        final pane = within[tab];
        const toolPanes = {'marketing/Social Media Management'};
        if (pane != null &&
            toolPanes.contains('$base/$pane') &&
            tabTours.containsKey('$base/$pane')) {
          continue;
        }
        // A parent's own tabs may sit under its own pane in its strip
        // (Marketing › Social Media Management › Compose).
        final depth = '$base/x/$tab'.split('/').length;
        final underPane = tabTours.keys.any(
          (k) =>
              k.startsWith('$base/') &&
              k.endsWith('/$tab') &&
              k.split('/').length == depth,
        );
        if (!tabTours.containsKey(key) &&
            !underPane &&
            !(i == 0 && tabTours.containsKey(base))) {
          missing.add(key);
        }
      }
    }
    expect(missing, isEmpty, reason: 'No walkthrough: ${missing.join(', ')}');
  });

  testWidgets('(?) runs the tour of the tab in front, its count dropped, and '
      'falls back to the nearest one', (tester) async {
    Widget strip(List<String> labels, int selected) => TabBarLike(
      labels: [for (final l in labels) (l, Icons.circle_outlined)],
      selected: selected,
      onSelected: (_) {},
    );
    await tester.pumpWidget(
      MaterialApp(
        home: Scaffold(
          body: Column(
            children: [
              strip(['Reports', 'Performance'], 1),
              strip(['Overview', 'Calls · 12'], 1),
              // A tab kept alive behind another is not the one in front.
              Offstage(child: strip(['Hidden'], 0)),
            ],
          ),
        ),
      ),
    );

    expect(openTabs(), ['Performance', 'Calls']);
    expect(tourKeyFor('reports', openTabs()), 'reports/Performance');
    expect(tourKeyFor('reports', ['Reports']), 'reports');
    expect(tourKeyFor('reports', ['Report Builder']), 'reports/Report Builder');
    expect(tourKeyFor('nowhere', const []), isNull);
  });
}
