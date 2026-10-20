import 'dart:async';
import 'dart:convert';
import 'dart:io';
import 'dart:typed_data';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/monitoring/camera_monitoring_tab.dart';
import 'package:ezbizi_client/screens/monitoring/camera_view.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Camera Monitoring — the tile grid over `GET/POST/PATCH/DELETE
/// /monitoring/cameras`.
///
/// The load-bearing assertions are the honesty ones: a disabled camera must
/// never render as though it were live (test "a disabled camera..."), and an
/// `hls`/`rtsp` tile must never look like the inline `mjpeg`/`snapshot` view
/// it is not (test "an mjpeg/snapshot tile renders..."). Both are proven
/// non-vacuous the same way desktop_monitoring_tab_test does it: fixtures on
/// both sides of the distinction, asserted separately.
///
/// `kIsWeb` is false under `flutter test` (it runs on the VM, not in a
/// browser), so `mjpeg`/`snapshot` tiles render the native dart:io MJPEG
/// reader (camera_view_stub.dart), fed a fake frame below. That inline view is
/// still visibly different from the `hls`/`rtsp` deep-link card (see
/// camera_monitoring_tab.dart's `_InlineViewCard` vs `_DeepLinkCard`), which
/// is what the distinguishing-tile test actually exercises. Tapping an inline
/// tile opens the live view (?live=1) in a side panel — see the tap test.
void main() {
  // Off-web (flutter test runs on the VM), an mjpeg/snapshot tile now renders
  // the native MJPEG reader, which opens an HttpClient. Feed it a fake frame so
  // no test touches the real network.
  setUp(() => HttpOverrides.global = _FrameHttpOverrides());
  tearDown(() => HttpOverrides.global = null);

  testWidgets(
    'without monitoring.cameras.view, the tab shows the no-permission note '
    'and never fetches',
    (tester) async {
      var fetchAttempted = false;

      await _pumpTab(
        tester,
        cameras: [_mjpegCamera],
        grantView: false,
        onCamerasFetch: () => fetchAttempted = true,
      );

      expect(tester.takeException(), isNull);
      expect(find.text(CameraMonitoringTab.noPermissionNote), findsOneWidget);
      expect(find.byKey(const Key('camera-monitoring-grid')), findsNothing);
      expect(
        fetchAttempted,
        isFalse,
        reason:
            'A caller who cannot view cameras at all should never need a '
            'round trip to learn that — the tab already knows from its own '
            'session.',
      );
    },
  );

  testWidgets(
    'with .view only, the grid renders the cameras the server returned, and '
    'carries no add or delete controls',
    (tester) async {
      await _pumpTab(
        tester,
        cameras: [_mjpegCamera, _hlsCamera],
        grantManage: false,
      );

      expect(
        find.byKey(ValueKey('camera-tile-${_mjpegCamera['id']}')),
        findsOneWidget,
      );
      expect(
        find.byKey(ValueKey('camera-tile-${_hlsCamera['id']}')),
        findsOneWidget,
      );

      expect(
        find.byKey(const Key('camera-monitoring-add-button')),
        findsNothing,
      );
      expect(
        find.byKey(ValueKey('camera-tile-delete-${_mjpegCamera['id']}')),
        findsNothing,
        reason:
            'A view-only caller must not see a delete affordance — the '
            'server would 403 the DELETE anyway.',
      );
    },
  );

  testWidgets(
    'with .manage, add and delete controls are present, and adding a camera '
    'POSTs and refreshes the grid',
    (tester) async {
      final posted = <Map<String, dynamic>>[];
      var camerasAfterAdd = [_mjpegCamera];

      await _pumpTab(
        tester,
        cameras: [_mjpegCamera],
        onRequest: (request) {
          if (request.method == 'POST' &&
              request.url.path.endsWith('/monitoring/cameras')) {
            final body = jsonDecode(request.body) as Map<String, dynamic>;
            posted.add(body);
            camerasAfterAdd = [_mjpegCamera, _newCamera];
            return _json({
              'data': {..._newCamera},
            }, 201);
          }
          return null;
        },
        camerasProvider: () => camerasAfterAdd,
      );

      expect(
        find.byKey(const Key('camera-monitoring-add-button')),
        findsOneWidget,
      );
      expect(
        find.byKey(ValueKey('camera-tile-delete-${_mjpegCamera['id']}')),
        findsOneWidget,
      );

      await tester.tap(find.byKey(const Key('camera-monitoring-add-button')));
      await tester.pumpAndSettle();

      await tester.enterText(
        find.byKey(const Key('camera-add-label-field')),
        _newCamera['label'] as String,
      );
      await tester.enterText(
        find.byKey(const Key('camera-add-url-field')),
        _newCamera['url'] as String,
      );

      await tester.tap(find.byKey(const Key('camera-add-submit-button')));
      await tester.pumpAndSettle();

      expect(
        posted,
        hasLength(1),
        reason: 'Submitting the form should have POSTed exactly once.',
      );
      expect(posted.single['label'], _newCamera['label']);
      expect(posted.single['url'], _newCamera['url']);

      expect(
        find.byKey(ValueKey('camera-tile-${_newCamera['id']}')),
        findsOneWidget,
        reason:
            'The grid should refresh with the server\'s post-add list, '
            'not just close the panel.',
      );
    },
  );

  testWidgets(
    'with .manage, tapping delete and confirming DELETEs and refreshes the '
    'grid',
    (tester) async {
      final deleted = <String>[];
      var camerasNow = [_mjpegCamera];

      await _pumpTab(
        tester,
        cameras: [_mjpegCamera],
        onRequest: (request) {
          if (request.method == 'DELETE' &&
              request.url.path.contains('/monitoring/cameras/')) {
            deleted.add(request.url.pathSegments.last);
            camerasNow = [];
            return http.Response('', 204);
          }
          return null;
        },
        camerasProvider: () => camerasNow,
      );

      expect(
        find.byKey(ValueKey('camera-tile-${_mjpegCamera['id']}')),
        findsOneWidget,
      );

      await tester.tap(
        find.byKey(ValueKey('camera-tile-delete-${_mjpegCamera['id']}')),
      );
      await tester.pumpAndSettle();

      // The confirm panel, not an immediate delete.
      expect(find.text('Delete ${_mjpegCamera['label']}?'), findsOneWidget);
      expect(
        deleted,
        isEmpty,
        reason:
            'Tapping the delete icon should ask first, not delete '
            'immediately.',
      );

      await tester.tap(find.text('Delete'));
      await tester.pumpAndSettle();

      expect(deleted, [_mjpegCamera['id']]);
      expect(
        find.byKey(ValueKey('camera-tile-${_mjpegCamera['id']}')),
        findsNothing,
        reason: 'The grid should refresh once the delete succeeds.',
      );
      expect(find.text(CameraMonitoringTab.noCamerasNote), findsOneWidget);
    },
  );

  testWidgets(
    'an mjpeg/snapshot tile renders as an inline-view host, an hls/rtsp tile '
    'renders as a deep-link card, and the two are never the same widget',
    (tester) async {
      await _pumpTab(tester, cameras: [_mjpegCamera, _hlsCamera]);

      final mjpegTile = find.byKey(
        ValueKey('camera-tile-${_mjpegCamera['id']}'),
      );
      final hlsTile = find.byKey(ValueKey('camera-tile-${_hlsCamera['id']}'));

      expect(
        find.descendant(
          of: mjpegTile,
          matching: find.byKey(ValueKey('camera-inline-${_mjpegCamera['id']}')),
        ),
        findsOneWidget,
        reason: 'An mjpeg camera should render through the inline-view host.',
      );
      // Off-web it now renders the native inline view (an Image once a frame
      // arrives), NOT the old open-in-browser card. Jose, 2026-10-06: it
      // "should work on the app, not just on web."
      await tester.pump();
      expect(
        find.descendant(of: mjpegTile, matching: find.byType(Image)),
        findsOneWidget,
        reason: 'An mjpeg camera renders a live frame inline, off-web too.',
      );
      expect(
        find.descendant(of: mjpegTile, matching: find.text('Open in browser')),
        findsNothing,
        reason: 'No browser hand-off — the frame is in the tile.',
      );
      expect(
        find.descendant(of: mjpegTile, matching: find.text('Copy URL')),
        findsNothing,
        reason: 'The inline-view host must not carry the deep-link controls.',
      );

      expect(
        find.descendant(
          of: hlsTile,
          matching: find.byKey(ValueKey('camera-deeplink-${_hlsCamera['id']}')),
        ),
        findsOneWidget,
        reason: 'An hls camera should render through the deep-link card.',
      );
      expect(
        find.descendant(of: hlsTile, matching: find.text('Copy URL')),
        findsOneWidget,
      );
      expect(
        find.descendant(of: hlsTile, matching: find.text('Open in browser')),
        findsNothing,
        reason:
            'The deep-link card must not carry the inline-view card\'s '
            'wording — hls has no inline playback yet.',
      );
    },
  );

  testWidgets(
    'a disabled camera shows its disabled state, never a live or deep-link '
    'view',
    (tester) async {
      await _pumpTab(tester, cameras: [_disabledCamera]);

      final tile = find.byKey(ValueKey('camera-tile-${_disabledCamera['id']}'));
      expect(tile, findsOneWidget);

      expect(
        find.descendant(of: tile, matching: find.text('Disabled')),
        findsOneWidget,
      );
      expect(
        find.descendant(
          of: tile,
          matching: find.text('Disabled — no view shown.'),
        ),
        findsOneWidget,
        reason:
            'A disabled camera must say so on the tile itself, never '
            'render its protocol view as though it were live.',
      );
      expect(
        find.descendant(
          of: tile,
          matching: find.byKey(
            ValueKey('camera-inline-${_disabledCamera['id']}'),
          ),
        ),
        findsNothing,
      );
      expect(
        find.descendant(
          of: tile,
          matching: find.byKey(
            ValueKey('camera-deeplink-${_disabledCamera['id']}'),
          ),
        ),
        findsNothing,
      );
    },
  );

  testWidgets(
    'tapping an inline camera tile opens the live view in a side panel',
    (tester) async {
      await _pumpTab(tester, cameras: [_mjpegCamera]);

      // No live panel until the tile is tapped.
      expect(find.text('Live — full motion while this is open.'), findsNothing);

      await tester.tap(
        find.byKey(ValueKey('camera-inline-${_mjpegCamera['id']}')),
      );
      await tester.pumpAndSettle();

      // The panel is open, titled for the camera and marked live. Jose,
      // 2026-10-07: "click one of them and then it goes into live mode."
      expect(
        find.text('Live — full motion while this is open.'),
        findsOneWidget,
      );
      expect(find.text(_mjpegCamera['label'] as String), findsWidgets);
    },
  );

  testWidgets(
    'an empty camera list shows the no-cameras note, and the add hint only '
    'when the viewer can manage',
    (tester) async {
      await _pumpTab(tester, cameras: const [], grantManage: false);

      expect(find.text(CameraMonitoringTab.noCamerasNote), findsOneWidget);
      expect(find.text(CameraMonitoringTab.noCamerasManageHint), findsNothing);
      expect(
        find.byKey(const Key('camera-monitoring-add-button')),
        findsNothing,
      );
    },
  );

  testWidgets(
    'an empty camera list with .manage shows the add hint and the add '
    'button',
    (tester) async {
      await _pumpTab(tester, cameras: const []);

      expect(find.text(CameraMonitoringTab.noCamerasNote), findsOneWidget);
      expect(
        find.text(CameraMonitoringTab.noCamerasManageHint),
        findsOneWidget,
      );
      expect(
        find.byKey(const Key('camera-monitoring-add-button')),
        findsOneWidget,
      );
    },
  );

  testWidgets('at 375px the grid renders with no overflow', (tester) async {
    await _pumpTab(
      tester,
      cameras: [_mjpegCamera, _hlsCamera],
      size: const Size(375, 800),
    );

    expect(tester.takeException(), isNull);
    expect(find.byKey(const Key('camera-monitoring-grid')), findsOneWidget);
    expect(
      find.byKey(ValueKey('camera-tile-${_mjpegCamera['id']}')),
      findsOneWidget,
    );
  });

  // The routing decision itself, tested where a widget pump cannot reach it.
  //
  // Under `flutter test`, kIsWeb is a compile-time false, so no pump can ever
  // send an `hls` tile down its web-only inline path — the tile always renders
  // the deep-link card here (which is exactly what the "an mjpeg/snapshot
  // tile..." widget test above still asserts). cameraBodyKind is the same
  // decision the tile makes, extracted so it CAN be fed `isWeb: true`. These
  // assert the selection, not playback — which is all a VM test can honestly
  // check.
  group('cameraBodyKind routes each protocol by platform capability', () {
    test('hls on web with a player routes to the inline video host', () {
      expect(
        cameraBodyKind(
          enabled: true,
          protocol: 'hls',
          isWeb: true,
          hlsInlineSupported: true,
        ),
        CameraBodyKind.inlineHls,
        reason:
            'On web with hls.js or native HLS, an HLS camera must play '
            'inline — not fall back to the copy-URL deep-link card.',
      );
    });

    test('rtsp on web still routes to the deep-link card', () {
      expect(
        cameraBodyKind(
          enabled: true,
          protocol: 'rtsp',
          isWeb: true,
          hlsInlineSupported: true,
        ),
        CameraBodyKind.deepLink,
        reason:
            'No browser can play RTSP; it stays a deep link even on web, '
            'even with a player available.',
      );
    });

    test('hls off-web (desktop) routes to the deep-link card', () {
      expect(
        cameraBodyKind(
          enabled: true,
          protocol: 'hls',
          isWeb: false,
          hlsInlineSupported: true,
        ),
        CameraBodyKind.deepLink,
        reason: 'There is no DOM <video> off-web; HLS stays a deep link there.',
      );
    });

    test('hls on web without a player routes to the deep-link card', () {
      expect(
        cameraBodyKind(
          enabled: true,
          protocol: 'hls',
          isWeb: true,
          hlsInlineSupported: false,
        ),
        CameraBodyKind.deepLink,
        reason:
            'A browser with neither hls.js nor native HLS cannot play the '
            'stream, so it must show the deep-link card, not an empty <video>.',
      );
    });

    test('a disabled hls camera is disabled, never inline or deep-link', () {
      expect(
        cameraBodyKind(
          enabled: false,
          protocol: 'hls',
          isWeb: true,
          hlsInlineSupported: true,
        ),
        CameraBodyKind.disabled,
        reason:
            'Disabled wins over every protocol path — a disabled camera is '
            'never shown as though it were live.',
      );
    });

    test('mjpeg/snapshot always route to the inline image host', () {
      expect(
        cameraBodyKind(
          enabled: true,
          protocol: 'mjpeg',
          isWeb: true,
          hlsInlineSupported: false,
        ),
        CameraBodyKind.inlineImage,
      );
      expect(
        cameraBodyKind(
          enabled: true,
          protocol: 'snapshot',
          isWeb: false,
          hlsInlineSupported: false,
        ),
        CameraBodyKind.inlineImage,
      );
    });
  });

  group('bridge cameras', () {
    const bridge = {
      'id': 'cam-b',
      'label': 'Dock',
      'protocol': 'mjpeg',
      'url': '',
      'source': 'bridge',
      'enabled': true,
      'created_at': '2026-10-20T12:00:00Z',
    };

    http.Response? watchReply(
      http.Request r,
      List<String> qualities, {
      int status = 200,
    }) {
      if (!r.url.path.endsWith('/monitoring/cameras/cam-b/watch')) return null;
      qualities.add((jsonDecode(r.body) as Map)['quality'] as String);
      if (status == 409) {
        return _json({
          'code': 'bridge_offline',
          'message': 'offline',
          'last_checkin_at': DateTime.now()
              .toUtc()
              .subtract(const Duration(minutes: 7))
              .toIso8601String(),
        }, 409);
      }
      return _json({
        'play': {
          'url': 'http://relay.test/api/stream.mjpeg?src=cam-b-x',
          'expires_at': '2099-01-01T00:00:00Z',
        },
      });
    }

    testWidgets('a bridge tile asks to watch the sub stream and plays it', (
      tester,
    ) async {
      final qualities = <String>[];
      await _pumpTab(
        tester,
        cameras: [bridge],
        onRequest: (r) => watchReply(r, qualities),
      );

      expect(qualities, ['sub']);
      expect(find.byKey(const ValueKey('camera-inline-cam-b')), findsOneWidget);
    });

    testWidgets('an offline bridge says so, with no spinner', (tester) async {
      await _pumpTab(
        tester,
        cameras: [bridge],
        onRequest: (r) => watchReply(r, [], status: 409),
      );

      expect(find.textContaining('Bridge offline · since '), findsOneWidget);
      expect(find.byType(CircularProgressIndicator), findsNothing);
    });

    testWidgets('a URL camera never calls watch', (tester) async {
      final qualities = <String>[];
      await _pumpTab(
        tester,
        cameras: [_mjpegCamera],
        onRequest: (r) => watchReply(r, qualities),
      );
      expect(qualities, isEmpty);
    });

    testWidgets('a play URL near expiry is swapped for the fresh one', (
      tester,
    ) async {
      var n = 0;
      await _pumpTab(
        tester,
        cameras: [bridge],
        onRequest: (r) {
          if (!r.url.path.endsWith('/monitoring/cameras/cam-b/watch')) {
            return null;
          }
          n++;
          final soon = DateTime.now()
              .toUtc()
              .add(const Duration(seconds: 40))
              .toIso8601String();
          return _json({
            'play': {
              'url': 'http://relay.test/s?n=$n',
              'expires_at': n == 1 ? soon : '2099-01-01T00:00:00Z',
            },
          });
        },
      );
      bool showing(String url) => find
          .byWidgetPredicate((w) => w is CameraImageView && w.url == url)
          .evaluate()
          .isNotEmpty;

      expect(showing('http://relay.test/s?n=1'), isTrue);
      await tester.pump(const Duration(seconds: 31));
      await tester.pump();
      expect(
        showing('http://relay.test/s?n=2'),
        isTrue,
        reason: 'The old token would 401 on the next reconnect.',
      );
    });

    testWidgets(
      'tapping a bridge tile opens the live panel on the main stream',
      (tester) async {
        final qualities = <String>[];
        await _pumpTab(
          tester,
          cameras: [bridge],
          onRequest: (r) => watchReply(r, qualities),
        );

        await tester.tap(find.byKey(const ValueKey('camera-inline-cam-b')));
        await tester.pump();
        await tester.pump(const Duration(milliseconds: 500));

        expect(qualities, ['sub', 'main']);
      },
    );
  });
}

