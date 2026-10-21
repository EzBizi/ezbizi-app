import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/widgets/search_picker.dart';
import 'package:ezbizi_client/widgets/workiz_connect_form.dart';
import 'package:ezbizi_client/widgets/workiz_session_login.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// UI queue 120 (Jose picked option 1 and phone 4): the Workiz web login,
/// handed over through the EzBizi extension, one per company since Workiz's
/// a09a8684. A company view (Harbor Home Services) unless a test names
/// another. Every name is invented.
void main() {
  const signedOut =
      'Workiz signed this session out. Sign in to Workiz and send the session '
      'to EzBizi again.';

  testWidgets('Get a link shows the link and Copy puts it on the clipboard', (
    tester,
  ) async {
    final server = _Server();
    final copied = <String>[];
    tester.binding.defaultBinaryMessenger.setMockMethodCallHandler(
      SystemChannels.platform,
      (call) async {
        if (call.method == 'Clipboard.setData') {
          copied.add((call.arguments as Map)['text'] as String);
        }
        return null;
      },
    );
    addTearDown(
      () => tester.binding.defaultBinaryMessenger.setMockMethodCallHandler(
        SystemChannels.platform,
        null,
      ),
    );

    await _pumpSession(tester, server);
    expect(find.text(WorkizSessionLogin.disclaimer), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('workiz-session-get')));
    await tester.pump();
    await tester.pump();

    expect(server.sent.last.method, 'POST');
    expect(server.sent.last.url.path, '/api/v1/social/workiz-web/link');
    expect(find.text('https://app.example.test/s/workiz/ab12'), findsOneWidget);
    expect(find.textContaining('Good for 30 minutes'), findsOneWidget);
    expect(find.text('Get a new one'), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('workiz-session-copy')));
    await tester.pump();
    expect(copied, ['https://app.example.test/s/workiz/ab12']);
    expect(find.text('Copied'), findsOneWidget);
  });

  testWidgets('polling turns the page Connected on its own', (tester) async {
    var done = 0;
    final server = _Server();
    await _pumpSession(tester, server, onDone: () => done++);

    await tester.tap(find.byKey(const ValueKey('workiz-session-get')));
    await tester.pump();
    await tester.pump();
    expect(find.text('Waiting for it…'), findsOneWidget);

    // The extension sends it; the next poll (5 s) sees it.
    server.row = _web('connected');
    await tester.pump(const Duration(seconds: 5));
    await tester.pumpAndSettle();

    expect(find.text('Connected'), findsOneWidget);
    expect(find.byKey(const ValueKey('workiz-session-get')), findsNothing);
    expect(find.text('Waiting for it…'), findsNothing);
    expect(done, 1);
  });

  // Workiz stamps last_reached_at on handoff (2026-10-20).
  testWidgets('a connected session says when it last reached EzBizi', (
    tester,
  ) async {
    final server = _Server()
      ..row = {
        ..._web('connected'),
        'last_reached_at': DateTime.now()
            .subtract(const Duration(minutes: 12))
            .toUtc()
            .toIso8601String(),
      };
    await _pumpSession(tester, server);

    expect(
      find.text('Reads payments · last reached 12 min ago'),
      findsOneWidget,
    );
  });

  testWidgets('a branch picked for an account is PUT', (tester) async {
    final server = _Server()..row = _web('connected');
    await _pumpSession(tester, server);

    expect(find.text('Lakeview Plumbing'), findsOneWidget);
    expect(find.text('Choose a branch'), findsOneWidget);

    await tester.tap(
      find.byKey(const ValueKey('workiz-session-branch:acct-1')),
    );
    await tester.pumpAndSettle();
    await tester.tap(find.text('Harbor Home Services · Maple Branch').last);
    await tester.pumpAndSettle();

    final put = server.sent.lastWhere((r) => r.method == 'PUT');
    expect(put.url.path, '/api/v1/social/workiz-web/w-1/accounts');
    expect(jsonDecode(put.body), {'account': 'acct-1', 'franchise_id': 'f-1'});
    expect(find.text('Choose a branch'), findsNothing);
  });

  // Workiz, 2026-10-21: the server fills the branch from the account's own
  // name (mapped false); nothing to do until somebody presses Change.
  testWidgets('an auto branch shows as text with Change and sends no PUT', (
    tester,
  ) async {
    final server = _Server()
      ..row = _web('connected')
      ..accounts = [
        {
          'account': 'acct-1',
          'name': 'Lakeview Plumbing - Maple',
          'mapped': false,
          'franchise_id': 'f-1',
        },
      ];
    await _pumpSession(tester, server);

    expect(find.text('→ Harbor Home Services · Maple Branch'), findsOneWidget);
    expect(find.byKey(const ValueKey('workiz-session-auto:acct-1')), findsOne);
    expect(find.text('Choose a branch'), findsNothing);
    expect(
      find.byKey(const ValueKey('workiz-session-branch:acct-1')),
      findsNothing,
    );
    expect(server.sent.where((r) => r.method == 'PUT'), isEmpty);

    // Change opens the picker; picking another branch PUTs it.
    await tester.tap(
      find.byKey(const ValueKey('workiz-session-change:acct-1')),
    );
    await tester.pumpAndSettle();
    expect(server.sent.where((r) => r.method == 'PUT'), isEmpty);
    await tester.tap(
      find.byKey(const ValueKey('workiz-session-branch:acct-1')),
    );
    await tester.pumpAndSettle();
    await tester.tap(find.text('Harbor Home Services · Cedar Branch').last);
    await tester.pumpAndSettle();

    final put = server.sent.lastWhere((r) => r.method == 'PUT');
    expect(put.url.path, '/api/v1/social/workiz-web/w-1/accounts');
    expect(jsonDecode(put.body), {'account': 'acct-1', 'franchise_id': 'f-2'});
    expect(find.text('→ Harbor Home Services · Cedar Branch'), findsOneWidget);
    // Picked by a person now, so no longer "auto".
    expect(
      find.byKey(const ValueKey('workiz-session-auto:acct-1')),
      findsNothing,
    );
  });

  testWidgets('no branch shows the picker highlighted; the reach is said', (
    tester,
  ) async {
    final server = _Server()..row = _web('connected');
    await _pumpSession(tester, server);

    final picker = tester.widget<SearchPickerField<String>>(
      find.byKey(const ValueKey('workiz-session-branch:acct-1')),
    );
    expect(picker.value, isNull);
    expect(picker.errorText, '');
    expect(
      find.byKey(const ValueKey('workiz-session-change:acct-1')),
      findsNothing,
    );
    expect(find.text(WorkizSessionLogin.reach), findsOneWidget);
  });

  // Jose, 2026-10-21: the picker listed every company's "Main" bare, and
  // the login belongs to one company, so only its branches are offered.
  testWidgets("a company's login offers only that company's branches", (
    tester,
  ) async {
    final server = _Server()..row = _web('connected', company: 'co-s');
    await _pumpSession(tester, server, viewId: 'co-s');

    expect(_branchLabels(tester), ['Summit Garage Doors · Maple Branch']);

    final other = _Server()..row = _web('connected');
    await _pumpSession(tester, other, fresh: true);
    expect(_branchLabels(tester), [
      'Harbor Home Services · Cedar Branch',
      'Harbor Home Services · Maple Branch',
    ]);
  });

  // Workiz, 2026-10-21: one login per company. A company view's link is for
  // the company in view; the server takes it from the view.
  testWidgets('a company view requests the link for its company', (
    tester,
  ) async {
    final server = _Server();
    await _pumpSession(tester, server);

    expect(find.byKey(const ValueKey('workiz-session-company')), findsNothing);
    await tester.tap(find.byKey(const ValueKey('workiz-session-get')));
    await tester.pump();
    await tester.pump();

    final post = server.sent.singleWhere((r) => r.method == 'POST');
    expect(post.url.path, '/api/v1/social/workiz-web/link');
    expect(jsonDecode(post.body), <String, dynamic>{});
    expect(find.text('https://app.example.test/s/workiz/ab12'), findsOneWidget);
  });

  testWidgets('the organization view picks the company and passes company_id', (
    tester,
  ) async {
    // Harbor already has a login; Summit has none.
    final server = _Server()..row = _web('connected');
    await _pumpSession(tester, server, viewType: 'organization', viewId: null);

    expect(find.text('For which company?'), findsOneWidget);
    expect(find.byKey(const ValueKey('workiz-session-get')), findsNothing);
    expect(find.text('Connected'), findsNothing);
    final picker = tester.widget<SearchPickerField<String>>(
      find.byKey(const ValueKey('workiz-session-company')),
    );
    expect(
      {for (final o in picker.options) o.label: o.detail},
      {'Summit Garage Doors': null, 'Harbor Home Services': 'Connected'},
    );

    await tester.tap(find.byKey(const ValueKey('workiz-session-company')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Summit Garage Doors').last);
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const ValueKey('workiz-session-get')));
    await tester.pump();
    await tester.pump();

    final post = server.sent.singleWhere((r) => r.method == 'POST');
    expect(post.url.path, '/api/v1/social/workiz-web/link');
    expect(jsonDecode(post.body), {'company_id': 'co-s'});
  });

  testWidgets("the organization view shows a picked company's own login", (
    tester,
  ) async {
    final server = _Server()..row = _web('connected');
    await _pumpSession(tester, server, viewType: 'organization', viewId: null);

    await tester.tap(find.byKey(const ValueKey('workiz-session-company')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Harbor Home Services').last);
    await tester.pumpAndSettle();

    expect(find.text('Connected'), findsOneWidget);
    expect(find.text('Lakeview Plumbing'), findsOneWidget);
    expect(server.sent.last.url.path, '/api/v1/social/workiz-web/w-1/accounts');
    expect(_branchLabels(tester), [
      'Harbor Home Services · Cedar Branch',
      'Harbor Home Services · Maple Branch',
    ]);
  });

  // Jose, 2026-10-21: "no way to disconnect either".
  testWidgets('Disconnect asks first, then DELETEs and shows the steps', (
    tester,
  ) async {
    var done = 0;
    final server = _Server()..row = _web('connected');
    await _pumpSession(
      tester,
      server,
      mayDisconnect: true,
      onDone: () => done++,
    );

    // The page's list reloads after it, as it does on connecting.
    final before = done;
    await tester.tap(find.byKey(const ValueKey('workiz-session-disconnect')));
    await tester.pumpAndSettle();
    expect(find.text(WorkizSessionLogin.disconnectQuestion), findsOneWidget);
    expect(server.sent.where((r) => r.method == 'DELETE'), isEmpty);

    await tester.tap(
      find.byKey(const ValueKey('workiz-session-disconnect-confirm')),
    );
    await tester.pumpAndSettle();

    final delete = server.sent.singleWhere((r) => r.method == 'DELETE');
    expect(delete.url.path, '/api/v1/social/connections/w-1');
    expect(find.text(WorkizSessionLogin.disconnectQuestion), findsNothing);
    expect(find.text('Connected'), findsNothing);
    expect(find.byKey(const ValueKey('workiz-session-get')), findsOneWidget);
    expect(
      find.byKey(const ValueKey('workiz-session-disconnect')),
      findsNothing,
    );
    expect(done, before + 1);
  });

  testWidgets('Cancel sends nothing; a refusal shows inline', (tester) async {
    final server = _Server()
      ..row = _web('expired')
      ..refuseDelete = true;
    await _pumpSession(tester, server, mayDisconnect: true);

    await tester.tap(find.byKey(const ValueKey('workiz-session-disconnect')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Cancel'));
    await tester.pumpAndSettle();
    expect(server.sent.where((r) => r.method == 'DELETE'), isEmpty);

    await tester.tap(find.byKey(const ValueKey('workiz-session-disconnect')));
    await tester.pumpAndSettle();
    await tester.tap(
      find.byKey(const ValueKey('workiz-session-disconnect-confirm')),
    );
    await tester.pumpAndSettle();

    expect(find.text('You cannot disconnect this.'), findsOneWidget);
    expect(find.text('Signed out'), findsOneWidget);
  });

  testWidgets('Disconnect is hidden without the permission', (tester) async {
    final server = _Server()..row = _web('connected');
    await _pumpSession(tester, server);

    expect(find.text('Connected'), findsOneWidget);
    expect(
      find.byKey(const ValueKey('workiz-session-disconnect')),
      findsNothing,
    );
  });

  testWidgets('signed out says why and offers Send it again', (tester) async {
    final server = _Server()
      ..row = {..._web('expired'), 'status_reason': signedOut};
    await _pumpSession(tester, server);

    expect(find.text('Signed out'), findsOneWidget);
    expect(find.text(signedOut), findsOneWidget);
    expect(find.byKey(const ValueKey('workiz-session-get')), findsNothing);

    await tester.tap(find.text('Send it again'));
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('workiz-session-get')), findsOneWidget);
  });

  testWidgets('a server without the routes says so', (tester) async {
    final server = _Server()..routes = false;
    await _pumpSession(tester, server);

    await tester.tap(find.byKey(const ValueKey('workiz-session-get')));
    await tester.pumpAndSettle();

    expect(find.text('Not available on this server yet.'), findsOneWidget);
    expect(find.byKey(const ValueKey('workiz-session-get')), findsNothing);
  });

  // Workiz, 2026-10-21: the login belongs to the company, so a branch view
  // gets a 403 for the link and the accounts; the card asks for neither.
  testWidgets('a branch view shows the hint and asks for no link', (
    tester,
  ) async {
    final server = _Server()..row = _web('connected');
    await _pumpSession(
      tester,
      server,
      viewType: 'franchise',
      viewId: 'f-1',
      mayDisconnect: true,
    );

    expect(find.text('Connected'), findsOneWidget);
    expect(
      find.byKey(const ValueKey('workiz-session-disconnect')),
      findsNothing,
    );
    expect(find.text(WorkizSessionLogin.branchHint), findsOneWidget);
    expect(find.byKey(const ValueKey('workiz-session-get')), findsNothing);
    expect(find.byKey(const ValueKey('workiz-session-company')), findsNothing);
    expect(find.text('Lakeview Plumbing'), findsNothing);
    expect(server.sent.map((r) => r.url.path), ['/api/v1/social/connections']);
  });

  testWidgets("a 403 from the link shows the server's sentence", (
    tester,
  ) async {
    final server = _Server()..forbidden = true;
    await _pumpSession(tester, server);

    await tester.tap(find.byKey(const ValueKey('workiz-session-get')));
    await tester.pumpAndSettle();

    expect(server.sent.last.url.path, '/api/v1/social/workiz-web/link');
    expect(find.text(_Server.refusal), findsOneWidget);
    expect(find.byKey(const ValueKey('workiz-session-get')), findsNothing);
    expect(find.text('Waiting for it…'), findsNothing);
  });

  // A link works once; Get a new one replaces it, so only the newest shows.
  testWidgets('Get a new one shows only the newest link', (tester) async {
    final server = _Server();
    await _pumpSession(tester, server);

    await tester.tap(find.byKey(const ValueKey('workiz-session-get')));
    await tester.pump();
    await tester.pump();
    expect(find.text('https://app.example.test/s/workiz/ab12'), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('workiz-session-new')));
    await tester.pump();
    await tester.pump();
    expect(find.text('https://app.example.test/s/workiz/ab12'), findsNothing);
    expect(
      find.text('https://app.example.test/s/workiz/ab12-2'),
      findsOneWidget,
    );
  });

  testWidgets('a phone shows the status and table, not the handoff', (
    tester,
  ) async {
    tester.view.physicalSize = const Size(390, 844);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.reset);

    final server = _Server();
    await _pumpSession(tester, server);
    expect(find.byKey(const ValueKey('workiz-session-get')), findsNothing);
    expect(
      find.text(
        'Sending the session needs the EzBizi extension on a computer.',
      ),
      findsOneWidget,
    );

    server.row = _web('connected');
    await _pumpSession(tester, server, fresh: true);
    expect(find.text('Connected'), findsOneWidget);
    expect(find.text('Lakeview Plumbing'), findsOneWidget);
    expect(find.text('Choose a branch'), findsOneWidget);
  });
}

