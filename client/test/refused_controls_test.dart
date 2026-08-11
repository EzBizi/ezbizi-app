import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/customers_screen.dart';
import 'package:ezbizi_client/screens/jobs_screen.dart';
import 'package:ezbizi_client/screens/organization_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Controls somebody cannot use, on the four screens that got it wrong.
///
/// ── The two gates, and why one sentence will not do ──────────────
///
/// §4 refuses in two places. The role may not hold the permission, which
/// somebody who edits roles fixes in ten seconds. Or the role holds it and the
/// person is assigned nowhere it applies, which is a different screen and often
/// a different person. Jobs and Customers tested only the second — silently —
/// and never asked about the permission at all, so a role without
/// `jobs.create` was handed the button and a 403.
///
/// Telling somebody who HOLDS the permission that they do not have permission
/// is the specific failure worth a test: they will go and ask for a grant they
/// already have, and be told they already have it.
void main() {
  group('Add job', () {
    testWidgets('is refused for a missing permission, and says a role holds it', (
      tester,
    ) async {
      await _pumpJobs(tester, permissions: const ['jobs.view']);

      expect(_button(tester, 'Add job').onPressed, isNull);
      expect(
        find.byTooltip(
          'You do not have permission to add a job. That is part of a role '
          'rather than of an account, so somebody who can edit roles can add it '
          'to yours.',
        ),
        findsOneWidget,
      );
    });

    testWidgets('is refused for a missing assignment, and says so instead', (
      tester,
    ) async {
      await _pumpJobs(
        tester,
        permissions: const ['jobs.view', 'jobs.create'],
        franchises: const [],
      );

      expect(_button(tester, 'Add job').onPressed, isNull);

      final tooltip = _onlyTooltip(tester);

      expect(tooltip, contains('not assigned to any franchise'));
      expect(
        tooltip,
        isNot(contains('do not have permission')),
        reason: 'They hold it. Saying otherwise sends them to ask for it again.',
      );
    });

    testWidgets('uses the organization’s own word for a place', (tester) async {
      await _pumpJobs(
        tester,
        permissions: const ['jobs.view', 'jobs.create'],
        franchises: const [],
        franchiseLabel: 'Territory',
      );

      // §8. The old sentence was hardcoded to "franchise", which is the one word
      // this product lets a customer replace.
      expect(_onlyTooltip(tester), contains('not assigned to any territory'));
      expect(_onlyTooltip(tester), isNot(contains('franchise')));
    });

    testWidgets('works when both gates pass', (tester) async {
      await _pumpJobs(tester, permissions: const ['jobs.view', 'jobs.create']);

      expect(_button(tester, 'Add job').onPressed, isNotNull);
      expect(find.byType(Tooltip), findsNothing);
    });
  });

  group('Add customer', () {
    testWidgets('is refused for a missing permission', (tester) async {
      await _pumpCustomers(tester, permissions: const ['customers.view']);

      expect(_button(tester, 'Add customer').onPressed, isNull);
      expect(
        _onlyTooltip(tester),
        contains('permission to add a customer'),
        reason: 'It was disabled on reach alone, silently, and never asked '
            'about the permission it is named after.',
      );
    });

    testWidgets('is refused for a missing assignment', (tester) async {
      await _pumpCustomers(
        tester,
        permissions: const ['customers.view', 'customers.create'],
        franchises: const [],
      );

      expect(_onlyTooltip(tester), contains('not assigned to any franchise'));
      expect(_onlyTooltip(tester), contains('accountable for the relationship'));
    });
  });

  group('the organization tree', () {
    testWidgets('shows Add a place refused, in the server’s own words', (
      tester,
    ) async {
      await _pumpOrganization(
        tester,
        mayAddFranchise: false,
        reason: 'You hold the permission to add a place to Everdoors, but '
            'Everdoors is outside where you are assigned.',
      );

      // Shown, not hidden, and explained. It used to be drawn from `may_edit` —
      // the wrong permission entirely — and disabled with nothing said.
      final chip = tester.widget<ActionChip>(
        find.widgetWithText(ActionChip, 'Add a place'),
      );
      expect(chip.onPressed, isNull);
      expect(
        find.byTooltip(
          'You hold the permission to add a place to Everdoors, but Everdoors '
          'is outside where you are assigned.',
        ),
        findsOneWidget,
      );
    });

    testWidgets('offers Add a place to somebody who may only do that', (
      tester,
    ) async {
      // The other direction of the same defect: `may_edit` false, so the whole
      // menu and the chip went away from somebody holding `franchises.create`.
      await _pumpOrganization(tester, mayEdit: false, mayAddFranchise: true);

      final chip = tester.widget<ActionChip>(
        find.widgetWithText(ActionChip, 'Add a place'),
      );
      expect(chip.onPressed, isNotNull);
      expect(find.byType(PopupMenuButton<String>), findsWidgets);
    });
  });
}

