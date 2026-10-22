import 'dart:io';

import 'package:flutter_test/flutter_test.dart';

/// Jose, 2026-10-22, on the Project Management › Reports branch picker
/// listing "Main" ten times: *"you always keep forgetting about this issue"*.
///
/// A branch picker labels each branch "Company · Branch"
/// (lib/core/branch_options.dart, or the grouped ScopeChoiceField). This pins
/// it: a file under lib/screens or lib/widgets that loops over a branch list
/// (`franchises`, `branches`, `_places`) and builds picker options
/// (SearchOption, ChoiceChip, DropdownMenuItem) labelled with the loop
/// variable's bare `.name` / `['name']` must import core/branch_options.dart
/// or use ScopeChoiceField, or be named in [_exempt] with a reason.
///
/// It reads code, not prose: comments are removed and strings blanked first
/// (the lexer is list_search_guard_test.dart's). The exempt list only shrinks.
void main() {
  test('every branch picker names the company', () {
    final offenders = <String>[];
    for (final path in _sourceFiles()) {
      final hit = bareBranchPicker(File(path).readAsStringSync());
      if (hit == null || _exempt.containsKey(path)) continue;
      offenders.add('$path  ($hit)');
    }
    expect(
      offenders,
      isEmpty,
      reason:
          '\nThese files label branches by their bare name:\n'
          '  ${offenders.join('\n  ')}\n\n'
          'Use branchOptions / branchOptionsInView / branchLabelOf from '
          'lib/core/branch_options.dart ("Company · Branch"), or '
          'ScopeChoiceField. If a list genuinely needs the bare name, add it '
          'to _exempt in test/branch_labels_guard_test.dart with the reason.',
    );
  });

  test('the exempt list holds only files that still need it', () {
    final stale = [
      for (final path in _exempt.keys)
        if (!File(path).existsSync() ||
            bareBranchPicker(File(path).readAsStringSync()) == null)
          path,
    ];
    expect(stale, isEmpty, reason: 'Delete these from _exempt: $stale');
  });

  test('the scan catches the bare picker and passes the labelled one', () {
    const bare =
        'for (final b in branches) SearchOption(value: b.id, label: b.name),\n';
    expect(bareBranchPicker(bare), isNotNull);
    expect(bareBranchPicker('// $bare'), isNull);
    expect(bareBranchPicker("final s = '${bare.trim()}';"), isNull);
    expect(
      bareBranchPicker("import '../core/branch_options.dart';\n$bare"),
      isNull,
    );
  });
}

/// Files exempt from the rule: path → why. Only shrinks.
const Map<String, String> _exempt = {
  'lib/screens/hr/records_tab.dart':
      'Mixes companies and branches in one list; each branch is already '
      'labelled by its company path ("A-1 Chimney › Ohio").',
  'lib/screens/scheduling/availability_calendar.dart':
      "Shows f['label'], which availability_pane.dart fills with "
      "branchLabelOf; ['name'] is only the fallback.",
};

final _loop = RegExp(
  r'for \(final (\w+) in [^)]*(franchis|branches|_places)[^)]*\)',
  caseSensitive: false,
);
final _option = RegExp(r'\b(SearchOption|ChoiceChip|DropdownMenuItem)\b');

/// What makes [source] a bare-name branch picker, or null.
String? bareBranchPicker(String source) {
  final scan = _scan(source);
  if (scan.code.contains('branch_options.dart') ||
      RegExp(r'\bScopeChoiceField\b').hasMatch(scan.bare)) {
    return null;
  }
  for (final m in _loop.allMatches(scan.bare)) {
    final v = m.group(1)!;
    final window = scan.bare.substring(
      m.end,
      (m.end + 300).clamp(0, scan.bare.length),
    );
    final option = _option.firstMatch(window);
    if (option == null) continue;
    if (RegExp(
      '\\b${RegExp.escape(v)}(\\.name\\b|\\[\'\'\\])',
    ).hasMatch(window)) {
      return '${option.group(0)} labelled by $v.name: ${m.group(0)}';
    }
  }
  return null;
}

Iterable<String> _sourceFiles() sync* {
  for (final root in ['lib/screens', 'lib/widgets']) {
    for (final entry in Directory(root).listSync(recursive: true)) {
      if (entry is File && entry.path.endsWith('.dart')) {
        yield entry.path.replaceAll(r'\', '/');
      }
    }
  }
}

/// [code]: comments removed, strings kept. [bare]: comments removed and every
/// string literal (interpolations included) replaced by `''`.
({String code, String bare}) _scan(String src) {
  final code = StringBuffer();
  final bare = StringBuffer();
  var i = 0;
  while (i < src.length) {
    if (src.startsWith('//', i)) {
      i = _skipLine(src, i);
    } else if (src.startsWith('/*', i)) {
      i = _skipBlock(src, i);
      code.write(' ');
      bare.write(' ');
    } else if (_startsString(src, i)) {
      final end = _skipString(src, i);
      code.write(src.substring(i, end));
      bare.write("''");
      i = end;
    } else {
      code.write(src[i]);
      bare.write(src[i]);
      i++;
    }
  }
  return (code: code.toString(), bare: bare.toString());
}

bool _startsString(String src, int i) {
  final ch = src[i];
  if (ch == "'" || ch == '"') return true;
  return ch == 'r' &&
      i + 1 < src.length &&
      (src[i + 1] == "'" || src[i + 1] == '"') &&
      (i == 0 || !_isIdent(src[i - 1]));
}

int _skipLine(String src, int i) {
  while (i < src.length && src[i] != '\n') {
    i++;
  }
  return i;
}

/// One string literal from its prefix or opening quote; returns the index
/// just past it. Interpolations are skipped as code, so a quote inside
/// `${...}` does not end the outer string.
int _skipString(String src, int i) {
  var raw = false;
  if (src[i] == 'r') {
    raw = true;
    i++;
  }
  final q = src[i];
  final close = src.startsWith(q * 3, i) ? q * 3 : q;
  final triple = close.length == 3;
  i += close.length;
  while (i < src.length) {
    if (src.startsWith(close, i)) return i + close.length;
    final ch = src[i];
    if (!raw && ch == r'\') {
      i += 2;
    } else if (!triple && ch == '\n') {
      return i; // unterminated: stop at the line
    } else if (!raw && ch == r'$' && src.startsWith(r'${', i)) {
      i = _skipInterpolation(src, i + 2);
    } else {
      i++;
    }
  }
  return i;
}

/// Code inside `${`, up to and past its matching `}`.
int _skipInterpolation(String src, int i) {
  var depth = 0;
  while (i < src.length) {
    if (src.startsWith('//', i)) {
      i = _skipLine(src, i);
    } else if (src.startsWith('/*', i)) {
      i = _skipBlock(src, i);
    } else if (_startsString(src, i)) {
      i = _skipString(src, i);
    } else {
      final ch = src[i++];
      if (ch == '{') depth++;
      if (ch == '}' && depth-- == 0) return i;
    }
  }
  return i;
}

bool _isIdent(String ch) => RegExp(r'[A-Za-z0-9_$]').hasMatch(ch);

/// A block comment starting at [i]; Dart block comments nest.
int _skipBlock(String src, int i) {
  var depth = 0;
  while (i < src.length) {
    if (src.startsWith('/*', i)) {
      depth++;
      i += 2;
    } else if (src.startsWith('*/', i)) {
      depth--;
      i += 2;
      if (depth == 0) return i;
    } else {
      i++;
    }
  }
  return i;
}
