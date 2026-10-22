import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/financials/financial_adjustments.dart';
import 'package:ezbizi_client/screens/financials/financial_bills.dart';
import 'package:ezbizi_client/screens/financials/invoice_sheets.dart';
import 'package:ezbizi_client/screens/financials/invoices_tab.dart';
import 'package:ezbizi_client/screens/inventory/order_list.dart';
import 'package:ezbizi_client/screens/marketing/newsletter_tab.dart';
import 'package:ezbizi_client/screens/marketing/social_media_tab.dart';
import 'package:ezbizi_client/screens/reviews_screen.dart';
import 'package:ezbizi_client/widgets/api_activity.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Search bars on the paged or capped lists (Jose, 2026-10-22: "i want search
/// bars"), group (c) of the search audit: the server pages or caps these, so
/// the box sends `q`, debounced, from the first page; and until each
/// controller reads `q`, the loaded rows are narrowed on the device too.
///
/// Each case types a term that matches one of two seeded rows, so a box that
/// sent nothing, or narrowed nothing, fails here. The fake server ignores `q`
/// on purpose: it is what every one of these controllers does today.
void main() {
  testWidgets('Reviews: q is sent and the rows narrow', (tester) async {
    final server = _Server({
      '/reviews': (_) => {
        'data': [
          _review('rev-1', 'Dana Whitfield', 'Turned up late.'),
          _review('rev-2', 'Omar Castillo', 'Spotless chimney.'),
        ],
        'meta': {
          'total': 2,
          'unanswered': 2,
          'connections': [
            {'provider': 'google_business_profile', 'status': 'connected'},
          ],
        },
      },
    });
    final session = await server.session(tester, const []);
    await _pump(tester, ReviewsScreen(api: server.api, session: session));

    expect(find.text('Dana Whitfield'), findsOneWidget);
    expect(find.text('Omar Castillo'), findsOneWidget);
    _sameHeight(
      tester,
      const Key('reviews-search'),
      const ValueKey('tour-customer_experience-rating'),
    );

    await _search(tester, const Key('reviews-search'), 'omar');

    final sent = server.last('/reviews');
    expect(sent['q'], 'omar');
    expect(sent.containsKey('page'), isFalse);
    expect(find.text('Dana Whitfield'), findsNothing);
    expect(find.text('Omar Castillo'), findsOneWidget);

    await _search(tester, const Key('reviews-search'), 'nobody');
    expect(find.text('No review matches "nobody".'), findsOneWidget);
  });

  testWidgets('Inventory › Orders: q goes to all three lists and rows narrow', (
    tester,
  ) async {
    final server = _Server({
      '/inventory/purchase-orders': (_) => {
        'data': [
          {
            'id': 'po-1',
            'number': 'PO-1001',
            'status': 'open',
            'vendor': {'name': 'Acme Flue Supply'},
            'lines': <Object>[],
          },
          {
            'id': 'po-2',
            'number': 'PO-1002',
            'status': 'open',
            'vendor': {'name': 'Northside Masonry'},
            'lines': <Object>[],
          },
        ],
      },
      '/inventory/issues': (_) => {'data': <Object>[]},
      '/inventory/sales': (_) => {'data': <Object>[]},
    });
    final session = await server.session(tester, const ['inventory.view']);
    await _pump(
      tester,
      OrderList(api: server.api, session: session, visible: true),
    );

    expect(find.text('PO-1001'), findsOneWidget);
    expect(find.text('PO-1002'), findsOneWidget);

    await _search(tester, const Key('inventory-orders-search'), 'acme');

    for (final path in const [
      '/inventory/purchase-orders',
      '/inventory/issues',
      '/inventory/sales',
    ]) {
      expect(server.last(path)['q'], 'acme', reason: path);
      expect(server.last(path).containsKey('page'), isFalse, reason: path);
    }
    expect(find.text('PO-1001'), findsOneWidget);
    expect(find.text('PO-1002'), findsNothing);

    await _search(tester, const Key('inventory-orders-search'), 'zzz');
    expect(find.text('No order, sale or issue matches "zzz".'), findsOneWidget);
  });

  testWidgets('Settings › API activity: q is sent from the newest row, '
      'dropping the older-page cursor, and rows narrow', (tester) async {
    final server = _Server({
      '/api-keys/activity': (request) => {
        // A full first page, so Show older is offered; the older page is one
        // row, so the cursor request can be told apart.
        'activity': request.url.queryParameters.containsKey('before')
            ? [_activity(900, '/api/v1/jobs/old')]
            : [
                _activity(0, '/api/v1/customers/c-1'),
                for (var i = 1; i < 200; i++)
                  _activity(i, '/api/v1/leads/l-$i'),
              ],
      },
    });
    await _pump(
      tester,
      SingleChildScrollView(
        child: ApiActivity(api: server.api, everyone: true),
      ),
      size: const Size(1300, 900),
    );

    _sameHeight(
      tester,
      const Key('api-activity-search'),
      const Key('api-activity-person'),
    );

    await tester.ensureVisible(find.byKey(const Key('api-activity-older')));
    await tester.tap(find.byKey(const Key('api-activity-older')));
    await tester.pumpAndSettle();
    expect(server.last('/api-keys/activity').containsKey('before'), isTrue);

    await _search(tester, const Key('api-activity-search'), 'customers');

    final sent = server.last('/api-keys/activity');
    expect(sent['q'], 'customers');
    expect(sent.containsKey('before'), isFalse);
    expect(find.textContaining('/customers/c-1'), findsWidgets);
    expect(find.textContaining('/leads/l-1'), findsNothing);

    await _search(tester, const Key('api-activity-search'), 'nothing-here');
    expect(
      find.text('Nothing done with an API key matches "nothing-here".'),
      findsOneWidget,
    );
  });

  testWidgets('Invoices › Invoices: q is sent and rows narrow', (tester) async {
    final server = _Server({
      '/invoices': (_) => {
        'data': [
          _invoice('i1', 'INV-0101', 'Springfield · Brightside'),
          _invoice('i2', 'INV-0102', 'Riverton · Brightside'),
        ],
        'totals': const {},
      },
    });
    final session = await server.session(tester, const [
      'invoices.view',
      'invoices.invoices.view',
    ]);
    await _pump(
      tester,
      InvoiceListTab(api: server.api, me: session.me!),
      size: const Size(1300, 900),
    );

    expect(find.text('INV-0101'), findsOneWidget);
    expect(find.text('INV-0102'), findsOneWidget);
    _sameHeight(
      tester,
      const Key('invoices-search'),
      const ValueKey('invoice-filter-period'),
    );

    await _search(tester, const Key('invoices-search'), 'riverton');

    final sent = server.last('/invoices');
    expect(sent['q'], 'riverton');
    expect(sent.containsKey('page'), isFalse);
    expect(find.text('INV-0101'), findsNothing);
    expect(find.text('INV-0102'), findsOneWidget);

    await _search(tester, const Key('invoices-search'), 'zzz');
    expect(find.text('No invoice matches "zzz".'), findsOneWidget);
  });

  testWidgets('Invoices › Charge sheets: q is sent and the sheets narrow', (
    tester,
  ) async {
    final sheets = [
      _sheet('sh1', 'September run'),
      _sheet('sh2', 'October run'),
    ];
    final server = _Server({
      '/invoices/sheets': (_) => {'data': sheets},
      '/invoices/sheets/sh1': (_) => {'data': sheets[0]},
      '/invoices/sheets/sh2': (_) => {'data': sheets[1]},
    });
    final session = await server.session(tester, const [
      'invoices.view',
      'invoices.charge_sheets.view',
    ]);
    await _pump(
      tester,
      ChargeSheetsTab(
        api: server.api,
        me: session.me!,
        onMade: () {},
        onOpenInvoice: (_) {},
      ),
      size: const Size(1300, 900),
    );

    expect(find.textContaining('September run'), findsOneWidget);
    _sameHeight(
      tester,
      const Key('charge-sheets-search'),
      const ValueKey('tour-invoices-sheet-picker'),
    );

    await _search(tester, const Key('charge-sheets-search'), 'october');

    final sent = server.last('/invoices/sheets');
    expect(sent['q'], 'october');
    expect(sent.containsKey('page'), isFalse);
    // The open sheet was left out by the search, so the match opens.
    expect(server.paths.last, '/invoices/sheets/sh2');
    expect(find.textContaining('September run'), findsNothing);
    expect(find.textContaining('October run'), findsOneWidget);

    await _search(tester, const Key('charge-sheets-search'), 'zzz');
    expect(find.text('No charge sheet matches "zzz".'), findsOneWidget);
  });

  testWidgets('Financials › Bills: q is sent and rows narrow', (tester) async {
    final server = _Server({
      '/financials/bills': (_) => {
        'data': [_bill('b1', 'Springfield'), _bill('b2', 'Riverton')],
      },
    });
    final session = await server.session(tester, const ['financials.view']);
    await _pump(
      tester,
      FinancialBillsTab(api: server.api, me: session.me!),
      size: const Size(1300, 900),
    );

    expect(find.textContaining('Springfield ·'), findsOneWidget);
    expect(find.textContaining('Riverton ·'), findsOneWidget);

    await _search(tester, const Key('financials-bills-search'), 'river');

    final sent = server.last('/financials/bills');
    expect(sent['q'], 'river');
    expect(sent.containsKey('page'), isFalse);
    expect(find.textContaining('Springfield ·'), findsNothing);
    expect(find.textContaining('Riverton ·'), findsOneWidget);

    await _search(tester, const Key('financials-bills-search'), 'zzz');
    expect(find.text('No bill matches "zzz".'), findsOneWidget);
  });

  testWidgets('Financials › adjustments: q is sent and rows narrow', (
    tester,
  ) async {
    final server = _Server({
      '/financials/adjustments': (_) => {
        'data': [
          {
            'id': 'a1',
            'kind': 'refund',
            'date': '2026-10-02',
            'technician': 'Lee Park',
            'reason': 'Chimney cap',
            'cents': 5000,
          },
          {
            'id': 'a2',
            'kind': 'dispute',
            'date': '2026-10-03',
            'technician': 'Ana Ruiz',
            'reason': 'Card chargeback',
            'cents': 9000,
          },
        ],
      },
    });
    await _pump(
      tester,
      FinancialAdjustmentsList(api: server.api, now: DateTime(2026, 10, 15)),
    );

    expect(find.textContaining('Lee Park'), findsOneWidget);
    expect(find.textContaining('Ana Ruiz'), findsOneWidget);

    await _search(
      tester,
      const Key('financials-adjustments-search'),
      'chargeback',
    );

    final sent = server.last('/financials/adjustments');
    expect(sent['q'], 'chargeback');
    expect(sent['from'], '2026-10-01');
    expect(sent.containsKey('page'), isFalse);
    expect(find.textContaining('Lee Park'), findsNothing);
    expect(find.textContaining('Ana Ruiz'), findsOneWidget);

    await _search(tester, const Key('financials-adjustments-search'), 'zzz');
    expect(find.text('Nothing in this period matches "zzz".'), findsOneWidget);
  });

  testWidgets('Marketing › posts timeline: q is sent and rows narrow', (
    tester,
  ) async {
    final server = _Server({
      '/social/providers': (_) => {'data': <Object>[]},
      '/social/connections': (_) => {'data': <Object>[]},
      '/social/posts': (_) => {
        'data': [
          _post('p1', 'Fall chimney sweep special'),
          _post('p2', 'Meet our new technician'),
        ],
      },
    });
    final session = await server.session(tester, const [
      'marketing.view',
      'marketing.dashboard.view',
    ]);
    await _pump(
      tester,
      SocialMediaTab(
        api: server.api,
        session: session,
        pickMedia: () async => null,
      ),
      size: const Size(1400, 1600),
    );

    expect(find.textContaining('Fall chimney sweep'), findsOneWidget);
    expect(find.textContaining('Meet our new'), findsOneWidget);

    await _search(tester, const Key('social-posts-search'), 'technician');

    final sent = server.last('/social/posts');
    expect(sent['q'], 'technician');
    expect(sent.containsKey('page'), isFalse);
    expect(find.textContaining('Fall chimney sweep'), findsNothing);
    expect(find.textContaining('Meet our new'), findsOneWidget);

    await _search(tester, const Key('social-posts-search'), 'zzz');
    expect(find.text('No post matches "zzz".'), findsOneWidget);
  });

  testWidgets('Newsletter › Dashboard: q is sent and rows narrow', (
    tester,
  ) async {
    final server = _Server({
      '/newsletters': (_) => {
        'data': [
          {'id': 'n1', 'subject': 'October tips', 'status': 'draft'},
          {'id': 'n2', 'subject': 'Winter offers', 'status': 'draft'},
        ],
        'stats': const {},
      },
    });
    final session = await server.session(tester, const [
      'newsletter.view',
      'newsletter.dashboard.view',
    ]);
    await _pump(
      tester,
      NewsletterTab(api: server.api, session: session),
      size: const Size(1400, 1600),
    );

    expect(find.text('October tips'), findsOneWidget);
    expect(find.text('Winter offers'), findsOneWidget);

    await _search(tester, const Key('newsletters-search'), 'winter');

    final sent = server.last('/newsletters');
    expect(sent['q'], 'winter');
    expect(sent.containsKey('page'), isFalse);
    expect(find.text('October tips'), findsNothing);
    expect(find.text('Winter offers'), findsOneWidget);

    await _search(tester, const Key('newsletters-search'), 'zzz');
    expect(find.text('No newsletter matches "zzz".'), findsOneWidget);
  });
}

