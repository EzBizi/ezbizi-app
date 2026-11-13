import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/core/timestamps.dart';
import 'package:ezbizi_client/screens/jobs/job_where.dart';
import 'package:ezbizi_client/screens/jobs_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// A job's service area on the Jobs screen — queue 25, Jose's pick of 1 and 4:
/// one "Where" column, one "Where" picker, a field on the record, and the area
/// in the phone row. Jose, 2026-09-25: *"Service Areas — sub divisions within
/// the franchise."*
void main() {
  group('the words', () {
    test('franchise, then the area inside it', () {
      expect(whereText(_job('j', 'Illinois', 'Chicago')), 'Illinois · Chicago');
    });

    /// Three of A1-Group's areas share their franchise's name.
    test('an area that repeats its franchise is said once', () {
      expect(whereText(_job('j', 'Connecticut', 'Connecticut')), 'Connecticut');
    });

    test('no area is the franchise alone; the mark says the rest', () {
      expect(whereText(_job('j', 'Ohio', null)), 'Ohio');
      expect(jobWhere(_job('j', 'Ohio', null)).area, isNull);
    });
  });

  group('the table', () {
    testWidgets('one Where column, and an unplaced job marked in it', (
      tester,
    ) async {
      await _pumpJobs(tester);

      expect(find.text('Where'), findsWidgets);
      expect(find.text('Illinois · Chicago'), findsOneWidget);
      expect(find.byKey(const Key('not-placed')), findsOneWidget);
      // The cell collapses a repeat too — its own copy of the rule.
      expect(find.text('Connecticut'), findsOneWidget);
      expect(find.text('Connecticut · Connecticut'), findsNothing);
    });

    testWidgets('the Where picker offers the unplaced, each franchise and its '
        'areas — and says Connecticut once', (tester) async {
      await _pumpJobs(tester);

      await tester.tap(find.byKey(const Key('jobs-filters')));
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const Key('jobs-where')));
      await tester.pumpAndSettle();

      expect(find.text('Not placed yet'), findsOneWidget);
      expect(find.text('Jobs no area has claimed — 470'), findsOneWidget);
      expect(find.text('Illinois · Chicago'), findsWidgets);
      expect(find.text('Ohio · Cleveland'), findsOneWidget);
      // Connecticut's only area is "Connecticut": one row, not two.
      expect(find.text('Connecticut · Connecticut'), findsNothing);
    });

    testWidgets('an area, the unplaced and a franchise each ask the list '
        'their own question', (tester) async {
      final asked = <Map<String, String>>[];
      await _pumpJobs(tester, onJobs: asked.add);

      Future<void> choose(String label) async {
        if (find.byKey(const Key('jobs-where')).evaluate().isEmpty) {
          await tester.tap(find.byKey(const Key('jobs-filters')));
          await tester.pumpAndSettle();
        }
        await tester.tap(find.byKey(const Key('jobs-where')));
        await tester.pumpAndSettle();
        await tester.tap(find.text(label).last);
        await tester.pumpAndSettle();
      }

      await choose('Ohio · Cleveland');
      expect(asked.last['service_area_id'], 'sa-cle');
      expect(asked.last.containsKey('franchise_id'), isFalse);

      await choose('Not placed yet');
      expect(asked.last['service_area_id'], 'none');

      await choose('Illinois');
      expect(asked.last['franchise_id'], 'fr-il');
      expect(asked.last.containsKey('service_area_id'), isFalse);
    });

    testWidgets('an older server with no areas and one franchise offers no '
        'Where picker at all', (tester) async {
      await _pumpJobs(tester, areas: null, franchises: const [_il]);
      await tester.tap(find.byKey(const Key('jobs-filters')));
      await tester.pumpAndSettle();

      expect(find.byKey(const Key('jobs-where')), findsNothing);
      // And calls nothing unplaced: it has not said there is anywhere to be.
      expect(find.byKey(const Key('not-placed')), findsNothing);
    });

    /// "Not placed" is only true where there is somewhere to place it. A
    /// franchise with no areas gets its name alone.
    testWidgets('a franchise with no areas is never "not placed"', (
      tester,
    ) async {
      await _pumpJobs(
        tester,
        areas: _areas.where((a) => a['franchise_id'] != 'fr-oh').toList(),
      );

      expect(find.byKey(const Key('not-placed')), findsNothing);
      expect(find.text('Ohio'), findsWidgets);
    });
  });

  group('the record', () {
    testWidgets('an unplaced job asks for its area, and a choice is saved', (
      tester,
    ) async {
      final patches = <Map<String, dynamic>>[];
      await _pumpJobs(tester, onPatch: patches.add);

      await tester.tap(find.text('Crown Repair'));
      await tester.pumpAndSettle();

      expect(find.byKey(const Key('job-service-area')), findsOneWidget);
      // The header wears the same mark its row did.
      expect(find.byKey(const Key('not-placed')), findsWidgets);

      await tester.tap(find.byKey(const Key('job-service-area')));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Cleveland').last);
      await tester.pumpAndSettle();

      // A choice is an edit: the list will not switch jobs under it.
      await tester.tap(find.text('Chimney Sweep'));
      await tester.pumpAndSettle();
      expect(
        find.text('Save or cancel the changes to this job first.'),
        findsOneWidget,
      );

      await tester.tap(find.text('Save'));
      await tester.pumpAndSettle();

      expect(patches.single['service_area_id'], 'sa-cle');
    });

    testWidgets('a franchise with no areas asks nothing and sends nothing', (
      tester,
    ) async {
      final patches = <Map<String, dynamic>>[];
      await _pumpJobs(tester, onPatch: patches.add, detailAreas: const []);

      await tester.tap(find.text('Crown Repair'));
      await tester.pumpAndSettle();

      expect(find.byKey(const Key('job-service-area')), findsNothing);

      await tester.tap(find.text('Save'));
      await tester.pumpAndSettle();

      expect(patches.single.containsKey('service_area_id'), isFalse);
    });
  });

  group('on a phone', () {
    testWidgets('the area in the second line, the longest name included', (
      tester,
    ) async {
      await _pumpJobs(tester, size: const Size(375, 800));

      expect(tester.takeException(), isNull);
      expect(
        find.textContaining('Evan Brooks · Nationwide · Los Angeles'),
        findsOneWidget,
      );
      expect(find.byKey(const Key('not-placed')), findsOneWidget);
    });
  });
}

