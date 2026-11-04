import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/deployment.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/invitation_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// The other end of an invitation.
///
/// ── The failure this is written against ──────────────────────────
///
/// The password-reset flow was built end to end on the server and had no
/// ending in the client: the app decided what to draw from the HOST alone, so
/// `/reset?token=…` rendered the sign-in screen and threw the token away.
/// Nothing failed loudly enough to say so. An invitation is worse, because the
/// person following the link has no account yet and therefore no other way in —
/// a dropped token is not an inconvenience, it is the whole feature.
///
/// So the two halves are tested separately: that a URL yields a token at all,
/// and that the screen behind it reads an invitation, posts the right body, and
/// treats the answer as a sign-in.
void main() {
  group('reading the link', () {
    test('an invitation URL yields its token', () {
      expect(
        Deployment.tokenAt(
          Uri.parse('https://app.ezbizi.com/invite?token=abc123'),
          '/invite',
        ),
        'abc123',
      );
    });

    test('any other page does not, however the query reads', () {
      // The token is not the signal on its own — the path is. Otherwise a
      // `?token=` on any page in the app would swap the screen out from under
      // whoever is standing on it.
      expect(
        Deployment.tokenAt(
          Uri.parse('https://app.ezbizi.com/?token=abc123'),
          '/invite',
        ),
        isNull,
      );
    });

    test('an invitation link with no token is not one', () {
      expect(
        Deployment.tokenAt(Uri.parse('https://app.ezbizi.com/invite'), '/invite'),
        isNull,
      );
      expect(
        Deployment.tokenAt(
          Uri.parse('https://app.ezbizi.com/invite?token='),
          '/invite',
        ),
        isNull,
      );
    });

    test('the reset link still reads the way it always did', () {
      expect(
        Deployment.tokenAt(
          Uri.parse('https://app.ezbizi.com/reset?token=xyz'),
          '/reset',
        ),
        'xyz',
      );
    });
  });

  testWidgets('it says what is being joined, and pre-fills what was filled in', (
    tester,
  ) async {
    await _pump(tester, _handler());

    expect(find.text('Join Everdoors'), findsOneWidget);
    expect(find.text('Jose Silva invited you as Technician'), findsOneWidget);
    expect(find.text('nadia@example.com'), findsOneWidget);

    // Pre-filled and editable — the employer may have got it wrong, and
    // correcting it should be ordinary rather than a support request.
    expect(_textIn(tester, 'First name'), 'Nadia');
    expect(_textIn(tester, 'Last name'), '');

    // Never the assignment. Anybody holding a forwarded link can read this
    // page, and the shape of somebody's business is not theirs to read.
    expect(find.textContaining('Connecticut'), findsNothing);

    // §6: the organization's own minimum, resolved by the server because only
    // the server knows the setting. The reset screen cannot ask — it has no
    // organization until the token is examined — and this one can, because the
    // token has already named one.
    expect(find.text('At least 16 characters.'), findsOneWidget);
    expect(
      find.text('At least 12 characters.'),
      findsNothing,
      reason: 'The platform floor was stated over the organization’s own rule.',
    );
  });

  testWidgets('date of birth follows the organization date format', (
    tester,
  ) async {
    await _pump(
      tester,
      _handler(
        invitation: {
          ..._invitation,
          'display': {
            'date_format': 'D/M/YYYY',
            'time_format': '24h',
          },
        },
      ),
    );

    // One date field whose pattern is the org's order (Jose, 2026-11-03).
    expect(find.text('DD/MM/YYYY'), findsOneWidget);
  });

  testWidgets('nothing can be submitted until a password is chosen', (
    tester,
  ) async {
    await _pump(tester, _handler());

    expect(_accept(tester).onPressed, isNull);

    await _type(tester, 'Choose a password', 'a-long-enough-password');

    expect(_accept(tester).onPressed, isNotNull);
  });

  testWidgets('accepting posts the password and the core fields, and signs in', (
    tester,
  ) async {
    Map<String, dynamic>? sent;
    var done = false;

    final session = await _pump(
      tester,
      _handler(onAccept: (body) => sent = body),
      onDone: () => done = true,
    );

    await _type(tester, 'Choose a password', 'a-long-enough-password');
    await _type(tester, 'Last name', 'Okafor');
    await _type(tester, 'Phone', '860 555 0142');
    await _typeDay(tester, DateTime(1994, 2, 11));

    await tester.tap(_acceptButton());
    await tester.pumpAndSettle();

    // Only what was answered. An empty box is not an answer, and sending one
    // would overwrite something onboarding may already have collected later.
    expect(sent, {
      // The token travels in the BODY, which is the point of the change that
      // moved it out of the URL — nginx logs request lines, and a token in the
      // path was a live credential written to the access log.
      'token': 'raw-token-1',
      'password': 'a-long-enough-password',
      'first_name': 'Nadia',
      'last_name': 'Okafor',
      // Typed as '860 555 0142'; stored as E.164. The field formats what you
      // see, toE164 decides what is sent — a payload carrying the pretty form
      // would store a different number from the same digits typed elsewhere.
      'phone': '+18605550142',
      'date_of_birth': '1994-02-11',
    });

    // The response is exactly what /auth/login answers with, and goes through
    // the same door — there is no second way into the app.
    expect(session.signedIn, isTrue);
    expect(session.me!.name, 'Nadia Okafor');

    expect(
      done,
      isTrue,
      reason:
          'The spent token was left in the address bar, where it stays in '
          'history and gets pasted into support chats.',
    );
  });

  testWidgets('a dead link shows the server’s sentence instead of a form', (
    tester,
  ) async {
    await _pump(tester, _handler(getStatus: 404));

    expect(
      find.textContaining('Ask whoever invited you to send a new one'),
      findsOneWidget,
      reason:
          'The server writes this to be shown verbatim; a generic "something '
          'went wrong" leaves somebody with nothing to do about it.',
    );
    expect(find.text('Join Everdoors'), findsNothing);
    expect(find.text('Accept and sign in'), findsNothing);
  });

  /// The account exists and the session does not.
  ///
  /// The token was issued and `/me` did not answer. The invitation is spent, so
  /// reloading shows a dead link and nothing anywhere says the account was
  /// actually created — which is the worst outcome this screen can produce, and
  /// the cheapest to prevent.
  testWidgets('an account made without a session says so and offers a way on', (
    tester,
  ) async {
    var done = false;

    final session = await _pump(
      tester,
      _handler(meStatus: 500),
      onDone: () => done = true,
    );

    await _type(tester, 'Choose a password', 'a-long-enough-password');
    await tester.tap(_acceptButton());
    await tester.pumpAndSettle();

    expect(session.signedIn, isFalse);
    expect(done, isFalse, reason: 'It walked off the screen without saying so.');
    expect(find.textContaining('signing you in did not finish'), findsOneWidget);

    // And a second press cannot make the account twice — the token is spent and
    // the address is taken — so the button becomes the way onwards.
    await _tap(tester, find.widgetWithText(FilledButton, 'Go to sign in'));
    expect(done, isTrue);
  });

  /// A refusal that is not about a field. The server answers 422 with a
  /// `message` and a `code` and no `errors` map — "there is already an account
  /// with this address" is about the situation, not about a box on the form.
  testWidgets('a refusal with no field to blame is still shown', (
    tester,
  ) async {
    await _pump(tester, _handler(acceptStatus: 422, acceptFieldError: false));

    await _type(tester, 'Choose a password', 'a-long-enough-password');
    await tester.tap(_acceptButton());
    await tester.pumpAndSettle();

    expect(
      find.textContaining('is not something Serenisima can give out any more'),
      findsOneWidget,
    );
  });

  testWidgets('a refused acceptance keeps the form and the typing', (
    tester,
  ) async {
    await _pump(tester, _handler(acceptStatus: 422));

    await _type(tester, 'Choose a password', 'short');
    await tester.tap(_acceptButton());
    await tester.pumpAndSettle();

    // The field-level sentence, not the generic one above it: "the given data
    // was invalid" is not something anybody can act on.
    expect(find.text('The password must be at least 16 characters.'),
        findsOneWidget);
    expect(_textIn(tester, 'Choose a password'), 'short');
  });
}

