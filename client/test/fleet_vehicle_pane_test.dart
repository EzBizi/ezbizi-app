import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/fleet_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Opening a vehicle is one scroll of cards (spec §11), not a preview stub.
void main() {
  testWidgets('without financials the Payments card is absent', (tester) async {
    await _pumpScreen(
      tester,
      vehicles: [_van],
      permissions: const [
        'fleet_management.view',
        'fleet_management.general.view',
        'fleet_management.vehicles.view',
        'fleet_management.create',
        'fleet_management.edit',
      ],
    );

    await tester.tap(find.text('2022 Ford Transit · ABC-1234'));
    await tester.pumpAndSettle();

    expect(find.text('Photos'), findsOneWidget);
    expect(find.text('Identity'), findsOneWidget);
    expect(find.text('Assignment'), findsOneWidget);
    expect(find.text('Ownership'), findsOneWidget);
    expect(find.text('Insurance'), findsOneWidget);
    expect(find.text('Service'), findsOneWidget);
    expect(find.text('Payments'), findsNothing);
    expect(find.text('Assign'), findsNothing);
    expect(find.byType(Dialog), findsNothing);
  });

  testWidgets('with financials the Payments heading is present', (tester) async {
    await _pumpScreen(
      tester,
      vehicles: [_vanWithMoney],
      permissions: const [
        'fleet_management.view',
        'fleet_management.general.view',
        'fleet_management.vehicles.view',
        'fleet_management.create',
        'fleet_management.edit',
        'fleet_management.financials',
      ],
    );

    await tester.tap(find.text('2022 Ford Transit · ABC-1234'));
    await tester.pumpAndSettle();

    expect(find.text('Payments'), findsOneWidget);

    final photos = tester.getTopLeft(find.text('Photos'));
    final identity = tester.getTopLeft(find.text('Identity'));
    final assignment = tester.getTopLeft(find.text('Assignment'));
    final ownership = tester.getTopLeft(find.text('Ownership'));
    final insurance = tester.getTopLeft(find.text('Insurance'));
    final service = tester.getTopLeft(find.text('Service'));
    final payments = tester.getTopLeft(find.text('Payments'));

    expect(photos.dy, lessThan(identity.dy));
    expect(identity.dy, lessThan(assignment.dy));
    expect(assignment.dy, lessThan(ownership.dy));
    expect(ownership.dy, lessThan(insurance.dy));
    expect(insurance.dy, lessThan(service.dy));
    expect(service.dy, lessThan(payments.dy));
  });

  testWidgets('at 375 opening a vehicle is Back, not a 25% list', (
    tester,
  ) async {
    await _pumpScreen(
      tester,
      vehicles: [_van],
      size: const Size(375, 800),
    );

    await tester.tap(find.text('2022 Ford Transit · ABC-1234'));
    await tester.pumpAndSettle();

    expect(find.byTooltip('Back to the list'), findsOneWidget);
    expect(find.byKey(const Key('fleet-list')), findsNothing);
    expect(find.text('Identity'), findsOneWidget);
    expect(find.byType(Dialog), findsNothing);

    await tester.tap(find.byTooltip('Back to the list'));
    await tester.pumpAndSettle();

    expect(find.byKey(const Key('fleet-list')), findsOneWidget);
    expect(find.text('Identity'), findsNothing);
  });
}

