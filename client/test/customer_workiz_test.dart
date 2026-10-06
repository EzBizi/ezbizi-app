import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/customers/customer_detail_panel.dart';
import 'package:ezbizi_client/widgets/tab_bar_like.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// A customer's money and notes from Workiz (UI queue 59; Jose: "as u
/// recommend", desktop 1 and phone 4, relayed by Customers).
///
/// Customer, Money and Notes from Workiz tabs, as on a job. Past due is the
/// figure that needs somebody, so it is red on its card and named on its tab;
/// unknown is not zero. Notes are a live call to Workiz, asked for only when
/// their tab is opened, and a branch Workiz did not answer for says so alone.
void main() {
  testWidgets('the money tab names what is past due, and asks with today', (
    tester,
  ) async {
    final server = _Server();
    await _pump(tester, server);

    expect(server.customerQueries.single.keys, contains('today'));
    final tabs = tester.widget<TabBarLike>(
      find.byKey(const Key('customer-tabs')),
    );
    expect(
      [for (final l in tabs.labels) l.$1],
      ['Customer', r'Money · $1,240.00 past due', 'Notes from Workiz'],
    );
    expect(tabs.tones, [null, Tone.bad, null]);

    await tester.tap(find.textContaining('Money · '));
    await tester.pumpAndSettle();
    expect(
      find.descendant(
        of: find.byKey(const ValueKey('customer-money-past-due')),
        matching: find.text(r'$1,240.00'),
      ),
      findsOneWidget,
    );
    expect(
      find.descendant(
        of: find.byKey(const ValueKey('customer-money-paid')),
        matching: find.text(r'$12,880.00'),
      ),
      findsOneWidget,
    );
    expect(
      tester.widget<Text>(find.byKey(const Key('customer-money-line'))).data,
      r'14 invoices · $14,430.00 invoiced · 2 open estimates worth $3,240.00',
    );
  });

  testWidgets('nothing past due leaves the tab plain', (tester) async {
    await _pump(tester, _Server(money: [_usd(pastDue: 0)]));

    final tabs = tester.widget<TabBarLike>(
      find.byKey(const Key('customer-tabs')),
    );
    expect(tabs.labels[1].$1, 'Money');
    expect(tabs.tones?[1], isNull);
  });

  testWidgets('a past due the server could not work out is unknown, not zero', (
    tester,
  ) async {
    await _pump(tester, _Server(money: [_usd(pastDue: null)]));

    final tabs = tester.widget<TabBarLike>(
      find.byKey(const Key('customer-tabs')),
    );
    expect(tabs.labels[1].$1, 'Money');
    await tester.tap(find.text('Money'));
    await tester.pumpAndSettle();
    expect(
      find.descendant(
        of: find.byKey(const ValueKey('customer-money-past-due')),
        matching: find.text('—'),
      ),
      findsOneWidget,
    );
  });

  testWidgets(
    'no Financials, or nothing in Workiz, means no Money tab at all',
    (tester) async {
      await _pump(tester, _Server(money: null));
      expect(find.textContaining('Money'), findsNothing);

      await _pump(tester, _Server(money: const []));
      expect(find.textContaining('Money'), findsNothing);
    },
  );

  testWidgets('notes are asked for only when their tab opens', (tester) async {
    final server = _Server();
    await _pump(tester, server);
    expect(server.notesAsked, 0);

    await tester.tap(find.text('Notes from Workiz'));
    await tester.pump();
    expect(find.byKey(const Key('workiz-notes-loading')), findsOneWidget);
    await tester.pumpAndSettle();

    expect(server.notesAsked, 1);
    expect(
      find.text('Gate code 4411. Dog in the yard, call before arriving.'),
      findsOneWidget,
    );
    expect(find.textContaining('Sam Ortega'), findsOneWidget);
    expect(find.text('HARBOR CHIMNEY'), findsOneWidget);
  });

  testWidgets('a branch Workiz did not answer for says so on its own line', (
    tester,
  ) async {
    final server = _Server();
    await _pump(tester, server);
    await tester.tap(find.text('Notes from Workiz'));
    await tester.pumpAndSettle();

    final line = find.byKey(const ValueKey('workiz-notes-error-WC-2'));
    expect(
      find.descendant(
        of: line,
        matching: find.text(
          'Workiz did not answer for Harbor Massachusetts: Timed out.',
        ),
      ),
      findsOneWidget,
    );
    // The branch that did answer still shows its notes.
    expect(find.text('Prefers morning appointments.'), findsOneWidget);

    await tester.tap(
      find.descendant(of: line, matching: find.text('Try again')),
    );
    await tester.pumpAndSettle();
    expect(server.notesAsked, 2);
  });

  testWidgets('a customer with no Workiz client has no Notes tab', (
    tester,
  ) async {
    await _pump(tester, _Server(workizIds: const [], money: null));

    expect(find.byKey(const Key('customer-tabs')), findsNothing);
    expect(
      find.widgetWithText(TextField, 'Name'),
      findsOneWidget,
      reason: 'the control',
    );
  });
}

Map<String, dynamic> _usd({int? pastDue = 124000}) => {
  'currency': 'USD',
  'invoiced_minor': 1443000,
  'due_minor': 31000,
  'past_due_minor': pastDue,
  'paid_minor': 1288000,
  'invoices': 14,
  'open_estimates': 2,
  'open_estimates_minor': 324000,
};

class _Server {
  _Server({Object? money = _unset, this.workizIds = const ['WC-1', 'WC-2']})
    : money = identical(money, _unset) ? [_usd()] : money;

  static const _unset = Object();

  final Object? money;
  final List<String> workizIds;
  final List<Map<String, String>> customerQueries = [];
  int notesAsked = 0;

  Future<http.Response> handle(http.Request request) async {
    final path = request.url.path;
    if (path.endsWith('/me')) return _json({'data': _me});
    if (path.endsWith('/customers/c-1/workiz-notes')) {
      notesAsked++;
      return _json({
        'data': [
          {
            'client_id': 'WC-1',
            'franchise': {'id': 'fr-1', 'name': 'Harbor Chimney'},
            'notes': [
              {
                'text':
                    'Gate code 4411. Dog in the yard, call before arriving.',
                'created_at': '2026-09-12T14:20:00+00:00',
                'author': 'Sam Ortega',
              },
              {
                'text': 'Prefers morning appointments.',
                'created_at': '2026-03-02T15:00:00+00:00',
                'author': 'Office',
              },
            ],
          },
          {
            'client_id': 'WC-2',
            'franchise': {'id': 'fr-2', 'name': 'Harbor Massachusetts'},
            'notes': <dynamic>[],
            'error': 'Timed out.',
          },
        ],
      });
    }
    if (path.endsWith('/customers/c-1')) {
      customerQueries.add(request.url.queryParameters);
      return _json({
        'data': {
          'id': 'c-1',
          'number': 'C-2041',
          'name': 'Riley Park',
          'phone': null,
          'email': null,
          'notes': null,
          'is_active': true,
          'jobs_count': 0,
          'workiz_client_ids': workizIds,
          'owning_franchise': {'id': 'fr-1', 'name': 'Harbor Chimney'},
          'address': null,
          'money': money,
        },
      });
    }
    return _json({'data': <dynamic>[]});
  }
}

const _me = {
  'id': 'u-1',
  'name': 'Dana Reyes',
  'email': 'dana@example.com',
  'role': {'name': 'Office'},
  'has_avatar': false,
  'preferences': <String, dynamic>{},
  'permissions': ['customers.view', 'customers.edit', 'jobs.financials'],
  'organization': {
    'id': 'org-1',
    'name': 'Harbor Group',
    'franchise_label': 'Franchise',
    'is_read_only': false,
  },
  'scope': {
    'type': 'organization',
    'id': 'org-1',
    'label': 'Harbor Group',
    'path': ['Harbor Group'],
  },
  'companies': <dynamic>[],
  'franchises': <dynamic>[],
};

Future<void> _pump(WidgetTester tester, _Server server) async {
  tester.view.physicalSize = const Size(900, 1400);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(client: MockClient(server.handle));
  final session = Session(api);
  await tester.runAsync(() => session.adopt('tok'));

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(
        body: KeyedSubtree(
          key: UniqueKey(),
          child: CustomerDetailPanel(
            api: api,
            session: session,
            id: 'c-1',
            onDone: (_) {},
          ),
        ),
      ),
    ),
  );
  await tester.pumpAndSettle();
}

http.Response _json(Object body, [int status = 200]) => http.Response(
  jsonEncode(body),
  status,
  headers: const {'content-type': 'application/json'},
);