/// Types [text], waits out the 400 ms debounce, and lets the answer land.
/// Nothing is sent before the debounce ends.
Future<void> _search(WidgetTester tester, Key key, String text) async {
  await tester.enterText(find.byKey(key), text);
  await tester.pump(const Duration(milliseconds: 399));
  await tester.pump(const Duration(milliseconds: 2));
  await tester.pumpAndSettle();
}

/// The search box is as tall as the control beside it in the header row.
void _sameHeight(WidgetTester tester, Key search, Key neighbour) {
  final field = find.descendant(
    of: find.byKey(search),
    matching: find.byType(InputDecorator),
  );
  final other = find.descendant(
    of: find.byKey(neighbour),
    matching: find.byType(InputDecorator),
  );
  expect(
    tester.getSize(field.first).height,
    moreOrLessEquals(tester.getSize(other.first).height, epsilon: 1),
  );
}

Future<void> _pump(
  WidgetTester tester,
  Widget child, {
  Size size = const Size(1200, 1000),
}) async {
  tester.view.physicalSize = size;
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);
  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(body: child),
    ),
  );
  await tester.pumpAndSettle();
}

class _Server {
  _Server(this.routes);

  final Map<String, Map<String, Object?> Function(http.Request)> routes;
  final requests = <http.Request>[];
  List<String> permissions = const [];

