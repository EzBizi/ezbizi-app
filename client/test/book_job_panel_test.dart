import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/widgets/book_job_panel.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// The shared Book panel, design 153 options 1 and 4. It replaced
/// book_lead_panel: the lead tests here kept their claims (nothing is
/// preselected when the number matches; the lead is evidence) against it.
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

  test('the booked line formats the job time in words', () {
    final local = DateTime(2026, 9, 17, 14, 0).toUtc().toIso8601String();
    expect(formatJobWhen(local), 'Thu 17 Sep, 2 PM');
    expect(formatJobWhen(null), '');
  });

  testWidgets('options load for the ZIP, and the one matching area picks '
      'itself', (tester) async {
    await _pump(tester, prefill: _fromLead());

    final first = _optionsAsked.first;
    expect(first['franchise_id'], 'fr-1');
    expect(first['zip'], '06105');
    expect(
      _optionsAsked.any((q) => q['service_area_id'] == 'a-north'),
      isTrue,
      reason: 'the preselected area is asked for, for who covers it',
    );
    expect(find.byKey(const ValueKey('book-done-location')), findsOneWidget);
    expect(find.text('1 area matches 06105'), findsOneWidget);

    _optionsAsked.clear();
    await tester.enterText(find.widgetWithText(TextField, 'ZIP'), '10001');
    await tester.pumpAndSettle();
    expect(_optionsAsked.map((q) => q['zip']), contains('10001'));
  });

  testWidgets('a job type is required only when the branch books into Workiz', (
    tester,
  ) async {
    await _pump(tester, prefill: _fromLead(), workiz: true);
    expect(_enabled(tester, 'book-unscheduled'), isFalse);
    expect(
      tester.widget<Text>(find.byKey(const ValueKey('book-needs'))).data,
      'Needs: Job type, Start',
    );

    await _pick(tester, 'book-job-type', 'Chimney sweep');
    expect(_enabled(tester, 'book-unscheduled'), isTrue);

    await _pump(tester, prefill: _fromLead(), workiz: false);
    expect(_enabled(tester, 'book-unscheduled'), isTrue);
  });

  testWidgets('with matches nothing is chosen and Book waits; choosing '
      'one books for that customer', (tester) async {
    await _pump(tester, prefill: _fromLead(matches: _twoMatches));

    expect(find.byKey(const ValueKey('book-match-c-12')), findsOneWidget);
    expect(find.textContaining('Nothing is chosen for you'), findsOneWidget);
    expect(_enabled(tester, 'book-unscheduled'), isFalse);

    await _tapKey(tester, 'book-match-c-12');
    expect(find.byKey(const ValueKey('book-client-card')), findsOneWidget);
    expect(find.byKey(const ValueKey('book-name')), findsNothing);

    await _tapKey(tester, 'book-unscheduled');
    final sent = _bookings.single;
    expect(sent['customer_id'], 'c-12');
    expect(sent.containsKey('customer'), isFalse);
    expect(sent.containsKey('location'), isFalse);
    expect(sent['lead_id'], 'lead-1');
  });

  testWidgets('a new client sends the customer and the location, and the '
      'lead stays as it was', (tester) async {
    final lead = Map<String, dynamic>.from(_lead);
    await _pump(tester, prefill: _fromLead(lead: lead));

    await tester.enterText(
      find.byKey(const ValueKey('book-name')),
      'Jane A. Doe',
    );
    await _tapKey(tester, 'book-add-phone');
    await tester.enterText(
      find.byKey(const ValueKey('book-more-phone-0')),
      '+18605550100',
    );
    await tester.pumpAndSettle();
    await _tapKey(tester, 'book-unscheduled');

    final sent = _bookings.single;
    final customer = sent['customer'] as Map;
    expect(customer['name'], 'Jane A. Doe');
    expect(customer['more_phones'], ['+18605550100']);
    expect((sent['location'] as Map)['postal_code'], '06105');
    expect((sent['location'] as Map)['address_line1'], '12 Elm St');
    expect(sent['service_area_id'], 'a-north');
    expect(sent['title'], 'Fireplace chimney');
    expect(
      sent['job_source'],
      'Google',
      reason: "the lead's source, as listed",
    );
    expect(sent['status'], 'Submitted', reason: "the branch's first status");
    expect(sent['scheduled'], isFalse);
    expect(sent.containsKey('start'), isFalse);
    expect(sent.containsKey('recurrence'), isFalse);
    expect(lead['contact_name'], 'Jane Doe', reason: 'the lead is evidence');
  });

  testWidgets('a scheduled booking sends start, end and the repeat', (
    tester,
  ) async {
    await _pump(tester, prefill: _fromLead());

    await _tapKey(tester, 'book-suggestion-use');
    await _pick(tester, 'book-repeat', 'Every month');
    await _tapKey(tester, 'book-submit');

    final sent = _bookings.single;
    expect(sent['scheduled'], isTrue);
    expect(sent['start'], '2026-10-28 10:00');
    expect(sent['end'], '2026-10-28 12:00');
    expect(sent['all_day'], isFalse);
    expect(sent['recurrence'], {'every': 1, 'unit': 'month'});
  });

  testWidgets('only technicians who cover the area are offered', (
    tester,
  ) async {
    await _pump(tester, prefill: _fromLead());

    expect(find.byKey(const ValueKey('book-tech-t-sam')), findsOneWidget);
    expect(find.byKey(const ValueKey('book-tech-t-ana')), findsNothing);

    await _tapKey(tester, 'book-tech-t-sam');
    await _tapKey(tester, 'book-unscheduled');
    expect(_bookings.single['technician_ids'], ['t-sam']);
  });

  testWidgets('sent to Workiz says so', (tester) async {
    await _pump(tester, prefill: _fromLead(), answer: 'created');
    await _tapKey(tester, 'book-unscheduled');
    expect(
      find.textContaining('Booked in EzBizi · Sent to Workiz ✓'),
      findsOneWidget,
    );
    expect(find.byKey(const ValueKey('book-retry-workiz')), findsNothing);
    expect(find.byKey(const ValueKey('book-done')), findsOneWidget);
  });

  testWidgets('a Workiz refusal stays with its reason, and Retry Workiz '
      'tries again', (tester) async {
    await _pump(tester, prefill: _fromLead(), answer: 'refused', workiz: true);
    await _pick(tester, 'book-job-type', 'Chimney sweep');
    await _tapKey(tester, 'book-tech-t-sam');
    await _tapKey(tester, 'book-unscheduled');

    expect(find.text('Booked in EzBizi · Workiz refused it'), findsOneWidget);
    expect(find.text('Sam Ortiz: not in this area'), findsOneWidget);

    await _tapKey(tester, 'book-retry-workiz');
    expect(_retries.single, {
      'technician_ids': ['t-sam'],
    });
    expect(find.textContaining('Sent to Workiz ✓'), findsOneWidget);
  });

  testWidgets('a branch without Workiz just says Booked', (tester) async {
    await _pump(tester, prefill: _fromLead(), answer: 'no_workiz');
    await _tapKey(tester, 'book-unscheduled');
    expect(find.text('Booked'), findsOneWidget);
  });

  testWidgets('fields Workiz cannot take are tagged on a Workiz branch only', (
    tester,
  ) async {
    await _pump(tester, prefill: _fromLead(), workiz: true);
    expect(
      find.byKey(const ValueKey('ezbizi-only-description')),
      findsOneWidget,
    );
    expect(find.byKey(const ValueKey('ezbizi-only-company')), findsOneWidget);

    await _pump(tester, prefill: _fromLead(), workiz: false);
    expect(find.text('EzBizi only'), findsNothing);
  });

  testWidgets('after booking the tags follow the server list', (tester) async {
    await _pump(tester, prefill: _fromLead(), workiz: true);
    await _pick(tester, 'book-job-type', 'Chimney sweep');
    await _tapKey(tester, 'book-unscheduled');
    expect(
      find.byKey(const ValueKey('ezbizi-only-description')),
      findsOneWidget,
    );
    expect(
      find.byKey(const ValueKey('ezbizi-only-company')),
      findsNothing,
      reason: 'the answer listed only description and recurrence',
    );
  });

  testWidgets('from a customer: that customer and their saved address', (
    tester,
  ) async {
    await _pump(
      tester,
      prefill: const BookPrefill(customerId: 'c-12', franchiseId: 'fr-1'),
    );
    expect(find.byKey(const ValueKey('book-client-card')), findsOneWidget);
    expect(find.textContaining('Jane Doe'), findsWidgets);
    expect(find.text('9 Oak St, Hartford, CT 06105'), findsOneWidget);
    expect(_optionsAsked.last['zip'], '06105');
  });

  testWidgets('from a call or text: the number is the new client phone', (
    tester,
  ) async {
    await _pump(tester, prefill: const BookPrefill(phone: '+18605550123'));
    expect(
      tester
          .widget<TextField>(find.byKey(const ValueKey('book-phone')))
          .controller!
          .text,
      '+18605550123',
    );
  });

  testWidgets('from Jobs: an empty panel, the only branch chosen', (
    tester,
  ) async {
    await _pump(tester, prefill: const BookPrefill());
    expect(find.text('Book a job'), findsOneWidget);
    expect(find.byKey(const ValueKey('book-new-client')), findsOneWidget);
    expect(_optionsAsked.first['franchise_id'], 'fr-1');
  });

  testWidgets('on a 390x844 phone: stacked section cards, nothing overflows', (
    tester,
  ) async {
    await _pump(tester, prefill: _fromLead(), size: const Size(390, 844));
    for (final id in ['client', 'location', 'details', 'schedule']) {
      expect(find.byKey(ValueKey('book-section-$id')), findsOneWidget);
    }
    expect(
      find.byKey(const ValueKey('book-title')),
      findsNothing,
      reason: 'a closed card shows its summary',
    );
    await tester.tap(find.text('Job details'));
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('book-title')), findsOneWidget);
    await tester.tap(find.text('Scheduled').first);
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('book-unscheduled')), findsOneWidget);
    expect(tester.takeException(), isNull);
  });
}

