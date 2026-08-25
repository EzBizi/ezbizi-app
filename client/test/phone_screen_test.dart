import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/main.dart';
import 'package:ezbizi_client/screens/phone_screen.dart';
import 'package:ezbizi_client/widgets/coming_soon.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Phone — Call, Records and Settings, not a coming-soon tab.
void main() {
  testWidgets('a granted Phone tab is Call Records Settings, not Coming soon', (
    tester,
  ) async {
    await _pump(tester);

    expect(find.text('Coming soon'), findsNothing);
    expect(_chips(tester), ['Call', 'Records', 'Settings']);
    expect(find.widgetWithText(ChoiceChip, 'Call'), findsOneWidget);
    expect(find.widgetWithText(ChoiceChip, 'Records'), findsOneWidget);
    expect(find.widgetWithText(ChoiceChip, 'Settings'), findsOneWidget);

    expect(find.text('Connect Twilio in Settings'), findsOneWidget);
  });

  testWidgets('a granted Phone sidebar tab opens the real screen', (
    tester,
  ) async {
    await _pumpShell(tester);

    expect(find.byType(ComingSoonScreen), findsNothing);
    expect(find.text('Coming soon'), findsNothing);
    expect(find.byType(PhoneScreen), findsOneWidget);
    expect(_chips(tester), ['Call', 'Records', 'Settings']);
  });

  testWidgets('Records is an empty list, not Coming soon', (tester) async {
    await _pump(tester);

    await _tap(tester, _tab('Records'));

    expect(find.text('Coming soon'), findsNothing);
    expect(find.text('There are no calls or messages yet.'), findsOneWidget);
  });

  testWidgets('Settings shows the two connect sentences', (tester) async {
    await _pump(tester);

    await _tap(tester, _tab('Settings'));

    expect(find.text('Coming soon'), findsNothing);
    expect(find.text('Connect your Twilio'), findsWidgets);
    expect(find.text('EzBizi will create a phone account'), findsWidgets);
    expect(find.byType(Dialog), findsNothing);
  });

  testWidgets('Connect your Twilio is in-page fields, not a dialog', (
    tester,
  ) async {
    await _pump(tester);

    await _tap(tester, _tab('Settings'));
    await _tap(tester, find.text('Connect your Twilio').first);

    expect(find.widgetWithText(TextField, 'Account SID'), findsOneWidget);
    expect(find.widgetWithText(TextField, 'API key SID'), findsOneWidget);
    expect(find.widgetWithText(TextField, 'API secret'), findsOneWidget);
    expect(find.byType(Dialog), findsNothing);
  });

  testWidgets('a connected account masks the SID and never shows the secret', (
    tester,
  ) async {
    await _pump(
      tester,
      connections: [
        {
          'id': 'pc-1',
          'organization_id': 'org-1',
          'scope_type': 'organization',
          'scope_id': 'org-1',
          'kind': 'byo',
          'account_sid': 'ACxxxxxxxxxxxxxxxxxxxxxxxxxxxxa7c2',
          'api_key_sid': 'SKbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
          'twiml_app_sid': 'APcccccccccccccccccccccccccccccccc',
          'status': 'connected',
          'status_reason': null,
        },
      ],
    );

    await _tap(tester, _tab('Settings'));

    expect(find.textContaining('Account SID ends …a7c2'), findsOneWidget);
    expect(find.textContaining('The key is never shown back'), findsOneWidget);
    expect(find.text('Connected'), findsOneWidget);
    expect(find.text('Your Twilio'), findsOneWidget);
    expect(find.text('SKbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb'), findsNothing);
    expect(find.textContaining('secret'), findsNothing);
  });

  testWidgets(
    'creating a phone account shows the server sentence when EzBizi has none',
    (tester) async {
      await _pump(tester, createStatus: 501);

      await _tap(tester, _tab('Settings'));
      await _tap(tester, find.text('EzBizi will create a phone account').first);

      expect(
        find.text(
          'EzBizi has not been given a Twilio account to create phone service with.',
        ),
        findsOneWidget,
      );
      expect(find.byType(Dialog), findsNothing);
    },
  );

  testWidgets('Connect your Twilio posts the keys for that place', (
    tester,
  ) async {
    Map<String, dynamic>? posted;

    await _pump(tester, onCreate: (body) => posted = body);

    await _tap(tester, _tab('Settings'));
    await _tap(tester, find.text('Connect your Twilio').first);

    await tester.enterText(
      find.widgetWithText(TextField, 'Account SID'),
      'ACaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
    );
    await tester.enterText(
      find.widgetWithText(TextField, 'API key SID'),
      'SKbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
    );
    await tester.enterText(
      find.widgetWithText(TextField, 'API secret'),
      'never-echo-this-secret',
    );
    await tester.pumpAndSettle();

    await _tap(tester, find.widgetWithText(FilledButton, 'Save'));

    expect(posted, {
      'scope_type': 'organization',
      'scope_id': 'org-1',
      'kind': 'byo',
      'account_sid': 'ACaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
      'api_key_sid': 'SKbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
      'api_secret': 'never-echo-this-secret',
    });
  });

  testWidgets('Settings at 375 is the list, then the form with Back', (
    tester,
  ) async {
    await _pump(tester, size: const Size(375, 800));

    await _tap(tester, _tab('Settings'));

    expect(find.text('Connection'), findsOneWidget);
    expect(find.text('Numbers'), findsOneWidget);
    expect(find.text('Call flows'), findsOneWidget);
    expect(find.text('Hours'), findsOneWidget);
    expect(find.text('Ring groups'), findsOneWidget);
    expect(find.text('Trust'), findsOneWidget);
    expect(find.text('Messaging'), findsOneWidget);
    expect(find.text('Connect your Twilio'), findsNothing);

    await _tap(tester, find.text('Connection'));

    expect(find.text('Connect your Twilio'), findsWidgets);
    expect(find.byTooltip('Back to the list'), findsOneWidget);

    await _tap(tester, find.byTooltip('Back to the list'));

    expect(find.text('Connect your Twilio'), findsNothing);
    expect(find.text('Numbers'), findsOneWidget);
  });

  testWidgets('Replace the key posts new keys and never deletes first', (
    tester,
  ) async {
    Map<String, dynamic>? posted;
    var deleted = false;

    await _pump(
      tester,
      connections: [
        {
          'id': 'pc-1',
          'organization_id': 'org-1',
          'scope_type': 'organization',
          'scope_id': 'org-1',
          'kind': 'byo',
          'account_sid': 'ACxxxxxxxxxxxxxxxxxxxxxxxxxxxxa7c2',
          'api_key_sid': 'SKbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
          'twiml_app_sid': 'APcccccccccccccccccccccccccccccccc',
          'status': 'connected',
          'status_reason': null,
        },
      ],
      onCreate: (body) => posted = body,
      onDelete: () => deleted = true,
    );

    await _tap(tester, _tab('Settings'));
    await _tap(tester, find.text('Replace the key'));

    await tester.enterText(
      find.widgetWithText(TextField, 'Account SID'),
      'ACdddddddddddddddddddddddddddddddd',
    );
    await tester.enterText(
      find.widgetWithText(TextField, 'API key SID'),
      'SKeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee',
    );
    await tester.enterText(
      find.widgetWithText(TextField, 'API secret'),
      'replacement-secret',
    );
    await tester.pumpAndSettle();

    await _tap(tester, find.widgetWithText(FilledButton, 'Save'));

    expect(deleted, isFalse);
    expect(posted, {
      'scope_type': 'organization',
      'scope_id': 'org-1',
      'kind': 'byo',
      'account_sid': 'ACdddddddddddddddddddddddddddddddd',
      'api_key_sid': 'SKeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee',
      'api_secret': 'replacement-secret',
    });
  });

  testWidgets('Replace the key shows the server sentence on 409', (
    tester,
  ) async {
    await _pump(
      tester,
      connections: [
        {
          'id': 'pc-1',
          'organization_id': 'org-1',
          'scope_type': 'organization',
          'scope_id': 'org-1',
          'kind': 'byo',
          'account_sid': 'ACxxxxxxxxxxxxxxxxxxxxxxxxxxxxa7c2',
          'api_key_sid': 'SKbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
          'twiml_app_sid': 'APcccccccccccccccccccccccccccccccc',
          'status': 'connected',
          'status_reason': null,
        },
      ],
      createStatus: 409,
      createMessage:
          'A phone connection is already set up for that place. Disconnect it before connecting another.',
    );

    await _tap(tester, _tab('Settings'));
    await _tap(tester, find.text('Replace the key'));

    await tester.enterText(
      find.widgetWithText(TextField, 'Account SID'),
      'ACdddddddddddddddddddddddddddddddd',
    );
    await tester.enterText(
      find.widgetWithText(TextField, 'API key SID'),
      'SKeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee',
    );
    await tester.enterText(
      find.widgetWithText(TextField, 'API secret'),
      'replacement-secret',
    );
    await tester.pumpAndSettle();

    await _tap(tester, find.widgetWithText(FilledButton, 'Save'));

    expect(
      find.text(
        'A phone connection is already set up for that place. Disconnect it before connecting another.',
      ),
      findsOneWidget,
    );
  });

  testWidgets(
    'phone.view without phone.edit still opens Settings and Connect is refused',
    (tester) async {
      await _pump(tester, permissions: const ['phone.view']);

      await _tap(tester, _tab('Settings'));

      expect(find.text('Connect your Twilio'), findsWidgets);
      expect(find.text('EzBizi will create a phone account'), findsWidgets);

      final connect = tester.widget<FilledButton>(
        find.widgetWithText(FilledButton, 'Connect your Twilio').first,
      );
      final create = tester.widget<OutlinedButton>(
        find
            .widgetWithText(OutlinedButton, 'EzBizi will create a phone account')
            .first,
      );
      expect(connect.onPressed, isNull);
      expect(create.onPressed, isNull);
    },
  );

  testWidgets('EzBizi will create a phone account posts subaccount kind', (
    tester,
  ) async {
    Map<String, dynamic>? posted;

    await _pump(tester, onCreate: (body) => posted = body);

    await _tap(tester, _tab('Settings'));
    await _tap(tester, find.text('EzBizi will create a phone account').first);

    expect(posted, {
      'scope_type': 'organization',
      'scope_id': 'org-1',
      'kind': 'subaccount',
    });
  });
}

