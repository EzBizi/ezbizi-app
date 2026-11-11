import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/jobs_screen.dart';
import 'package:ezbizi_client/widgets/side_panel.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Open, search, filter, and a customer-search failure that is not "no such
/// customer". The API already knew how to edit and delete a job.
void main() {
  testWidgets('searching asks the list, and a job-number miss says so', (
    tester,
  ) async {
    final searches = <String>[];

    await _pump(
      tester,
      onRequest: (request) {
        if (request.url.path.endsWith('/jobs')) {
          searches.add(request.url.queryParameters['search'] ?? '');
          return _json({
            'data': <dynamic>[],
            'meta': {'total': 0, 'last_page': 1},
          });
        }
        return null;
      },
    );

    await tester.enterText(find.byType(TextField).first, 'J-1042');
    await tester.testTextInput.receiveAction(TextInputAction.done);
    await tester.pumpAndSettle();

    expect(searches, contains('J-1042'));
    expect(find.text('Job not found.'), findsOneWidget);
  });

  testWidgets('opening a row loads the job and can delete it', (tester) async {
    final paths = <String>[];

    await _pump(
      tester,
      jobs: [_crown],
      onRequest: (request) {
        paths.add('${request.method} ${request.url.path}');

        if (request.method == 'DELETE' &&
            request.url.path.endsWith('/jobs/j-1')) {
          return _json({'message': 'Job deleted.'});
        }

        return null;
      },
    );

    await tester.tap(find.text('Crown inspection'));
    await tester.pumpAndSettle();

    expect(find.textContaining('J-1001'), findsWidgets);
    expect(find.text('Scheduled for'), findsOneWidget);
    expect(find.text('Delete'), findsOneWidget);

    await tester.ensureVisible(find.text('Delete'));
    await tester.tap(find.text('Delete'));
    await tester.pumpAndSettle();

    // Asked twice (Jose, 2026-11-09): the first panel names the job, the
    // second wants its number typed back. Nothing is deleted before that.
    expect(find.text('Delete J-1001?'), findsOneWidget);
    await tester.tap(find.byKey(const Key('job-delete-continue')));
    await tester.pumpAndSettle();
    final confirm = find.byKey(const Key('job-delete-confirm'));
    expect(tester.widget<FilledButton>(confirm).onPressed, isNull);
    await tester.enterText(find.byKey(const Key('job-delete-number')), 'J-100');
    await tester.pump();
    expect(tester.widget<FilledButton>(confirm).onPressed, isNull);
    expect(paths.any((p) => p.startsWith('DELETE')), isFalse);

    await tester.enterText(
      find.byKey(const Key('job-delete-number')),
      'J-1001',
    );
    await tester.pump();
    await tester.tap(confirm);
    await tester.pumpAndSettle();

    expect(paths.any((p) => p == 'DELETE /api/v1/jobs/j-1'), isTrue);
  });

  // Jose, 2026-10-19: at organization level each job says its company.
  testWidgets('at organization level a Company column names each company', (
    tester,
  ) async {
    await _pump(
      tester,
      jobs: [
        {
          ..._crown,
          'company': {'id': 'co-1', 'name': 'A-1 Chimney'},
        },
      ],
    );

    expect(find.text('Company'), findsOneWidget);
    expect(find.text('A-1 Chimney'), findsOneWidget);
  });

  testWidgets('with one company selected there is no Company column', (
    tester,
  ) async {
    await _pump(
      tester,
      jobs: [
        {
          ..._crown,
          'company': {'id': 'co-1', 'name': 'A-1 Chimney'},
        },
      ],
      scope: {
        'type': 'company',
        'id': 'co-1',
        'label': 'A-1 Chimney',
        'path': ['Everdoors', 'A-1 Chimney'],
      },
    );

    expect(find.text('Company'), findsNothing);
    expect(
      find.text('Crown inspection'),
      findsOneWidget,
      reason: 'the control',
    );
  });

  testWidgets(
    'a Workiz job shows its Workiz number beside ours, list and panel',
    (tester) async {
      await _pump(
        tester,
        jobs: [
          {..._crown, 'workiz_job_number': 12345},
          {
            ..._job(number: 'J-1002', title: 'Typed in EzBizi'),
            'id': 'j-2',
            'workiz_job_number': null,
          },
        ],
        onRequest: (request) => request.url.path.endsWith('/jobs/j-1')
            ? _json({
                'data': {..._crownDetail, 'workiz_job_number': 12345},
              })
            : null,
      );

      expect(find.text('Workiz'), findsOneWidget, reason: 'the column');
      expect(find.text('#12345'), findsOneWidget);
      expect(find.text('—'), findsWidgets, reason: 'a job Workiz never had');

      await tester.tap(find.text('Crown inspection'));
      await tester.pumpAndSettle();

      expect(find.textContaining('J-1001 · Workiz #12345'), findsOneWidget);
    },
  );

  testWidgets('with no Workiz job loaded there is no Workiz column', (
    tester,
  ) async {
    // Most organizations have no Workiz: a column of dashes would be dead weight.
    await _pump(tester, jobs: [_crown]);

    expect(
      find.text('Crown inspection'),
      findsOneWidget,
      reason: 'the control',
    );
    expect(find.text('Workiz'), findsNothing);
  });

  testWidgets('a failed customer search is not an empty result', (
    tester,
  ) async {
    await _pump(tester);

    await tester.tap(find.text('Add job'));
    await tester.pumpAndSettle();

    // Add job opens the shared Book panel (design 153).
    final customerField = find.descendant(
      of: find.byKey(const ValueKey('book-client-search')),
      matching: find.byType(TextField),
    );
    expect(customerField, findsOneWidget);

    await tester.enterText(customerField, 'smith');
    await tester.pump(const Duration(milliseconds: 300));
    await tester.pumpAndSettle();

    expect(
      find.textContaining('Could not search customers'),
      findsOneWidget,
      reason:
          'An empty suggestion list reads as "no such customer". The '
          'request failed — that has to be said.',
    );
  });

  testWidgets('the create form has no typed price field', (tester) async {
    await _pump(tester);

    await tester.tap(find.text('Add job'));
    await tester.pumpAndSettle();

    // Task 8: price comes from service lines, always — a job is created
    // bare and priced afterwards by adding lines in the detail panel, not by
    // typing a number here. Only a "Notes" field is left after "Status".
    expect(find.text('Price'), findsNothing);
  });

  testWidgets('a job row shows the role it is assigned to', (tester) async {
    await _pump(
      tester,
      jobs: [
        _job(
          number: 'JOB-1042',
          title: 'Chimney sweep',
          role: 'Chimney Technician',
        ),
      ],
    );

    // Scoped to the row's own tag, not the tree at large: a role name that
    // also appears as a filter choice is not a second row tag, and a plain
    // find.text would count both.
    expect(
      find.descendant(
        of: find.byKey(const Key('job-role-tag')),
        matching: find.text('Chimney Technician'),
      ),
      findsOneWidget,
    );
  });

  testWidgets('a job with no role does not draw an empty tag', (tester) async {
    await _pump(
      tester,
      jobs: [_job(number: 'JOB-1043', title: 'Unassigned call')],
    );

    expect(find.byKey(const Key('job-role-tag')), findsNothing);
  });

  testWidgets(
    'several roles at 375px: rows and filter chips both wrap, neither overflows',
    (tester) async {
      await _pump(
        tester,
        jobs: [
          _job(
            number: 'JOB-1044',
            title: 'Full exterior chimney crown and flashing inspection',
            role: 'Chimney Sweep Technician',
          ),
          _job(
            number: 'JOB-1045',
            title: 'Flue liner replacement',
            role: 'Mason',
          ),
          _job(number: 'JOB-1046', title: 'Unassigned call'),
        ],
        size: const Size(375, 800),
      );

      expect(tester.takeException(), isNull);
    },
  );

  testWidgets(
    'a shared-customer row at 375px does not overflow without a role',
    (tester) async {
      await _pump(
        tester,
        jobs: [
          _job(
            number: 'JOB-1047',
            title: 'Full exterior chimney crown and flashing inspection',
            ownedHere: false,
          ),
        ],
        size: const Size(375, 800),
      );

      expect(tester.takeException(), isNull);
    },
  );
  // Queue 118, Jose's 1 and phone 4: type, sub-status and tags. Every word
  // here is invented.
  group('type, sub-status and tags', () {
    const labels = {
      'job_types': ['Cap Repair', 'Flue Survey'],
      'sub_statuses': ['Weather delay'],
      'tags': ['Gate code', 'Ladder needed', '🔥 Hot lead'],
    };
    final tagged = {
      ..._crown,
      'job_type': 'Flue Survey',
      'sub_status': null,
      'tags': ['🔥 Hot lead', 'Gate code', 'Ladder needed'],
    };
    final taggedDetail = {..._crownDetail, ...tagged};

    /// The list with [labels], the detail as [detail], and every PATCH body.
    Future<List<Map<String, dynamic>>> open(
      WidgetTester tester, {
      Map<String, dynamic>? detail,
    }) async {
      final patches = <Map<String, dynamic>>[];
      final current = Map<String, dynamic>.from(detail ?? taggedDetail);

      await _pump(
        tester,
        jobs: [tagged],
        onRequest: (request) {
          final path = request.url.path;
          if (path.endsWith('/jobs')) {
            return _json({
              'data': [tagged],
              'meta': {'total': 1, 'last_page': 1, 'labels': labels},
            });
          }
          if (path.endsWith('/jobs/j-1') && request.method == 'PATCH') {
            final body = jsonDecode(request.body) as Map<String, dynamic>;
            patches.add(body);
            current.addAll(body);
            return _json({'data': current});
          }
          if (path.endsWith('/jobs/j-1')) return _json({'data': current});
          return null;
        },
      );

      await tester.tap(find.text('Crown inspection'));
      await tester.pumpAndSettle();

      return patches;
    }

    Finder inDialog(Finder f) =>
        find.descendant(of: find.byType(SidePanel).last, matching: f);

    testWidgets('the panel shows the type and the tags; no sub-status on an '
        'open job without one', (tester) async {
      await open(tester);

      expect(
        find.descendant(
          of: find.byKey(const Key('job-type')),
          matching: find.text('Flue Survey'),
        ),
        findsOneWidget,
      );
      for (final t in ['🔥 Hot lead', 'Gate code', 'Ladder needed']) {
        expect(find.byKey(ValueKey('job-tag-$t')), findsOneWidget, reason: t);
      }
      expect(find.byKey(const Key('job-tag-add')), findsOneWidget);
      expect(find.byKey(const Key('job-sub-status')), findsNothing);
    });

    testWidgets('a cancelled job offers a sub-status, and one set shows', (
      tester,
    ) async {
      await open(
        tester,
        detail: {
          ...taggedDetail,
          'status': 'cancelled',
          'allowed_statuses': ['scheduled', 'cancelled'],
        },
      );
      expect(find.byKey(const Key('job-sub-status')), findsOneWidget);
    });

    testWidgets('a sub-status already set shows on an open job', (
      tester,
    ) async {
      await open(
        tester,
        detail: {...taggedDetail, 'sub_status': 'Weather delay'},
      );
      expect(
        find.descendant(
          of: find.byKey(const Key('job-sub-status')),
          matching: find.text('Weather delay'),
        ),
        findsOneWidget,
      );
    });

    testWidgets('removing a tag PATCHes the rest of the list, and only it', (
      tester,
    ) async {
      final patches = await open(tester);

      final chip = find.byKey(const ValueKey('job-tag-Gate code'));
      await tester.ensureVisible(chip);
      await tester.tap(find.byTooltip('Remove Gate code'));
      await tester.pumpAndSettle();

      expect(patches, [
        {
          'tags': ['🔥 Hot lead', 'Ladder needed'],
        },
      ]);
      expect(find.byKey(const ValueKey('job-tag-Gate code')), findsNothing);
    });

    testWidgets('a new tag is added from what was typed', (tester) async {
      final patches = await open(tester);

      await tester.ensureVisible(find.byKey(const Key('job-tag-add')));
      await tester.tap(find.byKey(const Key('job-tag-add')));
      await tester.pumpAndSettle();

      // The ones in use, less those the job has: none left here.
      await tester.enterText(inDialog(find.byType(TextField)), 'Back gate');
      await tester.pumpAndSettle();
      expect(find.text('Add "Back gate"'), findsOneWidget);

      await tester.tap(find.byKey(const Key('search-picker-add')));
      await tester.pumpAndSettle();

      expect(patches, [
        {
          'tags': ['🔥 Hot lead', 'Gate code', 'Ladder needed', 'Back gate'],
        },
      ]);
      expect(find.byKey(const ValueKey('job-tag-Back gate')), findsOneWidget);
    });

    testWidgets('changing the type PATCHes only the type', (tester) async {
      final patches = await open(tester);

      await tester.ensureVisible(find.byKey(const Key('job-type')));
      await tester.tap(find.byKey(const Key('job-type')));
      await tester.pumpAndSettle();
      await tester.tap(inDialog(find.text('Cap Repair')));
      await tester.pumpAndSettle();

      expect(patches, [
        {'job_type': 'Cap Repair'},
      ]);
      expect(
        find.descendant(
          of: find.byKey(const Key('job-type')),
          matching: find.text('Cap Repair'),
        ),
        findsOneWidget,
      );
    });

    testWidgets('a row shows its type, two tags and "+1", all on hover', (
      tester,
    ) async {
      await _pump(tester, jobs: [tagged]);

      expect(find.text('Flue Survey'), findsOneWidget);
      expect(find.text('🔥 Hot lead'), findsOneWidget);
      expect(find.text('Gate code'), findsOneWidget);
      expect(find.text('Ladder needed'), findsNothing);
      expect(find.text('+1'), findsOneWidget);
      expect(
        find.byTooltip('🔥 Hot lead\nGate code\nLadder needed'),
        findsOneWidget,
      );
    });

    testWidgets('a phone card shows the type, the first tag and "+2"', (
      tester,
    ) async {
      await _pump(tester, jobs: [tagged], size: const Size(375, 800));

      expect(find.text('Flue Survey'), findsOneWidget);
      expect(find.text('🔥 Hot lead'), findsOneWidget);
      expect(find.text('Gate code'), findsNothing);
      expect(find.text('+2'), findsOneWidget);
      expect(tester.takeException(), isNull);
    });

    testWidgets('the filters send one value as both forms, several as a list', (
      tester,
    ) async {
      final queries = <Map<String, List<String>>>[];

      await _pump(
        tester,
        onRequest: (request) {
          if (!request.url.path.endsWith('/jobs')) return null;
          queries.add(request.url.queryParametersAll);
          return _json({
            'data': <dynamic>[],
            'meta': {'total': 0, 'last_page': 1, 'labels': labels},
          });
        },
      );

      Future<void> pick(Key field, List<String> words) async {
        await tester.tap(find.byKey(field));
        await tester.pumpAndSettle();
        for (final w in words) {
          await tester.tap(inDialog(find.text(w)));
          await tester.pumpAndSettle();
        }
        await tester.tap(inDialog(find.text('Done')));
        await tester.pumpAndSettle();
      }

      await pick(const Key('jobs-type-filter'), ['Cap Repair']);
      expect(queries.last['job_type[]'], ['Cap Repair']);
      expect(queries.last['job_type'], ['Cap Repair']);

      await pick(const Key('jobs-type-filter'), ['Flue Survey']);
      expect(queries.last['job_type[]'], ['Cap Repair', 'Flue Survey']);
      expect(queries.last.containsKey('job_type'), isFalse);

      await pick(const Key('jobs-tag-filter'), ['Gate code']);
      expect(queries.last['tag[]'], ['Gate code']);
      expect(queries.last['tag'], ['Gate code']);
      expect(queries.last['job_type[]'], [
        'Cap Repair',
        'Flue Survey',
      ], reason: 'the two filters go together');
    });
  });
}

