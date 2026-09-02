import 'dart:async';
import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/monitoring/desktop_monitoring_tab.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Desktop Monitoring — the hero + filmstrip viewer (docs/previews/
/// monitoring-screenshots-layouts.html, "Layout 2").
///
/// The load-bearing assertion is the honesty one (test 3): a device whose
/// capture stream has gone quiet must say "capture stopped N ago" rather
/// than showing the last frame it has as though it were current — same
/// guarantee the reachability column and PcSpecsTab.webOnlyNote already
/// hold elsewhere. It is proven non-vacuous by pumping the FRESH fixture
/// first and asserting the phrase is absent there, then pumping the stale
/// one and asserting it appears — so a build that always said "capture
/// stopped" (or never did) would fail one half or the other.
void main() {
  testWidgets(
    'the hero renders the latest capture with its own timestamp and age',
    (tester) async {
      await _pumpTab(tester, devices: [_capableDevice], shots: _freshShots);

      final hero = find.byKey(const Key('desktop-monitoring-hero'));
      expect(hero, findsOneWidget);

      expect(
        find.descendant(of: hero, matching: find.textContaining('2m ago')),
        findsOneWidget,
        reason: 'The hero should carry the newest capture (2 minutes old) '
            'and its age, not a bare frame with nothing said about when it '
            'was taken.',
      );
    },
  );

  testWidgets('the filmstrip shows the recent captures below the hero', (
    tester,
  ) async {
    await _pumpTab(tester, devices: [_capableDevice], shots: _freshShots);

    expect(find.byKey(const Key('desktop-monitoring-filmstrip')), findsOneWidget);

    for (final shot in _freshShots) {
      expect(
        find.byKey(ValueKey('desktop-monitoring-thumb-${shot['id']}')),
        findsOneWidget,
        reason: 'Every capture the server returned should have a thumbnail '
            'in the filmstrip.',
      );
    }
  });

  testWidgets('tapping a filmstrip thumbnail makes it the hero', (
    tester,
  ) async {
    await _pumpTab(tester, devices: [_capableDevice], shots: _freshShots);

    final hero = find.byKey(const Key('desktop-monitoring-hero'));
    expect(
      find.descendant(of: hero, matching: find.textContaining('2m ago')),
      findsOneWidget,
      reason: 'Before tapping anything, the newest capture is the hero.',
    );

    await tester.tap(find.byKey(const Key('desktop-monitoring-thumb-shot-3')));
    await tester.pumpAndSettle();

    expect(
      find.descendant(of: hero, matching: find.textContaining('22m ago')),
      findsOneWidget,
      reason: 'Tapping an older thumbnail should swap the hero to that '
          'frame — its own, older age should now be what the hero shows.',
    );
  });

  testWidgets(
    'switching devices while the first one is still loading does not let '
    'its late response overwrite the newer selection',
    (tester) async {
      tester.view.physicalSize = const Size(1280, 900);
      tester.view.devicePixelRatio = 1.0;
      addTearDown(tester.view.reset);

      // Two GETs in flight have no ordering guarantee — this is what proves
      // the tab does not assume one. Device A's `/monitoring/screenshots`
      // response is held open on a Completer; device B's is completed
      // first; A's is resolved LAST, after B's data is already on screen.
      // Without the generation guard in `_loadShots`, A's late answer would
      // land last and silently replace B's shots/hero — one device's
      // screenshots shown under the other device's name in the picker.
      final pending = <String, Completer<http.Response>>{
        'dev-a': Completer<http.Response>(),
        'dev-b': Completer<http.Response>(),
      };

      final api = ApiClient(
        client: MockClient((request) async {
          final path = request.url.path;

          if (path.endsWith('/me')) {
            return _json({
              'data': {
                ..._me,
                'permissions': ['monitoring.view', 'monitoring.screenshots.view'],
              },
            });
          }

          if (path.endsWith('/monitoring/devices')) {
            return _json({'data': [_raceDeviceA, _raceDeviceB]});
          }

          if (path.endsWith('/monitoring/screenshots')) {
            final deviceId = request.url.queryParameters['device_id'];
            return pending[deviceId]!.future;
          }

          if (path.contains('/monitoring/screenshots/')) {
            return http.Response.bytes(
              _onePixelPng,
              200,
              headers: const {'content-type': 'image/jpeg'},
            );
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
          home: Scaffold(body: DesktopMonitoringTab(api: api, session: session)),
        ),
      );
      // Drives initState's _loadDevices -> _loadShots('dev-a') up to its
      // now-pending GET. Deliberately bounded `pump()` calls rather than
      // `pumpAndSettle()`: the loading state renders an INDETERMINATE
      // CircularProgressIndicator, whose animation never stops on its own
      // while A's Completer is unresolved — `pumpAndSettle` would spin
      // until it times out rather than recognising "nothing more to do".
      await _advance(tester);

      // Switch to device B (person Bob Race) before A has answered — a
      // direct tap on the person's row in the picker, never a dropdown.
      // Same reasoning as above: B's own fetch starts (and shows the same
      // indeterminate spinner) while ITS Completer is unresolved, so this
      // stays on bounded pumps too.
      await tester.tap(find.text('Bob Race'));
      await _advance(tester);

      // B answers first.
      pending['dev-b']!.complete(_json({'data': _raceShotsB}));
      await tester.pumpAndSettle();

      expect(
        find.byKey(const ValueKey('desktop-monitoring-thumb-shot-b1')),
        findsOneWidget,
        reason: "B's own capture should be showing once B's response lands.",
      );

      // A's late response finally arrives — it must be ignored.
      pending['dev-a']!.complete(_json({'data': _raceShotsA}));
      await tester.pumpAndSettle();

      expect(
        find.byKey(const ValueKey('desktop-monitoring-thumb-shot-b1')),
        findsOneWidget,
        reason: "A's late answer must not displace what B already applied.",
      );
      expect(
        find.byKey(const ValueKey('desktop-monitoring-thumb-shot-a1')),
        findsNothing,
        reason: 'A stale response landing after a newer selection must be '
            "dropped, not shown under B's name in the picker — that is "
            'exactly the wrong-attribution the honesty rule exists to '
            'prevent, triggered by a race instead of elapsed time.',
      );
    },
  );

  testWidgets(
    "selecting a different person loads that person's own captures",
    (tester) async {
      tester.view.physicalSize = const Size(1280, 900);
      tester.view.devicePixelRatio = 1.0;
      addTearDown(tester.view.reset);

      final api = ApiClient(
        client: MockClient((request) async {
          final path = request.url.path;

          if (path.endsWith('/me')) {
            return _json({
              'data': {
                ..._me,
                'permissions': ['monitoring.view', 'monitoring.screenshots.view'],
              },
            });
          }

          if (path.endsWith('/monitoring/devices')) {
            return _json({'data': [_raceDeviceA, _raceDeviceB]});
          }

          if (path.endsWith('/monitoring/screenshots')) {
            final deviceId = request.url.queryParameters['device_id'];
            final shots = deviceId == 'dev-b' ? _raceShotsB : _raceShotsA;
            return _json({'data': shots});
          }

          if (path.contains('/monitoring/screenshots/')) {
            return http.Response.bytes(
              _onePixelPng,
              200,
              headers: const {'content-type': 'image/jpeg'},
            );
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
          home: Scaffold(body: DesktopMonitoringTab(api: api, session: session)),
        ),
      );
      await tester.pumpAndSettle();

      expect(
        find.byKey(const ValueKey('desktop-monitoring-thumb-shot-a1')),
        findsOneWidget,
        reason: 'Alice is the default selection (her device arrived first).',
      );

      await tester.tap(find.text('Bob Race'));
      await tester.pumpAndSettle();

      expect(
        find.byKey(const ValueKey('desktop-monitoring-thumb-shot-b1')),
        findsOneWidget,
        reason: "Selecting Bob should load Bob's own captures.",
      );
      expect(
        find.byKey(const ValueKey('desktop-monitoring-thumb-shot-a1')),
        findsNothing,
      );
    },
  );

  // Non-vacuousness of the honesty label is proven across this PAIR of
  // tests, deliberately kept as two separate `testWidgets` blocks rather
  // than one test with two `_pumpTab` calls: `tester.pumpWidget` reuses the
  // same State when the widget tree shape is unchanged (no `initState`
  // re-run without an explicit `Key`), so a second `_pumpTab` in the same
  // test would silently keep reading the FIRST fixture's already-loaded
  // data — exactly the kind of false pass "non-vacuous" is meant to rule
  // out. Two tests sidestep that pitfall entirely: a build that always
  // showed the phrase fails the first; a build that never showed it fails
  // the second.
  testWidgets(
    'a device capturing normally never shows the "capture stopped" wording',
    (tester) async {
      await _pumpTab(tester, devices: [_capableDevice], shots: _freshShots);

      expect(
        find.textContaining('capture stopped'),
        findsNothing,
        reason: 'A device capturing normally must never show the '
            '"capture stopped" wording.',
      );
    },
  );

  testWidgets(
    'a device whose capture has stopped shows "capture stopped N ago" on '
    'the hero itself, never a frame passed off as live',
    (tester) async {
      // Newest capture and last heartbeat are both 40 minutes old — well
      // past 2x the 5 minute capture interval, and past the 5 minute
      // online window.
      await _pumpTab(tester, devices: [_staleDevice], shots: _staleShots);

      expect(
        find.textContaining('capture stopped 40m ago'),
        findsAtLeastNWidgets(1),
        reason: 'A device whose last capture is 40 minutes old (and whose '
            'heartbeat is just as stale) must say the stream has stopped, '
            'labelled with how long ago — never render the old frame as if '
            'it were current.',
      );

      final hero = find.byKey(const Key('desktop-monitoring-hero'));
      expect(
        find.descendant(
          of: hero,
          matching: find.textContaining('capture stopped 40m ago'),
        ),
        findsOneWidget,
        reason: 'The hero itself — not just some header off to the side — '
            'must carry the honesty label, since the hero is the thing '
            'somebody could mistake for a live view.',
      );
    },
  );

  testWidgets(
    'without monitoring.screenshots.view, the tab shows the no-permission '
    'note rather than fetching or crashing',
    (tester) async {
      var deviceFetchAttempted = false;

      await _pumpTab(
        tester,
        devices: [_capableDevice],
        shots: _freshShots,
        grantScreenshotsView: false,
        onDevicesFetch: () => deviceFetchAttempted = true,
      );

      expect(tester.takeException(), isNull);
      expect(find.text(DesktopMonitoringTab.noPermissionNote), findsOneWidget);
      expect(find.byKey(const Key('desktop-monitoring-hero')), findsNothing);
      expect(find.byKey(const Key('desktop-monitoring-filmstrip')), findsNothing);
      expect(
        deviceFetchAttempted,
        isFalse,
        reason: 'A caller who cannot view screenshots at all should never '
            'need a round trip to learn that — the tab already knows from '
            'its own session.',
      );
    },
  );

  testWidgets(
    'when nobody has a capture-capable device, the tab shows the honest '
    'empty state instead of picking a browser session',
    (tester) async {
      await _pumpTab(tester, devices: [_webDevice], shots: const []);

      expect(tester.takeException(), isNull);
      expect(find.text(DesktopMonitoringTab.noDevicesNote), findsOneWidget);
      expect(find.byKey(const Key('desktop-monitoring-hero')), findsNothing);
      expect(find.byKey(const Key('desktop-monitoring-filmstrip')), findsNothing);
      expect(find.byKey(const Key('desktop-monitoring-picker')), findsNothing);
    },
  );

  testWidgets(
    'a browser-only device (no screenshot capability) never appears in the '
    'picker, even mixed in with a real capture device',
    (tester) async {
      await _pumpTab(
        tester,
        devices: [_capableDevice, _webDevice],
        shots: _freshShots,
      );

      expect(find.text('Marco Reyes'), findsOneWidget);
      expect(
        find.text('Priya Nair'),
        findsNothing,
        reason: "Priya's only device is a browser session — it cannot be "
            'screen-captured and must not appear in this picker at all.',
      );
    },
  );

  testWidgets(
    'two people each with a capture device show two entries in the picker',
    (tester) async {
      final second = {
        ..._capableDevice,
        'id': 'dev-2',
        'user': {'id': 'u-2', 'name': 'Priya Nair'},
        'label': 'Priya PC',
      };

      await _pumpTab(
        tester,
        devices: [_capableDevice, second],
        shots: _freshShots,
      );

      expect(find.text('Marco Reyes'), findsOneWidget);
      expect(find.text('Priya Nair'), findsOneWidget);
    },
  );

  testWidgets('the search box filters the person list by name', (tester) async {
    final second = {
      ..._capableDevice,
      'id': 'dev-2',
      'user': {'id': 'u-2', 'name': 'Priya Nair'},
      'label': 'Priya PC',
    };

    await _pumpTab(
      tester,
      devices: [_capableDevice, second],
      shots: _freshShots,
    );

    expect(find.text('Marco Reyes'), findsOneWidget);
    expect(find.text('Priya Nair'), findsOneWidget);

    await tester.enterText(find.byType(TextField), 'Priya');
    await tester.pumpAndSettle();

    expect(find.text('Priya Nair'), findsOneWidget);
    expect(find.text('Marco Reyes'), findsNothing);
  });

  testWidgets(
    'no DropdownButton or any modal picker appears anywhere in this tab',
    (tester) async {
      await _pumpTab(tester, devices: [_capableDevice], shots: _freshShots);

      expect(find.byType(DropdownButtonFormField<String>), findsNothing);
      expect(find.byType(DropdownButton<String>), findsNothing);
    },
  );

  testWidgets(
    'a capable device with zero captures yet shows its own empty state',
    (tester) async {
      await _pumpTab(tester, devices: [_capableDevice], shots: const []);

      expect(tester.takeException(), isNull);
      expect(find.text(DesktopMonitoringTab.noCapturesYetNote), findsOneWidget);
      expect(find.byKey(const Key('desktop-monitoring-hero')), findsNothing);
    },
  );

  testWidgets('at 375px the hero, picker and filmstrip render with no overflow', (
    tester,
  ) async {
    await _pumpTab(
      tester,
      devices: [_capableDevice],
      shots: _freshShots,
      size: const Size(375, 800),
    );

    expect(tester.takeException(), isNull);
    expect(find.byKey(const Key('desktop-monitoring-hero')), findsOneWidget);
    expect(find.byKey(const Key('desktop-monitoring-filmstrip')), findsOneWidget);
    expect(find.byKey(const Key('desktop-monitoring-picker')), findsOneWidget);
  });

  // ── Live View (2026-09-02-monitoring-live-view, Part B) ────────────────
  //
  // These reuse the race-test device fixtures (`_raceDeviceA`/`_raceDeviceB`,
  // already-resolved timestamps, no `__NOW__`-style template to stamp) rather
  // than `_capableDevice`, since these tests build their own MockClient and
  // never go through `_pumpTab`'s stamping step.

  testWidgets(
    'toggling "Go live" starts polling live-frame and displays the returned '
    'bytes, with a LIVE badge, replacing the static hero and filmstrip',
    (tester) async {
      final harness = _liveHarness(
        devices: [_raceDeviceA],
        shotsFor: (_) => _raceShotsA,
        liveFrame: (_) => _onePixelPng,
      );

      await _pumpLiveTab(tester, harness.api);

      expect(find.text('Go live'), findsOneWidget);
      expect(find.byKey(const Key('desktop-monitoring-filmstrip')), findsOneWidget,
          reason: 'Before going live, the static 30-day view is shown as usual.');

      await tester.tap(find.byKey(const Key('desktop-monitoring-live-toggle')));
      await _liveTick(tester);
      await _liveTick(tester);

      expect(find.text('Stop'), findsOneWidget);
      expect(harness.watchCalls, contains('dev-a'),
          reason: 'Going live must re-assert interest via POST .../watch.');
      expect(harness.frameCalls, contains('dev-a'),
          reason: 'Going live must fetch a frame immediately, not wait a '
              'full interval for the first one.');

      expect(find.byKey(const Key('desktop-monitoring-hero')), findsOneWidget);
      expect(find.byKey(const Key('desktop-monitoring-live-badge')), findsOneWidget,
          reason: 'A frame has arrived, so the LIVE badge must be showing.');
      expect(find.text('Waiting for the live feed…'), findsNothing);
      expect(find.byKey(const Key('desktop-monitoring-filmstrip')), findsNothing,
          reason: 'The live hero replaces the static hero and filmstrip.');
    },
  );

  testWidgets(
    'repeated misses from live-frame clear a previously shown frame to the '
    'waiting state, never leaving a stale frame displayed as though it were '
    'current',
    (tester) async {
      // A mutable flag the harness's `liveFrame` closure reads on every
      // call — flipped mid-test to simulate the agent going quiet, while
      // driving the exact same widget tree and ApiClient throughout.
      List<int>? frameBytes = _onePixelPng;

      final harness = _liveHarness(
        devices: [_raceDeviceA],
        shotsFor: (_) => _raceShotsA,
        liveFrame: (_) => frameBytes,
      );

      await _pumpLiveTab(tester, harness.api);

      await tester.tap(find.byKey(const Key('desktop-monitoring-live-toggle')));
      await _liveTick(tester);
      await _liveTick(tester);

      expect(find.byKey(const Key('desktop-monitoring-live-badge')), findsOneWidget,
          reason: 'A frame must be showing before the agent stops sending, '
              'or the next assertion (that it gets CLEARED) proves nothing.');

      // The agent stops sending — every live-frame request now 404s, which
      // ApiClient.getBytes turns into null.
      frameBytes = null;

      // A single miss is tolerated — one periodic tick (1.5s) must not clear
      // an already-shown frame by itself.
      await tester.pump(const Duration(milliseconds: 1500));
      await tester.pump();
      await tester.pump();

      // Two consecutive misses must clear it.
      await tester.pump(const Duration(milliseconds: 1500));
      await tester.pump();
      await tester.pump();

      expect(find.text('Waiting for the live feed…'), findsOneWidget,
          reason: 'After repeated misses the hero must say the feed is '
              'waiting, never keep showing the old frame as if it were '
              'still current.');
      expect(find.byKey(const Key('desktop-monitoring-live-badge')), findsNothing,
          reason: 'No LIVE badge once the feed is no longer arriving.');
    },
  );

  testWidgets('turning "Go live" off stops both timers immediately', (tester) async {
    final harness = _liveHarness(
      devices: [_raceDeviceA],
      shotsFor: (_) => _raceShotsA,
      liveFrame: (_) => _onePixelPng,
    );

    await _pumpLiveTab(tester, harness.api);

    await tester.tap(find.byKey(const Key('desktop-monitoring-live-toggle')));
    await _liveTick(tester);
    await _liveTick(tester);

    expect(find.text('Stop'), findsOneWidget);
    final framesBeforeStop = harness.frameCalls.length;
    final watchesBeforeStop = harness.watchCalls.length;
    expect(framesBeforeStop, greaterThan(0));
    expect(watchesBeforeStop, greaterThan(0));

    await tester.tap(find.byKey(const Key('desktop-monitoring-live-toggle')));
    await _liveTick(tester);

    expect(find.text('Go live'), findsOneWidget);
    expect(find.byKey(const Key('desktop-monitoring-filmstrip')), findsOneWidget,
        reason: 'Turning live off falls back to the static hero + filmstrip.');

    // Advance well past both the watch (8s) and frame (1.5s) intervals — if
    // either timer were still running, more requests would land here.
    await tester.pump(const Duration(seconds: 10));
    await tester.pump();
    await tester.pump();

    expect(harness.frameCalls.length, framesBeforeStop,
        reason: 'Stopping live must cancel the frame-polling timer '
            'immediately, not merely stop rendering its results.');
    expect(harness.watchCalls.length, watchesBeforeStop,
        reason: 'Stopping live must cancel the watch-refresh timer '
            'immediately.');
  });

  testWidgets(
    'switching device while live cancels the old device\'s live polling — '
    'no cross-device frame painted',
    (tester) async {
      final harness = _liveHarness(
        devices: [_raceDeviceA, _raceDeviceB],
        shotsFor: (id) => id == 'dev-b' ? _raceShotsB : _raceShotsA,
        liveFrame: (_) => _onePixelPng,
      );

      await _pumpLiveTab(tester, harness.api);

      // Alice (dev-a) is the default selection.
      await tester.tap(find.byKey(const Key('desktop-monitoring-live-toggle')));
      await _liveTick(tester);
      await _liveTick(tester);

      expect(find.text('Stop'), findsOneWidget);
      expect(harness.frameCalls, contains('dev-a'));

      // Switch to Bob (dev-b) via the person picker — a direct tap, never a
      // dropdown, same as the existing device-switch race test above.
      await tester.tap(find.text('Bob Race'));
      await _liveTick(tester);
      await _liveTick(tester);

      // The switch must cancel live outright rather than silently carrying
      // it over onto the new device — DesktopMonitoringTab._selectDevice
      // calls _stopLive() before anything else.
      expect(find.text('Go live'), findsOneWidget,
          reason: 'Switching device stops live rather than leaving it on '
              'for whichever device is now selected.');
      expect(find.byKey(const Key('desktop-monitoring-live-badge')), findsNothing);

      final devAFramesAtSwitch = harness.frameCalls.where((id) => id == 'dev-a').length;

      // Advance well past both live intervals — if dev-a's timers were only
      // guarded rather than actually cancelled, more dev-a requests would
      // land here.
      await tester.pump(const Duration(seconds: 10));
      await tester.pump();
      await tester.pump();

      expect(
        harness.frameCalls.where((id) => id == 'dev-a').length,
        devAFramesAtSwitch,
        reason: "The old device's live polling must be CANCELLED by the "
            'switch — no further dev-a requests after leaving it.',
      );
      expect(
        harness.frameCalls.where((id) => id == 'dev-b').length,
        0,
        reason: 'Switching device does not auto-start live on the new '
            "device either — this proves dev-a's frames never get painted "
            "under dev-b's name, not that dev-b starts live on its own.",
      );
    },
  );

  // ── Remote Control (2026-09-02-monitoring-remote-control, Part B) ───────

  testWidgets(
    'without monitoring.control there is no "Take control" toggle, even with '
    'screenshots.view granted',
    (tester) async {
      final harness = _liveHarness(
        devices: [_raceDeviceA],
        shotsFor: (_) => _raceShotsA,
        liveFrame: (_) => _onePixelPng,
        // grantControl defaults to false — view is granted, control is not.
      );

      await _pumpLiveTab(tester, harness.api);

      expect(find.text('Go live'), findsOneWidget,
          reason: 'Viewing still works — this is the control grant missing, '
              'not the whole tab.');
      expect(find.text('Take control'), findsNothing);
      expect(find.byKey(const Key('desktop-monitoring-control-toggle')), findsNothing,
          reason: 'The control gate is the monitoring.control grant — without '
              'it the toggle must not exist at all.');
    },
  );

  testWidgets(
    'tapping "Take control" auto-starts live and posts to .../control',
    (tester) async {
      final harness = _liveHarness(
        devices: [_raceDeviceA],
        shotsFor: (_) => _raceShotsA,
        liveFrame: (_) => _onePixelPng,
        grantControl: true,
      );

      await _pumpLiveTab(tester, harness.api);

      expect(find.text('Take control'), findsOneWidget);
      expect(find.text('Go live'), findsOneWidget,
          reason: 'Not live yet — Take control is what starts it.');

      await tester.tap(find.byKey(const Key('desktop-monitoring-control-toggle')));
      await _liveTick(tester);
      await _liveTick(tester);

      expect(find.text('Release'), findsOneWidget,
          reason: 'The toggle flips to Release once control is on.');
      expect(find.text('Stop'), findsOneWidget,
          reason: 'Taking control must auto-start live — you cannot drive a '
              'screen you cannot see.');
      expect(harness.controlCalls, contains('dev-a'),
          reason: 'Taking control must assert interest via POST .../control.');
      expect(find.byKey(const Key('desktop-monitoring-controlling-badge')), findsOneWidget,
          reason: 'A clear CONTROLLING badge must be shown while driving.');
    },
  );

  testWidgets(
    'a pointer-down on the hero posts an input batch carrying a mouseButton '
    'and a mouseMove with normalized 0..1 coordinates',
    (tester) async {
      final harness = _liveHarness(
        devices: [_raceDeviceA],
        shotsFor: (_) => _raceShotsA,
        liveFrame: (_) => _onePixelPng,
        grantControl: true,
      );

      await _pumpLiveTab(tester, harness.api);

      await tester.tap(find.byKey(const Key('desktop-monitoring-control-toggle')));
      await _liveTick(tester);
      await _liveTick(tester);

      // Press at the centre of the hero — its box centre maps to (0.5, 0.5).
      final center = tester.getCenter(find.byKey(const Key('desktop-monitoring-hero')));
      final gesture = await tester.startGesture(center);
      await tester.pump();
      // Let the ~60ms flush debounce fire, then the post complete.
      await tester.pump(const Duration(milliseconds: 60));
      await tester.pump();
      await gesture.up();
      await tester.pump();
      await tester.pump(const Duration(milliseconds: 60));
      await tester.pump();

      expect(harness.inputBatches, isNotEmpty,
          reason: 'A pointer-down while controlling must post an input batch.');

      final events = harness.inputBatches.expand((b) => b).cast<Map<String, dynamic>>().toList();

      final moves = events.where((e) => e['type'] == 'mouseMove').toList();
      final buttons = events.where((e) => e['type'] == 'mouseButton').toList();

      expect(moves, isNotEmpty,
          reason: 'The press must carry a mouseMove to the pressed point.');
      expect(buttons, isNotEmpty,
          reason: 'The press must carry a mouseButton event.');

      final move = moves.first;
      final x = (move['x'] as num).toDouble();
      final y = (move['y'] as num).toDouble();
      expect(x, inInclusiveRange(0.0, 1.0),
          reason: 'x must be a normalized fraction of the hero width.');
      expect(y, inInclusiveRange(0.0, 1.0),
          reason: 'y must be a normalized fraction of the hero height.');

      expect(buttons.first['button'], 'left',
          reason: 'A primary press maps to the left button.');
      expect(buttons.first['down'], true,
          reason: 'A pointer-down is a button-down.');
    },
  );

  testWidgets(
    'releasing control stops capturing — a later pointer-down posts nothing',
    (tester) async {
      final harness = _liveHarness(
        devices: [_raceDeviceA],
        shotsFor: (_) => _raceShotsA,
        liveFrame: (_) => _onePixelPng,
        grantControl: true,
      );

      await _pumpLiveTab(tester, harness.api);

      await tester.tap(find.byKey(const Key('desktop-monitoring-control-toggle')));
      await _liveTick(tester);
      await _liveTick(tester);

      final center = tester.getCenter(find.byKey(const Key('desktop-monitoring-hero')));
      final first = await tester.startGesture(center);
      await tester.pump();
      await tester.pump(const Duration(milliseconds: 60));
      await tester.pump();
      await first.up();
      await tester.pump();

      expect(harness.inputBatches, isNotEmpty,
          reason: 'Sanity: input flows while controlling, or the next '
              'assertion (that it STOPS) proves nothing.');
      final batchesWhileControlling = harness.inputBatches.length;

      // Release control — the toggle now reads Release.
      await tester.tap(find.byKey(const Key('desktop-monitoring-control-toggle')));
      await _liveTick(tester);

      expect(find.text('Take control'), findsOneWidget,
          reason: 'Releasing flips the toggle back to Take control.');
      expect(find.byKey(const Key('desktop-monitoring-controlling-badge')), findsNothing);

      // A press now lands on a hero no longer wrapped in the capture layer.
      final second = await tester.startGesture(center);
      await tester.pump();
      await tester.pump(const Duration(milliseconds: 60));
      await tester.pump();
      await second.up();
      await tester.pump();
      await tester.pump(const Duration(milliseconds: 60));
      await tester.pump();

      expect(harness.inputBatches.length, batchesWhileControlling,
          reason: 'After release the pointer must no longer be captured — no '
              'further input posts.');
    },
  );

  testWidgets(
    'switching device while controlling cancels control outright',
    (tester) async {
      final harness = _liveHarness(
        devices: [_raceDeviceA, _raceDeviceB],
        shotsFor: (id) => id == 'dev-b' ? _raceShotsB : _raceShotsA,
        liveFrame: (_) => _onePixelPng,
        grantControl: true,
      );

      await _pumpLiveTab(tester, harness.api);

      // Alice (dev-a) is the default selection.
      await tester.tap(find.byKey(const Key('desktop-monitoring-control-toggle')));
      await _liveTick(tester);
      await _liveTick(tester);

      expect(find.text('Release'), findsOneWidget);
      expect(find.byKey(const Key('desktop-monitoring-controlling-badge')), findsOneWidget);

      final inputsAtSwitch = harness.inputBatches.length;

      // Switch to Bob via the person picker — a direct tap, never a dropdown.
      await tester.tap(find.text('Bob Race'));
      await _liveTick(tester);
      await _liveTick(tester);

      expect(find.text('Take control'), findsOneWidget,
          reason: 'Switching device stops control rather than carrying it '
              'onto the newly selected machine.');
      expect(find.text('Go live'), findsOneWidget,
          reason: 'And stops live with it — control cannot outlive its feed.');
      expect(find.byKey(const Key('desktop-monitoring-controlling-badge')), findsNothing);

      // Any buffered input must not leak to either device after the switch.
      await tester.pump(const Duration(seconds: 1));
      await tester.pump();
      expect(harness.inputBatches.length, inputsAtSwitch,
          reason: 'No input batch may be posted across a device switch.');
    },
  );
}

const _capableDevice = {
  'id': 'dev-1',
  'user': {'id': 'u-1', 'name': 'Marco Reyes'},
  'device_key': 'key-1',
  'label': 'Shop desktop',
  'platform': 'windows',
  'capabilities': ['activity', 'screenshot'],
  'last_seen_at': '__NOW__',
};

const _staleDevice = {
  'id': 'dev-1',
  'user': {'id': 'u-1', 'name': 'Marco Reyes'},
  'device_key': 'key-1',
  'label': 'Shop desktop',
  'platform': 'windows',
  'capabilities': ['activity', 'screenshot'],
  'last_seen_at': '__40M_AGO__',
};

const _webDevice = {
  'id': 'dev-2',
  'user': {'id': 'u-2', 'name': 'Priya Nair'},
  'device_key': 'key-2',
  'label': 'Chrome · Windows',
  'platform': 'web',
  'capabilities': ['activity'],
  'last_seen_at': '__NOW__',
};

// Newest first, matching ScreenshotsController::index's ordering.
const _freshShots = [
  {
    'id': 'shot-1',
    'device_id': 'dev-1',
    'captured_at': '__2M_AGO__',
    'bytes': 98304,
    'width': 1920,
    'height': 1080,
  },
  {
    'id': 'shot-2',
    'device_id': 'dev-1',
    'captured_at': '__7M_AGO__',
    'bytes': 96000,
    'width': 1920,
    'height': 1080,
  },
  {
    'id': 'shot-3',
    'device_id': 'dev-1',
    'captured_at': '__22M_AGO__',
    'bytes': 94000,
    'width': 1920,
    'height': 1080,
  },
];

const _staleShots = [
  {
    'id': 'shot-1',
    'device_id': 'dev-1',
    'captured_at': '__40M_AGO__',
    'bytes': 98304,
    'width': 1920,
    'height': 1080,
  },
];

// Fixtures for the device-switch race test only — already-resolved
// timestamps, since that test is about which device's data wins, not about
// staleness.
final _raceNow = DateTime.now().toUtc().toIso8601String();

final _raceDeviceA = {
  'id': 'dev-a',
  'user': {'id': 'u-a', 'name': 'Alice Race'},
  'device_key': 'key-a',
  'label': 'Desk A',
  'platform': 'windows',
  'capabilities': ['activity', 'screenshot'],
  'last_seen_at': _raceNow,
};

final _raceDeviceB = {
  'id': 'dev-b',
  'user': {'id': 'u-b', 'name': 'Bob Race'},
  'device_key': 'key-b',
  'label': 'Desk B',
  'platform': 'windows',
  'capabilities': ['activity', 'screenshot'],
  'last_seen_at': _raceNow,
};

List<Map<String, dynamic>> get _raceShotsA => [
  {
    'id': 'shot-a1',
    'device_id': 'dev-a',
    'captured_at': _raceNow,
    'bytes': 90000,
    'width': 1920,
    'height': 1080,
  },
];

List<Map<String, dynamic>> get _raceShotsB => [
  {
    'id': 'shot-b1',
    'device_id': 'dev-b',
    'captured_at': _raceNow,
    'bytes': 90000,
    'width': 1920,
    'height': 1080,
  },
];

/// A genuine 1x1 PNG. `Image.memory` decodes bytes for real, so a handful of
/// arbitrary numbers would fail to decode rather than exercise the hero and
/// filmstrip rendering this suite is actually testing.
final _onePixelPng = base64Decode(
  'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmM'
  'IQAAAABJRU5ErkJggg==',
);

Future<void> _pumpTab(
  WidgetTester tester, {
  required List<Map<String, dynamic>> devices,
  required List<Map<String, dynamic>> shots,
  bool grantScreenshotsView = true,
  VoidCallback? onDevicesFetch,
  Size size = const Size(1280, 900),
}) async {
  tester.view.physicalSize = size;
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final now = DateTime.now().toUtc();
  String stamp(String template) => switch (template) {
    '__NOW__' => now.toIso8601String(),
    '__2M_AGO__' => now.subtract(const Duration(minutes: 2)).toIso8601String(),
    '__7M_AGO__' => now.subtract(const Duration(minutes: 7)).toIso8601String(),
    '__22M_AGO__' =>
      now.subtract(const Duration(minutes: 22)).toIso8601String(),
    '__40M_AGO__' =>
      now.subtract(const Duration(minutes: 40)).toIso8601String(),
    _ => template,
  };

  final liveDevices = [
    for (final d in devices) {...d, 'last_seen_at': stamp(d['last_seen_at'] as String)},
  ];
  final liveShots = [
    for (final s in shots) {...s, 'captured_at': stamp(s['captured_at'] as String)},
  ];

  final permissions = <String>[
    'monitoring.view',
    if (grantScreenshotsView) 'monitoring.screenshots.view',
  ];

  final api = ApiClient(
    client: MockClient((request) async {
      final path = request.url.path;

      if (path.endsWith('/me')) {
        return _json({'data': {..._me, 'permissions': permissions}});
      }

      if (path.endsWith('/monitoring/devices')) {
        onDevicesFetch?.call();
        return _json({'data': liveDevices});
      }

      if (path.endsWith('/monitoring/screenshots')) {
        return _json({'data': liveShots});
      }

      if (path.contains('/monitoring/screenshots/')) {
        return http.Response.bytes(
          _onePixelPng,
          200,
          headers: const {'content-type': 'image/jpeg'},
        );
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
        body: DesktopMonitoringTab(api: api, session: session),
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

/// Advance a bounded amount of real time without waiting for the tree to
/// fully settle — `pumpAndSettle()` cannot be used anywhere an indeterminate
/// `CircularProgressIndicator` is on screen (its animation never stops on
/// its own, so `pumpAndSettle` spins until it times out rather than
/// recognising "nothing more to do"). Used only by the device-switch race
/// test, which deliberately leaves a fetch's response unresolved.
Future<void> _advance(WidgetTester tester, [int steps = 10]) async {
  for (var i = 0; i < steps; i++) {
    await tester.pump(const Duration(milliseconds: 50));
  }
}

/// A harness for the Live View tests — builds its own `ApiClient` (never
/// through `_pumpTab`, whose devices/shots fixtures rely on `__NOW__`-style
/// template stamping the live-view fixtures below don't need) and records
/// which device id every `.../watch` and `.../live-frame` request named, so
/// a test can assert not just THAT a request landed but for WHICH device.
///
/// [liveFrame] answers every `GET .../devices/{id}/live-frame` — return
/// bytes for a "frame arrived" tick, or `null` for a 404 ("no fresh frame"),
/// which is exactly what `ApiClient.getBytes` turns a non-200 into.
({
  ApiClient api,
  List<String> watchCalls,
  List<String> frameCalls,
  List<String> controlCalls,
  List<List<dynamic>> inputBatches,
}) _liveHarness({
  required List<Map<String, dynamic>> devices,
  required List<Map<String, dynamic>> Function(String deviceId) shotsFor,
  required List<int>? Function(String deviceId) liveFrame,
  bool grantControl = false,
}) {
  final watchCalls = <String>[];
  final frameCalls = <String>[];
  // Remote Control (Part B): which device every `.../control` post named, and
  // the decoded `events` list of every `.../input` batch — so a test can
  // assert not just THAT input was posted but WHAT was in it and for whom.
  final controlCalls = <String>[];
  final inputBatches = <List<dynamic>>[];

  final api = ApiClient(
    client: MockClient((request) async {
      final path = request.url.path;
      final segments = request.url.pathSegments;

      if (path.endsWith('/me')) {
        return _json({
          'data': {
            ..._me,
            'permissions': [
              'monitoring.view',
              'monitoring.screenshots.view',
              if (grantControl) 'monitoring.control',
            ],
          },
        });
      }

      if (path.endsWith('/monitoring/devices')) {
        return _json({'data': devices});
      }

      if (path.endsWith('/monitoring/screenshots')) {
        final deviceId = request.url.queryParameters['device_id'] ?? '';
        return _json({'data': shotsFor(deviceId)});
      }

      if (path.contains('/monitoring/screenshots/')) {
        return http.Response.bytes(
          _onePixelPng,
          200,
          headers: const {'content-type': 'image/jpeg'},
        );
      }

      if (path.contains('/monitoring/devices/') && path.endsWith('/watch')) {
        final id = segments[segments.length - 2];
        watchCalls.add(id);
        return http.Response('', 204);
      }

      if (path.contains('/monitoring/devices/') && path.endsWith('/control')) {
        final id = segments[segments.length - 2];
        controlCalls.add(id);
        return http.Response('', 204);
      }

      if (path.contains('/monitoring/devices/') && path.endsWith('/input')) {
        final body = jsonDecode(request.body) as Map<String, dynamic>;
        inputBatches.add((body['events'] as List<dynamic>?) ?? const []);
        return http.Response('', 204);
      }

      if (path.contains('/monitoring/devices/') && path.endsWith('/live-frame')) {
        final id = segments[segments.length - 2];
        frameCalls.add(id);
        final bytes = liveFrame(id);
        return bytes == null
            ? http.Response('', 404)
            : http.Response.bytes(bytes, 200, headers: const {'content-type': 'image/jpeg'});
      }

      return _json({'message': 'Nothing stubbed for $path.'}, 500);
    }),
  );

  return (
    api: api,
    watchCalls: watchCalls,
    frameCalls: frameCalls,
    controlCalls: controlCalls,
    inputBatches: inputBatches,
  );
}

Future<void> _pumpLiveTab(WidgetTester tester, ApiClient api) async {
  tester.view.physicalSize = const Size(1280, 900);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final session = Session(api);
  await tester.runAsync(() => session.adopt('tok'));
  expect(session.signedIn, isTrue);

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(body: DesktopMonitoringTab(api: api, session: session)),
    ),
  );
  await tester.pumpAndSettle();
}

/// Advances the fake clock by [by] and flushes the microtask queue an extra
/// couple of times so an in-flight `await` chain (mock HTTP response ->
/// `ApiClient` decoding -> `setState`) has settled before the next
/// assertion — same reasoning as [_advance], just a shorter, named step for
/// the live-view timers' own intervals.
Future<void> _liveTick(WidgetTester tester, [Duration by = const Duration(milliseconds: 50)]) async {
  await tester.pump(by);
  await tester.pump();
  await tester.pump();
}