Finder _tab(String label) => find.widgetWithText(ChoiceChip, label);

List<String> _chips(WidgetTester tester) => tester
    .widgetList<ChoiceChip>(find.byType(ChoiceChip))
    .map((chip) => (chip.label as Text).data!)
    .toList();

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
  List<String> permissions = const ['phone.view', 'phone.edit'],
  List<Map<String, Object?>> connections = const [],
  int createStatus = 201,
  String? createMessage,
  void Function(Map<String, dynamic> body)? onCreate,
  void Function()? onDelete,
}) async {
  tester.view.physicalSize = size;
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(
    client: MockClient(
      _handler(
        permissions: permissions,
        connections: connections,
        createStatus: createStatus,
        createMessage: createMessage,
        onCreate: onCreate,
        onDelete: onDelete,
      ),
    ),
  );
  final session = Session(api);

  await tester.runAsync(() => session.adopt('tok'));
  expect(session.signedIn, isTrue);

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(
        body: PhoneScreen(api: api, session: session),
      ),
    ),
  );
  await tester.pumpAndSettle();
}

Future<void> _pumpShell(WidgetTester tester) async {
  tester.view.physicalSize = const Size(1280, 900);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(client: MockClient(_handler()));
  final session = Session(api);
  await tester.runAsync(() => session.adopt('tok'));
  expect(session.signedIn, isTrue);

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: ListenableBuilder(
        listenable: session,
        builder: (_, _) => HomeShell(api: api, session: session),
      ),
    ),
  );
  await tester.pumpAndSettle();

  final inSidebar = find.descendant(
    of: find.byType(NavigationSidebar),
    matching: find.text('Phone'),
  );
  expect(inSidebar, findsOneWidget);
  await tester.tap(inSidebar);
  await tester.pumpAndSettle();
}