const _mjpegCamera = {
  'id': 'cam-1',
  'label': 'Shop floor',
  'protocol': 'mjpeg',
  'url': 'http://10.0.0.20/mjpeg',
  'enabled': true,
  'created_at': '2026-08-01T12:00:00Z',
};

const _hlsCamera = {
  'id': 'cam-2',
  'label': 'Back lot',
  'protocol': 'hls',
  'url': 'https://cam.example.com/stream.m3u8',
  'enabled': true,
  'created_at': '2026-08-01T12:00:00Z',
};

const _disabledCamera = {
  'id': 'cam-3',
  'label': 'Old bay',
  'protocol': 'snapshot',
  'url': 'http://10.0.0.21/snap.jpg',
  'enabled': false,
  'created_at': '2026-08-01T12:00:00Z',
};

const _newCamera = {
  'id': 'cam-9',
  'label': 'New dock cam',
  'protocol': 'mjpeg',
  'url': 'http://10.0.0.22/mjpeg',
  'enabled': true,
  'created_at': '2026-08-02T12:00:00Z',
};

Future<void> _pumpTab(
  WidgetTester tester, {
  required List<Map<String, dynamic>> cameras,
  bool grantView = true,
  bool grantManage = true,
  VoidCallback? onCamerasFetch,
  http.Response? Function(http.Request request)? onRequest,
  List<Map<String, dynamic>> Function()? camerasProvider,
  Size size = const Size(1280, 900),
}) async {
  tester.view.physicalSize = size;
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final permissions = <String>[
    'monitoring.view',
    if (grantView) 'monitoring.cameras.view',
    if (grantManage) 'monitoring.cameras.manage',
  ];

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

      if (request.method == 'GET' && path.endsWith('/monitoring/cameras')) {
        onCamerasFetch?.call();
        return _json({'data': camerasProvider?.call() ?? cameras});
      }

      return _json({
        'message': 'Nothing stubbed for ${request.method} $path.',
      }, 500);
    }),
  );

  final session = Session(api);
  await tester.runAsync(() => session.adopt('tok'));
  expect(session.signedIn, isTrue);

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(
        body: CameraMonitoringTab(api: api, session: session),
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
  'permissions': <String>['monitoring.view'],
  'preferences': <String, dynamic>{},
  'organization': {
    'id': 'org-1',
    'name': 'A-1 Chimney',
    'franchise_label': 'Franchise',
    'is_read_only': false,
  },
  'scope': {
    'type': 'organization',
    'id': 'org-1',
    'label': 'A-1 Chimney',
    'path': ['A-1 Chimney'],
  },
  'companies': [
    {'id': 'co-1', 'name': 'A-1 Chimney', 'parent_company_id': null},
  ],
  'franchises': <Map<String, Object?>>[],
  'modules': [
    {
      'key': 'monitoring',
      'label': 'Monitoring',
      'available': true,
      'reachable': true,
    },
  ],
};

