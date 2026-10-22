import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/customers_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Customers › Possible duplicates gets a search field: the loaded groups are
/// filtered on screen, and q goes to CustomersController@duplicates.
void main() {
  testWidgets('the duplicates search filters groups and sends q', (
    tester,
  ) async {
    final seen = <Uri>[];
    await _pump(tester, seen);

    await tester.tap(find.text('Possible duplicates'));
    await tester.pumpAndSettle();

    expect(find.text('Philip Ash (C-1)'), findsOneWidget);
    expect(find.text('Maria Lopez (C-3)'), findsOneWidget);

    final requestsBefore = seen.length;
    await tester.enterText(
      find.byKey(const Key('customers-duplicates-search')),
      'lopez',
    );
    await tester.pump();

    expect(find.text('Maria Lopez (C-3)'), findsOneWidget);
    expect(find.text('Philip Ash (C-1)'), findsNothing);

    await tester.pump(const Duration(milliseconds: 450));
    await tester.pumpAndSettle();

    final later = seen
        .skip(requestsBefore)
        .where((u) => u.path.endsWith('/customers/duplicates'))
        .toList();
    expect(later, isNotEmpty);
    expect(later.last.queryParameters['q'], 'lopez');

    await tester.enterText(
      find.byKey(const Key('customers-duplicates-search')),
      'zzz',
    );
    await tester.pump();
    expect(find.text('No duplicates match "zzz".'), findsOneWidget);
    expect(find.text('Maria Lopez (C-3)'), findsNothing);
    await tester.pump(const Duration(milliseconds: 450));
    await tester.pumpAndSettle();
  });
}

Map<String, dynamic> _member(
  String id,
  String number,
  String name,
  String franchise,
) => {
  'id': id,
  'number': number,
  'name': name,
  'owning_franchise': {
    'id': 'f-$id',
    'name': franchise,
    'company': {'id': 'co-1', 'name': 'A-1 Chimney'},
  },
};

final _groups = [
  {
    'by': 'phone',
    'value': '5551234567',
    'customers': [
      _member('c-1', 'C-1', 'Philip Ash', 'Florida'),
      _member('c-2', 'C-2', 'Phil Ash', 'New Jersey'),
    ],
  },
  {
    'by': 'email',
    'value': 'maria@example.com',
    'customers': [
      _member('c-3', 'C-3', 'Maria Lopez', 'Florida'),
      _member('c-4', 'C-4', 'M. Lopez', 'Florida'),
    ],
  },
];

Future<void> _pump(WidgetTester tester, List<Uri> seen) async {
  tester.view.physicalSize = const Size(1280, 900);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(
    client: MockClient((request) async {
      seen.add(request.url);
      final path = request.url.path;

      if (path.endsWith('/me')) {
        return _json({
          'data': {
            ..._me,
            'permissions': [
              'customers.view',
              'customers.create',
              'customers.edit',
              'customers.delete',
            ],
          },
        });
      }
      if (path.endsWith('/exports')) {
        return _json({
          'resources': const [],
          'organization': {'export_refusal': null, 'inventory_refusal': null},
        });
      }
      if (path.endsWith('/customers/duplicates')) {
        // The server ignores q today; the client filter is what the test sees.
        return _json({
          'mode': 'phone',
          'limited': false,
          'total': 2,
          'data': _groups,
        });
      }
      if (path.endsWith('/customers')) {
        return _json({
          'data': const [],
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
        body: CustomersScreen(api: api, session: session),
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
