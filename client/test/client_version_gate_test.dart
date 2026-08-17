import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/build_info.dart';
import 'package:ezbizi_client/core/client_platform.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/core/version_gate.dart';
import 'package:ezbizi_client/screens/update_required_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// The client half of the minimum-version gate.
///
/// ── What this is for ─────────────────────────────────────────────
///
/// EzBizi is shipping installed apps — Windows now, then Android, iOS and
/// macOS — alongside the web client. An installed app goes stale on somebody's
/// machine and then fails in confusing ways against an API that has moved on:
/// requests that half work, screens that load empty, a sign-in that refuses a
/// correct password. The server answers 426 to say plainly that this build is
/// too old, and these tests cover the two things the client owes that exchange.
///
/// It has to SAY who it is, on every request — a version alone is useless
/// because the minimum is kept per platform (Windows ships a fix in minutes;
/// iOS sits in App Store review for days, so one global number would brick every
/// iOS customer the day Windows raised it).
///
/// And it has to ANSWER the 426 with a whole screen that relays the server's own
/// sentence, offers the remedy that fits this platform, and can be left.
void main() {
  /// A client that records every request and answers 200 to all of them.
  ///
  /// Records the header VALUES rather than copying the map. `request.headers` is
  /// case-insensitive, as HTTP header names are; copying it into a plain Map
  /// throws that away and makes the assertions depend on the exact casing this
  /// client happens to write, which is not something the server can see.
  ///
  /// The two names are written out as LITERAL STRINGS here and nowhere read
  /// from [ApiClient]. That is the whole point of them, and the reason this
  /// fixture is worth its comment: these once said `x-client-platform` while the
  /// server read `X-EzBizi-Client-Platform`, and the feature was completely
  /// inert in production while this file was green — because it asserted the
  /// client agreed with the client. The same two literals appear in
  /// tests/Feature/Api/MinimumClientVersionTest, which is what makes the two
  /// halves of the contract actually meet.
  ({ApiClient api, List<({String? platform, String? version})> sent}) recording() {
    final sent = <({String? platform, String? version})>[];

    final api = ApiClient(
      client: MockClient((request) async {
        sent.add((
          platform: request.headers['x-ezbizi-client-platform'],
          version: request.headers['x-ezbizi-client-version'],
        ));

        return http.Response(
          jsonEncode({'data': <String, dynamic>{}}),
          200,
          headers: {'content-type': 'application/json'},
        );
      }),
    );

    return (api: api, sent: sent);
  }

  /// A client that refuses everything with a 426, in the shape the server sends.
  ///
  /// `download_url`, not `upgrade_url`. This fixture invented a key the server
  /// has never emitted under any configuration, called it "the shape the server
  /// sends", and then certified the client for reading it — so a configured
  /// download address was silently dropped on every installed platform while
  /// this file was green. A fixture is a claim about the other side, and a claim
  /// nothing checks is a claim that drifts. The keys below are the ones in
  /// PlatformMinimumClientVersion::refusal(), pinned verbatim in
  /// tests/Feature/Api/MinimumClientVersionTest.
  ApiClient refusing({
    String? message = 'EzBizi has been updated. Install version 0.0.0034 or '
        'newer to carry on.',
    String? downloadUrl,
    String? note,
    int status = 426,
    String? rawBody,
  }) {
    return ApiClient(
      client: MockClient((request) async {
        return http.Response(
          rawBody ??
              jsonEncode({
                'message': ?message,
                'code': 'client_too_old',
                'download_url': ?downloadUrl,
                'note': ?note,
              }),
          status,
          headers: {'content-type': 'application/json'},
        );
      }),
    );
  }

  group('the client says which build is asking', () {
    /// Every verb, because the gate is only as good as its least-covered one.
    ///
    /// A verb that forgets these headers is invisible to the server's check, and
    /// the symptom is not a clean failure — it is one part of a stale app that
    /// carries on working while the rest of it says it is out of date, which is
    /// precisely the confusing state this whole feature exists to end.
    test('every verb carries the platform and the version', () async {
      final it = recording();

      await it.api.get('/customers');
      await it.api.post('/customers', {'name': 'A1'});
      await it.api.put('/me/dashboard', {'widgets': <String>[]});
      await it.api.patch('/me/preferences', {'preferences': <String, dynamic>{}});
      await it.api.delete('/customers/c-1');
      await it.api.deleteWithBody('/me', {'password': 'x'});
      await it.api.postFile('/me/avatar', 'avatar', 'a.png', [1, 2, 3]);
      await it.api.getBytes('/customers/export');

      expect(
        it.sent,
        hasLength(8),
        reason: 'The fixture did not record every verb, so the assertions below '
            'are not covering what they claim to.',
      );

      for (final (index, headers) in it.sent.indexed) {
        expect(
          headers.platform,
          ClientPlatform.name,
          reason: 'Request $index went out with no platform, so the server has '
              'nothing to check a minimum against and this verb slips past the '
              'gate entirely.',
        );

        expect(
          headers.version,
          BuildInfo.version,
          reason: 'Request $index went out with no version, so the server '
              'cannot tell this build from any other.',
        );
      }
    });

    /// The exact strings on the wire, and the reason this test exists at all.
    ///
    /// The client shipped `X-Client-Platform` / `X-Client-Version` while
    /// EnforceMinimumClientVersion read `X-EzBizi-Client-Platform` /
    /// `X-EzBizi-Client-Version`. The server therefore saw no platform header
    /// from any real client, fell open on every request, and the entire feature
    /// was inert — staff could raise a minimum, watch the control plane report
    /// it as enforced, and refuse nobody.
    ///
    /// Both suites were green throughout. This file asserted the client's own
    /// spelling; the backend suite built its requests from the middleware's own
    /// constants. Each half was self-consistent and the two literals never met
    /// in a single assertion anywhere.
    ///
    /// So the strings are typed out here, and typed out again in
    /// tests/Feature/Api/MinimumClientVersionTest — which additionally reads
    /// this source file, so that changing one side alone turns the deploy gate
    /// red instead of quietly switching the feature off.
    test('the header names are exactly the ones the server reads', () async {
      expect(ApiClient.platformHeader, 'X-EzBizi-Client-Platform');
      expect(ApiClient.versionHeader, 'X-EzBizi-Client-Version');

      final it = recording();

      await it.api.get('/me');

      expect(
        it.sent.single.platform,
        isNotNull,
        reason: 'Nothing went out under the name the server reads, so the '
            'server has nothing to check a minimum against and every request '
            'from this build fails open — the gate is switched off in '
            'production while this file is green.',
      );
    });

    /// The version is the one already compiled in, not a second source that
    /// would drift from it. BuildInfo.version is APP_VERSION, set from
    /// client/VERSION by the build scripts.
    ///
    /// There WAS an `expect(BuildInfo.version, isNotEmpty)` here, reasoned as
    /// "an empty version would be dropped from the headers and the gate would
    /// silently never apply to this build". It could not fail. BuildInfo.version
    /// is a compile-time `String.fromEnvironment` with defaultValue 'dev', and
    /// `flutter test` passes no --dart-define — so under test it is always the
    /// literal 'dev' and the assertion was `expect('dev', isNotEmpty)`. The
    /// claim it was making is about the RELEASE build's APP_VERSION define,
    /// which lives in scripts/build-app.sh and which no Flutter test can
    /// observe: deleting that define left this green. Removed rather than
    /// rewritten, because a test that cannot fail is worse than no test — it
    /// reads as coverage of exactly the thing nobody is covering.
    test('the version sent is the build stamp, not a separate number', () async {
      final it = recording();

      await it.api.get('/me');

      expect(it.sent.single.version, BuildInfo.version);
    });

    /// Detection has to produce one of the six the server keys minimums on. An
    /// empty string, or a placeholder like "unknown", would mean every build on
    /// this platform is permanently ungated.
    test('the platform is one the server keeps a minimum for', () {
      expect(
        ClientPlatform.name,
        isIn(const ['web', 'windows', 'macos', 'linux', 'android', 'ios']),
        reason: 'Platform detection produced "${ClientPlatform.name}", which is '
            'not a platform the server stores a minimum against — so this '
            'build can never be gated.',
      );
    });

    // There WAS a test here asserting that an upload still goes out as
    // multipart/form-data after the identity headers are merged into it. It was
    // deleted rather than kept, because it could not fail: merging the full
    // `_headers` map — Content-Type: application/json and all — into a
    // MultipartRequest leaves the content type correct anyway, since
    // `MultipartRequest.finalize` writes that header itself with its generated
    // boundary. The test was asserting a guarantee that belongs to package:http
    // rather than anything this file does, so it read as coverage while covering
    // nothing. The mutation that should have killed it left it green, which is
    // exactly what mutating every test is for.
  });

  group('a 426 raises the gate', () {
    test('any refused request raises it', () async {
      final api = refusing();
      final gate = VersionGate()..install();

      final response = await api.get('/jobs');

      expect(response.upgradeRequired, isTrue);

      expect(
        gate.blocked,
        isTrue,
        reason: 'The server said this build is too old and the app carried on '
            'as though nothing happened, so every screen becomes its own small '
            'unexplained failure.',
      );
    });

    /// The whole path, end to end: a refusal carrying a configured address ends
    /// up as an address on the notice the screen will read.
    ///
    /// Asserted here as well as in the parser, because the two ends of this were
    /// each individually correct while the pair was broken — the server put the
    /// address in `download_url` and the client looked for `upgrade_url`, and
    /// nothing anywhere held both halves at once.
    test('a refusal carries its download address all the way to the notice',
        () async {
      final api = refusing(
        downloadUrl: 'https://downloads.ezbizi.com/windows',
        note: 'This update fixes invoice totals.',
      );
      final gate = VersionGate()..install();

      await api.get('/customers');

      expect(
        gate.notice!.upgradeUrl,
        'https://downloads.ezbizi.com/windows',
        reason: 'The address arrived in the refusal and did not survive the '
            'trip to the screen, which then tells the customer no address is '
            'configured — a dead end that also states something false.',
      );

      expect(gate.notice!.note, 'This update fixes invoice totals.');
    });

    /// The 426 is acted on with no condition attached, unlike the 401 beside it.
    /// An app too old to sign in is exactly as stuck as an app too old to load a
    /// job, and somebody staring at a sign-in form that refuses a correct
    /// password for no visible reason is worse off.
    test('it raises with nobody signed in', () async {
      final api = refusing();
      final gate = VersionGate()..install();

      expect(api.hasToken, isFalse);

      await api.post('/auth/login', {'email': 'marta@a-1chimney.com'});

      expect(
        gate.blocked,
        isTrue,
        reason: 'Somebody at the sign-in screen on a stale build is told '
            'nothing, so a correct password appears to be refused.',
      );
    });

    /// Several requests are in flight when a screen loads — a list, a count, a
    /// permission set. Each comes back 426, and the app must say so once.
    test('several refusals at once raise it once', () async {
      final api = refusing();
      final gate = VersionGate()..install();

      var raises = 0;
      gate.addListener(() => raises++);

      await Future.wait([
        api.get('/customers'),
        api.get('/jobs'),
        api.get('/hr/dashboard'),
      ]);

      expect(
        raises,
        1,
        reason: 'The gate fired $raises times for one stale build, so the app '
            'rebuilds once per in-flight request.',
      );
    });

    /// A download and an upload are requests like any other and can be refused
    /// like any other. Neither goes through the same code path as the rest.
    test('an upload and a download raise it too', () async {
      final upload = refusing();
      final uploadGate = VersionGate()..install();

      await upload.postFile('/me/avatar', 'avatar', 'a.png', [1]);

      expect(
        uploadGate.blocked,
        isTrue,
        reason: 'An upload from a stale build fails with nothing on screen.',
      );

      final download = refusing();
      final downloadGate = VersionGate()..install();

      await download.getBytes('/customers/export');

      expect(
        downloadGate.blocked,
        isTrue,
        reason: 'An export from a stale build fails with nothing on screen.',
      );
    });

    /// Everything else must go through untouched. A gate that trips on a 403
    /// would replace "you may not do that" with "your app is broken".
    test('no other status raises it', () async {
      for (final status in const [200, 400, 401, 402, 403, 422, 500, 503]) {
        final api = refusing(status: status);
        final gate = VersionGate()..install();

        await api.get('/customers');

        expect(
          gate.blocked,
          isFalse,
          reason: 'HTTP $status raised the update screen, so an ordinary '
              'refusal now tells somebody to reinstall the app.',
        );
      }
    });

    /// The status line is the part that matters. A proxy or a half-finished
    /// deploy can rewrite the body into an HTML page, and losing the 426 to that
    /// would report "the server returned a response the app could not read" to
    /// somebody whose real problem is a year-old app.
    test('a 426 with an unreadable body still raises it', () async {
      final api = refusing(rawBody: '<html><body>426</body></html>');
      final gate = VersionGate()..install();

      await api.get('/customers');

      expect(gate.blocked, isTrue);

      expect(
        gate.notice!.message,
        isEmpty,
        reason: 'A sentence was invented from a body that contained none.',
      );
    });

    /// Clearing is the way out. See VersionGate.clear — somebody who has just
    /// installed the newer build over the top must not need a reinstall to
    /// escape a conclusion this client drew from one answer.
    test('it can be cleared and raised again', () async {
      final api = refusing();
      final gate = VersionGate()..install();

      await api.get('/customers');
      expect(gate.blocked, isTrue);

      gate.clear();
      expect(
        gate.blocked,
        isFalse,
        reason: 'There is no way off the update screen, so a minimum raised by '
            'mistake traps everybody until they reinstall.',
      );

      await api.get('/customers');
      expect(
        gate.blocked,
        isTrue,
        reason: 'Trying again on a build that really is too old let the app '
            'back in, so the gate can be walked straight through.',
      );
    });

    /// Both faces, or the control plane never shows the screen at all.
    test('it covers the tenant client and the staff client', () async {
      final tenant = refusing();
      final staff = refusing();

      final gate = VersionGate()..install();

      await staff.get('/staff/me');

      expect(
        gate.blocked,
        isTrue,
        reason: 'A stale control-plane build is refused by the server and told '
            'nothing by the app.',
      );

      gate.clear();
      await tenant.get('/customers');

      expect(gate.blocked, isTrue);
    });

    /// The one that was actually broken, and the reason [VersionGate.install]
    /// replaced a per-client `watch()`.
    ///
    /// Screens build their own [ApiClient] for good reasons — the Development
    /// section needs a separate holder for the staff token, staff enrolment
    /// needs one for a fifteen-minute single-ability token — and none of those
    /// was ever handed to the gate. A 426 on one of them was dropped in silence,
    /// and the Development tab reported it as an expired control-plane session:
    /// a confident, wrong, unactionable explanation of a build the server has
    /// refused, with the one screen that would have explained it never shown.
    ///
    /// Registration that has to be remembered is invisible when it is forgotten,
    /// so nothing here is registered per client any more.
    test('a client built inside a screen is covered without being registered',
        () async {
      final gate = VersionGate()..install();

      // Built the way DevelopmentScreen and StaffLoginScreen build theirs:
      // locally, for one token, and never passed to anything.
      final deep = refusing();

      await deep.get('/staff/me');

      expect(
        gate.blocked,
        isTrue,
        reason: 'A client built inside a screen never reaches the gate, so the '
            'server refuses this build and the person is told something else '
            'entirely — on the Development tab, that their staff session has '
            'expired.',
      );
    });
  });

  group('what the server said is what is shown', () {
    /// The single most important thing on this screen. A sentence compiled into
    /// the client was written on the day the client was built, and the premise
    /// of the screen is that that day was too long ago.
    testWidgets('the screen shows the server sentence, not one of its own',
        (tester) async {
      const sentence = 'EzBizi needs version 0.0.0034 or newer. Your Windows '
          'app is version 0.0.0030.';

      await tester.pumpWidget(_host(
        UpdateRequiredScreen(
          notice: const UpgradeRequired(message: sentence),
          isWeb: false,
          reload: () => false,
        ),
      ));

      expect(
        find.text(sentence),
        findsOneWidget,
        reason: 'The server\'s own sentence is not on screen, so the screen is '
            'showing words frozen at build time instead of the ones the server '
            'wrote about this exact refusal.',
      );
    });

    /// A body with no sentence is ordinary — an early server, a proxy, a
    /// minimum configured without one. The screen must still explain itself.
    testWidgets('with no sentence it still says what happened', (tester) async {
      await tester.pumpWidget(_host(
        UpdateRequiredScreen(
          notice: const UpgradeRequired(),
          isWeb: false,
          reload: () => false,
        ),
      ));

      expect(
        find.textContaining('no longer accepts the version installed'),
        findsOneWidget,
        reason: 'The server sent no sentence and the screen showed a blank '
            'space where the explanation belongs.',
      );
    });

    /// The body the server ACTUALLY writes, copied key for key from
    /// PlatformMinimumClientVersion::refusal() rather than imagined.
    ///
    /// This test carried the same name while feeding a body containing
    /// `upgrade_url` — a key the server has never emitted. It passed, it would
    /// have gone on passing through any server-side rename, and meanwhile every
    /// Windows customer on a refused build was told "there is no download
    /// address set for this kind of device yet" with the configured address
    /// sitting unread in the payload. A dead end asserting a false fact about
    /// the configuration, certified by a test named for the contract it broke.
    ///
    /// The literal keys here are pinned against the real response in
    /// tests/Feature/Api/MinimumClientVersionTest.
    test('the body is read the way the server writes it', () {
      final notice = UpgradeRequired.fromBody(jsonEncode({
        'message': 'This copy of the EzBizi app is too old to talk to the server.',
        'code': 'client_too_old',
        'detail': 'Install the newer version from https://downloads.ezbizi.com/'
            'windows and sign in again. This update fixes invoice totals.',
        'platform': 'windows',
        'minimum_version': '0.0.0034',
        'your_version': '0.0.0030',
        'download_url': 'https://downloads.ezbizi.com/windows',
        'note': 'This update fixes invoice totals.',
      }));

      expect(
        notice.message,
        'This copy of the EzBizi app is too old to talk to the server.',
      );

      expect(
        notice.upgradeUrl,
        'https://downloads.ezbizi.com/windows',
        reason: 'The address the server configured was dropped, so somebody is '
            'told to install a newer version and then told no address exists.',
      );

      expect(
        notice.note,
        'This update fixes invoice totals.',
        reason: 'The staff note was dropped, so a field staff can edit, that is '
            'validated, audited and shipped in the refusal, changes nothing a '
            'customer can see.',
      );
    });

    /// The staff note reaches the person it was written for.
    ///
    /// It is stored, validated, audited and shipped in every refusal, and for a
    /// while no screen read it — a field staff could edit that changed nothing
    /// anybody could see. It is the only part of this screen that answers "why
    /// is this happening to me today", and being told to install something for
    /// no stated reason is how an update prompt becomes a support call.
    testWidgets('the staff note is shown, under the remedy', (tester) async {
      await tester.pumpWidget(_host(
        UpdateRequiredScreen(
          notice: const UpgradeRequired(
            message: 'Too old.',
            upgradeUrl: 'https://ezbizi.com/download/windows',
            note: 'We stop accepting the old app on Friday.',
          ),
          isWeb: false,
          reload: () => false,
        ),
      ));

      expect(
        find.text('We stop accepting the old app on Friday.'),
        findsOneWidget,
        reason: 'Staff wrote a note against this minimum and the customer never '
            'sees it, so the field is decoration.',
      );

      // Under the remedy, not instead of it. Why never outranks what to do.
      final noteY = tester.getTopLeft(
        find.text('We stop accepting the old app on Friday.'),
      ).dy;
      final remedyY = tester.getTopLeft(
        find.text('Install the newer version'),
      ).dy;

      expect(
        noteY,
        greaterThan(remedyY),
        reason: 'The note is above the remedy, so the reason arrives before the '
            'instruction and the instruction is what somebody needs.',
      );
    });

    /// No note is the ordinary case, and it must leave no gap or stray line.
    testWidgets('with no note nothing is drawn for it', (tester) async {
      await tester.pumpWidget(_host(
        UpdateRequiredScreen(
          notice: const UpgradeRequired(message: 'Too old.'),
          isWeb: false,
          reload: () => false,
        ),
      ));

      expect(tester.takeException(), isNull);
      expect(find.text(''), findsNothing);
    });

    /// `upgrade_url` is tolerated as well, and that is not a hedge — it costs
    /// one `??` and it means a server that ever renames the key does not produce
    /// a dead end on clients already installed, which are by definition the ones
    /// that cannot be changed.
    test('an upgrade_url is accepted as well as a download_url', () {
      expect(
        UpgradeRequired.fromBody('{"upgrade_url": "https://x.test"}').upgradeUrl,
        'https://x.test',
      );
    });

    /// Parsing runs at the exact moment the two sides are known to disagree, so
    /// it is the worst possible moment to throw. Every shape must produce a
    /// notice rather than an exception.
    test('any body at all parses instead of throwing', () {
      for (final body in const [
        '',
        'not json',
        '[]',
        'null',
        '{}',
        '{"message": null}',
        '{"message": "   "}',
        '{"data": {"message": "Too old.", "download_url": "https://x.test"}}',
      ]) {
        expect(
          () => UpgradeRequired.fromBody(body),
          returnsNormally,
          reason: 'Body $body threw, so "your app is too old" became a crash.',
        );
      }

      expect(UpgradeRequired.fromBody('{"message": "   "}').message, isEmpty,
          reason: 'A whitespace sentence was treated as a real one, so the '
              'screen shows an empty line instead of its own fallback.');

      expect(
        UpgradeRequired.fromBody(
          '{"data": {"download_url": "https://x.test"}}',
        ).upgradeUrl,
        'https://x.test',
        reason: 'A body wrapped in the data envelope this API uses elsewhere '
            'lost its address, so somebody is told to install a newer version '
            'and not told where to get one.',
      );
    });
  });

  group('the remedy fits the platform', () {
    /// Rule seven of the design. A browser is served fresh on every load, so it
    /// is only ever stale because a tab has been open a long time — the remedy
    /// is a reload. Telling a browser user to download an installer sends them
    /// looking for a file that does not exist for them.
    testWidgets('web is told to reload and never to download', (tester) async {
      await tester.pumpWidget(_host(
        UpdateRequiredScreen(
          notice: const UpgradeRequired(
            message: 'This tab has been open a while.',
            upgradeUrl: 'https://ezbizi.com/download/windows',
          ),
          isWeb: true,
          reload: () => true,
        ),
      ));

      expect(find.text('Reload EzBizi'), findsOneWidget);
      expect(find.text('Reload this page'), findsOneWidget);

      expect(
        find.textContaining('Nothing to install'),
        findsOneWidget,
        reason: 'The web copy does not say that nothing needs installing, so a '
            'browser user is left wondering what they are meant to download.',
      );

      expect(
        find.textContaining('Install the newer version'),
        findsNothing,
        reason: 'A browser user is being told to install something. There is '
            'no installer for them and the actual fix is one button away.',
      );

      expect(
        find.textContaining('ezbizi.com/download/windows'),
        findsNothing,
        reason: 'The Windows download address is on screen in a browser, where '
            'it is the wrong remedy for the wrong platform.',
      );
    });

    testWidgets('an installed app is told to download', (tester) async {
      await tester.pumpWidget(_host(
        UpdateRequiredScreen(
          notice: const UpgradeRequired(
            message: 'Install 0.0.0034 or newer.',
            upgradeUrl: 'https://ezbizi.com/download/windows',
          ),
          isWeb: false,
          reload: () => false,
        ),
      ));

      expect(find.text('Install the newer version'), findsOneWidget);

      expect(
        find.text('https://ezbizi.com/download/windows'),
        findsOneWidget,
        reason: 'The address the server gave is not on screen, so somebody is '
            'told to install a newer version with no way to find one.',
      );

      expect(
        find.text('Reload EzBizi'),
        findsNothing,
        reason: 'An installed app is being offered a reload, which cannot fetch '
            'a new copy of the app and therefore does nothing.',
      );
    });

    /// The two must genuinely differ. Asserted head to head rather than only
    /// one at a time, because a change that made both branches say the same
    /// thing would pass every assertion above.
    testWidgets('the web copy and the installed copy are not the same',
        (tester) async {
      const notice = UpgradeRequired(upgradeUrl: 'https://ezbizi.com/windows');

      Future<String> copyFor(bool isWeb) async {
        await tester.pumpWidget(_host(
          UpdateRequiredScreen(
            notice: notice,
            isWeb: isWeb,
            reload: () => true,
          ),
        ));

        return tester
            .widgetList<Text>(find.byType(Text))
            .map((t) => t.data ?? '')
            .join(' ');
      }

      final web = await copyFor(true);
      final installed = await copyFor(false);

      expect(web, isNot(equals(installed)),
          reason: 'Both platforms are reading identical words, so one of them '
              'is being told to do something it cannot do.');

      expect(web, contains('Reload'));
      expect(installed, contains('Install'));

      expect(
        installed,
        isNot(contains('served fresh')),
        reason: 'An installed app is being told it is served fresh every time, '
            'which is the one thing that is not true of it.',
      );
    });

    /// No address configured is a normal state on the day a platform is new. It
    /// must still end in something a person can do.
    testWidgets('with no address it still says what to do', (tester) async {
      await tester.pumpWidget(_host(
        UpdateRequiredScreen(
          notice: const UpgradeRequired(message: 'Too old.'),
          isWeb: false,
          reload: () => false,
        ),
      ));

      expect(
        find.textContaining('Whoever set EzBizi up for your business'),
        findsOneWidget,
        reason: 'No download address was configured and the screen offered no '
            'next step at all, which is the dead end this screen exists to '
            'avoid.',
      );
    });

    /// Some embedded webviews refuse programmatic navigation. A button that
    /// silently does nothing is a dead end wearing the costume of a way out.
    testWidgets('a refused reload says so', (tester) async {
      await tester.pumpWidget(_host(
        UpdateRequiredScreen(
          notice: const UpgradeRequired(),
          isWeb: true,
          reload: () => false,
        ),
      ));

      await tester.tap(find.text('Reload EzBizi'));
      await tester.pump();

      expect(
        find.textContaining('would not reload the page by itself'),
        findsOneWidget,
        reason: 'The reload did nothing and said nothing, so the only control '
            'on the screen appears to be broken.',
      );
    });
  });

  group('it can be left', () {
    testWidgets('try again and sign out are offered and work', (tester) async {
      var tried = 0;
      var signedOut = 0;

      await tester.pumpWidget(_host(
        UpdateRequiredScreen(
          notice: const UpgradeRequired(message: 'Too old.'),
          isWeb: false,
          reload: () => false,
          onTryAgain: () => tried++,
          onSignOut: () => signedOut++,
        ),
      ));

      await tester.tap(find.text('Try again'));
      await tester.tap(find.text('Sign out'));
      await tester.pump();

      expect(tried, 1, reason: 'Try again did nothing.');
      expect(signedOut, 1, reason: 'Sign out did nothing, so somebody on a '
          'stale build cannot leave their session — on a shared machine that is '
          'a real problem and not a cosmetic one.');
    });

    /// A sign-out control shown to somebody who is not signed in is a control
    /// that does nothing, which reads as the app being confused about who they
    /// are.
    testWidgets('sign out is absent when nobody is signed in', (tester) async {
      await tester.pumpWidget(_host(
        UpdateRequiredScreen(
          notice: const UpgradeRequired(message: 'Too old.'),
          isWeb: true,
          reload: () => true,
        ),
      ));

      expect(find.text('Sign out'), findsNothing);
    });
  });

  /// 375 is the narrowest phone this app is built for, and an installed Android
  /// build is one of the very reasons this gate exists. A screen that overflows
  /// there hides its own remedy behind a yellow-and-black stripe.
  testWidgets('it fits a 375px phone', (tester) async {
    tester.view.physicalSize = const Size(375, 640);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);

    await tester.pumpWidget(_host(
      UpdateRequiredScreen(
        // A long address on purpose: a URL has no spaces to wrap at, so it is
        // the single most likely thing on this screen to run off the edge.
        notice: const UpgradeRequired(
          message: 'EzBizi has been updated and this version of the app can no '
              'longer talk to the server. Install the newer version to carry on.',
          upgradeUrl:
              'https://downloads.ezbizi.com/windows/stable/EzBizi-Setup-0.0.0034-x64.msix',
        ),
        isWeb: false,
        reload: () => false,
        onTryAgain: () {},
        onSignOut: () {},
      ),
    ));

    expect(
      tester.takeException(),
      isNull,
      reason: 'The update screen overflows at 375px, so the person most likely '
          'to be running a stale installed build cannot read the way out of it.',
    );

    // And the way out is actually reachable, not merely un-crashed. A control
    // rendered below the fold of a screen that does not scroll is as absent as
    // one that was never drawn.
    await tester.ensureVisible(find.text('Sign out'));
    await tester.pumpAndSettle();

    expect(find.text('Sign out'), findsOneWidget);
  });
}

/// The screen under a MaterialApp with the real theme.
///
/// The real theme rather than a bare one because this screen reads colours and
/// text styles from it, and a widget that only ever renders under ThemeData()
/// can hide a null style that would throw in the app.
Widget _host(Widget child) => MaterialApp(
      theme: EzBiziTheme.light(),
      home: child,
    );