const _crown = {
  'id': 'j-1',
  'number': 'J-1001',
  'title': 'Crown inspection',
  'status': 'scheduled',
  'scheduled_for': '2026-03-08T09:00:00',
  'franchise': {'id': 'fr-1', 'name': 'Connecticut'},
  'customer': {
    'id': 'c-1',
    'name': 'Alice Roofing',
    'number': 'C-1001',
    'owned_by_this_franchise': true,
  },
  'amount': {'minor': 19999, 'currency': 'USD', 'display': '199.99'},
};

const _crownDetail = {
  ..._crown,
  'description': 'Check the crown.',
  'duration_minutes': 60,
  'allowed_statuses': ['scheduled', 'completed'],
};

/// A minimal job row, for the tests that only care about one or two fields.
/// `role` adds `assigned_role` the way the server does — omitted, not null,
/// when the job has none, which is the common case at booking time.
Map<String, dynamic> _job({
  required String number,
  required String title,
  String? role,
  bool ownedHere = true,
}) => {
  'id': number,
  'number': number,
  'title': title,
  'status': 'scheduled',
  'franchise': {'id': 'fr-1', 'name': 'Connecticut'},
  'customer': {
    'id': 'c-1',
    'name': 'Alice Roofing',
    'number': 'C-1001',
    'owned_by_this_franchise': ownedHere,
  },
  'amount': {'minor': 0, 'currency': 'USD', 'display': null},
  if (role != null) 'assigned_role': {'id': 'r-$role', 'name': role},
};

