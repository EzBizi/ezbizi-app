import 'dart:convert';
import 'dart:ui' as ui;

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/services/service_list.dart';
import 'package:ezbizi_client/screens/services/service_pane.dart';
import 'package:ezbizi_client/screens/services_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// UI queue 148: the catalogue as photo cards grouped by kind.
void main() {
  late List<int> png;
  setUpAll(() async {
    png = await _png();
  });

  testWidgets('a card with a photo asks for it and paints it', (tester) async {
    final asked = <String>[];
    await _pumpScreen(
      tester,
      services: [_withPhoto],
      onRequest: (r) {
        if (r.url.path.contains('/attachments/')) {
          asked.add(r.url.path);
          return http.Response.bytes(
            png,
            200,
            headers: const {'content-type': 'image/png'},
          );
        }
        return null;
      },
    );
    await tester.runAsync(
      () => Future<void>.delayed(const Duration(milliseconds: 100)),
    );
    await tester.pumpAndSettle();
    expect(asked, ['/api/v1/attachments/att-1']);
    final image = find.descendant(
      of: find.byKey(const ValueKey('services-photo-svc-2')),
      matching: find.byType(Image),
    );
    expect(image, findsOneWidget);
    final box = tester.renderObject<RenderBox>(image);
    expect(box.hasSize, isTrue);
    expect(box.size.width, greaterThan(0));
    expect(box.size.height, greaterThan(0));
    expect(find.text(r'$250.00'), findsOneWidget);
    expect(find.text('Everdoors · Connecticut'), findsOneWidget);
    expect(find.text('Add a photo'), findsNothing);
  });

  testWidgets(
    'no photo shows the placeholder and Add a photo opens the editor',
    (tester) async {
      await _pumpScreen(tester, services: [_service]);
      expect(find.text('Add a photo'), findsOneWidget);
      expect(find.byType(Image), findsNothing);
      await tester.tap(find.text('Add a photo'));
      await tester.pumpAndSettle();
      expect(find.byType(ServicePane), findsOneWidget);
      expect(find.text('Photo'), findsOneWidget);
    },
  );

  testWidgets('a role that cannot edit gets no Add a photo', (tester) async {
    await _pumpScreen(
      tester,
      services: [_service],
      permissions: const ['services.view', 'services.catalogue.view'],
    );
    expect(find.byKey(const ValueKey('services-card-svc-1')), findsOneWidget);
    expect(find.text('Add a photo'), findsNothing);
  });

  testWidgets('groups by group, then label, then Other, with counts', (
    tester,
  ) async {
    await _pumpScreen(tester, services: _mixed);
    expect(
      find.byKey(const ValueKey('services-group-Chimney')),
      findsOneWidget,
    );
    expect(find.text('Chimney · 2'), findsWidgets);
    expect(find.text('Popular · 1'), findsWidgets);
    expect(find.text('Other · 1'), findsWidgets);
    expect(serviceKind({'group': null, 'label': null}), 'Other');
  });

  testWidgets('a kind chip filters to that kind', (tester) async {
    await _pumpScreen(tester, services: _mixed);
    await tester.tap(find.byKey(const ValueKey('services-kind-Popular')));
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('services-card-svc-c')), findsOneWidget);
    expect(find.byKey(const ValueKey('services-card-svc-a')), findsNothing);
    expect(find.byKey(const ValueKey('services-card-svc-d')), findsNothing);
    await tester.tap(find.byKey(const ValueKey('services-kind-all')));
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('services-card-svc-a')), findsOneWidget);
  });

  testWidgets('sort by name or price reorders the cards', (tester) async {
    await _pumpScreen(tester, services: _mixed);
    double x(String id) =>
        tester.getTopLeft(find.byKey(ValueKey('services-card-$id'))).dx;
    // Chimney holds Alpha ($300) and Zulu ($100).
    expect(x('svc-a'), lessThan(x('svc-b')));
    await tester.tap(find.text('Price'));
    await tester.pumpAndSettle();
    expect(x('svc-b'), lessThan(x('svc-a')));
  });

  testWidgets('Grid/List is remembered through the preference', (tester) async {
    Map<String, dynamic>? patched;
    await _pumpScreen(
      tester,
      services: [_service],
      onRequest: (r) {
        if (r.method == 'PATCH' && r.url.path.endsWith('/me/preferences')) {
          patched = jsonDecode(r.body) as Map<String, dynamic>;
          return _json({'data': patched!['preferences']});
        }
        return null;
      },
    );
    expect(find.byKey(const ValueKey('services-card-svc-1')), findsOneWidget);
    await tester.tap(find.text('List'));
    await tester.pumpAndSettle();
    expect(patched, {
      'preferences': {'services.catalogue_view': 'list'},
    });
    expect(find.byKey(const ValueKey('services-row-svc-1')), findsOneWidget);
    expect(find.byKey(const ValueKey('services-card-svc-1')), findsNothing);
    expect(find.text(r'$189.00'), findsOneWidget);
  });

  testWidgets('a saved list preference opens in List', (tester) async {
    await _pumpScreen(
      tester,
      services: [_service],
      me: {
        'preferences': {'services.catalogue_view': 'list'},
      },
    );
    expect(find.byKey(const ValueKey('services-row-svc-1')), findsOneWidget);
    expect(find.byKey(const ValueKey('services-card-svc-1')), findsNothing);
  });

  testWidgets('every card is the same size, five across when wide', (
    tester,
  ) async {
    await _pumpScreen(tester, services: _many, size: const Size(1500, 1400));
    final sizes = {
      for (final s in _many)
        if (find
            .byKey(ValueKey('services-card-${s['id']}'))
            .evaluate()
            .isNotEmpty)
          tester.getSize(find.byKey(ValueKey('services-card-${s['id']}'))),
    };
    expect(sizes, hasLength(1));
    expect(sizes.first.width, greaterThan(100));
    final tops = {
      for (var i = 0; i < 5; i++)
        tester.getTopLeft(find.byKey(ValueKey('services-card-m$i'))).dy,
    };
    expect(tops, hasLength(1), reason: 'five on the first row');
    expect(
      tester.getTopLeft(find.byKey(const ValueKey('services-card-m5'))).dy,
      greaterThan(tops.first),
    );
  });

  testWidgets('a phone at 390x844 shows two across with no overflow', (
    tester,
  ) async {
    await _pumpScreen(tester, services: _many, size: const Size(390, 844));
    expect(tester.takeException(), isNull);
    final a = tester.getTopLeft(find.byKey(const ValueKey('services-card-m0')));
    final b = tester.getTopLeft(find.byKey(const ValueKey('services-card-m1')));
    final c = tester.getTopLeft(find.byKey(const ValueKey('services-card-m2')));
    expect(a.dy, b.dy);
    expect(c.dy, greaterThan(a.dy));
    expect(
      tester.getTopRight(find.byKey(const ValueKey('services-card-m1'))).dx,
      lessThanOrEqualTo(390),
    );
    await tester.tap(find.text('List'));
    await tester.pumpAndSettle();
    expect(tester.takeException(), isNull);
  });
}

