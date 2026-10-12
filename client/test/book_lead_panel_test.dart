import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/widgets/book_lead_panel.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Booking a lead, approved layout 1 / 4 (d1c69cb5).
///
/// The one that would be missed: with matches, NOTHING is preselected and
/// Book is disabled until somebody says who this is. Attaching a job to the
/// wrong existing customer looks exactly like getting it right.
void main() {
  test('a website form fills the address, a state name becomes its code', () {
    final a = addressFromForm([
      '\u{1F464}First Name: tes\n\n\u{2709}\u{FE0F}Email Address: john@test.com\n\n'
          '\u{1F4CD}Address Line 1: 12 Elm St\n\n\u{1F4CD}Address Line 2 (Optional): Apt 4\n\n'
          '\u{1F4CD}City: Tempe\n\n\u{1F4CD}State: Arizona\n\n\u{1F4CD}Zip Code: 85281',
    ]);
    expect(a.line1, '12 Elm St');
    expect(a.line2, 'Apt 4');
    expect(a.city, 'Tempe');
    expect(a.state, 'AZ');
    expect(a.postal, '85281');
    expect(addressFromForm(['Hi, my chimney leaks']).line1, '');

    // The condensed form (2026-10-12): the address on one line.
    final c = addressFromForm([
      '👤 Nora Vale\n📍 123 Elm St Apt 4, Tempe, Arizona 85281\n🛠️ Cap',
    ]);
    expect(c.line1, '123 Elm St Apt 4');
    expect(c.city, 'Tempe');
    expect(c.state, 'AZ');
    expect(c.postal, '85281');
  });

  testWidgets('with matches nothing is chosen and Book waits; choosing '
      'answers it', (tester) async {
    await _pump(tester, matches: _twoMatches);

    expect(find.byKey(const ValueKey('book-match-new')), findsOneWidget);
    expect(find.byKey(const ValueKey('book-match-c-12')), findsOneWidget);
    expect(_submitEnabled(tester), isFalse, reason: 'nobody has said who');
    expect(find.textContaining('Nothing is chosen for you'), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('book-match-c-12')));
    await tester.pumpAndSettle();

    expect(_submitEnabled(tester), isTrue);
    expect(
      find.byKey(const ValueKey('book-name')),
      findsNothing,
      reason: 'an existing customer needs no new-customer fields',
    );

    // A frame, not settle: the booked panel keeps its spinner until the host
    // pops it, which the real screens do and this harness does not.
    await tester.tap(find.byKey(const ValueKey('book-submit')));
    await tester.pump(const Duration(milliseconds: 200));

    expect(_bookings.single['customer_id'], 'c-12');
    expect(_bookings.single.containsKey('customer'), isFalse);
  });

  testWidgets('with no match nothing is asked: straight to a new customer', (
    tester,
  ) async {
    await _pump(tester, matches: const []);

    expect(find.byKey(const ValueKey('book-match-new')), findsNothing);
    expect(find.textContaining('already ha'), findsNothing);
    expect(find.byKey(const ValueKey('book-name')), findsOneWidget);
    expect(
      _submitEnabled(tester),
      isTrue,
      reason: 'franchise prefilled from the lead, status from the tenant',
    );
  });

  /// The design says the customer fields edit the customer, not the lead.
  /// Only a test keeps that claim.
  testWidgets('editing the customer name changes the customer being created '
      'and not what the lead reports', (tester) async {
    final lead = Map<String, dynamic>.from(_lead);
    Map<String, dynamic>? booked;

    await _pump(
      tester,
      matches: const [],
      lead: lead,
      onBooked: (r) => booked = r,
    );

    await tester.enterText(
      find.byKey(const ValueKey('book-name')),
      'Jane A. Doe',
    );
    // A frame, not settle: the booked panel keeps its spinner until the host
    // pops it, which the real screens do and this harness does not.
    await tester.tap(find.byKey(const ValueKey('book-submit')));
    await tester.pump(const Duration(milliseconds: 200));

    final sent = _bookings.single;
    expect((sent['customer'] as Map)['name'], 'Jane A. Doe');
    expect(sent['franchise_id'], 'fr-1');
    expect(sent['status'], 'scheduled', reason: 'the tenant\'s first status');
    expect(lead['contact_name'], 'Jane Doe', reason: 'the lead is evidence');
    expect(booked?['job'], isNotNull);
  });

  /// Jose, 2026-09-30, typing "92 summerhill rd" into one Address box: "it
  /// should show me suggested addresses", and "we should also have more field
  /// for the address, city, state, zip code". The customer is created with
  /// every part the lookup found, not the street alone.
  testWidgets('a suggested address fills the parts, and the new customer '
      'gets all of them', (tester) async {
    await _pump(tester, matches: const []);

    expect(find.widgetWithText(TextField, 'City'), findsOneWidget);
    expect(find.widgetWithText(TextField, 'ZIP'), findsOneWidget);

    await tester.enterText(
      find.widgetWithText(TextField, 'Find an address'),
      '14 elm ridge',
    );
    await tester.pump(const Duration(milliseconds: 300));
    await tester.pumpAndSettle();
    await tester.tap(find.text('14 Elm Ridge Rd, Hartford, CT 06105'));
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const ValueKey('book-submit')));
    await tester.pump(const Duration(milliseconds: 200));

    final customer = _bookings.single['customer'] as Map;
    expect(customer['address_line1'], '14 Elm Ridge Rd');
    expect(customer['city'], 'Hartford');
    expect(customer['state'], 'CT');
    expect(customer['postal_code'], '06105');
  });

  test('the booked line formats the job time in words', () {
    final local = DateTime(2026, 9, 17, 14, 0).toUtc().toIso8601String();
    expect(formatJobWhen(local), 'Thu 17 Sep, 2 PM');
    expect(formatJobWhen(null), '');
  });
}