/// A company-scoped `workiz_web` row; Harbor Home Services unless named.
Map<String, dynamic> _web(String status, {String company = 'co-h'}) => {
  'id': 'w-1',
  'provider': 'workiz_web',
  'status': status,
  'scope_type': 'company',
  'scope_id': company,
};

class _Server {
  /// The server's 403 sentence from a branch view (Workiz, 2026-10-21).
  static const refusal =
      'The Workiz web login belongs to the company. Switch to the company '
      'view to change it.';

  final sent = <http.Request>[];
  Map<String, dynamic>? row;

  /// What GET accounts answers; one account with no branch unless set.
  List<Map<String, dynamic>> accounts = [
    {
      'account': 'acct-1',
      'name': 'Lakeview Plumbing',
      'mapped': false,
      'franchise_id': null,
    },
  ];
  bool routes = true;
  bool forbidden = false;
  bool refuseDelete = false;
  int links = 0;

  late final client = MockClient((request) async {
    sent.add(request);
    final path = request.url.path;
    http.Response json(Object body, [int status = 200]) => http.Response(
      jsonEncode(body),
      status,
      headers: const {'content-type': 'application/json'},
    );

    if (request.method == 'DELETE' &&
        path.startsWith('/api/v1/social/connections/')) {
      if (refuseDelete) {
        return json({'message': 'You cannot disconnect this.'}, 403);
      }
      row = null;
      return json({'data': <String, dynamic>{}});
    }
    if (path == '/api/v1/social/connections') {
      return json({
        'data': [
          {'id': 'c-2', 'provider': 'workiz', 'status': 'connected'},
          ?row,
        ],
      });
    }
    if (!routes) return json({'message': 'Not found.'}, 404);
    if (forbidden && path.startsWith('/api/v1/social/workiz-web')) {
      return json({'message': refusal}, 403);
    }
    if (path == '/api/v1/social/workiz-web/link') {
      links++;
      return json({
        'data': {
          'id': 'w-1',
          'link':
              'https://app.example.test/s/workiz/ab12${links > 1 ? '-$links' : ''}',
        },
      });
    }
    if (path.endsWith('/accounts') && request.method == 'GET') {
      return json({'data': accounts});
    }
    return json({'data': <String, dynamic>{}});
  });
}

