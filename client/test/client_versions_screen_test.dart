import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/staff_session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/staff/client_versions_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// The control plane's minimum-version screen.
///
/// ── Why this file exists ─────────────────────────────────────────
///
/// The server half of this feature shipped without any screen at all. The
/// endpoints were there, audited, validated and exempted from the gate they
/// configure — and nothing in the product called them. The only way to raise or
/// lift a minimum was to issue `PUT /api/v1/staff/client-versions/{platform}` by
/// hand with a bearer token, which is precisely the shell-adjacent operation the
/// house rule (the app configures itself) exists to avoid, and which fails in
/// the exact situation the endpoint was written for: an incident, out of hours,
/// from a phone.
///
/// Nothing failed while that was true, because a missing screen breaks no test.
/// So these assertions are mostly about the recovery path — the undo has to be
/// present, reachable and correct, because it is the thing standing between a
/// mistyped version and every customer on a platform being locked out.
void main() {
  testWidgets('every platform is listed, including the unconfigured ones', (
    tester,
  ) async {
    await _pump(tester, _handler());

    // Six rows, four of them saying nothing is set. The unconfigured platforms
    // are the interesting half — they are where a stale build is currently
    // sailing through — so a list of only the configured ones would answer a
    // different question from the one being asked.
    expect(find.text('Windows'), findsOneWidget);
    expect(find.text('Web (in a browser)'), findsOneWidget);
    expect(find.text('macOS'), findsOneWidget);
    expect(find.text('Linux'), findsOneWidget);
    expect(find.text('Android'), findsOneWidget);
    expect(find.text('iPhone and iPad'), findsOneWidget);

    expect(
      find.text('No minimum set — every build gets through.'),
      findsNWidgets(5),
    );

    // The state as a sentence rather than a bare number, so nobody has to work
    // out whether 0.0.0030 is the oldest allowed build or the newest refused
    // one. Getting that backwards by one build is an outage.
    expect(find.text('Builds older than 0.0.0030 are refused.'), findsOneWidget);
  });

  testWidgets('lifting a minimum is one press and clears it', (tester) async {
    final sent = <({String platform, Map<String, dynamic> body})>[];

    await _pump(tester, _handler(onPut: sent.add));

    await tester.tap(find.text('Lift it'));
    await tester.pumpAndSettle();

    expect(
      sent,
      hasLength(1),
      reason: 'The undo sent nothing, so a minimum set too high can only be '
          'lifted by hand-issuing an HTTP request — which is the state this '
          'screen was built to end.',
    );

    expect(sent.single.platform, 'windows');

    expect(
      sent.single.body['minimum_version'],
      isNull,
      reason: 'Lifting sent something other than a cleared minimum, so the one '
          'control that ends a lockout does not end it.',
    );
  });

  /// Only where there is something to lift. A control that does nothing reads as
  /// the screen being confused about what is set.
  testWidgets('there is nothing to lift where nothing is set', (tester) async {
    await _pump(tester, _handler());

    expect(find.text('Lift it'), findsOneWidget);
    expect(find.text('Set a minimum'), findsNWidgets(5));
  });

  testWidgets('a save sends the whole configuration, because PUT replaces it', (
    tester,
  ) async {
    final sent = <({String platform, Map<String, dynamic> body})>[];

    await _pump(tester, _handler(onPut: sent.add));

    await tester.tap(find.text('Change'));
    await tester.pumpAndSettle();

    await tester.enterText(
      find.widgetWithText(TextField, 'Oldest allowed version'),
      '0.0.0034',
    );
    await tester.enterText(
      find.widgetWithText(TextField, 'Note to customers'),
      'This update fixes invoice totals.',
    );

    await tester.tap(find.text('Save'));
    await tester.pumpAndSettle();

    expect(sent, hasLength(1));

    // All three fields, every time. Under PUT an omitted field is a cleared
    // field, so sending only what changed would make the result of a save depend
    // on which boxes happened to be touched.
    expect(sent.single.body, {
      'minimum_version': '0.0.0034',
      'download_url': 'https://downloads.ezbizi.com/windows',
      'note': 'This update fixes invoice totals.',
    });
  });

  /// Web has no installer, so the form must not offer a field for one — and the
  /// server says so rather than the client deciding it independently.
  testWidgets('web is not offered a download address', (tester) async {
    await _pump(tester, _handler());

    await tester.tap(find.text('Set a minimum').first);
    await tester.pumpAndSettle();

    expect(find.text('Web (in a browser)'), findsWidgets);
    expect(find.text('Where the newer build lives'), findsNothing);
    expect(find.textContaining('A browser has nothing to install'), findsOneWidget);
  });

  /// A refused save has to be readable, and it has to stay on screen. The
  /// server's sentence here explains a rule — "that is not a version this can
  /// compare" — and it is the only thing that tells somebody what to type
  /// instead.
  testWidgets('a refused save shows the reason the server gave', (tester) async {
    await _pump(tester, _handler(refuseWith: {
      'minimum_version': ["'v0.0.30' is not a version this can compare."],
    }));

    await tester.tap(find.text('Change'));
    await tester.pumpAndSettle();

    await tester.enterText(
      find.widgetWithText(TextField, 'Oldest allowed version'),
      'v0.0.30',
    );

    await tester.tap(find.text('Save'));
    await tester.pumpAndSettle();

    expect(
      find.text("'v0.0.30' is not a version this can compare."),
      findsOneWidget,
      reason: 'The save was refused and the screen said nothing, so somebody '
          'walks away believing the gate is up when it is not.',
    );
  });

  /// This screen is reached during an incident, which is exactly when the person
  /// who has to act is on whatever device is in their hand.
  testWidgets('it fits a 375px phone', (tester) async {
    tester.view.physicalSize = const Size(375, 800);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);

    await _pump(tester, _handler(), wide: false);

    expect(
      tester.takeException(),
      isNull,
      reason: 'The screen overflows on a phone, so the control that ends a '
          'lockout is behind a yellow-and-black stripe.',
    );

    expect(find.text('Lift it'), findsOneWidget);
  });
}

