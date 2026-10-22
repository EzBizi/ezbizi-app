import 'dart:io';

import 'package:flutter_test/flutter_test.dart';

/// Jose, 2026-10-22: *"i get tired of asking this, i want search bars"*.
///
/// Three pins gave every list screen a find field. Nothing stopped the next
/// list screen shipping without one, so this pins the rule: a file under
/// lib/screens or lib/widgets that draws rows with one of the list widgets
/// below must carry a search field Key, or be named in [_exempt] with the
/// reason it has none.
///
/// **It reads code, not prose.** Comments are removed and string literals are
/// blanked before a list widget is looked for, so a docblock or an error
/// message that names one of them cannot make a file a list screen. The search
/// Key is looked for in the same comment-free text with its strings kept,
/// because a Key's name is a string literal.
///
/// **The exempt list only shrinks.** An entry whose file is gone, no longer
/// draws a list, or now has a search Key fails as stale, so it has to be
/// deleted the day it stops being true.
void main() {
  final files = _sourceFiles().toList()..sort();

  test('every list screen has a search bar', () {
    final offenders = <String>[];
    for (final path in files) {
      final source = File(path).readAsStringSync();
      final scan = _scan(source);
      final widget = _listWidget.firstMatch(scan.bare);
      if (widget == null) continue;
      if (_hasSearch(scan)) continue;
      if (_exempt.containsKey(path)) continue;
      offenders.add('$path  (uses ${widget.group(0)!.replaceAll('(', '')})');
    }

    expect(
      offenders,
      isEmpty,
      reason:
          '\nThese files draw a list and have no search field:\n'
          '  ${offenders.join('\n  ')}\n\n'
          'Rule (Jose, 2026-10-22, "i want search bars"): a file under '
          'lib/screens or lib/widgets that uses ListView.builder, '
          'ListView.separated, DataTable, GroupedTable, FilterTiles or '
          'ReportGrid must hold a search TextField with a literal Key, e.g. '
          "ValueKey('leads-search'). If the list genuinely needs none (a chat "
          'thread, a picker, content rather than records) or its search is '
          'drawn by a parent file, add it to _exempt in '
          'test/list_search_guard_test.dart with the reason and owner.',
    );
  });

  test('the exempt list holds only files that still need it', () {
    final stale = <String>[];
    for (final path in _exempt.keys) {
      final file = File(path);
      if (!file.existsSync()) {
        stale.add('$path  (file no longer exists)');
        continue;
      }
      final scan = _scan(file.readAsStringSync());
      if (!_listWidget.hasMatch(scan.bare)) {
        stale.add('$path  (no longer uses a list widget)');
      } else if (_hasSearch(scan)) {
        stale.add('$path  (now has a search Key)');
      }
      // A search said to live in a parent file has to be there.
      final host = _exempt[path]!.host;
      if (host != null) {
        final hostFile = File(host);
        if (!hostFile.existsSync()) {
          stale.add('$path  (its host $host no longer exists)');
        } else if (!_hasSearch(_scan(hostFile.readAsStringSync()))) {
          stale.add('$path  (its host $host has no search Key)');
        }
      }
    }

    expect(
      stale,
      isEmpty,
      reason:
          '\nDelete these entries from _exempt; the list may only shrink:\n'
          '  ${stale.join('\n  ')}',
    );
  });

  // The screens whose search goes to the server as q. Each already has a
  // widget test that types a term and asserts the request carried it; this
  // pins that those tests stay, and that each screen still sends q.
  test('every server-search screen sends q and has a test that checks it', () {
    final problems = <String>[];
    for (final MapEntry(key: screen, value: testFile)
        in _serverSearch.entries) {
      final screenFile = File(screen);
      final tested = File(testFile);
      if (!screenFile.existsSync()) {
        problems.add('$screen  (screen file is gone)');
        continue;
      }
      if (!RegExp(
        r"""'q'|[?&]q=""",
      ).hasMatch(_scan(screenFile.readAsStringSync()).code)) {
        problems.add('$screen  (no longer sends q)');
      }
      if (!tested.existsSync()) {
        problems.add('$screen  (its test $testFile is gone)');
        continue;
      }
      final body = tested.readAsStringSync();
      final import = "package:ezbizi_client/${screen.substring(4)}";
      if (!body.contains(import)) {
        problems.add('$screen  ($testFile no longer imports it)');
      }
      if (!body.contains("['q']")) {
        problems.add('$screen  ($testFile no longer asserts q)');
      }
    }

    expect(
      problems,
      isEmpty,
      reason:
          '\nA server-search screen lost its q or its test:\n'
          '  ${problems.join('\n  ')}',
    );
  });

  test('the stripper blanks strings and comments, not code', () {
    final scan = _scan(r'''
// ListView.builder( in a line comment
/* DataTable( in a block /* nested */ comment */
/// ReportGrid( in a docblock
final a = 'FilterTiles(';
final b = "GroupedTable<${x('ListView.separated(')}>";
final c = r'DataTable(\';
final d = """
ListView.builder(
""";
final key = ValueKey('leads-search');
final real = ListView.separated(itemBuilder: (_, i) => Text('$i'));
''');
    expect(_listWidget.allMatches(scan.bare).map((m) => m.group(0)), [
      'ListView.separated(',
    ]);
    expect(_hasSearch(scan), isTrue);
    expect(scan.code, isNot(contains('in a block')));
    expect(_hasSearch(_scan("// Key('x-search')")), isFalse);
    expect(_hasSearch(_scan("final k = Key('row-7');")), isFalse);
    // A key name picked by a switch is still a literal key name.
    expect(
      _hasSearch(_scan("Filter.calls => 'records-calls-search',")),
      isTrue,
    );
  });
}

/// A list widget being USED: a constructor call, never the bare name.
final _listWidget = RegExp(
  r'\bListView\.builder\(|\bListView\.separated\(|\bDataTable\(|'
  r'\bGroupedTable\s*[(<]|\bFilterTiles\(|\bReportGrid\(',
);

/// `Key('…search…')` or `ValueKey<String>('…search…')`, single or double
/// quoted, interpolation allowed inside the literal.
final _searchKey = RegExp(
  r'''\b(?:Value)?Key(?:<String>)?\(\s*(?:const\s+)?r?(['"])[^'"\n]*search[^'"\n]*\1''',
  caseSensitive: false,
);

/// A key name by this app's convention, `'…-search'`, wherever the literal
/// sits (records_tab picks one per filter from a switch).
final _searchKeyName = RegExp(r'''(['"])[^'"\n]*-search\1''');

bool _hasSearch(({String code, String bare}) scan) =>
    _searchKey.hasMatch(scan.code) || _searchKeyName.hasMatch(scan.code);

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

typedef _Exemption = ({String why, String owner, String? host});

/// Files that draw a list and rightly carry no search field of their own,
/// with why and the module that owns each. [host] names the parent file that
/// draws the search; the stale check holds it to having one. Seeded
/// 2026-10-22 from the tree after the three search-bar pins.
const Map<String, _Exemption> _exempt = {
  // The list widgets themselves. Each caller draws the search.
  'lib/widgets/grouped_rows.dart': (
    why: 'defines GroupedTable; the screen that uses it holds the search',
    owner: 'UI/UX',
    host: null,
  ),
  'lib/widgets/list_table.dart': (
    why: 'defines FilterTiles; the screen that uses it holds the search',
    owner: 'UI/UX',
    host: null,
  ),
  'lib/widgets/report_grid.dart': (
    why: 'defines ReportGrid; the screen that uses it holds the search',
    owner: 'UI/UX',
    host: null,
  ),

  // Search drawn by the parent file.
  'lib/screens/jobs/project_management_tab.dart': (
    why:
        "one job's timeline, a dot per event; opened from a row in Jobs and "
        'Logs, which search',
    owner: 'Project Management',
    host: 'lib/screens/jobs/project_management_jobs.dart',
  ),
  'lib/screens/financials/financial_grid.dart': (
    why: 'the grid is searched from the Financials bar',
    owner: 'Financials',
    host: 'lib/screens/financials_screen.dart',
  ),
  'lib/screens/marketing/newsletter_dashboard.dart': (
    why: 'the table search is passed in by the Newsletter tab',
    owner: 'Marketing',
    host: 'lib/screens/marketing/newsletter_tab.dart',
  ),

  // Conversations: one thread, read top to bottom.
  'lib/screens/email/message_view.dart': (
    why: 'one open message and its thread, not a record list',
    owner: 'Email — inbound',
    host: null,
  ),
  'lib/screens/phone/staff_thread.dart': (
    why: 'a chat thread between staff',
    owner: 'Phone',
    host: null,
  ),
  'lib/widgets/lead_thread.dart': (
    why: "a lead's conversation thread",
    owner: 'EZ Leads',
    host: null,
  ),

  // Content and summaries, not records.
  'lib/screens/training/guide_player.dart': (
    why: 'a table block inside a guide topic',
    owner: 'Training',
    host: null,
  ),
  'lib/widgets/flow_chart.dart': (
    why: 'the steps of one flow chart, in order',
    owner: 'Phone',
    host: null,
  ),
  'lib/screens/customer_experience/review_links_tab.dart': (
    why: 'a totals table, one row per branch or person',
    owner: 'EZ Leads',
    host: null,
  ),
  'lib/screens/phone/call_tab.dart': (
    why: 'the Team face of the call desk: filter tiles over the roster',
    owner: 'Phone',
    host: null,
  ),
  'lib/widgets/notification_bell.dart': (
    why: 'the bell drop-down of recent notifications',
    owner: 'UI/UX',
    host: null,
  ),
};

/// Server-search screen → the test that asserts it sends q. Pin C, plus the
/// q screens of pins A and B (2026-10-22).
const _serverSearch = {
  // Pin C.
  'lib/screens/reviews_screen.dart': 'test/list_search_c_test.dart',
  'lib/screens/inventory/order_list.dart': 'test/list_search_c_test.dart',
  'lib/widgets/api_activity.dart': 'test/list_search_c_test.dart',
  'lib/screens/financials/invoices_tab.dart': 'test/list_search_c_test.dart',
  'lib/screens/financials/invoice_sheets.dart': 'test/list_search_c_test.dart',
  'lib/screens/financials/financial_bills.dart': 'test/list_search_c_test.dart',
  'lib/screens/financials/financial_adjustments.dart':
      'test/list_search_c_test.dart',
  'lib/screens/marketing/social_media_tab.dart': 'test/list_search_c_test.dart',
  'lib/screens/marketing/newsletter_tab.dart': 'test/list_search_c_test.dart',
  // Pin A.
  'lib/screens/phone/voicemails_pane.dart': 'test/comm_search_bars_test.dart',
  'lib/screens/phone/call_history_pane.dart': 'test/comm_search_bars_test.dart',
  'lib/screens/field/recordings_tab.dart': 'test/comm_search_bars_test.dart',
  // Pin B.
  'lib/screens/staff/impersonations_screen.dart':
      'test/search_impersonations_test.dart',
  'lib/screens/staff/phone_requests_screen.dart':
      'test/search_phone_requests_test.dart',
  'lib/screens/staff/suggestions_queue_screen.dart':
      'test/search_suggestions_queue_test.dart',
  'lib/screens/training/settings_tab.dart':
      'test/search_training_settings_test.dart',
  'lib/screens/customers_screen.dart':
      'test/search_customers_duplicates_test.dart',
};