const _van = {
  'id': 'v-1',
  'number': 'V-00001',
  'label': '2022 Ford Transit · ABC-1234',
  'year': 2022,
  'make': 'Ford',
  'model': 'Transit',
  'plate': 'ABC-1234',
  'vin': '1FTNE2CM5HKA12345',
  'colour': 'White',
  'body_type': 'van',
  'fuel_type': 'gas',
  'odometer_miles': 41200,
  'next_service_on': '2026-09-01',
  'next_service_miles': 45000,
  'status': 'active',
  'company': {'id': 'co-1', 'name': 'Everdoors'},
  'franchise': {'id': 'fr-1', 'name': 'Connecticut'},
  'ownership': {
    'id': 'own-1',
    'kind': 'owned',
    'counterparty': 'Ford dealer',
    'started_on': '2022-03-08',
    'ended_on': null,
  },
  'ownership_periods': [
    {
      'id': 'own-1',
      'kind': 'owned',
      'counterparty': 'Ford dealer',
      'started_on': '2022-03-08',
      'ended_on': null,
    },
  ],
  'insurance': {
    'id': 'ins-1',
    'carrier': 'Hartford',
    'policy_number': 'POL-9',
    'coverage': 'Commercial auto',
    'started_on': '2026-01-01',
    'expires_on': '2027-01-01',
    'ended_on': null,
  },
  'insurance_policies': [
    {
      'id': 'ins-1',
      'carrier': 'Hartford',
      'policy_number': 'POL-9',
      'coverage': 'Commercial auto',
      'started_on': '2026-01-01',
      'expires_on': '2027-01-01',
      'ended_on': null,
    },
  ],
  'assignment': null,
  'assignments': <Map<String, dynamic>>[],
  'service_visits': [
    {
      'id': 'svc-1',
      'kind': 'oil',
      'occurred_on': '2026-06-01',
      'odometer_miles': 40000,
      'performed_by': 'Jiffy',
      'notes': null,
    },
  ],
};

final _vanWithMoney = {
  ..._van,
  'ownership': {
    ...(_van['ownership'] as Map<String, dynamic>),
    'purchase_price_cents': 2850000,
  },
  'ownership_periods': [
    {
      ...((_van['ownership_periods'] as List).first as Map<String, dynamic>),
      'purchase_price_cents': 2850000,
    },
  ],
  'payments': [
    {
      'id': 'pay-1',
      'category': 'loan',
      'amount_cents': 42000,
      'occurred_on': '2026-08-01',
      'method': 'ach',
      'note': 'August',
    },
  ],
};

Future<void> _pumpScreen(
  WidgetTester tester, {
  required List<Map<String, dynamic>> vehicles,
  List<String> permissions = const [
    'fleet_management.view',
    'fleet_management.general.view',
    'fleet_management.vehicles.view',
    'fleet_management.create',
  ],
  Size size = const Size(1280, 900),
}) async {
  tester.view.physicalSize = size;
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(
    client: MockClient(_handler(permissions: permissions, vehicles: vehicles)),
  );
  final session = Session(api);
  await tester.runAsync(() => session.adopt('tok'));
  expect(session.signedIn, isTrue);

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(
        body: FleetScreen(api: api, session: session),
      ),
    ),
  );
  await tester.pumpAndSettle();

  // General opens first now; these tests are about the register.
  await tester.tap(find.text('Vehicles'));
  await tester.pumpAndSettle();
}

MockClientHandler _handler({
  required List<String> permissions,
  required List<Map<String, dynamic>> vehicles,
}) {
  return (request) async {
    final path = request.url.path;

    if (path.endsWith('/me')) {
      return _json({
        'data': {..._me, 'permissions': permissions},
      });
    }

    if (path.endsWith('/vehicles')) {
      return _json({
        'data': vehicles,
        'meta': {'total': vehicles.length, 'last_page': 1},
      });
    }

    if (path.contains('/vehicles/') && path.endsWith('/v-1')) {
      return _json({'data': vehicles.first});
    }

    if (path.endsWith('/attachments')) {
      return _json({
        'data': <Map<String, dynamic>>[],
        'limits': {
          'max_kilobytes': 20480,
          'max_readable': '20 MB',
          'extensions': ['pdf', 'jpg', 'png'],
        },
      });
    }

    return _json({'message': 'Nothing stubbed for $path.'}, 500);
  };
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
  'companies': [
    {'id': 'co-1', 'name': 'Everdoors', 'parent_company_id': null},
  ],
  'franchises': [
    {'id': 'fr-1', 'name': 'Connecticut', 'company_id': 'co-1'},
  ],
  'modules': [
    {
      'key': 'fleet_management',
      'label': 'Fleet Management',
      'available': true,
      'reachable': true,
    },
  ],
};