BookPrefill _fromLead({
  Map<String, dynamic> lead = _lead,
  List<Map<String, dynamic>> matches = const [],
}) => BookPrefill.fromLead(
  lead,
  matches: matches,
  said: const ['📍 12 Elm St, Hartford, Connecticut 06105'],
);

const _twoMatches = [
  {'id': 'c-12', 'number': 'C-12', 'name': 'Jane Doe', 'phone': '+15132267716'},
  {'id': 'c-88', 'number': 'C-88', 'name': 'J. Doe', 'phone': '+15132267716'},
];

const _lead = {
  'id': 'lead-1',
  'contact_name': 'Jane Doe',
  'phone': '+1 (513) 226-7716',
  'email': null,
  'service': 'fireplace_chimney',
  'source': 'google',
  'status': 'new',
  'franchise': {'id': 'fr-1', 'name': 'Connecticut'},
};

final _bookings = <Map<String, dynamic>>[];
final _retries = <Map<String, dynamic>>[];
final _optionsAsked = <Map<String, String>>[];

bool _enabled(WidgetTester tester, String key) =>
    tester.widget<ButtonStyleButton>(find.byKey(ValueKey(key))).enabled;

Future<void> _tapKey(WidgetTester tester, String key) async {
  await tester.ensureVisible(find.byKey(ValueKey(key)));
  await tester.pumpAndSettle();
  await tester.tap(find.byKey(ValueKey(key)));
  await tester.pumpAndSettle();
}