Future<void> _pumpSession(
  WidgetTester tester,
  _Server server, {
  VoidCallback? onDone,
  bool fresh = false,
  String viewType = 'company',
  String? viewId = 'co-h',
  bool mayDisconnect = false,
}) async {
  const companies = [
    CompanySummary(
      id: 'co-s',
      name: 'Summit Garage Doors',
      parentCompanyId: null,
    ),
    CompanySummary(
      id: 'co-h',
      name: 'Harbor Home Services',
      parentCompanyId: null,
    ),
  ];
  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(
        body: SingleChildScrollView(
          child: WorkizConnectForm(
            key: fresh ? UniqueKey() : null,
            api: ApiClient(client: server.client),
            branches: const [
              FranchiseSummary(
                id: 'f-1',
                name: 'Maple Branch',
                companyId: 'co-h',
              ),
              FranchiseSummary(
                id: 'f-2',
                name: 'Cedar Branch',
                companyId: 'co-h',
              ),
              FranchiseSummary(
                id: 'f-3',
                name: 'Maple Branch',
                companyId: 'co-s',
              ),
            ],
            companies: companies,
            // What `/me` answers per view: every company in the organization
            // view, the one in view otherwise (a branch's is Harbor's).
            companiesInView: [
              for (final c in companies)
                if (viewType == 'organization' ||
                    c.id == (viewType == 'franchise' ? 'co-h' : viewId))
                  c,
            ],
            viewType: viewType,
            viewId: viewId,
            mayDisconnect: mayDisconnect,
            onDone: onDone ?? () {},
          ),
        ),
      ),
    ),
  );
  await tester.pumpAndSettle();
  await tester.tap(find.text('Signed-in session'));
  await tester.pumpAndSettle();
}

List<String> _branchLabels(WidgetTester tester) => tester
    .widget<SearchPickerField<String>>(
      find.byKey(const ValueKey('workiz-session-branch:acct-1')),
    )
    .options
    .map((o) => o.label)
    .toList();
