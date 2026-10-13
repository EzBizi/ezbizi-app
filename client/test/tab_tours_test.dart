import 'dart:io';

import 'package:ezbizi_client/core/module_screens.dart';
import 'package:ezbizi_client/core/tab_tours.dart';
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
      expect(
        moduleScreens.containsKey(module),
        isTrue,
        reason: '"$module" is not a key in module_screens.dart',
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
}
