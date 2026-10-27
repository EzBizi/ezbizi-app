import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/customers/customer_detail_panel.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Customers opens the shared Book panel (design 153) on that customer.
/// Leads, Jobs and Communication are asserted in book_job_panel_test,
/// jobs_screen_test and phone_call_tab_test.
void main() {
  testWidgets('a customer opens Book a job with themselves chosen', (
    tester,
  ) async {
    tester.view.physicalSize = const Size(1200, 1400);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);
    final asked = <String>[];

    final api = ApiClient(
      client: MockClient((request) async {
        final path = request.url.path;
        asked.add('${request.url.path}?${request.url.query}');
        if (path.endsWith('/me')) return _json({'data': _me});
        if (path.endsWith('/customers/c-1')) {
          return _json({
            'data': {
              'id': 'c-1',
              'number': 'C-2041',
              'name': 'Riley Park',
              'phone': '+18605550100',
              'is_active': true,
              'jobs_count': 0,
              'workiz_client_ids': <int>[],
              'owning_franchise': {'id': 'fr-1', 'name': 'Harbor Chimney'},
              'address': {
                'line1': '9 Oak St',
                'city': 'Hartford',
                'state': 'CT',
                'postal_code': '06105',
              },
            },
          });
        }
        return _json({'data': <dynamic>[]});
      }),
    );
    final session = Session(api);
    await tester.runAsync(() => session.adopt('tok'));

    await tester.pumpWidget(
      MaterialApp(
        theme: EzBiziTheme.light(),
        home: Scaffold(
          body: CustomerDetailPanel(
            api: api,
            session: session,
            id: 'c-1',
            onDone: (_) {},
          ),
        ),
      ),
    );
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const ValueKey('customer-book-job')));
    await tester.pumpAndSettle();

    expect(find.byKey(const ValueKey('book-client-card')), findsOneWidget);
    expect(find.text('9 Oak St, Hartford, CT 06105'), findsOneWidget);
    expect(
      asked.any(
        (a) =>
            a.contains('/jobs/book/options') &&
            a.contains('franchise_id=fr-1') &&
            a.contains('zip=06105'),
      ),
      isTrue,
    );
  });
}

http.Response _json(Object body) => http.Response(
  jsonEncode(body),
  200,
  headers: {'content-type': 'application/json'},
);

const _me = {
  'id': 'u-1',
  'name': 'Dana Reyes',
  'email': 'dana@example.com',
  'role': {'name': 'Office'},
  'has_avatar': false,
  'preferences': <String, dynamic>{},
  'permissions': ['customers.view', 'customers.edit', 'jobs.create'],
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
  'companies': [
    {'id': 'co-1', 'name': 'Harbor Group', 'parent_company_id': null},
  ],
  'franchises': [
    {'id': 'fr-1', 'name': 'Harbor Chimney', 'company_id': 'co-1'},
  ],
};
