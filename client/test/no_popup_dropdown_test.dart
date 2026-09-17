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
/// and the cheapest fix for that would be to delete the explanation.
void main() {
  test('nothing in the app opens a popup dropdown any more', () {
    final offenders = <String>[];

    for (final file in _dartFiles()) {
      final lines = file.readAsLinesSync();
      for (var i = 0; i < lines.length; i++) {
        if (_isComment(lines[i])) continue;
        if (lines[i].contains('DropdownButtonFormField<') ||
            lines[i].contains('DropdownButtonFormField(') ||
            lines[i].contains('DropdownButton<') ||
            lines[i].contains('DropdownButton(')) {
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

  /// `DropdownMenu` is the one member of the family that can already be what he
  /// asked for: with `enableFilter: true` it IS a search bar picker, wearing
  /// Flutter's name for one. Nine of them are, and they are left alone.
  ///
  /// Without it, it is the same popup in a text field's clothing — which is
  /// worse than the plain dropdown, because it looks like something you can
  /// type into and is not.
  test('a DropdownMenu that cannot be filtered is a popup dropdown', () {
    final offenders = <String>[];

    for (final file in _dartFiles()) {
      final lines = file.readAsLinesSync();
      for (var i = 0; i < lines.length; i++) {
        if (_isComment(lines[i])) continue;
        if (!lines[i].contains('DropdownMenu<') &&
            !lines[i].contains('DropdownMenu(')) {
          continue;
        }

        // The argument list, near enough: far enough to clear a long entries
        // list, and it is only ever read for one flag.
        final end = (i + 30).clamp(0, lines.length);
        final body = lines.sublist(i, end).join('\n');
        if (!body.contains('enableFilter: true')) {
          offenders.add('${file.path}:${i + 1}');
        }
      }
    }

    expect(
      offenders,
      isEmpty,
      reason:
          'Either set enableFilter: true and requestFocusOnTap: true, or use '
          'SearchPickerField.',
    );
  });
}

Iterable<File> _dartFiles() sync* {
  for (final entry in Directory('lib').listSync(recursive: true)) {
    if (entry is File && entry.path.endsWith('.dart')) yield entry;
  }
}

bool _isComment(String line) {
  final trimmed = line.trimLeft();

  return trimmed.startsWith('//') || trimmed.startsWith('*');
}
