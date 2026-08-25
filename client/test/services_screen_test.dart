import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/main.dart';
import 'package:ezbizi_client/screens/services_screen.dart';
import 'package:ezbizi_client/widgets/coming_soon.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

void main() {
  testWidgets('a granted Services tab shows Catalogue and Settings, not Coming soon', (
    tester,
  ) async {
    await _pumpShell(tester);

    expect(find.byType(ComingSoonScreen), findsNothing);
    expect(find.byType(ServicesScreen), findsOneWidget);
    expect(
      find.descendant(
        of: find.byType(ServicesScreen),
        matching: find.text('Catalogue'),
      ),
      findsWidgets,
    );
    expect(
      find.descendant(
        of: find.byType(ServicesScreen),
        matching: find.text('Settings'),
      ),
      findsOneWidget,
    );
  });

  testWidgets('Catalogue lists services and Add service is not a modal', (
    tester,
  ) async {
    await _pumpScreen(tester, services: [_service]);

    expect(find.text('Chimney sweep'), findsOneWidget);
    expect(find.text('Add service'), findsOneWidget);
    expect(find.byType(Dialog), findsNothing);
  });

  testWidgets('Add service posts name, price, group and label', (tester) async {
    Map<String, dynamic>? posted;

    await _pumpScreen(
      tester,
      groups: [_group],
      labels: [_label],
      onRequest: (request) {
        if (request.method == 'POST' &&
            request.url.path.endsWith('/services') &&
            !request.url.path.contains('/groups') &&
            !request.url.path.contains('/labels')) {
          posted = jsonDecode(request.body) as Map<String, dynamic>;
          return _json({
            'data': {..._service, 'name': posted!['name']},
          }, 201);
        }
        return null;
      },
    );

    await tester.tap(find.text('Add service'));
    await tester.pumpAndSettle();

    expect(find.text('New service'), findsOneWidget);
    expect(find.byType(Dialog), findsNothing);

    await tester.enterText(
      find.widgetWithText(TextField, 'Name of service'),
      'Cap install',
    );
    await tester.enterText(find.widgetWithText(TextField, 'Price'), '219');
    await tester.pump();
    await tester.tap(find.text('Save'));
    await tester.pumpAndSettle();

    expect(posted, isNotNull);
    expect(posted!['name'], 'Cap install');
    expect(posted!['price_minor'], 21900);
  });

  testWidgets('Settings can add a group', (tester) async {
    Map<String, dynamic>? posted;

    await _pumpScreen(
      tester,
      onRequest: (request) {
        if (request.method == 'POST' &&
            request.url.path.endsWith('/services/groups')) {
          posted = jsonDecode(request.body) as Map<String, dynamic>;
          return _json({
            'data': {'id': 'g-new', 'name': posted!['name']},
          }, 201);
        }
        return null;
      },
    );

    await tester.tap(find.text('Settings'));
    await tester.pumpAndSettle();

    expect(find.text('Groups'), findsOneWidget);
    expect(find.text('Labels'), findsOneWidget);

    await tester.enterText(find.widgetWithText(TextField, 'New group'), 'Caps');
    await tester.pump();
    await tester.tap(find.text('Add group'));
    await tester.pumpAndSettle();

    expect(posted, isNotNull);
    expect(posted!['name'], 'Caps');
  });

  testWidgets(
    'a company view does not offer sibling companies on New service',
    (tester) async {
      await _pumpScreen(
        tester,
        me: {
          'organization': {
            'id': 'org-1',
            'name': 'EzBizi Group',
            'franchise_label': 'Franchise',
            'is_read_only': false,
          },
          'scope': {
            'type': 'company',
            'id': 'co-a1',
            'label': 'A-1 Chimney',
            'path': ['A-1 Chimney'],
          },
          'companies': [
            {'id': 'co-a1', 'name': 'A-1 Chimney', 'parent_company_id': null},
            {'id': 'co-ever', 'name': 'Everdoors', 'parent_company_id': null},
          ],
          'franchises': [
            {'id': 'fr-a1', 'name': 'Connecticut', 'company_id': 'co-a1'},
          ],
        },
      );

      await tester.tap(find.text('Add service'));
      await tester.pumpAndSettle();

      expect(find.text('Everdoors'), findsNothing);
      expect(find.text('Company'), findsNothing);
    },
  );
}

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
    matching: find.text('Services'),
  );
  expect(inSidebar, findsOneWidget);
  await tester.tap(inSidebar);
  await tester.pumpAndSettle();
}

Future<void> _pumpScreen(
  WidgetTester tester, {
  List<String> permissions = const [
    'services.view',
    'services.create',
    'services.edit',
    'services.delete',
  ],
  Map<String, dynamic>? me,
  List<Map<String, dynamic>> services = const [],
  List<Map<String, dynamic>> groups = const [],
  List<Map<String, dynamic>> labels = const [],
  http.Response? Function(http.Request request)? onRequest,
}) async {
  tester.view.physicalSize = const Size(1280, 900);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(
    client: MockClient(
      _handler(
        permissions: permissions,
        me: me,
        services: services,
        groups: groups,
        labels: labels,
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
        body: ServicesScreen(api: api, session: session),
      ),
    ),
  );
  await tester.pumpAndSettle();
}

MockClientHandler _handler({
  List<String> permissions = const [
    'services.view',
    'services.create',
    'services.edit',
    'services.delete',
  ],
  Map<String, dynamic>? me,
  List<Map<String, dynamic>> services = const [],
  List<Map<String, dynamic>> groups = const [],
  List<Map<String, dynamic>> labels = const [],
  http.Response? Function(http.Request request)? onRequest,
}) {
  return (request) async {
    final override = onRequest?.call(request);
    if (override != null) return override;

    final path = request.url.path;

    if (path.endsWith('/me')) {
      return _json({
        'data': {..._me, 'permissions': permissions, ...?me},
      });
    }

    if (path.endsWith('/services/groups')) {
      return _json({'data': groups});
    }

    if (path.endsWith('/services/labels')) {
      return _json({'data': labels});
    }

    if (path.endsWith('/services')) {
      return _json({
        'data': services,
        'meta': {'total': services.length, 'last_page': 1},
      });
    }

    if (RegExp(r'/services/[^/]+$').hasMatch(path)) {
      return _json({'data': services.isEmpty ? _service : services.first});
    }

    return _json({'message': 'Nothing stubbed for $path.'}, 500);
  };
}

http.Response _json(Object body, [int status = 200]) => http.Response(
  jsonEncode(body),
  status,
  headers: const {'content-type': 'application/json'},
);

const _group = {'id': 'g-1', 'name': 'Chimney'};
const _label = {'id': 'l-1', 'name': 'Popular'};

const _service = {
  'id': 'svc-1',
  'number': 'SVC-00001',
  'name': 'Chimney sweep',
  'description': 'A standard sweep.',
  'price_minor': 18900,
  'group': _group,
  'label': _label,
  'company': {'id': 'co-1', 'name': 'Everdoors'},
  'franchise': {'id': 'fr-1', 'name': 'Connecticut'},
  'photo': null,
};

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
      'key': 'services',
      'label': 'Services',
      'available': true,
      'reachable': true,
    },
  ],
};
