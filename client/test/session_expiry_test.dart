import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// What happens when a token dies while somebody is still looking at the app.
///
/// ── The report this exists for ───────────────────────────────────
///
/// The owner left a tab open overnight:
///
///   *"today when i tried clicking on a tab it said not authenticated … if that
///    is the case it should log me out to the login screen instead of letting
///    me still click through the app."*
///
/// `Session.refresh()` had always handled a 401 from `/me` — but that runs when
/// the app STARTS. Every other request returned its refusal to whichever screen
/// asked for it, so the shell stayed up, the sidebar still looked live, and each
/// tab reported its own failure in its own words. The session was over and the
/// app was the last thing to find out.
///
/// The fix is one callback on the client every request already passes through,
/// so the assertions below are about the two halves of it: any refused request
/// ends the session, and a wrong password at the sign-in screen does not.
void main() {
  /// A client that answers `/me` once so a session exists, then 401s.
  ///
  /// The 401 body is Laravel's own, because the shape of an unauthenticated
  /// response is the thing being reacted to and a hand-written one could differ
  /// from what the server really sends.
  ({Session session, ApiClient api, List<String> paths}) signedIn() {
    final paths = <String>[];
    var meAnswered = false;

    final api = ApiClient(
      client: MockClient((request) async {
        paths.add(request.url.path);

        if (request.url.path.endsWith('/me') && !meAnswered) {
          meAnswered = true;

          return http.Response(
            jsonEncode({'data': _me}),
            200,
            headers: {'content-type': 'application/json'},
          );
        }

        return http.Response(
          jsonEncode({'message': 'Unauthenticated.'}),
          401,
          headers: {'content-type': 'application/json'},
        );
      }),
    );

    return (session: Session(api), api: api, paths: paths);
  }

  test('a refused request from a live token ends the session', () async {
    final it = signedIn();

    expect(await it.session.adopt('a-token'), isTrue,
        reason: 'The fixture did not produce a session, so nothing below is '
            'testing what happens when one ends.');

    // Clicking a tab: any request at all, from any screen.
    final response = await it.api.get('/customers');

    expect(response.unauthorized, isTrue);

    expect(
      it.session.signedIn,
      isFalse,
      reason:
          'The token was refused and the app is still signed in, so the shell '
          'stays up and every tab becomes its own small failure — which is the '
          'bug this is about.',
    );

    expect(
      it.api.hasToken,
      isFalse,
      reason: 'A token the server refuses is still being sent with requests.',
    );

    expect(
      it.session.expired,
      isTrue,
      reason:
          'The sign-in screen cannot tell this person why they are looking at '
          'it, so it will greet somebody who was thrown out as though they had '
          'chosen to leave.',
    );
  });

  /// The discriminator, and the reason the check is `_token != null` rather
  /// than just the status code.
  ///
  /// Signing in with the wrong password is also a 401. If that fired the
  /// session-ended path it would announce "your session ended" to somebody who
  /// never had one — a confusing sentence attached to an ordinary typo.
  test('a wrong password at sign-in is not a session ending', () async {
    final api = ApiClient(
      client: MockClient((request) async => http.Response(
            jsonEncode({'message': 'Those credentials do not match our records.'}),
            401,
            headers: {'content-type': 'application/json'},
          )),
    );

    final session = Session(api);

    final response = await api.post('/auth/login', {
      'email': 'marta@a-1chimney.com',
      'password': 'the-wrong-one',
    });

    expect(response.unauthorized, isTrue);

    expect(
      session.expired,
      isFalse,
      reason:
          'A mistyped password was treated as a session ending, so the sign-in '
          'screen will tell somebody their session ended when they were simply '
          'never signed in.',
    );
  });

  /// Several requests are usually in flight when a token lapses — a screen that
  /// loads a list and a count and a permission set fires three. Each comes back
  /// 401, and the session must end once rather than three times.
  test('several refusals at once end the session once', () async {
    final it = signedIn();

    await it.session.adopt('a-token');

    var endings = 0;
    it.session.addListener(() {
      if (!it.session.signedIn) endings++;
    });

    await Future.wait([
      it.api.get('/customers'),
      it.api.get('/jobs'),
      it.api.get('/hr/dashboard'),
    ]);

    expect(
      endings,
      1,
      reason:
          'The session ended $endings times for one lapsed token, so the app '
          'rebuilds once per in-flight request at the moment it is least able '
          'to afford it.',
    );
  });
}

/// The shape `GET /me` really answers with — a `data` envelope around the
/// account. Taken from the fixture the rest of the suite uses rather than
/// written fresh, because a stub that disagrees with the server tests the stub.
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
