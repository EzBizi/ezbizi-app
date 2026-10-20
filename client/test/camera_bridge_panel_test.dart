import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/settings/camera_bridge_panel.dart';
import 'package:ezbizi_client/screens/settings/settings_hub.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Settings › This computer › Camera bridge (UI queue 117): the checklist on
/// the site PC that stays on, against a fake bridge.
void main() {
  testWidgets('unpaired: step 2 is a code box, and Pair sends the code and '
      'shows the refusal inline', (tester) async {
    final fake = _FakeBridge(
      const BridgePanelStatus(on: true, startsWithWindows: true),
      pairAnswer: 'That code has expired. Get a new one on the Cameras page.',
    );
    await _pumpPanel(tester, fake);

    expect(find.byKey(const ValueKey('camera-bridge-line-1-done')), findsOne);
    expect(
      find.byKey(const ValueKey('camera-bridge-line-2-pending')),
      findsOne,
    );
    expect(find.byKey(const ValueKey('camera-bridge-code')), findsOne);

    await tester.enterText(
      find.byKey(const ValueKey('camera-bridge-code')),
      'QWER-7788',
    );
    await tester.tap(find.byKey(const ValueKey('camera-bridge-pair')));
    await tester.pumpAndSettle();

    expect(fake.pairedWith, ['QWER-7788']);
    expect(
      find.descendant(
        of: find.byKey(const ValueKey('camera-bridge-pair-error')),
        matching: find.text(
          'That code has expired. Get a new one on the Cameras page.',
        ),
      ),
      findsOne,
    );
    // Still unpaired: the box stays for another go.
    expect(find.byKey(const ValueKey('camera-bridge-code')), findsOne);
  });

  testWidgets('a code that pairs replaces the box with the site', (
    tester,
  ) async {
    final fake = _FakeBridge(
      const BridgePanelStatus(on: true, startsWithWindows: true),
      afterPair: const BridgePanelStatus(
        on: true,
        startsWithWindows: true,
        paired: true,
        siteName: 'Harbor lot',
        organization: 'Pinecrest Services',
      ),
    );
    await _pumpPanel(tester, fake);

    await tester.enterText(
      find.byKey(const ValueKey('camera-bridge-code')),
      'ZXCV-1122',
    );
    await tester.tap(find.byKey(const ValueKey('camera-bridge-pair')));
    await tester.pumpAndSettle();

    expect(find.byKey(const ValueKey('camera-bridge-code')), findsNothing);
    expect(
      find.byKey(const ValueKey('camera-bridge-pair-error')),
      findsNothing,
    );
    expect(find.byKey(const ValueKey('camera-bridge-line-2-done')), findsOne);
    expect(find.textContaining('Harbor lot', findRichText: true), findsOne);
  });

  testWidgets('running: all five lines ticked, with the site and the camera '
      'count', (tester) async {
    final fake = _FakeBridge(
      BridgePanelStatus(
        on: true,
        startsWithWindows: true,
        paired: true,
        siteName: 'Harbor lot',
        organization: 'Pinecrest Services',
        recorderHost: '10.0.4.20',
        passwordSaved: true,
        camerasFound: 6,
        lastOk: DateTime.now(),
      ),
    );
    await _pumpPanel(tester, fake);

    for (var n = 1; n <= 5; n++) {
      expect(
        find.byKey(ValueKey('camera-bridge-line-$n-done')),
        findsOne,
        reason: 'line $n',
      );
    }
    expect(find.text('Camera bridge is on, and starts with the PC'), findsOne);
    expect(
      find.text(
        'Paired with Harbor lot · Pinecrest Services',
        findRichText: true,
      ),
      findsOne,
    );
    expect(
      find.text('Recorder found at 10.0.4.20 · password saved on this PC'),
      findsOne,
    );
    expect(find.text('6 cameras found'), findsOne);
    expect(find.text('Talking to EzBizi · last just now'), findsOne);
    expect(find.byKey(const ValueKey('camera-bridge-code')), findsNothing);
  });

  testWidgets('trouble: the line goes red with its fix sentence', (
    tester,
  ) async {
    const fix =
        'EzBizi did not answer this PC. Check its internet connection; the '
        'bridge keeps trying.';
    final fake = _FakeBridge(
      const BridgePanelStatus(
        on: true,
        startsWithWindows: true,
        paired: true,
        siteName: 'Harbor lot',
        recorderHost: '10.0.4.20',
        passwordSaved: true,
        camerasFound: 6,
        fixes: {BridgeLine.talking: fix},
      ),
    );
    await _pumpPanel(tester, fake);

    expect(
      find.byKey(const ValueKey('camera-bridge-line-5-trouble')),
      findsOne,
    );
    expect(find.text(fix), findsOne);
    expect(find.byKey(const ValueKey('camera-bridge-line-4-done')), findsOne);
    final icon = tester.widget<Icon>(
      find.descendant(
        of: find.byKey(const ValueKey('camera-bridge-line-5-trouble')),
        matching: find.byType(Icon),
      ),
    );
    expect(icon.color, EzBiziTheme.light().colorScheme.error);
  });

  testWidgets('the recorder form saves host, username and password, and never '
      'shows the password back', (tester) async {
    final fake = _FakeBridge(
      const BridgePanelStatus(
        on: true,
        startsWithWindows: true,
        paired: true,
        siteName: 'Harbor lot',
      ),
    );
    await _pumpPanel(tester, fake);

    expect(
      find.byKey(const ValueKey('camera-bridge-line-3-pending')),
      findsOne,
    );
    expect(find.text('Kept on this PC only.'), findsOne);
    final pass = find.byKey(const ValueKey('camera-bridge-recorder-pass'));
    expect(tester.widget<TextField>(pass).obscureText, isTrue);

    await tester.enterText(
      find.byKey(const ValueKey('camera-bridge-recorder-host')),
      '10.0.4.20',
    );
    await tester.enterText(
      find.byKey(const ValueKey('camera-bridge-recorder-user')),
      'viewer',
    );
    await tester.enterText(pass, 'tulip-anchor-91');
    await tester.tap(find.byKey(const ValueKey('camera-bridge-recorder-save')));
    await tester.pumpAndSettle();

    expect(fake.savedRecorder, ('10.0.4.20', 'viewer', 'tulip-anchor-91'));
    expect(find.byKey(const ValueKey('camera-bridge-line-3-done')), findsOne);
    expect(find.textContaining('tulip-anchor-91'), findsNothing);

    // Change opens the form again with the address, not the password.
    await tester.tap(
      find.byKey(const ValueKey('camera-bridge-recorder-change')),
    );
    await tester.pumpAndSettle();
    final again = tester.widget<TextField>(
      find.byKey(const ValueKey('camera-bridge-recorder-pass')),
    );
    expect(again.controller!.text, isEmpty);
    expect(
      tester
          .widget<TextField>(
            find.byKey(const ValueKey('camera-bridge-recorder-host')),
          )
          .controller!
          .text,
      '10.0.4.20',
    );
  });

  testWidgets('the switch turns the bridge off and step 1 goes back to '
      'pending', (tester) async {
    final fake = _FakeBridge(
      const BridgePanelStatus(on: true, startsWithWindows: true),
    );
    await _pumpPanel(tester, fake);

    await tester.tap(find.byKey(const ValueKey('camera-bridge-on')));
    await tester.pumpAndSettle();

    expect(fake.switched, [false]);
    expect(
      find.byKey(const ValueKey('camera-bridge-line-1-pending')),
      findsOne,
    );
  });

  testWidgets('not supported: the panel draws nothing', (tester) async {
    final fake = _FakeBridge(const BridgePanelStatus(), supported: false);
    await _pumpPanel(tester, fake);

    expect(find.byKey(const ValueKey('camera-bridge-panel')), findsNothing);
    expect(find.text('Camera bridge'), findsNothing);
  });

  testWidgets('Settings lists This computer only where a bridge can run', (
    tester,
  ) async {
    await _pumpHub(tester, thisComputer: false);
    expect(
      find.byKey(const ValueKey('settings-hub-item-this_computer')),
      findsNothing,
    );

    await _pumpHub(tester, thisComputer: true);
    expect(
      find.byKey(const ValueKey('settings-hub-item-this_computer')),
      findsOne,
    );
    expect(find.text('This computer'), findsOne);
  });
}