// ── driving the screens ────────────────────────────────────────────

Future<void> _pumpJobs(
  WidgetTester tester, {
  required List<String> permissions,
  List<Map<String, dynamic>> franchises = _oneFranchise,
  String franchiseLabel = 'Franchise',
}) => _pump(
  tester,
  permissions: permissions,
  franchises: franchises,
  franchiseLabel: franchiseLabel,
  path: '/jobs',
  build: (api, session) => JobsScreen(api: api, session: session),
);

Future<void> _pumpCustomers(
  WidgetTester tester, {
  required List<String> permissions,
  List<Map<String, dynamic>> franchises = _oneFranchise,
}) => _pump(
  tester,
  permissions: permissions,
  franchises: franchises,
  path: '/customers',
  build: (api, session) => CustomersScreen(api: api, session: session),
);

Future<void> _pumpOrganization(
  WidgetTester tester, {
  bool mayEdit = true,
  bool mayAddFranchise = true,
  String? reason,
}) => _pump(
  tester,
  permissions: const ['companies.view'],
  franchises: _oneFranchise,
  path: '/companies',
  tree: {
    'organization': {
      'id': 'org-1',
      'name': 'Everdoors',
      'may_add_company': false,
      'may_add_company_reason': 'Only somebody assigned at the whole '
          'organization can add a company.',
      'has_logo': false,
      'may_edit': false,
    },
    'companies': [
      {
        'id': 'co-1',
        'name': 'Everdoors',
        'is_active': true,
        'parent_company_id': null,
        'may_edit': mayEdit,
        'may_add_franchise': mayAddFranchise,
        'may_add_franchise_reason': reason,
        'may_add_sub_company': false,
        'may_add_sub_company_reason': 'Not yours to do.',
        'has_logo': false,
        'franchises': <Map<String, dynamic>>[],
        'sub_companies': <Map<String, dynamic>>[],
      },
    ],
  },
  build: (api, session) => OrganizationScreen(api: api, session: session),
);

Future<void> _pump(
  WidgetTester tester, {
  required List<String> permissions,
  required List<Map<String, dynamic>> franchises,
  required String path,
  required Widget Function(ApiClient, Session) build,
  String franchiseLabel = 'Franchise',
  Map<String, dynamic>? tree,
}) async {
  tester.view.physicalSize = const Size(1400, 1000);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(
    client: MockClient((request) async {
      if (request.url.path.endsWith('/me')) {
        return _json({
          'data': {
            'id': 'u-1',
            'name': 'Jose',
            'email': 'jose@example.com',
            'has_avatar': false,
            'permissions': permissions,
            'preferences': <String, dynamic>{},
            'organization': {
              'id': 'org-1',
              'name': 'Everdoors',
              'franchise_label': franchiseLabel,
              'is_read_only': false,
            },
            'scope': {
              'type': 'organization',
              'id': 'org-1',
              'label': 'Everdoors',
              'path': ['Everdoors'],
            },
            'companies': <Map<String, dynamic>>[],
            'franchises': franchises,
          },
        });
      }

      if (tree != null && request.url.path.endsWith(path)) {
        return _json(tree);
      }

      // The logo badges on the organization tree fetch bytes. Answering with
      // JSON hands the image decoder something it cannot read, and the failure
      // is reported as a test failure rather than as the missing logo it is.
      if (request.url.path.contains('/branding/')) {
        return _json({'message': 'No logo.'}, 404);
      }

      return _json({'data': <Map<String, dynamic>>[], 'meta': {'total': 0, 'last_page': 1}});
    }),
  );

  final session = Session(api);
  await tester.runAsync(() => session.adopt('tok'));
  expect(session.signedIn, isTrue);

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(body: build(api, session)),
    ),
  );
  await tester.pumpAndSettle();
}

const _oneFranchise = [
  {'id': 'f-1', 'name': 'Connecticut', 'company_id': 'co-1'},
];

http.Response _json(Object body, [int status = 200]) => http.Response(
  jsonEncode(body),
  status,
  headers: const {'content-type': 'application/json'},
);

ButtonStyleButton _button(WidgetTester tester, String label) =>
    tester.widget<ButtonStyleButton>(
      find
          .ancestor(
            of: find.text(label),
            // byType is exact, and FilledButton is a subclass — so byType
            // would find none of the buttons this file is about.
            matching: find.byWidgetPredicate((w) => w is ButtonStyleButton),
          )
          .first,
    );

/// The one explanation on screen.
///
/// Asserted as the ONLY one on purpose: a screen that grows a second tooltip is
/// a screen where two controls are refused and only one of them was meant to be.
String _onlyTooltip(WidgetTester tester) {
  final tooltips = tester.widgetList<Tooltip>(find.byType(Tooltip)).toList();

  expect(tooltips, hasLength(1));

  return tooltips.single.message!;
}