MockClientHandler _handler({
  List<String> permissions = const ['phone.view', 'phone.edit'],
  List<Map<String, Object?>> connections = const [],
  int createStatus = 201,
  String? createMessage,
  void Function(Map<String, dynamic> body)? onCreate,
  void Function()? onDelete,
}) {
  return (request) async {
    final path = request.url.path;

    if (path.endsWith('/me')) {
      return _json({
        'data': {..._me, 'permissions': permissions},
      });
    }

    if (path.endsWith('/phone/connections') && request.method == 'GET') {
      return _json({'data': connections});
    }

    if (path.endsWith('/phone/numbers') && request.method == 'GET') {
      return _json({'data': <Map<String, Object?>>[]});
    }

    if (path.endsWith('/phone/calls') && request.method == 'GET') {
      return _json({'data': <Map<String, Object?>>[]});
    }

    if (path.endsWith('/phone/messages') && request.method == 'GET') {
      return _json({'data': <Map<String, Object?>>[]});
    }

    if (path.endsWith('/phone/presence')) {
      return _json({'data': <Map<String, Object?>>[]});
    }

    if (path.endsWith('/phone/tokens') && request.method == 'POST') {
      return _json({
        'token': 'fake-access-token',
        'identity': 'u1',
        'twiml_app_sid': 'APcccccccccccccccccccccccccccccccc',
      });
    }

    if (path.contains('/phone/connections/') && request.method == 'DELETE') {
      onDelete?.call();
      return _json({'message': 'deleted'}, 200);
    }

    if (path.endsWith('/phone/connections') && request.method == 'POST') {
      onCreate?.call(jsonDecode(request.body) as Map<String, dynamic>);
      if (createStatus == 501) {
        return _json({
          'message':
              createMessage ??
              'EzBizi has not been given a Twilio account to create phone service with.',
        }, 501);
      }
      if (createStatus == 409) {
        return _json({
          'message':
              createMessage ??
              'A phone connection is already set up for that place. Disconnect it before connecting another.',
        }, 409);
      }
      return _json({
        'data': {
          'id': 'pc-new',
          'organization_id': 'org-1',
          'scope_type': 'organization',
          'scope_id': 'org-1',
          'kind': 'byo',
          'account_sid': 'ACaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
          'api_key_sid': 'SKbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
          'twiml_app_sid': 'APdddddddddddddddddddddddddddddddd',
          'status': 'connected',
          'status_reason': null,
        },
      }, createStatus);
    }

    return _json({'message': 'Unexpected ${request.method} $path'}, 500);
  };
}

http.Response _json(Object body, [int status = 200]) => http.Response(
  jsonEncode(body),
  status,
  headers: {'content-type': 'application/json'},
);

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
