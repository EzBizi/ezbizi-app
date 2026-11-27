import 'dart:convert';

import 'package:archive/archive.dart';
import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/files.dart';
import 'package:ezbizi_client/screens/reports/jobs_report.dart';
import 'package:ezbizi_client/screens/reports/marketing_report.dart';
import 'package:ezbizi_client/screens/reports/report_run_view.dart';
import 'package:ezbizi_client/widgets/date_range_picker.dart';
import 'package:ezbizi_client/widgets/table_download.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Merav, 2026-11-19: "Reports - Marketing — Option to download by excel or
/// csv files". Every Reports table: Download → Excel or CSV, of what the
/// screen shows, named for the report and its dates.
void main() {
  /// The first sheet's cells, read back out of the workbook.
  List<List<String>> cells(List<int> xlsx) {
    final zip = ZipDecoder().decodeBytes(xlsx);
    final sheet = utf8.decode(
      zip.findFile('xl/worksheets/sheet1.xml')!.content as List<int>,
    );
    return [
      for (final row in RegExp(r'<row [^>]*>(.*?)</row>').allMatches(sheet))
        [
          for (final c in RegExp(
            r'<c [^>]*>(?:<v>([^<]*)</v>|<is><t[^>]*>([^<]*)</t></is>)</c>',
          ).allMatches(row[1]!))
            c[1] ?? c[2]!,
        ],
    ];
  }

  late Map<String, List<int>> saved;
  setUp(() {
    saved = {};
    Files.debugOnDownload = (name, bytes) => saved[name] = bytes;
  });
  tearDown(() => Files.debugOnDownload = null);

  Future<void> choose(WidgetTester tester, String format) async {
    await tester.tap(find.byKey(const Key('table-download')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(Key('table-download-$format')));
    await tester.pumpAndSettle();
  }

  test('a figure is a number, a dash is empty, anything else its text', () {
    expect(TableFile.value(r'$1,925.00', numeric: true), 1925);
    expect(TableFile.value(r'−$45.00 *', numeric: true), -45);
    expect(TableFile.value('1,204', numeric: true), 1204);
    expect(TableFile.value('12%', numeric: true), '12%');
    expect(TableFile.value(r'$5.09M', numeric: true), r'$5.09M');
    expect(TableFile.value('—', numeric: true), isNull);
    // A name column keeps its digits as text.
    expect(TableFile.value('1042', numeric: false), '1042');
  });

  test('CSV quotes what needs it and never hands Excel a formula', () {
    final bytes = TableFile.csv([
      ['Client', 'Total'],
      ['Lee, Ann', -45],
      ['=HYPERLINK("x")', null],
    ]);
    // The byte-order mark Excel needs to read UTF-8 (utf8.decode drops it).
    expect(bytes.take(3), [0xEF, 0xBB, 0xBF]);
    expect(
      utf8.decode(bytes),
      'Client,Total\r\n"Lee, Ann",-45\r\n"\'=HYPERLINK(""x"")",\r\n',
    );
  });

  test('the workbook names its sheet within Excel\'s rules', () {
    final zip = ZipDecoder().decodeBytes(
      TableFile.xlsx('Open: jobs / week [all] and a very long name', [
        ['A'],
      ]),
    );
    final book = utf8.decode(
      zip.findFile('xl/workbook.xml')!.content as List<int>,
    );
    expect(book, contains('name="Open  jobs   week  all  and a v"'));
  });

  testWidgets('Marketing: the rows the search leaves, the total, its dates', (
    tester,
  ) async {
    tester.view.physicalSize = const Size(2400, 1200);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.reset);
    final api = ApiClient(
      client: MockClient(
        (request) async => http.Response(
          jsonEncode({
            'data': {
              'rows': [
                {
                  'branch': 'Connecticut',
                  'source': 'LS Ads',
                  'cost_minor': 300000,
                  'leads': 10,
                },
                {
                  'branch': 'New Jersey',
                  'source': 'PPC',
                  'cost_minor': 100000,
                  'leads': 2,
                },
              ],
            },
          }),
          200,
          headers: {'content-type': 'application/json'},
        ),
      ),
    );
    final span = DateSpan(DateTime(2026, 10, 18), DateTime(2026, 11, 16));

    await tester.pumpWidget(
      MaterialApp(
        home: Scaffold(
          body: MarketingReport(api: api, span: span, canExport: true),
        ),
      ),
    );
    await tester.pumpAndSettle();
    await tester.enterText(
      find.byKey(const Key('marketing-report-search')),
      'jersey',
    );
    await tester.pumpAndSettle();
    await choose(tester, 'xlsx');

    final file = saved['Marketing Reports 2026-10-18 to 2026-11-16.xlsx'];
    expect(file, isNotNull, reason: '${saved.keys}');
    final sheet = cells(file!);
    expect(sheet.first.take(5), [
      'Branch',
      'Source',
      'Clicks',
      'Conversions',
      'Cost',
    ]);
    // Cost $1,000.00 as the number 1000, leads 2; empty cells are left out.
    expect(sheet[1].take(4), ['New Jersey', 'PPC', '1000', '2']);
    // Connecticut is not on screen, so not in the file; the Total row is.
    expect(sheet.any((r) => r.contains('Connecticut')), isFalse);
    expect(sheet, hasLength(3));

    await choose(tester, 'csv');
    expect(
      utf8.decode(saved['Marketing Reports 2026-10-18 to 2026-11-16.csv']!),
      contains('New Jersey,PPC,,,1000,2,'),
    );
  });

  testWidgets('without reports.export there is no Download', (tester) async {
    tester.view.physicalSize = const Size(2400, 1200);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.reset);
    final api = ApiClient(
      client: MockClient(
        (request) async => http.Response(
          jsonEncode({
            'data': {
              'rows': [
                {'branch': 'Ohio', 'source': 'PPC', 'cost_minor': 100},
              ],
            },
          }),
          200,
          headers: {'content-type': 'application/json'},
        ),
      ),
    );

    await tester.pumpWidget(
      MaterialApp(
        home: Scaffold(
          body: MarketingReport(
            api: api,
            span: DateSpan(DateTime(2026, 11), DateTime(2026, 11, 7)),
          ),
        ),
      ),
    );
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('marketing-table')), findsOneWidget);
    expect(find.byKey(const Key('table-download')), findsNothing);
  });

  testWidgets('Jobs: every job the filters match, not only the page shown', (
    tester,
  ) async {
    tester.view.physicalSize = const Size(2400, 1400);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.reset);
    Map<String, dynamic> job(String id) => {
      'workiz_id': id,
      'workiz_number': id,
      'ezbizi_number': 'E$id',
      'created_at': '2026-11-10 09:05:00',
      'client': 'Ann Lee',
      'branch': 'Ohio',
      'source': 'PPC',
      'total': 300.5,
    };
    final asked = <Map<String, String>>[];
    final api = ApiClient(
      client: MockClient((request) async {
        final q = request.url.queryParameters;
        asked.add(q);
        final all = [job('J1'), job('J2'), job('J3')];
        final offset = int.parse(q['offset']!);
        final limit = int.parse(q['limit']!);
        return http.Response(
          jsonEncode({
            'data': {
              // The screen's pages are one row here, so the file needs more.
              'rows': all.skip(offset).take(limit == 200 ? 1 : 2).toList(),
              'count': 3,
              'total': 901.5,
              'calls_read': true,
            },
          }),
          200,
          headers: {'content-type': 'application/json'},
        );
      }),
    );

    await tester.pumpWidget(
      MaterialApp(
        home: Scaffold(
          body: JobsReport(
            api: api,
            span: DateSpan(DateTime(2026, 11, 10), DateTime(2026, 11, 10)),
            canExport: true,
          ),
        ),
      ),
    );
    await tester.pumpAndSettle();
    expect(find.text('J2'), findsNothing);
    await choose(tester, 'csv');

    final text = utf8.decode(saved['Jobs 2026-11-10.csv']!);
    for (final id in ['J1', 'J2', 'J3']) {
      expect(text, contains('E$id,$id'));
    }
    expect(text, contains('300.5'));
    // The file's pages are the server's largest, with the screen's filters.
    expect(asked.last['limit'], '5000');
    expect(asked.last['from'], '2026-11-10');
  });

  testWidgets('a saved report: its columns and the rows the search leaves', (
    tester,
  ) async {
    tester.view.physicalSize = const Size(1400, 900);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.reset);
    final api = ApiClient(
      client: MockClient(
        (request) async => http.Response(
          jsonEncode({
            'data': {
              'columns': [
                {'key': 'jobs.title', 'label': 'Title', 'type': 'string'},
                {'key': 'jobs.total', 'label': 'Total', 'type': 'money'},
              ],
              'rows': [
                {'jobs.title': 'Sweep', 'jobs.total': 192500},
                {'jobs.title': 'Cap install', 'jobs.total': 5000},
              ],
              'capped': false,
              'count': 2,
              'cap': 5000,
            },
          }),
          200,
          headers: const {'content-type': 'application/json'},
        ),
      ),
    );

    await tester.pumpWidget(
      MaterialApp(
        home: Scaffold(
          body: ReportRunView(
            api: api,
            reportId: 'rep-1',
            name: 'Open/closed jobs',
            canExport: true,
          ),
        ),
      ),
    );
    await tester.pumpAndSettle();
    await tester.enterText(find.byKey(const Key('report-run-search')), 'sweep');
    await tester.pumpAndSettle();
    await choose(tester, 'xlsx');

    final name = saved.keys.single;
    expect(name, startsWith('Open-closed jobs '));
    expect(cells(saved[name]!), [
      ['Title', 'Total'],
      ['Sweep', '1925'],
    ]);
  });
}
