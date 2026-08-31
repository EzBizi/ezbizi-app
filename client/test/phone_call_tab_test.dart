import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/phone/call_tab.dart';
import 'package:ezbizi_client/screens/phone/voice_device.dart';
import 'package:ezbizi_client/widgets/coming_soon.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Call desk — layout 1: three panes, stack below 600, 10DLC send disabled.
void main() {
  testWidgets('the desk is not Coming soon', (tester) async {
    await _pump(tester, numbers: const []);

    expect(find.byType(ComingSoonScreen), findsNothing);
    expect(find.text('Coming soon'), findsNothing);
    expect(find.text('Connect Twilio in Settings'), findsOneWidget);
  });

  testWidgets('three columns at 900: teammates, pad, person', (tester) async {
    await _pump(tester, size: const Size(900, 800));

    expect(find.text('Coming soon'), findsNothing);
    expect(find.byType(Dialog), findsNothing);

    expect(find.byKey(const Key('phone-call-teammates')), findsOneWidget);
    expect(find.byKey(const Key('phone-call-pad')), findsOneWidget);
    expect(find.byKey(const Key('phone-call-person')), findsOneWidget);

    expect(find.text('Maya Chen'), findsOneWidget);
    expect(find.text('Calling as'), findsOneWidget);
    expect(find.textContaining('CT main'), findsWidgets);
    expect(find.text('Call'), findsOneWidget);
    expect(find.text('Internal'), findsOneWidget);
    expect(find.text('Live transcript is off on this number.'), findsOneWidget);
    expect(find.text('Messages'), findsOneWidget);

    final team = tester.getRect(find.byKey(const Key('phone-call-teammates')));
    final pad = tester.getRect(find.byKey(const Key('phone-call-pad')));
    final person = tester.getRect(find.byKey(const Key('phone-call-person')));
    expect(team.left, lessThan(pad.left));
    expect(pad.left, lessThan(person.left));
    expect((team.top - pad.top).abs(), lessThan(8));

    expect(find.byTooltip('Back to the team'), findsNothing);
  });

  testWidgets('at 375 one pane at a time, with Back', (tester) async {
    await _pump(tester, size: const Size(375, 800));

    expect(find.byKey(const Key('phone-call-pad')), findsOneWidget);
    expect(find.byKey(const Key('phone-call-teammates')), findsNothing);
    expect(find.byKey(const Key('phone-call-person')), findsNothing);
    expect(find.text('Calling as'), findsOneWidget);
    expect(find.byTooltip('Back to the team'), findsOneWidget);

    await _tap(tester, find.byTooltip('Back to the team'));

    expect(find.byKey(const Key('phone-call-teammates')), findsOneWidget);
    expect(find.byKey(const Key('phone-call-pad')), findsNothing);
    expect(find.text('Maya Chen'), findsOneWidget);
    expect(find.text('Calling as'), findsNothing);

    await _tap(tester, find.text('Maya Chen'));

    expect(find.byKey(const Key('phone-call-pad')), findsOneWidget);
    expect(find.text('Calling as'), findsOneWidget);

    await _tap(tester, find.byTooltip('To the customer'));

    expect(find.byKey(const Key('phone-call-person')), findsOneWidget);
    expect(find.byKey(const Key('phone-call-pad')), findsNothing);
    expect(find.text('Messages'), findsOneWidget);
    expect(find.byTooltip('Back to the pad'), findsOneWidget);

    await _tap(tester, find.byTooltip('Back to the pad'));

    expect(find.byKey(const Key('phone-call-pad')), findsOneWidget);
    expect(find.text('Calling as'), findsOneWidget);
  });

  testWidgets(
    'SMS send is disabled with the 10DLC sentence when the line cannot send',
    (tester) async {
      await _pump(tester, size: const Size(900, 800));

      expect(
        find.text('This line cannot send texts until messaging is approved.'),
        findsOneWidget,
      );

      final send = tester.widget<FilledButton>(
        find.widgetWithText(FilledButton, 'Send'),
      );
      expect(send.onPressed, isNull);
      expect(find.text('Messages'), findsOneWidget);
      expect(find.byType(Dialog), findsNothing);
    },
  );

  testWidgets('incoming is an in-page banner, not a dialog', (tester) async {
    final device = _FakeDevice();
    addTearDown(device.dispose);

    await _pump(tester, device: device, size: const Size(900, 800));

    device.ring('+12035550199');
    await tester.pump();

    expect(find.textContaining('Incoming from +12035550199'), findsOneWidget);
    expect(find.widgetWithText(FilledButton, 'Accept'), findsOneWidget);
    expect(find.widgetWithText(OutlinedButton, 'Decline'), findsOneWidget);
    expect(find.byType(Dialog), findsNothing);

    await _tap(tester, find.widgetWithText(OutlinedButton, 'Decline'));

    expect(device.declined, isTrue);
    expect(find.textContaining('Incoming from'), findsNothing);
  });

  testWidgets('empty pad does not request messages without a peer', (
    tester,
  ) async {
    final seen = <http.Request>[];
    await _pump(tester, size: const Size(900, 800), seen: seen);

    expect(
      seen.where(
        (r) => r.method == 'GET' && r.url.path.endsWith('/phone/messages'),
      ),
      isEmpty,
    );
    expect(find.text('Thursday morning still good?'), findsNothing);
    expect(find.text('Type a number to see who it is.'), findsOneWidget);
  });

  testWidgets('a plus number loads the thread with that peer', (tester) async {
    final seen = <http.Request>[];
    await _pump(tester, size: const Size(900, 800), seen: seen);

    await tester.enterText(
      find.widgetWithText(TextField, 'Number'),
      '+12035550199',
    );
    await tester.pumpAndSettle();

    final gets = seen.where(
      (r) => r.method == 'GET' && r.url.path.endsWith('/phone/messages'),
    );
    expect(gets, isNotEmpty);
    expect(gets.last.url.queryParameters['peer'], '+12035550199');
    expect(find.text('Thursday morning still good?'), findsOneWidget);
  });

  testWidgets('pad digits load the thread as E.164', (tester) async {
    final seen = <http.Request>[];
    await _pump(tester, size: const Size(900, 800), seen: seen);

    await tester.enterText(
      find.widgetWithText(TextField, 'Number'),
      '12035550199',
    );
    await tester.pumpAndSettle();

    final gets = seen.where(
      (r) => r.method == 'GET' && r.url.path.endsWith('/phone/messages'),
    );
    expect(gets, isNotEmpty);
    expect(gets.last.url.queryParameters['peer'], '+12035550199');
  });

  testWidgets('Accept puts presence on_call', (tester) async {
    final device = _FakeDevice();
    final seen = <http.Request>[];
    addTearDown(device.dispose);

    await _pump(tester, device: device, size: const Size(900, 800), seen: seen);

    seen.clear();
    device.ring('+12035550199');
    await tester.pump();

    await _tap(tester, find.widgetWithText(FilledButton, 'Accept'));

    expect(device.accepted, isTrue);
    expect(
      seen.any((r) {
        if (r.method != 'PUT' || !r.url.path.endsWith('/phone/presence')) {
          return false;
        }
        final body = jsonDecode(r.body) as Map<String, dynamic>;
        return body['state'] == 'on_call';
      }),
      isTrue,
    );
  });

  testWidgets('Internal connects to the teammate client identity', (
    tester,
  ) async {
    final device = _FakeDevice();
    addTearDown(device.dispose);

    await _pump(tester, device: device, size: const Size(900, 800));

    await _tap(tester, find.text('Maya Chen'));
    await _tap(tester, find.widgetWithText(OutlinedButton, 'Internal'));

    expect(device.connectedTo, 'client:u2');
    expect(device.callerId, '+12035550142');
  });

  testWidgets('allowed SMS enables Send when there is a peer and a body', (
    tester,
  ) async {
    await _pump(
      tester,
      size: const Size(900, 800),
      numbers: [
        {..._number, 'sms_outbound_allowed': true},
      ],
    );

    expect(
      find.text('This line cannot send texts until messaging is approved.'),
      findsNothing,
    );

    await tester.enterText(
      find.widgetWithText(TextField, 'Number'),
      '+12035550199',
    );
    await tester.enterText(find.widgetWithText(TextField, 'Message'), 'Hello');
    await tester.pumpAndSettle();

    final send = tester.widget<FilledButton>(
      find.widgetWithText(FilledButton, 'Send'),
    );
    expect(send.onPressed, isNotNull);
  });

  /// The keypad was a GridView that stretched to its column: each key was
  /// roughly 400 x 130 on a monitor, and twelve of them filled the desk. A dial
  /// pad is a small dense thing, and it is the least-used control here.
  testWidgets('the keypad is keypad-sized, not the whole desk', (tester) async {
    await _pump(tester, size: const Size(1280, 900));

    final key = tester.getSize(find.byKey(const ValueKey('key:5')));

    expect(key.width, 56);
    expect(key.height, 46);

    // 44px is the touch guidance; a key that shrank below it would be a
    // regression even though it looks tidier.
    expect(key.height, greaterThanOrEqualTo(44));
  });

  testWidgets('the pad is the same size on a phone as on a monitor', (
    tester,
  ) async {
    await _pump(tester, size: const Size(375, 800));

    expect(tester.getSize(find.byKey(const ValueKey('key:5'))).width, 56);
    expect(tester.takeException(), isNull);
  });

  testWidgets('the letters are on the keys', (tester) async {
    await _pump(tester, size: const Size(1280, 900));

    expect(find.text('ABC'), findsOneWidget);
    expect(find.text('PQRS'), findsOneWidget);
  });

  /// The desktop app cannot place calls, and must say THAT rather than
  /// blaming the network.
  ///
  /// The Twilio Voice SDK is a browser script, so the Windows and macOS
  /// builds have no way to make a call at all. The desk detected this
  /// correctly — `registered` is false — but said "reload the page; if it
  /// keeps happening the network is blocking the connection", with a Try
  /// again button. That is a transient-sounding diagnosis and a retry
  /// control for a permanent platform fact, so somebody goes and looks at
  /// their firewall, finds nothing, and comes back tomorrow.
  testWidgets(
    'a build with no Voice SDK says so instead of blaming the network',
    (tester) async {
      final device = _FakeDevice()
        ..supported = false
        ..registered = false;

      await _pump(tester, device: device);

      expect(
        find.textContaining('Calls are made in the browser'),
        findsOneWidget,
      );
      expect(
        find.textContaining('the network is blocking'),
        findsNothing,
        reason: 'a permanent platform limit was reported as a network fault',
      );
      expect(
        find.text('Try again'),
        findsNothing,
        reason: 'offered a retry for something that can never succeed',
      );
    },
  );

  /// And a genuine registration failure keeps the retry, because retrying is
  /// exactly what might fix it.
  testWidgets('a browser build that failed to register still offers a retry', (
    tester,
  ) async {
    final device = _FakeDevice()
      ..supported = true
      ..registered = false;

    await _pump(tester, device: device);

    expect(find.textContaining('the network is blocking'), findsOneWidget);
    expect(find.text('Try again'), findsOneWidget);
  });
}

