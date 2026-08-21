import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/main.dart';
import 'package:ezbizi_client/screens/fleet_screen.dart';
import 'package:ezbizi_client/widgets/coming_soon.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Fleet is a register, not a placeholder: list, 25/75 add, no modal.
void main() {
  testWidgets('a granted Fleet tab is the register, not Coming soon', (
    tester,
  ) async {
    await _pumpShell(tester);

    expect(find.byType(ComingSoonScreen), findsNothing);
    expect(find.text('Coming soon'), findsNothing);
    expect(find.byType(FleetScreen), findsOneWidget);
    expect(find.byType(TextField), findsWidgets);
    expect(find.text('Add vehicle'), findsOneWidget);

    await tester.tap(find.text('Add vehicle'));
    await tester.pumpAndSettle();

    expect(find.text('How do we hold it?'), findsOneWidget);
    expect(find.byType(Dialog), findsNothing);
  });

  testWidgets('search is on the list, and Add opens the ownership card', (
    tester,
  ) async {
    await _pumpScreen(tester);

    expect(find.text('Coming soon'), findsNothing);
    expect(
      find.widgetWithText(TextField, 'Name, plate, VIN, number or driver'),
      findsOneWidget,
    );

    await tester.tap(find.text('Add vehicle'));
    await tester.pumpAndSettle();

    expect(find.text('New vehicle'), findsOneWidget);
    expect(find.text('How do we hold it?'), findsOneWidget);
    expect(find.text('Owned'), findsWidgets);
    expect(find.text('Rented'), findsWidgets);
    expect(find.text('Save'), findsOneWidget);
    expect(find.byType(Dialog), findsNothing);
  });

  testWidgets('a failed load is the request failed, not an empty list', (
    tester,
  ) async {
    await _pumpScreen(
      tester,
      onRequest: (request) {
        if (request.url.path.endsWith('/vehicles')) {
          return _json({'message': 'the request failed'}, 500);
        }
        return null;
      },
    );

    expect(find.text('the request failed'), findsOneWidget);
    expect(find.text('There are no vehicles.'), findsNothing);
    expect(find.text('Try again'), findsOneWidget);
  });

  testWidgets('an empty success says there are no vehicles', (tester) async {
    await _pumpScreen(tester);

    expect(find.text('There are no vehicles.'), findsOneWidget);
    expect(find.text('Add vehicle'), findsOneWidget);
  });

  testWidgets('Add is not drawn without create', (tester) async {
    await _pumpScreen(tester, permissions: const ['fleet_management.view']);

    expect(find.text('Add vehicle'), findsNothing);
    expect(find.text('Assign'), findsNothing);
  });

  testWidgets('Assign is not drawn without assign', (tester) async {
    await _pumpScreen(
      tester,
      vehicles: [_van],
      permissions: const [
        'fleet_management.view',
        'fleet_management.create',
        'fleet_management.edit',
      ],
    );

    await tester.tap(find.text('2022 Ford Transit · ABC-1234'));
    await tester.pumpAndSettle();

    expect(find.text('Assign'), findsNothing);
  });

  testWidgets('at 1280 a selected vehicle keeps the list at 25%', (
    tester,
  ) async {
    await _pumpScreen(tester, vehicles: [_van], size: const Size(1280, 900));

    await tester.tap(find.text('2022 Ford Transit · ABC-1234'));
    await tester.pumpAndSettle();

    expect(find.text('2022 Ford Transit · ABC-1234'), findsWidgets);
    expect(find.text('There are no vehicles.'), findsNothing);
    expect(find.byTooltip('Back to the list'), findsNothing);

    final list = tester.getRect(find.byKey(const Key('fleet-list')));
    final pane = tester.getRect(find.byKey(const Key('fleet-pane')));
    expect(pane.width, closeTo(list.width * 3, 8));
  });

  testWidgets('at 375 the pane is the whole body, with Back', (tester) async {
    await _pumpScreen(tester, size: const Size(375, 800));

    expect(tester.takeException(), isNull);

    await tester.ensureVisible(find.text('Add vehicle'));
    await tester.tap(find.text('Add vehicle'));
    await tester.pumpAndSettle();

    expect(tester.takeException(), isNull);
    expect(find.text('How do we hold it?'), findsOneWidget);
    expect(find.byKey(const Key('fleet-list')), findsNothing);
    expect(find.byTooltip('Back to the list'), findsOneWidget);

    await tester.tap(find.byTooltip('Back to the list'));
    await tester.pumpAndSettle();

    expect(find.text('How do we hold it?'), findsNothing);
    expect(find.byKey(const Key('fleet-list')), findsOneWidget);
  });

  testWidgets('Cancel leaves nothing', (tester) async {
    await _pumpScreen(tester);

    await tester.tap(find.text('Add vehicle'));
    await tester.pumpAndSettle();
    expect(find.text('How do we hold it?'), findsOneWidget);

    await tester.tap(find.text('Cancel'));
    await tester.pumpAndSettle();

    expect(find.text('How do we hold it?'), findsNothing);
    expect(find.byKey(const Key('fleet-pane')), findsNothing);
  });

  testWidgets('Save posts identity and the first ownership period', (
    tester,
  ) async {
    Map<String, dynamic>? posted;

    await _pumpScreen(
      tester,
      onRequest: (request) {
        if (request.method == 'POST' &&
            request.url.path.endsWith('/vehicles')) {
          posted = jsonDecode(request.body) as Map<String, dynamic>;
          return _json({
            'data': {
              ..._van,
              'id': 'v-new',
              'label': '2022 Ford Transit · NEW-1',
            },
          }, 201);
        }
        return null;
      },
    );

    await tester.tap(find.text('Add vehicle'));
    await tester.pumpAndSettle();

    await tester.enterText(find.widgetWithText(TextField, 'Year'), '2022');
    await tester.enterText(find.widgetWithText(TextField, 'Make'), 'Ford');
    await tester.enterText(find.widgetWithText(TextField, 'Model'), 'Transit');
    await tester.enterText(find.widgetWithText(TextField, 'Plate'), 'NEW-1');
    await tester.ensureVisible(find.widgetWithText(ChoiceChip, 'Owned'));
    await tester.tap(find.widgetWithText(ChoiceChip, 'Owned'));
    await tester.pumpAndSettle();

    await tester.ensureVisible(find.text('Save'));
    await tester.tap(find.text('Save'));
    await tester.pumpAndSettle();

    expect(posted, isNotNull);
    expect(posted!['company_id'], 'co-1');
    expect(posted!['year'], 2022);
    expect(posted!['make'], 'Ford');
    expect(posted!['model'], 'Transit');
    expect(posted!['plate'], 'NEW-1');
    expect(posted!['ownership'], isA<Map>());
    expect((posted!['ownership'] as Map)['kind'], 'owned');
  });

  testWidgets('no status chip means the list is asked without status', (
    tester,
  ) async {
    final queries = <Map<String, String>>[];

    await _pumpScreen(
      tester,
      onRequest: (request) {
        if (request.url.path.endsWith('/vehicles')) {
          queries.add(request.url.queryParameters);
        }
        return null;
      },
    );

    expect(queries, isNotEmpty);
    expect(queries.first.containsKey('status'), isFalse);
    expect(find.widgetWithText(FilterChip, 'Owned'), findsOneWidget);
    expect(find.widgetWithText(FilterChip, 'Due soon'), findsOneWidget);
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
  'status': 'active',
  'company': {'id': 'co-1', 'name': 'Everdoors'},
  'franchise': {'id': 'fr-1', 'name': 'Connecticut'},
  'ownership': {'kind': 'owned'},
  'assignment': null,
};

Future<void> _pumpShell(WidgetTester tester) async {
  tester.view.physicalSize = const Size(1280, 900);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(client: MockClient(_handler()));
  final session = Session(api);
  await tester.runAsync(() => session.adopt('tok'));
  expect(session.signedIn, isTrue);

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: ListenableBuilder(
        listenable: session,
        builder: (_, _) => HomeShell(api: api, session: session),
      ),
    ),
  );
  await tester.pumpAndSettle();

  final inSidebar = find.descendant(
    of: find.byType(NavigationSidebar),
    matching: find.text('Fleet Management'),
  );
  expect(inSidebar, findsOneWidget);
  await tester.tap(inSidebar);
  await tester.pumpAndSettle();
}

Future<void> _pumpScreen(
  WidgetTester tester, {
  List<String> permissions = const [
    'fleet_management.view',
    'fleet_management.create',
  ],
  List<Map<String, dynamic>> vehicles = const [],
  Size size = const Size(1280, 900),
  http.Response? Function(http.Request request)? onRequest,
}) async {
  tester.view.physicalSize = size;
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(
    client: MockClient(
      _handler(
        permissions: permissions,
        vehicles: vehicles,
        onRequest: onRequest,
      ),
    ),
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
}

MockClientHandler _handler({
  List<String> permissions = const [
    'fleet_management.view',
    'fleet_management.create',
  ],
  List<Map<String, dynamic>> vehicles = const [],
  http.Response? Function(http.Request request)? onRequest,
}) {
  return (request) async {
    final override = onRequest?.call(request);
    if (override != null) return override;

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
