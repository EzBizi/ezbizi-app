import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/customers_screen.dart';
import 'package:ezbizi_client/screens/jobs_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Import and export, inside the tab that owns the records (plan §12).
///
/// ── What these are here to catch ─────────────────────────────────
///
/// There was an "Import & export" destination listing every resource in one
/// place. The owner removed it — "the import exports will go inside each of the
/// tabs, not a separate tab to do all of them, it's more intuitive that way" —
/// and the failure mode of removing a screen is that the thing it did quietly
/// goes with it. So each of these fails if the control is not in the tab.
///
/// The second thing they pin is the split the move exposed. The catalogue used
/// to answer `importable: false` both for a resource nobody can import (jobs)
/// and for a person who lacks the grant, and the screen drew nothing either
/// way. Those are two different facts owed two different answers, and only one
/// of them is "there is nothing here" (R8).
void main() {
  testWidgets('the customers tab can export and import its own records', (
    tester,
  ) async {
    _wide(tester);

    await _pumpCustomers(tester, permissions: ['customers.create']);

    expect(
      find.text('Export'),
      findsOneWidget,
      reason:
          'Exporting customers has to be reachable FROM customers. It was on a '
          'tab of its own and that tab is gone.',
    );
    expect(find.text('Import'), findsOneWidget);

    expect(_enabled(tester, 'Export'), isTrue);
    expect(_enabled(tester, 'Import'), isTrue);
  });

  /// The import panel is the server's, down to the columns it names.
  testWidgets('importing opens the panel the server described', (tester) async {
    _wide(tester);

    await _pumpCustomers(tester, permissions: ['customers.create']);

    await tester.tap(find.text('Import'));
    await tester.pumpAndSettle();

    expect(find.text('Import customers'), findsOneWidget);
    expect(
      find.text('Columns: number, name, email'),
      findsOneWidget,
      reason:
          'The columns come from the catalogue, so a column added on the server '
          'appears here without a client release.',
    );
  });

  /// R8. Somebody without the grant is told which act was refused and what
  /// fixes it, rather than shown a header with one button fewer.
  testWidgets('an import nobody granted still says why', (tester) async {
    _wide(tester);

    await _pumpCustomers(tester, permissions: const []);

    expect(
      find.text('Import'),
      findsOneWidget,
      reason: 'A control that is simply absent explains nothing.',
    );
    expect(_enabled(tester, 'Import'), isFalse);

    final tooltips = tester
        .widgetList<Tooltip>(find.byType(Tooltip))
        .map((t) => t.message)
        .toList();

    expect(
      tooltips,
      contains(
        'You do not have permission to import customers. That is part of a role '
        'rather than of an account, so somebody who can edit roles can add it '
        'to yours.',
      ),
    );
  });

  /// Jobs are export-only on the server, because a CSV of names cannot say
  /// WHICH "J. Smith" a job belongs to. The tab must not offer an import that
  /// the server would refuse — and this is different from the refusal above:
  /// nobody, at any permission, can import jobs, so there is no grant to
  /// describe and no control to draw.
  testWidgets('the jobs tab exports and does not offer an import', (
    tester,
  ) async {
    _wide(tester);

    await _pumpJobs(tester);

    expect(find.text('Export'), findsOneWidget);
    expect(find.text('Import'), findsNothing);
  });

  /// The catalogue is the permission answer for export: it lists only what this
  /// person may read in bulk. A resource missing from it gets no controls,
  /// because the tab itself is the sentence — somebody who cannot read
  /// customers is not standing on the customers tab.
  testWidgets('a resource the server did not list gets no controls', (
    tester,
  ) async {
    _wide(tester);

    await _pumpCustomers(
      tester,
      permissions: ['customers.create'],
      resources: const [],
    );

    expect(find.text('Export'), findsNothing);
    expect(find.text('Import'), findsNothing);
    // The tab is otherwise itself.
    expect(find.text('Add customer'), findsOneWidget);
  });
}

// ── harness ────────────────────────────────────────────────────────

void _wide(WidgetTester tester) {
  tester.view.physicalSize = const Size(1400, 900);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);
}

/// Is the button carrying this label pressable?
bool _enabled(WidgetTester tester, String label) {
  final button = tester.widget<ButtonStyleButton>(
    find.ancestor(
      of: find.text(label),
      matching: find.byWidgetPredicate((w) => w is ButtonStyleButton),
    ),
  );

  return button.onPressed != null;
}

/// One entry as DataController::catalogue answers it.
///
/// `importable` is a property of the RESOURCE — jobs are false for everybody —
/// and `import_permission` is the grant it takes. Two fields, because one
/// boolean could not tell those apart and the screen drew nothing for both.
Map<String, dynamic> _resource(
  String key,
  String label, {
  required bool importable,
  String? importPermission,
  List<String> importColumns = const [],
}) => {
  'key': key,
  'label': label,
  'columns': const ['number', 'name'],
  'importable': importable,
  'import_permission': importPermission,
  'import_columns': importColumns,
};

Future<void> _pumpCustomers(
  WidgetTester tester, {
  required List<String> permissions,
  List<Map<String, dynamic>>? resources,
}) async {
  resources ??= [
    _resource(
      'customers',
      'Customers',
      importable: true,
      importPermission: 'customers.create',
      importColumns: const ['number', 'name', 'email'],
    ),
    _resource('jobs', 'Jobs', importable: false),
  ];

  await _pump(
    tester,
    permissions: permissions,
    resources: resources,
    build: (api, session) => CustomersScreen(api: api, session: session),
    listPath: '/customers',
  );
}

Future<void> _pumpJobs(WidgetTester tester) => _pump(
  tester,
  permissions: const ['jobs.create'],
  resources: [
    _resource(
      'customers',
      'Customers',
      importable: true,
      importPermission: 'customers.create',
    ),
    _resource('jobs', 'Jobs', importable: false),
  ],
  build: (api, session) => JobsScreen(api: api, session: session),
  listPath: '/jobs',
);

Future<void> _pump(
  WidgetTester tester, {
  required List<String> permissions,
  required List<Map<String, dynamic>> resources,
  required Widget Function(ApiClient, Session) build,
  required String listPath,
}) async {
  final api = ApiClient(
    client: MockClient((request) async {
      final path = request.url.path;

      if (path.endsWith('/me')) {
        return _json({
          'data': {..._me, 'permissions': permissions},
        });
      }

      if (path.endsWith('/exports')) {
        return _json({
          'resources': resources,
          'organization': {
            'export_refusal': null,
            'inventory_refusal': null,
          },
        });
      }

      if (path.endsWith(listPath)) {
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

  expect(
    session.signedIn,
    isTrue,
    reason: 'The /me stub did not produce a session.',
  );

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(body: build(api, session)),
    ),
  );
  await tester.pumpAndSettle();
}

http.Response _json(Object body, [int status = 200]) => http.Response(
  jsonEncode(body),
  status,
  headers: {'content-type': 'application/json'},
);

/// Signed in, assigned to one franchise — so the import panel has somewhere to
/// put the rows and does not open into its own refusal.
const Map<String, dynamic> _me = {
  'id': 'u-1',
  'name': 'Jose Silva',
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
