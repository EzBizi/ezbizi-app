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
    await _pump(tester, approvedCompanyIds: const ['co-1']);

    await _tap(tester, _tab('Settings'));

    expect(find.text('Coming soon'), findsNothing);
    expect(find.text('Connect your Twilio'), findsWidgets);
    expect(find.text('Activate phone service'), findsWidgets);
    expect(find.byType(Dialog), findsNothing);
  });

  /// §4: a control that is offered must be a control that works. Before EzBizi
  /// approves the company there is nothing to activate, so nothing is drawn —
  /// the server would refuse it, and a button that refuses is a dead end.
  testWidgets('a company with no approval is not offered activation', (
    tester,
  ) async {
    await _pump(tester);

    await _tap(tester, _tab('Settings'));

    expect(find.text('Activate phone service'), findsNothing);
    expect(find.text('Ask for phone service'), findsOneWidget);
  });

  testWidgets(
    'a company view does not offer Twilio for sibling companies',
    (tester) async {
      await _pump(
        tester,
        approvedCompanyIds: const ['co-a1'],
        scope: {
          'type': 'company',
          'id': 'co-a1',
          'label': 'A-1 Chimney',
          'path': ['A-1 Chimney'],
        },
        companies: [
          {'id': 'co-a1', 'name': 'A-1 Chimney', 'parent_company_id': null},
          {
            'id': 'co-ever',
            'name': 'Everdoors Co',
            'parent_company_id': null,
          },
        ],
      );

      await _tap(tester, _tab('Settings'));

      // The rule this test exists for: standing in A-1 Chimney must not offer
      // to connect Everdoors.
      expect(find.text('A-1 Chimney'), findsOneWidget);
      expect(find.text('Everdoors Co'), findsNothing);
      expect(find.text('Connect your Twilio'), findsOneWidget);
      expect(find.text('Activate phone service'), findsOneWidget);

      // Phone service is per company now, so there is no organization card at
      // all — and nothing to say about switching to the group to edit it.
      expect(find.text('Everdoors'), findsNothing);
      expect(
        find.textContaining('Switch to the group to connect or change it'),
        findsNothing,
      );
    },
  );

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
          'scope_type': 'company',
          'scope_id': 'co-1',
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
      await _pump(tester, approvedCompanyIds: const ['co-1'], createStatus: 501);

      await _tap(tester, _tab('Settings'));
      await _tap(tester, find.text('Activate phone service').first);

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
      'scope_type': 'company',
      'scope_id': 'co-1',
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
          'scope_type': 'company',
          'scope_id': 'co-1',
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
      'scope_type': 'company',
      'scope_id': 'co-1',
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
          'scope_type': 'company',
          'scope_id': 'co-1',
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
      await _pump(
      tester,
      approvedCompanyIds: const ['co-1'],
      permissions: const ['phone.view'],
    );

      await _tap(tester, _tab('Settings'));

      expect(find.text('Connect your Twilio'), findsWidgets);
      expect(find.text('Activate phone service'), findsWidgets);

      final connect = tester.widget<FilledButton>(
        find.widgetWithText(FilledButton, 'Connect your Twilio').first,
      );
      final create = tester.widget<OutlinedButton>(
        find
            .widgetWithText(OutlinedButton, 'Activate phone service')
            .first,
      );
      expect(connect.onPressed, isNull);
      expect(create.onPressed, isNull);
    },
  );

  testWidgets('Activate phone service posts subaccount kind', (
    tester,
  ) async {
    Map<String, dynamic>? posted;

    await _pump(
      tester,
      approvedCompanyIds: const ['co-1'],
      onCreate: (body) => posted = body,
    );

    await _tap(tester, _tab('Settings'));
    await _tap(tester, find.text('Activate phone service').first);

    expect(posted, {
      'scope_type': 'company',
      'scope_id': 'co-1',
      'kind': 'subaccount',
    });
  });

  /// The gap this closed: TwimlRenderer reads phone_numbers.ring_group_id and
  /// falls through to voicemail when it is null, and nothing could set it. A
  /// group has to be creatable AND attachable to a line, or the phone never
  /// rings on a fully configured account.
  testWidgets('a ring group is created and pointed at a line', (tester) async {
    Map<String, dynamic>? group;
    Map<String, dynamic>? patched;

    await _pump(
      tester,
      connections: const [
        {
          'id': 'conn-1',
          'scope_type': 'company',
          'scope_id': 'co-1',
          'kind': 'subaccount',
          'status': 'connected',
          'account_sid': 'ACaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
        },
      ],
      numbers: const [
        {
          'id': 'num-1',
          'connection_id': 'conn-1',
          'e164': '+12035550123',
          'label': 'Front desk',
          'ring_group_id': null,
        },
      ],
      members: const [
        {'id': 'u-1', 'name': 'Dana Reyes', 'role_id': 'r-1'},
      ],
      onRingGroup: (body) => group = body,
      onNumberPatch: (body) => patched = body,
    );

    await _tap(tester, _tab('Settings'));
    await _tap(tester, find.text('Ring groups'));

    expect(
      find.textContaining('a call goes straight to voicemail'),
      findsOneWidget,
    );

    await _tap(tester, find.text('Add a ring group'));

    await tester.enterText(
      find.widgetWithText(TextFormField, 'Name'),
      'Front desk',
    );
    await tester.pumpAndSettle();

    await _tap(tester, find.text('Dana Reyes'));
    await _tap(tester, find.text('Front desk').last);
    await _tap(tester, find.text('Save'));

    expect(group?['name'], 'Front desk');
    expect(group?['member_user_ids'], ['u-1']);
    expect(group?['connection_id'], 'conn-1');

    // The half that made the phone ring: the line now points at the group.
    expect(patched, {'ring_group_id': 'rg-new'});
  });

  /// §4 gate 2 from the customer's side. Phone is a paid extra billed to
  /// EzBizi, so the tab must explain itself and offer the one thing that moves
  /// — asking — rather than hiding or refusing at the door.
  testWidgets('asking for phone service sends the companies, volume and use', (
    tester,
  ) async {
    Map<String, dynamic>? asked;

    await _pump(
      tester,
      serviceCompanies: const [
        {'id': 'co-1', 'name': 'Connecticut', 'has_service': false},
      ],
      onServiceRequest: (body) => asked = body,
    );

    await _tap(tester, _tab('Settings'));

    expect(find.text('Activate phone service'), findsNothing);
    expect(find.textContaining('It is a paid extra'), findsOneWidget);

    await _tap(tester, find.text('Ask for phone service'));

    // .first — the form's checkbox. The connection card below also says
    // "Connecticut", and .last picks that instead, which ticks nothing.
    await _tap(tester, find.text('Connecticut').first);
    await _tap(tester, find.text('Fewer than 100 calls a month'));
    await _tap(tester, find.textContaining('text messaging as well as calls'));

    await tester.enterText(
      find.byType(TextField).last,
      'Booking sweeps and calling customers back when we miss them.',
    );
    await tester.pumpAndSettle();

    await _tap(tester, find.text('Send the request'));

    expect(asked?['company_ids'], ['co-1']);
    expect(asked?['expected_volume'], 'under_100');
    expect(asked?['wants_sms'], true);
  });

  testWidgets('a request already waiting says so and offers nothing to press', (
    tester,
  ) async {
    await _pump(
      tester,
      serviceRequest: const {
        'id': 'req-1',
        'status': 'pending',
        'requested_at': '2026-08-27T10:00:00Z',
        'companies': [
          {'company_id': 'co-1', 'name': 'Connecticut', 'approved': null},
        ],
      },
    );

    await _tap(tester, _tab('Settings'));

    expect(find.textContaining('We are looking at your request'), findsOneWidget);
    expect(find.text('Ask for phone service'), findsNothing);
    expect(find.text('Send the request'), findsNothing);
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
  Map<String, Object?>? scope,
  List<Map<String, Object?>>? companies,
  int createStatus = 201,
  String? createMessage,
  List<Map<String, Object?>> subaccounts = const [],
  List<Map<String, Object?>> numbers = const [],
  List<Map<String, Object?>> ringGroups = const [],
  List<Map<String, Object?>> members = const [],
  List<Map<String, Object?>> serviceCompanies = const [],
  List<String> approvedCompanyIds = const [],
  Map<String, Object?>? serviceRequest,
  void Function(Map<String, dynamic> body)? onServiceRequest,
  void Function(Map<String, dynamic> body)? onCreate,
  void Function(Map<String, dynamic> body)? onRingGroup,
  void Function(Map<String, dynamic> body)? onNumberPatch,
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
        scope: scope,
        companies: companies,
        createStatus: createStatus,
        createMessage: createMessage,
        subaccounts: subaccounts,
        numbers: numbers,
        ringGroups: ringGroups,
        members: members,
        serviceCompanies: serviceCompanies,
        approvedCompanyIds: approvedCompanyIds,
        serviceRequest: serviceRequest,
        onServiceRequest: onServiceRequest,
        onCreate: onCreate,
        onRingGroup: onRingGroup,
        onNumberPatch: onNumberPatch,
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
  Map<String, Object?>? scope,
  List<Map<String, Object?>>? companies,
  int createStatus = 201,
  String? createMessage,
  List<Map<String, Object?>> subaccounts = const [],
  List<Map<String, Object?>> numbers = const [],
  List<Map<String, Object?>> ringGroups = const [],
  List<Map<String, Object?>> members = const [],
  List<Map<String, Object?>> serviceCompanies = const [],
  List<String> approvedCompanyIds = const [],
  Map<String, Object?>? serviceRequest,
  void Function(Map<String, dynamic> body)? onServiceRequest,
  void Function(Map<String, dynamic> body)? onCreate,
  void Function(Map<String, dynamic> body)? onRingGroup,
  void Function(Map<String, dynamic> body)? onNumberPatch,
  void Function()? onDelete,
}) {
  return (request) async {
    final path = request.url.path;

    if (path.endsWith('/me')) {
      return _json({
        'data': {
          ..._me,
          'permissions': permissions,
          'scope': ?scope,
          'companies': ?companies,
        },
      });
    }

    if (path.endsWith('/phone/service-request/companies') &&
        request.method == 'GET') {
      return _json({'data': serviceCompanies});
    }

    if (path.endsWith('/phone/service-request') && request.method == 'GET') {
      return _json({
        'data': {
          'latest': serviceRequest,
          'approved_company_ids': approvedCompanyIds,
          'may_request': serviceRequest == null,
          'cooldown_ends_at': null,
          'volumes': {'under_100': 'Fewer than 100 calls a month'},
        },
      });
    }

    if (path.endsWith('/phone/service-request') && request.method == 'POST') {
      onServiceRequest?.call(jsonDecode(request.body) as Map<String, dynamic>);
      return _json({'data': {'status': 'pending'}}, 201);
    }

    if (path.endsWith('/phone/connections/subaccounts') &&
        request.method == 'GET') {
      return _json({'data': subaccounts});
    }

    if (path.endsWith('/phone/connections') && request.method == 'GET') {
      return _json({'data': connections});
    }

    if (path.endsWith('/phone/numbers') && request.method == 'GET') {
      return _json({'data': numbers});
    }

    if (path.endsWith('/phone/ring-groups') && request.method == 'GET') {
      return _json({'data': ringGroups});
    }

    if (path.endsWith('/phone/members') && request.method == 'GET') {
      return _json({
        'data': {'people': members, 'roles': <Map<String, Object?>>[]},
      });
    }

    if (path.contains('/phone/ring-groups') && request.method == 'POST') {
      onRingGroup?.call(jsonDecode(request.body) as Map<String, dynamic>);
      return _json({'data': {'id': 'rg-new'}}, 201);
    }

    if (path.contains('/phone/numbers/') && request.method == 'PATCH') {
      onNumberPatch?.call(jsonDecode(request.body) as Map<String, dynamic>);
      return _json({'data': <String, Object?>{}});
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
          'scope_type': 'company',
          'scope_id': 'co-1',
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