class _FakeBridge extends BridgePanelSource {
  _FakeBridge(
    this._status, {
    this.supported = true,
    this.pairAnswer,
    this.afterPair,
  });

  BridgePanelStatus _status;
  final String? pairAnswer;
  final BridgePanelStatus? afterPair;

  @override
  final bool supported;

  final pairedWith = <String>[];
  (String, String, String)? savedRecorder;
  final switched = <bool>[];

  @override
  BridgePanelStatus get status => _status;

  void _set(BridgePanelStatus s) {
    _status = s;
    notifyListeners();
  }

  @override
  Future<String?> pair(String code) async {
    pairedWith.add(code);
    if (pairAnswer == null && afterPair != null) _set(afterPair!);
    return pairAnswer;
  }

  @override
  Future<void> saveRecorder({
    required String host,
    required String username,
    required String password,
  }) async {
    savedRecorder = (host, username, password);
    _set(
      BridgePanelStatus(
        on: _status.on,
        startsWithWindows: _status.startsWithWindows,
        paired: _status.paired,
        siteName: _status.siteName,
        organization: _status.organization,
        recorderHost: host,
        passwordSaved: password.isNotEmpty,
      ),
    );
  }

  @override
  Future<void> setOn(bool on) async {
    switched.add(on);
    _set(
      BridgePanelStatus(on: on, startsWithWindows: _status.startsWithWindows),
    );
  }
}

ApiClient _api() => ApiClient(
  client: MockClient((request) async {
    if (request.url.path.endsWith('/me')) {
      return _json({
        'data': {
          'id': 'u-7',
          'name': 'Rory',
          'email': 'rory@pinecrest.example',
          'role': {'name': 'Administrator'},
          'has_avatar': false,
          'preferences': <String, dynamic>{},
          'permissions': ['organizations.settings.view'],
          'modules': const <dynamic>[],
          'organization': {
            'id': 'org-7',
            'name': 'Pinecrest Services',
            'franchise_label': 'Franchise',
            'is_read_only': false,
          },
          'scope': {
            'type': 'organization',
            'id': 'org-7',
            'label': 'Pinecrest Services',
            'path': ['Pinecrest Services'],
          },
        },
      });
    }
    return _json({'data': const <dynamic>[]});
  }),
);

Future<void> _pumpPanel(WidgetTester tester, BridgePanelSource source) async {
  tester.view.physicalSize = const Size(1200, 1000);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);
  final api = _api();
  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(
        body: CameraBridgePanel(
          api: api,
          session: Session(api),
          source: source,
        ),
      ),
    ),
  );
  await tester.pumpAndSettle();
}

Future<void> _pumpHub(WidgetTester tester, {required bool thisComputer}) async {
  tester.view.physicalSize = const Size(1400, 1000);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);
  final api = _api();
  final session = Session(api);
  await tester.runAsync(() => session.adopt('tok'));
  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(
        body: SettingsHub(
          key: ValueKey('hub-$thisComputer'),
          api: api,
          session: session,
          thisComputer: thisComputer,
        ),
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