class _FakeDevice implements VoiceDevice {
  var declined = false;
  var accepted = false;
  String? connectedTo;
  String? callerId;

  @override
  void Function(VoiceIncoming incoming)? onIncoming;

  /// Registration succeeds unless a test says otherwise — the desk being
  /// usable is the precondition for almost every case in this file.
  @override
  var registered = true;

  /// A browser build unless a test says otherwise. Separate from [registered]
  /// because they mean different things: this build CANNOT call, versus this
  /// build did not manage to this time.
  @override
  var supported = true;

  @override
  Future<void> setup(String token) async {}

  @override
  Future<void> connect({required String to, String? callerId}) async {
    connectedTo = to;
    this.callerId = callerId;
  }

  @override
  void hangUp() {}

  @override
  void acceptIncoming() => accepted = true;

  @override
  void declineIncoming() => declined = true;

  @override
  void dispose() {
    onIncoming = null;
  }

  void ring(String from) => onIncoming?.call(VoiceIncoming(from: from));
}

Future<void> _tap(WidgetTester tester, Finder finder) async {
  final scrollable = find.ancestor(
    of: finder,
    matching: find.byType(Scrollable),
  );
  if (scrollable.evaluate().isNotEmpty) {
    await tester.ensureVisible(finder.first);
    await tester.pumpAndSettle();
  }
  await tester.tap(finder.first);
  await tester.pumpAndSettle();
}