Future<void> _pump(
  WidgetTester tester, {
  List<String> permissions = const [
    'jobs.view',
    'jobs.create',
    'jobs.edit',
    'jobs.delete',
  ],
  List<Map<String, dynamic>> jobs = const [],
  http.Response? Function(http.Request request)? onRequest,
  Size size = const Size(1280, 900),
  Map<String, dynamic>? scope,
}) async {
  tester.view.physicalSize = size;
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(
    client: MockClient((request) async {
      final override = onRequest?.call(request);
      if (override != null) return override;

      final path = request.url.path;

      if (path.endsWith('/me')) {
        return _json({
          'data': {..._me, 'permissions': permissions, 'scope': ?scope},
        });
      }

      if (path.endsWith('/exports')) {
        return _json({
          'resources': [
            {
              'key': 'jobs',
              'label': 'Jobs',
              'columns': ['number', 'title'],
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
              'value': ['scheduled', 'completed'],
            },
          ],
        });
      }

      if (path.endsWith('/jobs/j-1')) {
        return _json({'data': _crownDetail});
      }

      if (path.endsWith('/jobs')) {
        return _json({
          'data': jobs,
          'meta': {'total': jobs.length, 'last_page': 1},
        });
      }

      if (path.endsWith('/customers') &&
          (request.url.queryParameters['search'] ?? '').isNotEmpty) {
        return _json({'message': 'The mail service stopped answering.'}, 502);
      }

      if (path.endsWith('/customers')) {
        return _json({
          'data': <dynamic>[],
          'meta': {'total': 0, 'last_page': 1},
        });
      }

      return _json({'message': 'Nothing stubbed for $path.'}, 500);
    }),
  );

  final session = Session(api);
  await tester.runAsync(() => session.adopt('tok'));
  expect(session.signedIn, isTrue);

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
    'name': 'Everdoors',
    'franchise_label': 'Franchise',
    'is_read_only': false,
  },
  'scope': {
    'type': 'organization',
    'id': 'org-1',
    'label': 'Everdoors',
    'path': ['Everdoors'],
  },
  'companies': <dynamic>[],
  'franchises': [
    {'id': 'fr-1', 'name': 'Connecticut', 'company_id': 'co-1'},
  ],
};