Future<void> _pick(WidgetTester tester, String key, String label) async {
  await _tapKey(tester, key);
  await tester.tap(find.text(label).last);
  await tester.pumpAndSettle();
}

http.Response _json(Object body, [int status = 200]) => http.Response(
  jsonEncode(body),
  status,
  headers: {'content-type': 'application/json'},
);

Map<String, dynamic> _workiz(String status) => switch (status) {
  'created' => {
    'status': 'created',
    'serial_id': 'W-77',
    'assigned': ['Sam Ortiz'],
  },
  'refused' => {
    'status': 'refused',
    'refused': 'Sam Ortiz: not in this area',
    'serial_id': 'W-77',
    'assigned': <String>[],
  },
  _ => {'status': 'no_workiz'},
};

Future<void> _pump(
  WidgetTester tester, {
  required BookPrefill prefill,
  bool workiz = false,
  String answer = 'no_workiz',
  Size size = const Size(1200, 1600),
}) async {
  _bookings.clear();
  _retries.clear();
  _optionsAsked.clear();
  tester.view.physicalSize = size;
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(
    client: MockClient((request) async {
      final path = request.url.path;
      final q = request.url.queryParameters;
      if (path.endsWith('/me')) return _json({'data': _me});
      if (path.endsWith('/jobs/book/options')) {
        _optionsAsked.add(q);
        final area = q['service_area_id'];
        return _json({
          'data': {
            'statuses': ['Submitted', 'Done'],
            'workiz': workiz,
            'job_types': ['Chimney sweep', 'Inspection'],
            'job_sources': ['Google', 'Yelp'],
            'service_areas': [
              {
                'id': 'a-north',
                'name': 'North',
                'matches_zip': q['zip'] == '06105',
              },
              {'id': 'a-south', 'name': 'South', 'matches_zip': false},
            ],
            'external_companies': <String>[],
            'custom_fields': [
              {'name': 'Gate code', 'type': 'text', 'options': <String>[]},
            ],
            'technicians': [
              {
                'id': 't-sam',
                'name': 'Sam Ortiz',
                'user_id': null,
                'covers_area': area == null ? null : area == 'a-north',
              },
              {
                'id': 't-ana',
                'name': 'Ana Ruiz',
                'user_id': null,
                'covers_area': area == null ? null : false,
              },
            ],
          },
        });
      }
      if (path.endsWith('/scheduling/availability/suggestion')) {
        return _json({
          'data': {
            'earliest': {
              'date': '2026-10-28',
              'window': {'start_time': '10:00', 'end_time': '12:00'},
            },
            'suggested': {
              'date': '2026-10-28',
              'window': {'start_time': '10:00:00', 'end_time': '12:00:00'},
              'technicians': ['Sam Ortiz'],
            },
          },
        });
      }
      if (path.endsWith('/customers/c-12')) {
        return _json({
          'data': {
            'id': 'c-12',
            'number': 'C-12',
            'name': 'Jane Doe',
            'phone': '+15132267716',
            'address': {
              'line1': '9 Oak St',
              'city': 'Hartford',
              'state': 'CT',
              'postal_code': '06105',
            },
          },
        });
      }
      if (request.method == 'POST' && path.endsWith('/jobs/book')) {
        _bookings.add(
          (jsonDecode(request.body) as Map).cast<String, dynamic>(),
        );
        return _json({
          'data': {
            'job': {'id': 'job-1', 'number': 'J-0142'},
            'workiz': _workiz(answer),
            'ezbizi_only': ['description', 'recurrence'],
          },
        }, 201);
      }
      if (request.method == 'POST' &&
          path.endsWith('/jobs/job-1/book/workiz')) {
        _retries.add((jsonDecode(request.body) as Map).cast<String, dynamic>());
        return _json({
          'data': {
            'job': {'id': 'job-1', 'number': 'J-0142'},
            'workiz': _workiz('created'),
          },
        });
      }
      return _json({}, 404);
    }),
  );

  final session = Session(api);
  await tester.runAsync(() => session.adopt('tok'));

  await tester.pumpWidget(
    MaterialApp(
      key: UniqueKey(),
      theme: EzBiziTheme.light(),
      home: Scaffold(
        body: Builder(
          builder: (context) => TextButton(
            onPressed: () => showBookJobPanel(
              context,
              api: api,
              session: session,
              prefill: prefill,
            ),
            child: const Text('open'),
          ),
        ),
      ),
    ),
  );
  await tester.tap(find.text('open'));
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
