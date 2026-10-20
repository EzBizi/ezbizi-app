import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/monitoring/camera_sites_settings.dart';
import 'package:ezbizi_client/screens/settings/settings_hub.dart';
import 'package:ezbizi_client/widgets/refused.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Settings › Monitoring › Camera sites (UI queue 117, option 1 + phone 4),
/// against the camera bridge plan's Task 3 routes, mocked.
void main() {
  // Monitoring 2026-10-20: channels the bridge found are named and added
  // here; one already added (matched on channel) is not offered again.
  testWidgets('a found channel is named and added', (tester) async {
    final server = _Server();
    await _pump(tester, server, _manage);
    await tester.tap(find.byKey(const ValueKey('camera-site-s-yard')));
    await tester.pumpAndSettle();

    expect(find.byKey(const ValueKey('camera-found-add-1')), findsNothing);
    await tester.enterText(
      find.byKey(const ValueKey('camera-found-name-2')),
      'Back fence',
    );
    await tester.tap(find.byKey(const ValueKey('camera-found-add-2')));
    await tester.pumpAndSettle();

    expect(
      server.writes,
      contains(
        'POST /monitoring/camera-sites/s-yard/cameras '
        '{"channel":2,"label":"Back fence"}',
      ),
    );
  });

  testWidgets('the hub lists Monitoring only with monitoring.cameras.view', (
    tester,
  ) async {
    final server = _Server();
    await _pumpHub(tester, server, ['monitoring.cameras.view']);
    expect(
      find.byKey(const ValueKey('settings-hub-item-monitoring')),
      findsOne,
    );

    await tester.tap(
      find.byKey(const ValueKey('settings-hub-item-monitoring')),
    );
    await tester.pumpAndSettle();
    expect(find.byType(CameraSitesSettings), findsOne);
    expect(find.text('Camera sites'), findsOne);

    final without = _Server();
    await _pumpHub(tester, without, const []);
    expect(
      find.byKey(const ValueKey('settings-hub-item-monitoring')),
      findsNothing,
    );
  });

  testWidgets('offline sites come first, with since and the reason', (
    tester,
  ) async {
    final server = _Server();
    await _pump(tester, server, _manage);

    final north = tester.getTopLeft(
      find.byKey(const ValueKey('camera-site-s-north')),
    );
    final yard = tester.getTopLeft(
      find.byKey(const ValueKey('camera-site-s-yard')),
    );
    expect(north.dy, lessThan(yard.dy), reason: 'offline sorts first');

    final row = find.byKey(const ValueKey('camera-site-s-north'));
    expect(find.descendant(of: row, matching: find.text('OFFLINE')), findsOne);
    expect(
      find.descendant(of: row, matching: find.textContaining('since ')),
      findsOne,
    );
    expect(
      find.descendant(of: row, matching: find.text('The PC lost its network')),
      findsOne,
    );
    expect(
      find.descendant(
        of: row,
        matching: find.textContaining('Bridge: WH-NORTH-PC · 6 cameras'),
      ),
      findsOne,
    );
    expect(
      find.descendant(
        of: find.byKey(const ValueKey('camera-site-s-yard')),
        matching: find.text('Online'),
      ),
      findsOne,
    );
    expect(
      tester
          .widget<OutlinedButton>(
            find.byKey(const ValueKey('camera-sites-wiring')),
          )
          .onPressed,
      isNull,
    );
  });

  testWidgets('without manage: everything shows, every write is greyed with '
      'its reason', (tester) async {
    final server = _Server();
    await _pump(tester, server, const ['monitoring.cameras.view']);

    final add = find.byKey(const ValueKey('camera-sites-add'));
    expect(tester.widget<FilledButton>(add).onPressed, isNull);
    expect(_refusedReason(tester, add), contains('add a camera site'));

    await tester.tap(find.byKey(const ValueKey('camera-site-s-yard')));
    await tester.pumpAndSettle();

    final sw = find.byKey(const ValueKey('camera-enabled-c-gate'));
    expect(tester.widget<Switch>(sw).onChanged, isNull);
    expect(_refusedReason(tester, sw), contains('turn a camera on or off'));
    for (final key in ['camera-site-pair-new', 'camera-site-disconnect']) {
      final b = find.byKey(ValueKey(key));
      expect(tester.widget<OutlinedButton>(b).onPressed, isNull, reason: key);
      expect(_refusedReason(tester, b), isNotNull, reason: key);
    }
    expect(
      tester
          .widget<IconButton>(
            find.byKey(const ValueKey('camera-name-edit-c-gate')),
          )
          .onPressed,
      isNull,
    );
    expect(server.writes, isEmpty);
  });

  testWidgets('Add a site creates it and shows its pairing code', (
    tester,
  ) async {
    final server = _Server();
    await _pump(tester, server, _manage);

    await tester.tap(find.byKey(const ValueKey('camera-sites-add')));
    await tester.pumpAndSettle();
    await tester.enterText(
      find.byKey(const ValueKey('camera-site-new-name')),
      'Brightside depot',
    );
    await tester.tap(find.byKey(const ValueKey('camera-site-new-save')));
    await tester.pumpAndSettle();

    expect(server.writes, [
      'POST /monitoring/camera-sites {"name":"Brightside depot"}',
      'POST /monitoring/camera-sites/s-new/pairing-code {}',
    ]);
    expect(find.text('ABCD-2345'), findsOne);
    expect(find.textContaining('Expires in 1'), findsOne);
    expect(
      find.textContaining('EzBizi › Settings › This computer › Camera bridge'),
      findsOne,
    );

    await tester.tap(find.byKey(const ValueKey('camera-site-new-code')));
    await tester.pumpAndSettle();
    expect(
      server.writes.last,
      'POST /monitoring/camera-sites/s-new/pairing-code {}',
    );
  });

  testWidgets('Disconnect this PC asks once, then revokes', (tester) async {
    final server = _Server();
    await _pump(tester, server, _manage);
    await tester.tap(find.byKey(const ValueKey('camera-site-s-yard')));
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const ValueKey('camera-site-disconnect')));
    await tester.pumpAndSettle();
    expect(server.writes, isEmpty, reason: 'asks before it acts');
    expect(find.text('Disconnect'), findsOne);

    await tester.tap(
      find.byKey(const ValueKey('camera-site-disconnect-confirm')),
    );
    await tester.pumpAndSettle();
    // Revoked once; the site has no PC now, so it offers Get a code for the
    // next one rather than making one unasked.
    expect(server.writes, ['POST /monitoring/camera-sites/s-yard/revoke {}']);
    expect(find.byKey(const ValueKey('camera-site-bridge')), findsNothing);
    expect(find.byKey(const ValueKey('camera-site-new-code')), findsOne);
  });

  testWidgets('a camera switch PATCHes the camera; a name saves in place', (
    tester,
  ) async {
    final server = _Server();
    await _pump(tester, server, _manage);
    await tester.tap(find.byKey(const ValueKey('camera-site-s-yard')));
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const ValueKey('camera-enabled-c-gate')));
    await tester.pumpAndSettle();
    expect(server.writes, [
      'PATCH /monitoring/cameras/c-gate {"enabled":false}',
    ]);
    expect(
      tester
          .widget<Switch>(find.byKey(const ValueKey('camera-enabled-c-gate')))
          .value,
      isFalse,
    );

    await tester.tap(find.byKey(const ValueKey('camera-name-edit-c-dock')));
    await tester.pumpAndSettle();
    await tester.enterText(
      find.byKey(const ValueKey('camera-name-field-c-dock')),
      'Loading dock east',
    );
    await tester.tap(find.byKey(const ValueKey('camera-name-save-c-dock')));
    await tester.pumpAndSettle();
    expect(
      server.writes.last,
      'PATCH /monitoring/cameras/c-dock {"label":"Loading dock east"}',
    );
    expect(find.text('Loading dock east'), findsOne);
  });

  testWidgets('a camera with no still says when one comes; a bridge problem '
      'reads in error tone; the bridge box keeps the password note', (
    tester,
  ) async {
    final server = _Server();
    await _pump(tester, server, _manage);
    await tester.tap(find.byKey(const ValueKey('camera-site-s-yard')));
    await tester.pumpAndSettle();

    expect(find.byKey(const ValueKey('camera-still-c-gate')), findsOne);
    expect(find.byKey(const ValueKey('camera-still-empty-c-dock')), findsOne);
    expect(
      find.descendant(
        of: find.byKey(const ValueKey('camera-tile-c-dock')),
        matching: find.text(
          'No picture yet — the bridge sends one every 10 minutes',
        ),
      ),
      findsOne,
    );
    final problem = tester.widget<Text>(
      find.byKey(const ValueKey('camera-problem-c-dock')),
    );
    expect(problem.data, 'Wrong password for the recorder');
    final context = tester.element(find.byType(CameraSitesSettings));
    expect(problem.style?.color, Theme.of(context).colorScheme.error);
    expect(find.text('Live'), findsNWidgets(2));
    expect(
      find.textContaining("The recorder's password is kept on this PC only."),
      findsOne,
    );
    expect(find.textContaining('YARD-OFFICE · EzBizi 0.0.0460'), findsOne);
  });

  // Opening a site only reads it: a code is made on Add a site or on Get a
  // code, never by looking.
  testWidgets('a site with no PC offers a code, made on asking', (
    tester,
  ) async {
    final server = _Server();
    await _pump(tester, server, _manage);
    await tester.tap(find.byKey(const ValueKey('camera-site-s-shop')));
    await tester.pumpAndSettle();
    expect(server.writes, isEmpty);

    await tester.tap(find.byKey(const ValueKey('camera-site-new-code')));
    await tester.pumpAndSettle();
    expect(server.writes, [
      'POST /monitoring/camera-sites/s-shop/pairing-code {}',
    ]);
    expect(find.byKey(const ValueKey('camera-site-pairing-code')), findsOne);
    expect(find.byKey(const ValueKey('camera-site-bridge')), findsNothing);
  });

  testWidgets('the endpoint missing (404) is an empty state with Try again', (
    tester,
  ) async {
    final server = _Server()..missing = true;
    await _pump(tester, server, _manage);

    expect(
      find.text('Camera sites are not available on this server yet.'),
      findsOne,
    );
    expect(find.byKey(const ValueKey('camera-sites-retry')), findsOne);

    server.missing = false;
    await tester.tap(find.byKey(const ValueKey('camera-sites-retry')));
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('camera-site-s-yard')), findsOne);
  });

  testWidgets('narrow: sites as rows, a site lists cameras one per row', (
    tester,
  ) async {
    final server = _Server();
    await _pump(tester, server, _manage, width: 420);

    final north = find.byKey(const ValueKey('camera-site-s-north'));
    expect(
      find.descendant(of: north, matching: find.text('6 cameras')),
      findsOne,
    );
    expect(
      find.descendant(of: north, matching: find.text('OFFLINE')),
      findsOne,
    );

    await tester.tap(find.byKey(const ValueKey('camera-site-s-yard')));
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('camera-row-c-gate')), findsOne);
    expect(find.byKey(const ValueKey('camera-tile-c-gate')), findsNothing);
    expect(
      find.descendant(
        of: find.byKey(const ValueKey('camera-row-c-gate')),
        matching: find.byType(Switch),
      ),
      findsOne,
    );
    expect(tester.takeException(), isNull);
  });
}