// ── driving the screen ─────────────────────────────────────────────

Future<Session> _pump(
  WidgetTester tester,
  MockClientHandler handler, {
  VoidCallback? onDone,
}) async {
  tester.view.physicalSize = const Size(900, 1400);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(client: MockClient(handler));
  final session = Session(api);

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: InvitationScreen(
        api: api,
        session: session,
        token: 'raw-token-1',
        onDone: onDone ?? () {},
      ),
    ),
  );
  await tester.pumpAndSettle();

  return session;
}

Future<void> _type(WidgetTester tester, String label, String text) async {
  await tester.enterText(find.widgetWithText(TextField, label).first, text);
  await tester.pumpAndSettle();
}

Future<void> _typeDay(WidgetTester tester, DateTime at) async {
  // The fixture's organization writes M/D/YYYY.
  await tester.enterText(
    find.byKey(const Key('calendar-day-text')),
    '${at.month}/${at.day}/${at.year}',
  );
  await tester.pumpAndSettle();
}

Future<void> _tap(WidgetTester tester, Finder finder) async {
  await tester.ensureVisible(finder.first);
  await tester.pumpAndSettle();
  await tester.tap(finder.first);
  await tester.pumpAndSettle();
}

String? _textIn(WidgetTester tester, String label) => tester
    .widget<TextField>(find.widgetWithText(TextField, label).first)
    .controller
    ?.text;