/// A synthetic JPEG frame (FFD8…FFD9) the native reader will frame on.
final Uint8List _fakeFrame = Uint8List.fromList([
  0xFF,
  0xD8,
  0xAA,
  0xBB,
  0xFF,
  0xD9,
]);

/// Feeds every request one frame and then holds the connection open, so the
/// reader shows a frame and schedules no reconnect timer (a closed stream would
/// trigger the 4s poll and leave a pending timer at test end).
class _FrameHttpOverrides extends HttpOverrides {
  @override
  HttpClient createHttpClient(SecurityContext? context) => _FrameHttpClient();
}

class _FrameHttpClient implements HttpClient {
  @override
  Future<HttpClientRequest> getUrl(Uri url) async => _FrameRequest();
  @override
  void close({bool force = false}) {}
  @override
  dynamic noSuchMethod(Invocation invocation) => null;
}

class _FrameRequest implements HttpClientRequest {
  @override
  final HttpHeaders headers = _FrameHeaders();
  @override
  Future<HttpClientResponse> close() async => _FrameResponse();
  @override
  dynamic noSuchMethod(Invocation invocation) => null;
}

class _FrameHeaders implements HttpHeaders {
  @override
  dynamic noSuchMethod(Invocation invocation) => null;
}

class _FrameResponse extends Stream<List<int>> implements HttpClientResponse {
  final Stream<List<int>> _s = (() async* {
    yield _fakeFrame;
    await Completer<void>().future; // stay open: no onDone, no reconnect
  })();

  @override
  int get statusCode => 200;
  @override
  StreamSubscription<List<int>> listen(
    void Function(List<int>)? onData, {
    Function? onError,
    void Function()? onDone,
    bool? cancelOnError,
  }) => _s.listen(
    onData,
    onError: onError,
    onDone: onDone,
    cancelOnError: cancelOnError,
  );
  @override
  dynamic noSuchMethod(Invocation invocation) => null;
}
