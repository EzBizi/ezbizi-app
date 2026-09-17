import 'dart:io';

import 'package:flutter_test/flutter_test.dart';

/// Jose, 2026-09-17: *"this sort of popup dropdown are terrible ui design,
/// anything that offers users should be a search bar picker instead. So i want
/// you to go and find every single use of this sort of dropdown in the app and
/// replace it."*
///
/// He said every single use, and every single use is a thing that decays: the
/// next form somebody writes reaches for the control Flutter documents, not the
/// one this app decided on, and nothing else in the suite would notice. So the
/// count is pinned at zero rather than left as a fact about one afternoon.
///
/// **It matches a USE, not a mention.** `DropdownButtonFormField<` or
/// `DropdownButtonFormField(` — never the bare name, which appears in
/// `search_picker.dart`'s own explanation of what it replaced and in several
/// comments recording why a screen is shaped the way it is. A guard that
/// scanned for the word would go red on the prose describing its own subject,
/// and the fix for that would be to delete the explanation.
void main() {
  test('nothing in the app opens a popup dropdown any more', () {
    final offenders = <String>[];

    for (final file in Directory('lib').listSync(recursive: true)) {
      if (file is! File || !file.path.endsWith('.dart')) continue;

      final lines = file.readAsLinesSync();
      for (var i = 0; i < lines.length; i++) {
        final line = lines[i];
        if (line.trimLeft().startsWith('//') ||
            line.trimLeft().startsWith('///')) {
          continue;
        }
        if (line.contains('DropdownButtonFormField<') ||
            line.contains('DropdownButtonFormField(')) {
          offenders.add('${file.path}:${i + 1}');
        }
      }
    }

    expect(
      offenders,
      isEmpty,
      reason:
          'Use SearchPickerField from lib/widgets/search_picker.dart. It shows '
          'a search box only once the list is long enough to need one, so it '
          'is also the right control for three fixed choices.',
    );
  });
}