Finder _acceptButton() =>
    find.widgetWithText(FilledButton, 'Accept and sign in');

FilledButton _accept(WidgetTester tester) =>
    tester.widget<FilledButton>(_acceptButton());

MockClientHandler _handler({
  void Function(Map<String, dynamic> body)? onAccept,
  int getStatus = 200,
  int acceptStatus = 200,
  int meStatus = 200,
  bool acceptFieldError = true,
  Map<String, dynamic>? invitation,
}) {
  return (request) async {
    final path = request.url.path;

    if (path.endsWith('/me')) {
      return meStatus == 200
          ? _json({'data': _me})
          : _json({'message': 'Something went wrong.'}, meStatus);
    }

    // Both verbs are POST now, and the token is in the BODY — it used to be in
    // the path, which meant nginx logged every live invitation credential to
    // disk for a fortnight. So these are told apart by the path's last segment,
    // and the lookup is a POST that reads.
    if (path.endsWith('/invitations/lookup') ||
        path.endsWith('/invitations/accept')) {
      final accepting = path.endsWith('/invitations/accept');

      if (accepting) {
        if (acceptStatus != 200) {
          return _json(
            acceptFieldError
                ? {
                    'message': 'The given data was invalid.',
                    'errors': {
                      'password': [
                        'The password must be at least 16 characters.',
                      ],
                    },
                  }
                // Reworded on 2026-08-11, and the fixture with it. Acceptance
                // used to be re-checked against the INVITER, so the refusal
                // blamed them — "the person who sent it is no longer able to
                // grant what it offers". An invitation belongs to the
                // organization now, so both refusals are about what the
                // organization can still offer, and a fixture still quoting the
                // old sentence would be describing a server that is gone.
                : {
                    'message':
                        'This invitation can no longer be completed — the role '
                        'it offers is not something Serenisima can give out any '
                        'more. Ask somebody there to send you a new one.',
                    'code': 'invitation_superseded',
                  },
            acceptStatus,
          );
        }

        onAccept?.call(jsonDecode(request.body) as Map<String, dynamic>);

        // Exactly what /auth/login answers with, built by the same object
        // (IssueSession) — 201 rather than 200, because an account was made.
        return _json({
          'status': 'authenticated',
          'token': 'tenant-token',
          'staff_token': null,
        }, 201);
      }

      if (getStatus != 200) {
        // One answer for all four ways a token can be dead — wrong, expired,
        // withdrawn, already used. The server cannot tell them apart on
        // purpose: "already used" would confirm to a stranger that the address
        // they guessed belongs to a real employee of a real organization.
        return _json({
          'message': 'That invitation link is not valid. It may have expired, '
              'been used already, or been withdrawn. Ask whoever invited you '
              'to send a new one.',
          'code': 'invalid_invitation',
        }, getStatus);
      }

      return _json({'data': invitation ?? _invitation});
    }

    return _json({'message': 'Unexpected ${request.method} $path'}, 500);
  };
}

http.Response _json(Object body, [int status = 200]) => http.Response(
  jsonEncode(body),
  status,
  headers: {'content-type': 'application/json'},
);

// ── the server, as this screen sees it ─────────────────────────────

/// Exactly what InvitationAcceptanceController::show answers — the name of the
/// organization and nothing else about it, the role, who asked, and the two
/// core fields an invitation itself carries.
///
/// No scope. Anybody holding a forwarded link can read this page, and the shape
/// of somebody's business is not theirs to read; see the test that depends on it.
const _invitation = {
  'organization': {'name': 'Everdoors'},
  'role': {'name': 'Technician'},
  'invited_by': {'name': 'Jose Silva'},
  'email': 'nadia@example.com',
  'first_name': 'Nadia',
  'last_name': null,
  'expires_at': '2026-08-18T09:00:00+00:00',
  // This organization has raised the floor above the platform's 12 (§6).
  'password_min_length': 16,
  'display': {'date_format': 'M/D/YYYY', 'time_format': '12h'},
};

const _me = {
  'id': 'u-7',
  'name': 'Nadia Okafor',
  'email': 'nadia@example.com',
  'has_avatar': false,
  'role': {'id': 'role-2', 'name': 'Technician'},
  'permissions': <String>[],
  'modules': <dynamic>[],
  'preferences': <String, dynamic>{},
  'organization': {
    'id': 'org-1',
    'name': 'Everdoors',
    'franchise_label': 'Franchise',
    'is_read_only': false,
  },
  'scope': {
    'type': 'franchise',
    'id': 'fr-9',
    'label': 'Connecticut',
    'path': ['Everdoors', 'Connecticut'],
  },
  'companies': <dynamic>[],
  'franchises': <dynamic>[],
};
