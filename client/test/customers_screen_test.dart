import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/customers_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// The list is not a dead end. The API already knew how to show, edit,
/// deactivate, delete and reassign a customer; these pin the missing face.
void main() {
  testWidgets('creating a customer offers a street address', (tester) async {
    await _pump(tester);

    await tester.tap(find.text('Add customer'));
    await tester.pumpAndSettle();

    expect(find.text('Street address'), findsOneWidget);
    expect(find.text('City'), findsOneWidget);
    expect(find.text('State'), findsOneWidget);
  });

  testWidgets('opening a row loads the customer and can deactivate them', (
    tester,
  ) async {
    final paths = <String>[];

    await _pump(
      tester,
      customers: [_alice],
      onRequest: (request) {
        paths.add('${request.method} ${request.url.path}');

        if (request.method == 'PATCH' &&
            request.url.path.endsWith('/customers/c-1')) {
          return _json({
            'data': {..._aliceDetail, 'is_active': false},
          });
        }

        return null;
      },
    );

    await tester.tap(find.text('Alice Roofing'));
    await tester.pumpAndSettle();

    expect(find.textContaining('C-1001'), findsWidgets);
    expect(find.text('Street address'), findsOneWidget);
    expect(find.text('Deactivate'), findsOneWidget);
    expect(find.text('Reassign owner'), findsOneWidget);
    expect(find.text('Delete'), findsOneWidget);

    await tester.ensureVisible(find.text('Deactivate'));
    await tester.tap(find.text('Deactivate'));
    await tester.pumpAndSettle();

    expect(
      paths.any((p) => p == 'PATCH /api/v1/customers/c-1'),
      isTrue,
      reason: 'Deactivate is a save of is_active, not a missing control.',
    );
  });

  testWidgets('delete is refused in a sentence when the role cannot', (
    tester,
  ) async {
    await _pump(
      tester,
      permissions: const ['customers.view', 'customers.create', 'customers.edit'],
      customers: [_alice],
    );

    await tester.tap(find.text('Alice Roofing'));
    await tester.pumpAndSettle();

    expect(
      find.byTooltip(
        'You do not have permission to delete a customer. That is part of a '
        'role rather than of an account, so somebody who can edit roles can '
        'add it to yours.',
      ),
      findsOneWidget,
    );
  });
}

const _alice = {
  'id': 'c-1',
  'name': 'Alice Roofing',
  'number': 'C-1001',
  'email': 'alice@example.com',
  'phone': '203-555-0100',
  'is_active': true,
  'jobs_count': 0,
  'owning_franchise': {'id': 'fr-1', 'name': 'Connecticut'},
};

const _aliceDetail = {
  ..._alice,
  'address': {
    'line1': '12 Oak St',
    'line2': null,
    'city': 'Hartford',
    'state': 'CT',
    'postal_code': '06103',
  },
  'notes': null,
};

Future<void> _pump(
  WidgetTester tester, {
  List<String> permissions = const [
    'customers.view',
    'customers.create',
    'customers.edit',
    'customers.delete',
  ],
  List<Map<String, dynamic>> customers = const [],
  http.Response? Function(http.Request request)? onRequest,
}) async {
  tester.view.physicalSize = const Size(1280, 900);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(
    client: MockClient((request) async {
      final override = onRequest?.call(request);
      if (override != null) return override;

      final path = request.url.path;

      if (path.endsWith('/me')) {
        return _json({
          'data': {..._me, 'permissions': permissions},
        });
      }

      if (path.endsWith('/exports')) {
        return _json({
          'resources': [
            {
              'key': 'customers',
              'label': 'Customers',
              'columns': ['number', 'name'],
              'importable': true,
              'import_permission': 'customers.create',
              'import_columns': ['number', 'name', 'email'],
            },
          ],
          'organization': {
            'export_refusal': null,
            'inventory_refusal': null,
          },
        });
      }

      if (path.endsWith('/customers/c-1')) {
        return _json({'data': _aliceDetail});
      }

      if (path.endsWith('/customers')) {
        return _json({
          'data': customers,
          'meta': {'total': customers.length, 'last_page': 1},
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
      home: Scaffold(body: CustomersScreen(api: api, session: session)),
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
