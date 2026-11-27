import 'dart:convert';
import 'dart:io';
import 'dart:ui' as ui;

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/field/field_status.dart';
import 'package:ezbizi_client/screens/field/map_overlays.dart';
import 'package:ezbizi_client/screens/field/map_tab.dart';
import 'package:flutter/material.dart';
import 'package:flutter/rendering.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

// Jose, 2026-10-26: grey offline, red not reported, red dashed lost signal,
// green ongoing, purple stopped too long; never a job marker's colour.

Map<String, dynamic> _person(
  String id,
  String name, {
  double lat = 26.0,
  bool seen = true,
  bool fresh = true,
  bool stopped = false,
  bool silent = false,
  bool? shouldTrack,
}) {
  final now = DateTime.now().toUtc().toIso8601String();
  return {
    'user': {'id': id, 'name': name},
    'lat': seen ? lat : null,
    'lng': seen ? -80.14 : null,
    'last_seen_at': seen ? now : null,
    'fresh': seen && fresh,
    'stopped': stopped,
    'silent': silent,
    'should_track': ?shouldTrack,
    'at_job': null,
    'since': seen ? now : null,
    'distance_mi': 1,
    'speed_mph': 0,
  };
}

final _people = [
  _person('u-g', 'Gina Green', lat: 26.0),
  _person('u-s', 'Sam Silent', lat: 26.1, fresh: false, silent: true),
  _person('u-p', 'Pat Parked', lat: 26.2, stopped: true),
  _person('u-o', 'Olly Off', lat: 26.3, fresh: false),
  _person('u-r', 'Ray Never', seen: false),
];

Future<Color> _pixel(WidgetTester tester, Offset at) async {
  final boundary =
      tester.renderObject(find.byKey(const ValueKey('shot')))
          as RenderRepaintBoundary;
  final image = (await tester.runAsync(() => boundary.toImage()))!;
  final bytes = (await tester.runAsync(
    () => image.toByteData(format: ui.ImageByteFormat.rawRgba),
  ))!;
  final i = (at.dy.round() * image.width + at.dx.round()) * 4;
  return Color.fromARGB(
    bytes.getUint8(i + 3),
    bytes.getUint8(i),
    bytes.getUint8(i + 1),
    bytes.getUint8(i + 2),
  );
}

/// Painted, within anti-aliasing: every channel within 8%.
bool _near(Color a, Color b) =>
    (a.r - b.r).abs() < 0.08 &&
    (a.g - b.g).abs() < 0.08 &&
    (a.b - b.b).abs() < 0.08;

/// A point on a ring of radius [r], a little past 0 rad: inside the first
/// dash of a dashed ring, and on any solid one.
Offset _onRing(double r) => Offset(r * 0.95, r * 0.31);

/// Is [colour] painted at [at] or a pixel beside it (a thin anti-aliased
/// stroke may fully cover only one of them)?
Future<bool> _paintedNear(WidgetTester tester, Offset at, Color colour) async {
  for (final dx in [0.0, -1.0, 1.0]) {
    for (final dy in [0.0, -1.0, 1.0]) {
      if (_near(await _pixel(tester, at + Offset(dx, dy)), colour)) return true;
    }
  }
  return false;
}