const _manage = ['monitoring.cameras.view', 'monitoring.cameras.manage'];

String? _refusedReason(WidgetTester tester, Finder control) => tester
    .widget<Refused>(
      find.ancestor(of: control, matching: find.byType(Refused)).first,
    )
    .reason;

/// An in-memory camera-sites server: three sites, one paired site's cameras.
class _Server {
  bool missing = false;
  final writes = <String>[];

  final _now = DateTime.now().toUtc();
  String _iso(Duration ago) => _now.subtract(ago).toIso8601String();

  late final List<Map<String, dynamic>> sites = [
    {
      'id': 's-yard',
      'name': 'Main yard',
      'online': true,
      'online_since': _iso(const Duration(days: 2)),
      'last_reason': null,
      'last_checkin_at': _iso(const Duration(seconds: 20)),
      'device': {'pc_name': 'YARD-OFFICE', 'app_version': '0.0.0460'},
      'camera_count': 2,
    },
    {
      'id': 's-north',
      'name': 'North warehouse',
      'online': false,
      'offline_since': _iso(const Duration(minutes: 42)),
      'last_reason': 'The PC lost its network',
      'last_checkin_at': _iso(const Duration(minutes: 42)),
      'device': {'pc_name': 'WH-NORTH-PC', 'app_version': '0.0.0458'},
      'camera_count': 6,
    },
    {
      'id': 's-shop',
      'name': 'Brightside shop',
      'online': false,
      'offline_since': null,
      'last_reason': null,
      'last_checkin_at': null,
      'device': null,
      'camera_count': 0,
    },
  ];

