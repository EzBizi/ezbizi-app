import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/staff_session.dart';
import 'package:ezbizi_client/core/token_store.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// "Keep me signed in" has to survive the restart an update forces.
///
/// Jose, 2026-11-16: "every new update seem to log me out". Measured on his
/// PC: the installer and the keychain path were not it (the same keychain
/// file still held this install's phone_device_id from 09-10), and the
/// server still accepted the desktop's token. The token was simply no longer
/// in the keychain when 0.0.0577 started, although the app had been running
/// on it until the installer closed it. A token can leave the keychain while
/// the session keeps its in-memory copy, and nothing ever noticed.
///
/// These run against the plugin's in-memory store
/// (FlutterSecureStorage.setMockInitialValues), so "the keychain" below is
/// what TokenStore.init reads at the next launch.
void main() {
  const tenantKey = 'ezbizi.token';
  const disk = FlutterSecureStorage();

  Future<void> launchWith(Map<String, String> keychain) async {
    FlutterSecureStorage.setMockInitialValues(Map.of(keychain));
    TokenStore.debugReset();
    await TokenStore.init();
  }

  /// TokenStore writes are fire-and-forget; let them land.
  Future<void> settle() async {
    for (var i = 0; i < 10; i++) {
      await Future<void>.delayed(Duration.zero);
    }
  }

  http.Response json(Object body, int status) => http.Response(
    jsonEncode(body),
    status,
    headers: {'content-type': 'application/json'},
  );

  Session customer() => Session(
    ApiClient(
      client: MockClient((request) async {
        if (request.url.path.endsWith('/me')) return json({'data': _me}, 200);
        return json({'data': <Object>[]}, 200);
      }),
    ),
  );

  test(
    'a kept token that leaves the keychain mid-session is written back',
    () async {
      await launchWith({tenantKey: 'tok-801', 'phone_device_id': 'f7120e'});
      final session = customer();
      await session.restore();
      expect(session.signedIn, isTrue);

      // What the 0.0.0577 launch found: the device id still there, the
      // token gone, while this process went on using it.
      await disk.delete(key: tenantKey);
      expect(await disk.read(key: tenantKey), isNull);

      await session.refresh(); // the shell's 60-second /me
      await settle();

      expect(
        await disk.read(key: tenantKey),
        'tok-801',
        reason:
            'The session is alive on this token and the person ticked "keep '
            'me signed in", yet the keychain no longer holds it, so the next '
            'restart (an update) opens on the sign-in screen.',
      );
      expect(await disk.read(key: 'phone_device_id'), 'f7120e');
    },
  );

  test(
    'a sign-in without "keep me signed in" is never written to disk',
    () async {
      await launchWith({});
      final session = customer();
      expect(await session.adopt('tok-1', persist: false), isTrue);
      await settle();

      await session.refresh();
      await settle();

      expect(await disk.read(key: tenantKey), isNull);
    },
  );

  test('signing out is not undone by the keep check', () async {
    await launchWith({tenantKey: 'tok-801'});
    final session = customer();
    await session.restore();

    session.signOut();
    await session.refresh();
    await settle();

    expect(await disk.read(key: tenantKey), isNull);
    expect(TokenStore.tenantToken, isNull);
  });

  test("the Development tab's staff token being refused leaves the customer's "
      'kept token alone', () async {
    await launchWith({tenantKey: 'tok-801'});

    var staffMeAnswered = false;
    final staff = StaffSession(
      ApiClient(
        client: MockClient((request) async {
          if (request.url.path.endsWith('/staff/me') && !staffMeAnswered) {
            staffMeAnswered = true;
            return json({'data': _staffMe}, 200);
          }
          return json({'message': 'Unauthenticated.'}, 401);
        }),
      ),
    );

    // As DevelopmentScreen does: a staff session that is never persisted.
    expect(await staff.adopt('staff-tok', persist: false), isTrue);

    // Its token lapses (12 hours) while the tab polls Releases.
    await staff.api.get('/staff/releases');
    await settle();
    expect(staff.signedIn, isFalse);

    expect(
      TokenStore.tenantToken,
      'tok-801',
      reason:
          'StaffSession.signOut called TokenStore.clear(), which also drops '
          "the customer's token while the customer session keeps running.",
    );
    expect(await disk.read(key: tenantKey), 'tok-801');
  });
}

const _staffMe = {
  'id': 's-1',
  'name': 'Jose',
  'email': 'jose@ezbizi.com',
  'tier': 'lead_developer',
};

const _me = {
  'id': 'u-1',
  'name': 'Marta',
  'email': 'marta@a-1chimney.com',
  'has_avatar': false,
  'role': {'id': 'role-1', 'name': 'Administrator'},
  'permissions': <String>[],
  'modules': <Map<String, Object?>>[],
  'preferences': <String, dynamic>{},
  'organization': {
    'id': 'org-1',
    'name': 'A1-Group',
    'franchise_label': 'Franchise',
    'is_read_only': false,
  },
  'scope': {
    'type': 'organization',
    'id': 'org-1',
    'label': 'A1-Group',
    'path': ['A1-Group'],
  },
  'companies': <Map<String, Object?>>[],
  'franchises': <Map<String, Object?>>[],
};