// ── fixtures ──────────────────────────────────────────────────────

const _il = {'id': 'fr-il', 'name': 'Illinois', 'company_id': 'co-1'};
const _oh = {'id': 'fr-oh', 'name': 'Ohio', 'company_id': 'co-1'};
const _ct = {'id': 'fr-ct', 'name': 'Connecticut', 'company_id': 'co-1'};
const _nw = {'id': 'fr-nw', 'name': 'Nationwide', 'company_id': 'co-1'};

const _ids = {
  'Illinois': 'fr-il',
  'Ohio': 'fr-oh',
  'Connecticut': 'fr-ct',
  'Nationwide': 'fr-nw',
};

const _areas = [
  {'id': 'sa-ct', 'name': 'Connecticut', 'franchise_id': 'fr-ct'},
  {'id': 'sa-chi', 'name': 'Chicago', 'franchise_id': 'fr-il'},
  {'id': 'sa-ind', 'name': 'Indiana', 'franchise_id': 'fr-il'},
  {
    'id': 'sa-la',
    'name': 'Los Angeles / Orange County',
    'franchise_id': 'fr-nw',
  },
  {'id': 'sa-cle', 'name': 'Cleveland', 'franchise_id': 'fr-oh'},
  {'id': 'sa-col', 'name': 'Columbus', 'franchise_id': 'fr-oh'},
];

String _wall(String clock) =>
    '${formatCalendarDay(DateTime.now())}T$clock:00-05:00';

Map<String, dynamic> _job(
  String id,
  String franchise,
  String? area, {
  String title = 'Job',
  String customer = 'Maria Delgado',
  String clock = '08:00',
}) => {
  'id': id,
  'number': 'JOB-$id',
  'title': title,
  'status': 'Pending',
  'status_label': 'Pending',
  'scheduled_for': _wall(clock),
  'franchise': {'id': _ids[franchise] ?? 'fr-none', 'name': franchise},
  'customer': {
    'id': 'c-$id',
    'name': customer,
    'number': 'C-1',
    'owned_by_this_franchise': true,
  },
  'amount': {'minor': 0, 'currency': 'USD', 'display': null},
  'service_area': area == null ? null : {'id': 'sa-$id', 'name': area},
};