Future<List<int>> _png() async {
  final recorder = ui.PictureRecorder();
  ui.Canvas(recorder).drawRect(
    const ui.Rect.fromLTWH(0, 0, 4, 4),
    ui.Paint()..color = const ui.Color(0xFF3366CC),
  );
  final image = await recorder.endRecording().toImage(4, 4);
  final data = await image.toByteData(format: ui.ImageByteFormat.png);
  return data!.buffer.asUint8List();
}

final _withPhoto = <String, dynamic>{
  ..._service,
  'id': 'svc-2',
  'name': 'Cap install',
  'price_minor': 25000,
  'photo': {'id': 'att-1', 'mime': 'image/png', 'name': 'cap.png'},
};

final _mixed = <Map<String, dynamic>>[
  <String, dynamic>{
    ..._service,
    'id': 'svc-b',
    'name': 'Zulu sweep',
    'price_minor': 10000,
  },
  <String, dynamic>{
    ..._service,
    'id': 'svc-a',
    'name': 'Alpha sweep',
    'price_minor': 30000,
  },
  <String, dynamic>{..._service, 'id': 'svc-c', 'name': 'Liner', 'group': null},
  <String, dynamic>{
    ..._service,
    'id': 'svc-d',
    'name': 'Misc',
    'group': null,
    'label': null,
  },
];

final _many = <Map<String, dynamic>>[
  for (var i = 0; i < 7; i++)
    <String, dynamic>{
      ..._service,
      'id': 'm$i',
      'name': 'Service $i',
      'price_minor': 1000 * i,
    },
];

Future<void> _pumpScreen(
  WidgetTester tester, {
  List<String> permissions = const [
    'services.view',
    'services.create',
    'services.edit',
    'services.delete',
    'services.catalogue.view',
    'services.settings.view',
  ],
  Map<String, dynamic>? me,
  List<Map<String, dynamic>> services = const [],
  List<Map<String, dynamic>> groups = const [],
  List<Map<String, dynamic>> labels = const [],
  http.Response? Function(http.Request request)? onRequest,
  Size size = const Size(1280, 900),
}) async {
  tester.view.physicalSize = size;
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
    'services.catalogue.view',
    'services.settings.view',
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