  List<String> get paths => [
    for (final r in requests)
      if (!r.url.path.endsWith('/me')) r.url.path.replaceFirst('/api/v1', ''),
  ];

  /// The query of the last GET to [path].
  Map<String, String> last(String path) => requests
      .lastWhere((r) => r.url.path.replaceFirst('/api/v1', '') == path)
      .url
      .queryParameters;

  late final api = ApiClient(
    client: MockClient((request) async {
      requests.add(request);
      final path = request.url.path.replaceFirst('/api/v1', '');
      if (path.endsWith('/me')) {
        return _json({
          'data': {
            'id': 'u-1',
            'name': 'Dana',
            'email': 'dana@brightside.example',
            'permissions': permissions,
            'modules': <dynamic>[],
            'organization': {'id': 'org-1', 'name': 'Brightside'},
            'scope': {
              'type': 'organization',
              'id': null,
              'label': 'Brightside',
              'path': ['Brightside'],
            },
          },
        });
      }
      final route = routes[path];
      return route == null
          ? _json({'data': <Object>[]})
          : _json(route(request));
    }),
  );

  Future<Session> session(WidgetTester tester, List<String> grants) async {
    permissions = grants;
    final session = Session(api);
    await tester.runAsync(() => session.adopt('tok'));
    return session;
  }
}