final _jobs = [
  _job('1', 'Illinois', 'Chicago', title: 'Chimney Sweep'),
  _job('4', 'Connecticut', 'Connecticut', title: 'Flue Liner', clock: '07:00'),
  _job(
    '2',
    'Ohio',
    null,
    title: 'Crown Repair',
    customer: 'Hannah Cole',
    clock: '09:00',
  ),
  _job(
    '3',
    'Nationwide',
    'Los Angeles / Orange County',
    title: 'Liner Install',
    customer: 'Evan Brooks',
    clock: '10:00',
  ),
];

Future<void> _pumpJobs(
  WidgetTester tester, {
  void Function(Map<String, String> query)? onJobs,
  void Function(Map<String, dynamic> body)? onPatch,
  List<Map<String, dynamic>>? areas = _areas,
  List<Map<String, dynamic>> detailAreas = const [
    {'id': 'sa-cle', 'name': 'Cleveland'},
    {'id': 'sa-col', 'name': 'Columbus'},
  ],
  List<Map<String, dynamic>> franchises = const [_il, _oh, _ct, _nw],
  Size size = const Size(1280, 900),
}) async {
  tester.view.physicalSize = size;
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(
    client: MockClient((request) async {
      final path = request.url.path;

      if (path.endsWith('/me')) {
        return _json({
          'data': {
            ..._me,
            'franchises': franchises,
            'permissions': [
              'jobs.view',
              'jobs.create',
              'jobs.edit',
              'jobs.delete',
            ],
          },
        });
      }
      if (path.endsWith('/exports')) {
        return _json({
          'resources': [
            {
              'key': 'jobs',
              'label': 'Jobs',
              'columns': ['number'],
              'importable': false,
            },
          ],
          'organization': {'export_refusal': null, 'inventory_refusal': null},
        });
      }
      if (path.endsWith('/settings')) {
        return _json({
          'data': [
            {
              'key': 'jobs.statuses',
              'value': ['Pending', 'Done'],
            },
          ],
        });
      }
      if (path.contains('/jobs/')) {
        final id = path.split('/').last;
        final job = _jobs.firstWhere((j) => j['id'] == id);
        if (request.method == 'PATCH') {
          onPatch?.call(jsonDecode(request.body) as Map<String, dynamic>);
        }
        return _json({
          'data': {
            ...job,
            'description': null,
            'duration_minutes': null,
            'allowed_statuses': ['Pending', 'Done'],
            'lines': <dynamic>[],
            'allowed_service_areas': detailAreas,
          },
        });
      }
      if (path.endsWith('/jobs')) {
        onJobs?.call(request.url.queryParameters);
        return _json({
          'data': _jobs,
          'meta': {
            'total': _jobs.length,
            'last_page': 1,
            'current_page': 1,
            'service_areas': ?areas,
            if (areas != null) 'unplaced_count': 470,
          },
        });
      }
      return _json({'message': 'Nothing stubbed for $path.'}, 500);
    }),
  );

  final session = Session(api);
  await tester.runAsync(() => session.adopt('tok'));

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(
        body: JobsScreen(api: api, session: session),
      ),
    ),
  );
  await tester.pumpAndSettle();
}

http.Response _json(Object body, [int status = 200]) => http.Response(
  jsonEncode(body),
  status,
  headers: const {'content-type': 'application/json'},
);

const _me = {
  'id': 'u-1',
  'name': 'Jose',
  'email': 'jose@example.com',
  'role': {'name': 'Manager'},
  'has_avatar': false,
  'preferences': <String, dynamic>{},
  'organization': {
    'id': 'org-1',
    'name': 'A1-Group',
    'franchise_label': 'Franchise',
    'is_read_only': false,
  },
  'scope': {
    'type': 'organization',
    'id': 'org-1',
    'label': 'A1-Group',
    'path': ['A1-Group'],
  },
  'companies': <dynamic>[],
};
