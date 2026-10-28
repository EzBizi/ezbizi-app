import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/email/filters_panel.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Email › Inbox › Filters. Every name and address here is invented.
void main() {
  testWidgets('the list shows each filter, its action, and Shared · scope', (
    tester,
  ) async {
    final server = _Server(mayShare: false);
    await _pump(tester, server);

    expect(find.byKey(const ValueKey('email-filters-search')), findsOneWidget);
    expect(find.text('Newsletters'), findsOneWidget);
    expect(find.text('From contains "news@example.com"'), findsOneWidget);
    expect(find.text('Move to Notifications'), findsOneWidget);
    expect(find.text('Subject contains "Sale"'), findsOneWidget);
    expect(find.text('Hide'), findsOneWidget);
    expect(find.text('Shared · Everdoors · Connecticut'), findsOneWidget);
    // Delete only where may_delete.
    expect(find.byKey(const ValueKey('email-filter-delete-1')), findsOneWidget);
    expect(find.byKey(const ValueKey('email-filter-delete-2')), findsNothing);

    await tester.enterText(
      find.byKey(const ValueKey('email-filters-search')),
      'sale',
    );
    await tester.pump();
    expect(find.text('Newsletters'), findsNothing);
    expect(find.text('Sales blasts'), findsOneWidget);
  });

  testWidgets('no scope picker without may_share', (tester) async {
    await _pump(tester, _Server(mayShare: false));
    await tester.tap(find.byKey(const ValueKey('email-filter-add')));
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('email-filter-action')), findsNothing);
    expect(find.byKey(const ValueKey('email-filter-scope')), findsNothing);
  });

  testWidgets('a scope picker with may_share, saving its scope', (
    tester,
  ) async {
    final server = _Server(mayShare: true);
    await _pump(tester, server);
    await tester.tap(find.byKey(const ValueKey('email-filter-add')));
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('email-filter-scope')), findsOneWidget);
    expect(find.text('Just me'), findsOneWidget);

    await tester.enterText(
      find.byKey(const ValueKey('email-filter-name')),
      'Vendors',
    );
    await tester.enterText(
      find.byKey(const ValueKey('email-filter-from')),
      '@vendor.example',
    );
    await tester.tap(find.byKey(const ValueKey('email-filter-save')));
    await tester.pumpAndSettle();

    expect(server.posts.single, {
      'name': 'Vendors',
      'from_contains': '@vendor.example',
    });
  });

  testWidgets('From or Subject is required, and a 403 sentence is shown', (
    tester,
  ) async {
    final server = _Server(mayShare: false, refuse: true);
    await _pump(tester, server);
    await tester.tap(find.byKey(const ValueKey('email-filter-add')));
    await tester.pumpAndSettle();

    await tester.enterText(
      find.byKey(const ValueKey('email-filter-name')),
      'Empty',
    );
    await tester.tap(find.byKey(const ValueKey('email-filter-save')));
    await tester.pumpAndSettle();
    expect(find.textContaining('Fill in From contains'), findsOneWidget);
    expect(server.posts, isEmpty);

    await tester.enterText(
      find.byKey(const ValueKey('email-filter-subject')),
      'Invoice',
    );
    await tester.tap(find.byKey(const ValueKey('email-filter-save')));
    await tester.pumpAndSettle();
    expect(server.posts, hasLength(1));
    expect(
      find.text('Your role may not share filters with everyone.'),
      findsOneWidget,
    );
  });

  testWidgets('Filter mail like this opens the form with the sender', (
    tester,
  ) async {
    await _pump(
      tester,
      _Server(mayShare: false),
      prefillFrom: 'sam@example.com',
    );
    expect(find.text('Add a filter'), findsOneWidget);
    final from = tester.widget<TextField>(
      find.byKey(const ValueKey('email-filter-from')),
    );
    expect(from.controller!.text, 'sam@example.com');
  });
}

class _Server {
  _Server({required this.mayShare, this.refuse = false});

  final bool mayShare;
  final bool refuse;
  final List<Map<String, dynamic>> posts = [];

  Future<http.Response> handle(http.Request request) async {
    final path = request.url.path;
    if (path.endsWith('/email/filters') && request.method == 'GET') {
      return _json({
        'data': {
          'filters': [
            {
              'id': 1,
              'name': 'Newsletters',
              'from_contains': 'news@example.com',
              'subject_contains': null,
              'action': 'notifications',
              'shared': false,
              'scope_type': null,
              'scope_id': null,
              'may_delete': true,
            },
            {
              'id': 2,
              'name': 'Sales blasts',
              'from_contains': null,
              'subject_contains': 'Sale',
              'action': 'hide',
              'shared': true,
              'scope_type': 'franchise',
              'scope_id': 'f-ct',
              'may_delete': false,
            },
          ],
          'may_share': mayShare,
          'actions': ['notifications', 'hide'],
        },
      });
    }
    if (path.endsWith('/email/filters') && request.method == 'POST') {
      posts.add(jsonDecode(request.body) as Map<String, dynamic>);
      if (refuse) {
        return _json({
          'message': 'Your role may not share filters with everyone.',
        }, 403);
      }
      return _json({'data': {}}, 201);
    }
    return _json({'message': 'Not found'}, 404);
  }
}

Future<void> _pump(
  WidgetTester tester,
  _Server server, {
  String? prefillFrom,
}) async {
  tester.view.physicalSize = const Size(1400, 1000);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(client: MockClient(server.handle));
  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(
        body: EmailFiltersPanel(
          api: api,
          organizationName: 'Acme Org',
          companies: const [
            CompanySummary(
              id: 'c-ev',
              name: 'Everdoors',
              parentCompanyId: null,
            ),
          ],
          branches: const [
            FranchiseSummary(
              id: 'f-ct',
              name: 'Connecticut',
              companyId: 'c-ev',
            ),
          ],
          prefillFrom: prefillFrom,
        ),
      ),
    ),
  );
  await tester.pumpAndSettle();
}

http.Response _json(Object body, [int status = 200]) => http.Response(
  jsonEncode(body),
  status,
  headers: {'content-type': 'application/json'},
);