Future<void> _pump(
  WidgetTester tester, {
  Size size = const Size(1280, 900),
  List<Map<String, Object?>>? numbers,
  VoiceDevice? device,
  List<http.Request>? seen,
}) async {
  tester.view.physicalSize = size;
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final inner = _handler(numbers: numbers ?? const [_number]);
  final api = ApiClient(
    client: MockClient((request) async {
      seen?.add(request);
      return inner(request);
    }),
  );
  final session = Session(api);
  await tester.runAsync(() => session.adopt('tok'));
  expect(session.signedIn, isTrue);

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(
        body: CallTab(api: api, session: session, device: device),
      ),
    ),
  );
  await tester.pumpAndSettle();
}

MockClientHandler _handler({required List<Map<String, Object?>> numbers}) {
  return (request) async {
    final path = request.url.path;

    if (path.endsWith('/me')) {
      return _json({'data': _me});
    }

    if (path.endsWith('/phone/numbers') && request.method == 'GET') {
      return _json({'data': numbers});
    }

    if (path.endsWith('/phone/presence') && request.method == 'GET') {
      return _json({
        'data': [
          {
            'user_id': 'u-2',
            'name': 'Maya Chen',
            'state': 'on_call',
            'updated_at': '2026-08-24T12:00:00+00:00',
          },
          {
            'user_id': 'u-1',
            'name': 'Jose Silva',
            'state': 'registered',
            'updated_at': '2026-08-24T12:01:00+00:00',
          },
        ],
      });
    }

    if (path.endsWith('/phone/presence') && request.method == 'PUT') {
      return _json({
        'data': {
          'id': 'pr-1',
          'connection_id': 'pc-1',
          'user_id': 'u-1',
          'state': 'registered',
        },
      });
    }

    if (path.endsWith('/phone/tokens') && request.method == 'POST') {
      return _json({
        'token': 'fake-access-token',
        'identity': 'u1',
        'twiml_app_sid': 'APcccccccccccccccccccccccccccccccc',
      });
    }

    if (path.endsWith('/phone/messages') && request.method == 'GET') {
      return _json({
        'data': [
          {
            'id': 'm-1',
            'number_id': 'n-1',
            'connection_id': 'pc-1',
            'direction': 'inbound',
            'from_e164': '+12035550199',
            'to_e164': '+12035550142',
            'body': 'Thursday morning still good?',
            'customer_id': 'c-1',
            'created_at': '2026-08-23T16:02:00+00:00',
          },
        ],
      });
    }

    return _json({'message': 'Unexpected ${request.method} $path'}, 500);
  };
}

http.Response _json(Object body, [int status = 200]) => http.Response(
  jsonEncode(body),
  status,
  headers: {'content-type': 'application/json'},
);

const _number = {
  'id': 'n-1',
  'connection_id': 'pc-1',
  'e164': '+12035550142',
  'label': 'CT main',
  'transcribe': false,
  'sms_outbound_allowed': false,
};

const _me = {
  'id': 'u-1',
  'name': 'Jose Silva',
  'email': 'jose@example.com',
  'has_avatar': false,
  'role': {'id': 'role-1', 'name': 'Administrator'},
  'permissions': ['phone.view', 'phone.edit'],
  'modules': [
    {'key': 'phone', 'label': 'Phone', 'available': true, 'reachable': true},
  ],
  'preferences': <String, dynamic>{},
  'organization': {
    'id': 'org-1',
    'name': 'Everdoors',
    'franchise_label': 'Franchise',
    'is_read_only': false,
  },
  'scope': {
    'type': 'organization',
    'id': 'org-1',
    'label': 'Everdoors',
    'path': ['Everdoors'],
  },
  'companies': [
    {'id': 'co-1', 'name': 'Connecticut', 'parent_company_id': null},
  ],
  'franchises': <Map<String, Object?>>[],
};