const _me = {
  'id': 'staff-1',
  'name': 'Owner',
  'email': 'owner@ezbizi.test',
  'tier': 'lead_developer',
  'may_enter_without_approval': true,
  'recovery_code_count': 8,
};

Map<String, dynamic> _platform(
  String platform, {
  String? minimum,
  String? url,
  String? note,
}) => {
  'platform': platform,
  'minimum_version': minimum,
  'is_enforced': minimum != null,
  'download_url': url,
  'note': note,
  'changed_by': minimum == null ? null : 'Owner <owner@ezbizi.test>',
  'changed_at': minimum == null ? null : '2026-08-17T09:00:00+00:00',
  'takes_download_url': platform != 'web',
};

MockClientHandler _handler({
  void Function(({String platform, Map<String, dynamic> body}))? onPut,
  Map<String, List<String>>? refuseWith,
}) {
  return (http.Request request) async {
    final path = request.url.path;

    if (path.endsWith('/staff/me')) return _json({'data': _me});

    if (path.endsWith('/staff/client-versions') && request.method == 'GET') {
      return _json({
        'data': [
          // Deliberately in the server's order, web first, so the screen is
          // shown to render what it is given rather than a sort of its own.
          _platform('web'),
          _platform(
            'windows',
            minimum: '0.0.0030',
            url: 'https://downloads.ezbizi.com/windows',
          ),
          _platform('macos'),
          _platform('linux'),
          _platform('android'),
          _platform('ios'),
        ],
      });
    }

    if (request.method == 'PUT') {
      final platform = path.split('/').last;
      final body = jsonDecode(request.body) as Map<String, dynamic>;

      onPut?.call((platform: platform, body: body));

      if (refuseWith != null) {
        return _json({
          'message': 'The given data was invalid.',
          'errors': refuseWith,
        }, status: 422);
      }

      return _json({
        'data': _platform(
          platform,
          minimum: body['minimum_version'] as String?,
          url: body['download_url'] as String?,
          note: body['note'] as String?,
        ),
      });
    }

    return _json({'message': 'unexpected ${request.method} $path'}, status: 404);
  };
}

http.Response _json(Map<String, dynamic> body, {int status = 200}) =>
    http.Response(
      jsonEncode(body),
      status,
      headers: {'content-type': 'application/json'},
    );

Future<void> _pump(
  WidgetTester tester,
  MockClientHandler handler, {
  bool wide = true,
}) async {
  if (wide) {
    tester.view.physicalSize = const Size(1600, 2200);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);
  }

  final api = ApiClient(client: MockClient(handler));
  final staff = StaffSession(api);

  await tester.runAsync(() => staff.adopt('tok'));

  expect(
    staff.signedIn,
    isTrue,
    reason: 'The /staff/me stub did not produce a session.',
  );

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(body: ClientVersionsScreen(staff: staff)),
    ),
  );
  await tester.pumpAndSettle();
}