void main() {
  test('each server state maps to its state', () {
    final s = _people.map(fieldPersonState).toList();
    expect(s, [
      FieldPersonState.ongoing,
      FieldPersonState.lostSignal,
      FieldPersonState.stopped,
      FieldPersonState.offline,
      FieldPersonState.notReported,
    ]);
    // Stopped is the server's field.stopped_minutes flag, even when stale.
    expect(
      fieldPersonState(_person('x', 'X', stopped: true, fresh: false)),
      FieldPersonState.stopped,
    );
    // Off shift and never reported: offline once the server says so.
    expect(
      fieldPersonState(_person('x', 'X', seen: false, shouldTrack: false)),
      FieldPersonState.offline,
    );
    expect(
      fieldPersonState(_person('x', 'X', seen: false, shouldTrack: true)),
      FieldPersonState.notReported,
    );
  });

  test('colours, and none is a job marker colour', () {
    expect(FieldPersonState.notReported.colour, EzBiziTheme.fieldRed);
    expect(FieldPersonState.lostSignal.colour, EzBiziTheme.fieldYellow);
    expect(FieldPersonState.lostSignal.tone, Tone.caution);
    expect(FieldPersonState.notReported.tone, Tone.bad);
    expect(FieldPersonState.offline.colour, EzBiziTheme.fieldSlate);
    expect(FieldPersonState.ongoing.colour, EzBiziTheme.fieldGreen);
    expect(FieldPersonState.stopped.colour, EzBiziTheme.fieldTeal);
    // Unassigned jobs are colorScheme.outline since 2026-10-30, so yellow
    // is free for lost signal.
    final jobColours = {
      EzBiziTheme.fieldBlue,
      EzBiziTheme.light().colorScheme.outline,
      EzBiziTheme.dark().colorScheme.outline,
      EzBiziTheme.light().colorScheme.onSurface,
      EzBiziTheme.dark().colorScheme.onSurface,
    };
    for (final s in FieldPersonState.values) {
      expect(jobColours.contains(s.colour), isFalse, reason: s.name);
    }
  });

  test('sort: reporting, stopped, lost signal, not reported, then name', () {
    final sorted = fieldSortByAttention([
      _person('a', 'Zed Green'),
      _person('b', 'amy Green'),
      ..._people,
    ]).map((p) => (p['user'] as Map)['name']).toList();
    expect(sorted, [
      'amy Green',
      'Gina Green',
      'Zed Green',
      'Pat Parked',
      'Sam Silent',
      'Ray Never',
      'Olly Off',
    ]);
  });

  test('initials', () {
    expect(fieldInitials('John Clements'), 'JC');
    expect(fieldInitials('Cher'), 'C');
    expect(fieldInitials(' '), '?');
  });

  for (final dark in [false, true]) {
    testWidgets('painted pin and list dot per state (dark: $dark)', (
      tester,
    ) async {
      tester.view.physicalSize = const Size(1400, 1800);
      tester.view.devicePixelRatio = 1.0;
      addTearDown(tester.view.reset);
      // toImage runs real async, where the tile cache asks for a folder.
      const channel = MethodChannel('plugins.flutter.io/path_provider');
      tester.binding.defaultBinaryMessenger.setMockMethodCallHandler(
        channel,
        (_) async => Directory.systemTemp.path,
      );
      addTearDown(
        () => tester.binding.defaultBinaryMessenger.setMockMethodCallHandler(
          channel,
          null,
        ),
      );
      final api = ApiClient(
        client: MockClient((request) async {
          final path = request.url.path;
          if (path.endsWith('/field/map/tiles')) {
            return _json({
              'data': {'url': 'https://tiles.invalid/{z}/{x}/{y}.png'},
            });
          }
          if (path.endsWith('/field/map') &&
              !request.url.queryParameters.containsKey('user_id')) {
            return _json({'data': _people});
          }
          return _json({'data': <Object?>[]});
        }),
      );
      await tester.pumpWidget(
        RepaintBoundary(
          key: const ValueKey('shot'),
          child: MaterialApp(
            theme: dark ? EzBiziTheme.dark() : EzBiziTheme.light(),
            home: Scaffold(body: FieldMapTab(api: api)),
          ),
        ),
      );
      await tester.pumpAndSettle();
      addTearDown(() => tester.pumpWidget(const SizedBox()));

      for (final p in _people) {
        final name = '${(p['user'] as Map)['name']}';
        final state = fieldPersonState(p);
        if (p['lat'] != null) {
          // The pin: a ring painted in the state colour on its right edge,
          // where a dashed ring's first dash starts. Initials, no name.
          final pin = find.byWidgetPredicate(
            (w) => w is FieldPersonPin && w.name == name,
          );
          expect(pin, findsOneWidget);
          expect(
            tester.widget<FieldPersonPin>(pin).dashed,
            state == FieldPersonState.lostSignal,
          );
          final ring = find
              .descendant(of: pin, matching: find.byType(CustomPaint))
              .first;
          final box = tester.getRect(ring);
          expect(
            _near(
              await _pixel(tester, box.center + _onRing(box.width / 2 - 1.5)),
              state.colour,
            ),
            isTrue,
            reason: 'pin $name',
          );
          expect(
            find.descendant(of: pin, matching: find.text(name)),
            findsNothing,
          );
          expect(
            find.descendant(of: pin, matching: find.text(fieldInitials(name))),
            findsOneWidget,
          );
        }

        // The list dot, left of the name (10 px wide, 5 px down).
        final row = tester.getRect(find.text(name).last);
        final dashed = state == FieldPersonState.lostSignal;
        final dotCentre = Offset(row.left - 15, row.top + 10);
        final at = dashed ? dotCentre + _onRing(4) : dotCentre;
        expect(
          await _paintedNear(tester, at, state.colour),
          isTrue,
          reason: 'dot $name',
        );
      }

      // The list sorts by attention.
      final ys = [
        for (final p in fieldSortByAttention(_people))
          tester.getTopLeft(find.text('${(p['user'] as Map)['name']}').last).dy,
      ];
      expect(ys, orderedEquals([...ys]..sort()));
    });
  }

  testWidgets('the legend names all five states', (tester) async {
    await tester.pumpWidget(
      MaterialApp(
        theme: EzBiziTheme.light(),
        home: const Scaffold(body: FieldMapLegend(showJobs: true)),
      ),
    );
    for (final s in FieldPersonState.values) {
      expect(find.byKey(ValueKey('field-legend-${s.name}')), findsOneWidget);
      expect(find.text(s.label), findsOneWidget);
    }
  });
}

http.Response _json(Map<String, Object?> body) => http.Response(
  jsonEncode(body),
  200,
  headers: {'content-type': 'application/json'},
);