const _twoMatches = [
  {'id': 'c-12', 'number': 'C-12', 'name': 'Jane Doe', 'phone': '+15132267716'},
  {
    'id': 'c-88',
    'number': 'C-88',
    'name': 'J. Doe (Ash Lane)',
    'phone': '+15132267716',
  },
];

const _lead = {
  'id': 'lead-1',
  'contact_name': 'Jane Doe',
  'phone': '+1 (513) 226-7716',
  'email': null,
  'service': 'fireplace_chimney',
  'status': 'new',
  'franchise': {'id': 'fr-1', 'name': 'Connecticut'},
};

final _bookings = <Map<String, dynamic>>[];

bool _submitEnabled(WidgetTester tester) => tester
    .widget<FilledButton>(find.byKey(const ValueKey('book-submit')))
    .enabled;

Future<void> _pump(
  WidgetTester tester, {
  required List<Map<String, dynamic>> matches,
  Map<String, dynamic> lead = _lead,
  void Function(Map<String, dynamic>)? onBooked,
}) async {
  _bookings.clear();
  tester.view.physicalSize = const Size(700, 1200);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(
    client: MockClient((request) async {
      if (request.url.path.endsWith('/me')) {
        return http.Response(
          jsonEncode({'data': _me}),
          200,
          headers: {'content-type': 'application/json'},
        );
      }
      if (request.url.path.endsWith('/settings')) {
        return http.Response(
          jsonEncode({
            'data': [
              {
                'key': 'jobs.statuses',
                'value': [
                  {'value': 'scheduled', 'label': 'Scheduled'},
                  {'value': 'done', 'label': 'Done'},
                ],
              },
            ],
          }),
          200,
          headers: {'content-type': 'application/json'},
        );
      }
      if (request.url.path.endsWith('/address-lookup')) {
        return http.Response(
          jsonEncode({
            'data': [
              {
                'label': '14 Elm Ridge Rd, Hartford, CT 06105',
                'address_line1': '14 Elm Ridge Rd',
                'city': 'Hartford',
                'state': 'CT',
                'postal_code': '06105',
              },
            ],
          }),
          200,
          headers: {'content-type': 'application/json'},
        );
      }
      if (request.method == 'POST' && request.url.path.endsWith('/book')) {
        final body = (jsonDecode(request.body) as Map).cast<String, dynamic>();
        _bookings.add(body);
        return http.Response(
          jsonEncode({
            'data': {
              'lead': {
                ...lead,
                'status': 'booked',
                'job': {'id': 'job-1', 'number': '0142'},
              },
              'customer': {'id': body['customer_id'] ?? 'c-new', 'name': 'x'},
              'customer_created': body['customer_id'] == null,
              'job': {
                'id': 'job-1',
                'number': '0142',
                'title': body['title'],
                'status': body['status'],
                'scheduled_for': null,
              },
            },
          }),
          201,
          headers: {'content-type': 'application/json'},
        );
      }
      return http.Response(
        '{}',
        404,
        headers: {'content-type': 'application/json'},
      );
    }),
  );

  final session = Session(api);
  await tester.runAsync(() => session.adopt('tok'));

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(
        body: SingleChildScrollView(
          child: BookLeadPanel(
            api: api,
            session: session,
            lead: lead,
            matches: matches,
            onBooked: onBooked ?? (_) {},
          ),
        ),
      ),
    ),
  );
  await tester.pumpAndSettle();
}

const _me = {
  'id': 'u-1',
  'name': 'Jose',
  'email': 'jose@example.com',
  'role': {'name': 'Manager'},
  'has_avatar': false,
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
    {'id': 'co-1', 'name': 'Everdoors', 'parent_company_id': null},
  ],
  'franchises': [
    {'id': 'fr-1', 'name': 'Connecticut', 'company_id': 'co-1'},
  ],
  'modules': [
    {'key': 'leads', 'label': 'Leads', 'available': true, 'reachable': true},
    {'key': 'jobs', 'label': 'Jobs', 'available': true, 'reachable': true},
  ],
  'permissions': [
    'leads.view',
    'leads.edit',
    'jobs.create',
    'customers.create',
  ],
};