  final cameras = [
    {
      'id': 'c-gate',
      'channel': 1,
      'label': 'Gate',
      'enabled': true,
      'supports': ['live'],
      'bridge_status': 'ok',
      'bridge_reason': null,
      'still_url': 'https://stills.example/gate.jpg',
    },
    {
      'id': 'c-dock',
      'label': 'Loading dock',
      'enabled': true,
      'supports': ['live'],
      'bridge_status': 'auth_failed',
      'bridge_reason': 'Wrong password for the recorder',
      'still_url': null,
    },
  ];

  Future<http.Response?> handle(http.Request r) async {
    final path = r.url.path.replaceFirst('/api/v1', '');
    if (!path.startsWith('/monitoring/')) return null;
    if (missing) return _json({'message': 'Not Found'}, 404);
    if (r.method != 'GET') writes.add('${r.method} $path ${r.body}');

    if (r.method == 'GET' && path == '/monitoring/camera-sites') {
      return _json({'data': sites});
    }
    if (r.method == 'POST' && path == '/monitoring/camera-sites') {
      final name = (jsonDecode(r.body) as Map)['name'];
      final site = {
        'id': 's-new',
        'name': name,
        'online': false,
        'device': null,
        'camera_count': 0,
      };
      sites.add(site);
      return _json({'data': site}, 201);
    }
    final m = RegExp(
      r'^/monitoring/camera-sites/([\w-]+)(/.*)?$',
    ).firstMatch(path);
    if (m != null) {
      final id = m.group(1);
      final rest = m.group(2);
      final site = sites.firstWhere((s) => s['id'] == id);
      if (r.method == 'GET' && rest == null) {
        return _json({
          'data': {
            ...site,
            'cameras': id == 's-yard' ? cameras : const <dynamic>[],
            // Channel 1 is already the Gate; 2 is new.
            'found': id == 's-yard'
                ? [
                    {
                      'key': 'nvr',
                      'label': 'NVR',
                      'kind': 'recorder',
                      'address': '192.168.1.64',
                      'channels': [
                        {'channel': 1, 'label': 'Cam 1'},
                        {'channel': 2, 'label': null},
                      ],
                    },
                  ]
                : const <dynamic>[],
          },
        });
      }
      if (rest == '/pairing-code') {
        return _json({
          'data': {
            'code': 'ABCD-2345',
            'expires_at': DateTime.now()
                .toUtc()
                .add(const Duration(minutes: 15))
                .toIso8601String(),
          },
        });
      }
      if (rest == '/revoke') {
        site['device'] = null;
        return _json({'data': site});
      }
    }
    if (r.method == 'PATCH' && path.startsWith('/monitoring/cameras/')) {
      return _json({'data': <String, dynamic>{}});
    }
    return _json({'message': 'not stubbed'}, 500);
  }
}