http.Response _json(Object body, [int status = 200]) => http.Response(
  jsonEncode(body),
  status,
  headers: {'content-type': 'application/json'},
);

Map<String, Object?> _review(String id, String name, String comment) => {
  'id': id,
  'reviewer_name': name,
  'rating': 4,
  'comment': comment,
  'reviewed_at': '2026-10-01T09:00:00Z',
  'reply': null,
};

Map<String, Object?> _activity(int i, String path) => {
  'id': i,
  'at': '2026-10-21T10:${(i % 60).toString().padLeft(2, '0')}:00Z',
  'method': 'POST',
  'path': path,
  'status': 201,
  'who': 'Dana',
  'user_id': 'u-1',
  'key_id': 'k-1',
  'key_name': 'Claude',
};

Map<String, Object?> _invoice(String id, String number, String to) => {
  'id': id,
  'number': number,
  'status': 'sent',
  'bill_to': {'name': to},
  'total_cents': 10000,
  'amount_due_cents': 10000,
  'due_on': '2026-11-01',
};

Map<String, Object?> _sheet(String id, String title) => {
  'id': id,
  'title': title,
  'period_from': '2026-10-01',
  'period_to': '2026-10-31',
  'rows_count': 0,
  'columns': <Object>[],
  'rows': <Object>[],
};

Map<String, Object?> _bill(String id, String place) => {
  'id': id,
  'place': place,
  'period_from': '2026-10-01',
  'period_to': '2026-10-31',
  'lines': <Object>[],
  'total_cents': 1000,
  'has_image': false,
};

Map<String, Object?> _post(String id, String body) => {
  'id': id,
  'body': body,
  'created_at': '2026-10-20T10:00:00Z',
  'targets': [
    {
      'id': 't-$id',
      'status': 'sent',
      'account': 'Brightside Page',
      'provider_label': 'Facebook',
    },
  ],
};