Future<Session> _session(
  WidgetTester tester,
  _Server server,
  List<String> permissions,
  double width,
) async {
  tester.view.physicalSize = Size(width, 1600);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);
  final api = ApiClient(
    client: MockClient((request) async {
      final handled = await server.handle(request);
      if (handled != null) return handled;
      if (request.url.path.endsWith('/me')) {
        return _json({'data': _me(permissions)});
      }
      return _json({'data': const <dynamic>[]});
    }),
  );
  final session = Session(api);
  await tester.runAsync(() => session.adopt('tok'));
  return session;
}

Future<void> _pump(
  WidgetTester tester,
  _Server server,
  List<String> permissions, {
  double width = 1400,
}) async {
  final session = await _session(tester, server, permissions, width);
  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(
        body: CameraSitesSettings(api: session.api, session: session),
      ),
    ),
  );
  await tester.pumpAndSettle();
}

Future<void> _pumpHub(
  WidgetTester tester,
  _Server server,
  List<String> permissions,
) async {
  final session = await _session(tester, server, permissions, 1400);
  await tester.pumpWidget(
    MaterialApp(
      key: UniqueKey(),
      theme: EzBiziTheme.light(),
      home: Scaffold(
        body: SettingsHub(api: session.api, session: session),
      ),
    ),
  );
  await tester.pumpAndSettle();
}

Map<String, dynamic> _me(List<String> permissions) => {
  'id': 'u-1',
  'name': 'Dana',
  'email': 'dana@brightside.example',
  'role': {'name': 'Dispatcher'},
  'has_avatar': false,
  'preferences': <String, dynamic>{},
  'permissions': ['organizations.settings.view', ...permissions],
  'modules': [
    {
      'key': 'monitoring',
      'label': 'Monitoring',
      'available': true,
      'reachable': true,
      'parent': null,
    },
  ],
  'organization': {
    'id': 'org-1',
    'name': 'Brightside Home Services',
    'franchise_label': 'Franchise',
    'is_read_only': false,
  },
  'scope': {
    'type': 'organization',
    'id': 'org-1',
    'label': 'Brightside Home Services',
    'path': ['Brightside Home Services'],
  },
};

http.Response _json(Object body, [int status = 200]) => http.Response(
  jsonEncode(body),
  status,
  headers: const {'content-type': 'application/json'},
);
