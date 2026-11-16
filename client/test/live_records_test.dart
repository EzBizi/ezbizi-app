import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/live_changes.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/customer_experience/complaints_tab.dart';
import 'package:ezbizi_client/screens/email/gmail_inbox.dart';
import 'package:ezbizi_client/screens/google_campaigns_view.dart';
import 'package:ezbizi_client/screens/marketing/social_media_tab.dart';
import 'package:ezbizi_client/screens/customers/memberships_dashboard.dart';
import 'package:ezbizi_client/screens/customers/memberships_members.dart';
import 'package:ezbizi_client/screens/customers/memberships_promotions.dart';
import 'package:ezbizi_client/screens/customers/memberships_settings.dart';
import 'package:ezbizi_client/screens/inspection/reports_tab.dart'
    as inspection;
import 'package:ezbizi_client/screens/jobs/estimates_tab.dart';
import 'package:ezbizi_client/screens/services/service_list.dart';
import 'package:ezbizi_client/screens/services/service_pane.dart';
import 'package:ezbizi_client/screens/services/service_settings.dart';
import 'package:ezbizi_client/screens/fleet/vehicle_pane.dart';
import 'package:ezbizi_client/screens/hr/records_tab.dart';
import 'package:ezbizi_client/screens/phone/records_tab.dart' as phone;
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// An OPEN record follows the change feed in place (Jose, 2026-11-16: every
/// module live): somebody else's change shows on the vehicle, the person or
/// the complaint being read, and never over what is being typed.
void main() {
  testWidgets('Vehicle record: a change elsewhere shows in place; a typed '
      'field is kept', (tester) async {
    var vehicle = _vehicle('Van One', 'Ford');
    final s = await _pump(
      tester,
      permissions: [
        'fleet_management.view',
        'fleet_management.vehicles.view',
        'fleet_management.edit',
      ],
      handler: (r) => r.url.path.endsWith('/vehicles/v-1')
          ? _json({'data': vehicle})
          : null,
      home: (api, session) => VehiclePane(
        api: api,
        session: session,
        vehicle: vehicle,
        onBack: () {},
        onChanged: (_) {},
        onDeleted: () {},
      ),
    );
    expect(find.text('Van One'), findsWidgets);

    vehicle = _vehicle('Van One (Workiz)', 'Ram');
    await _tick(tester, s, 'vehicles', 'v-1');
    expect(find.text('Van One (Workiz)'), findsWidgets);
    expect(_text(tester, 'Make'), 'Ram');

    final reads = s.reads['/vehicles/v-1'];
    await _tick(tester, s, 'vehicles', 'v-2');
    expect(
      s.reads['/vehicles/v-1'],
      reads,
      reason: 'another van reads nothing',
    );

    await tester.enterText(find.widgetWithText(TextField, 'Make'), 'Nissan');
    vehicle = _vehicle('Van One (sold)', 'Chevy');
    await _tick(tester, s, 'vehicles', 'v-1');
    expect(find.text('Van One (sold)'), findsWidgets);
    expect(_text(tester, 'Make'), 'Nissan', reason: 'what is typed stays');
  });

  testWidgets('HR record: a change elsewhere re-reads the open person, not '
      'while a box is being typed in', (tester) async {
    var phoneNumber = '(203) 555-0148';
    final s = await _pump(
      tester,
      size: const Size(1400, 1200),
      permissions: ['hr.view', 'hr.edit', 'hr.records.view'],
      handler: (r) {
        final path = r.url.path;
        if (path.endsWith('/hr/people/scope-options')) {
          return _json({
            'data': {
              'organization': {'id': 'org-1', 'name': 'Everdoors'},
              'companies': <dynamic>[],
              'franchises': <dynamic>[],
              'roles': <dynamic>[],
            },
          });
        }
        if (path.endsWith('/hr/people/u-2')) {
          return _json({
            'data': {
              ..._person,
              'middle_name': null,
              'phone': phoneNumber,
              'address': '41 Elm St, Stamford CT',
              'gender': null,
              'date_of_birth': null,
              'started_on': null,
              'emergency_contacts': <dynamic>[],
              'notes': <dynamic>[],
              'equipment': <dynamic>[],
              'pay_rates': <dynamic>[],
            },
          });
        }
        if (path.endsWith('/hr/people')) {
          return _json({
            'data': [_person],
            'meta': {
              'total': 1,
              'per_page': 50,
              'current_page': 1,
              'last_page': 1,
            },
          });
        }
        return null;
      },
      home: (api, session) => RecordsTab(api: api, session: session),
    );
    await tester.tap(find.text('Bea Okafor').first);
    await tester.pumpAndSettle();
    final opened = s.reads['/hr/people/u-2'] ?? 0;
    expect(opened, greaterThan(0));

    phoneNumber = '(203) 555-0199';
    await _tick(tester, s, 'people', 'u-2');
    expect(s.reads['/hr/people/u-2'], opened + 1);
    expect(find.textContaining('555-0199'), findsWidgets);

    await _tick(tester, s, 'people', 'u-9');
    expect(s.reads['/hr/people/u-2'], opened + 1, reason: 'somebody else');

    await tester.tap(find.byType(TextField).last);
    await tester.pump();
    await _tick(tester, s, 'people', 'u-2');
    expect(
      s.reads['/hr/people/u-2'],
      opened + 1,
      reason: 'a box was gone into: its draft is not read over',
    );
  });

  testWidgets('Complaint: a note added elsewhere shows on the open one', (
    tester,
  ) async {
    final notes = <Map<String, dynamic>>[];
    final s = await _pump(
      tester,
      size: const Size(1400, 1000),
      permissions: [
        'customer_experience.view',
        'customer_experience.complaints.view',
        'customer_experience.complaints.manage',
      ],
      handler: (r) {
        final path = r.url.path;
        if (path.endsWith('/complaints/c-1')) {
          return _json({
            'data': {..._complaint, 'notes': notes},
          });
        }
        if (path.endsWith('/complaints')) {
          return _json({
            'data': [_complaint],
            'meta': {
              'total': 1,
              'per_page': 50,
              'current_page': 1,
              'last_page': 1,
              'counts': {'new': 1, 'open': 1, 'all': 1},
              'people': <dynamic>[],
            },
          });
        }
        return null;
      },
      home: (api, session) => ComplaintsTab(api: api, session: session),
    );
    await tester.tap(find.text('Quinn Harlow').first);
    await tester.pumpAndSettle();
    final opened = s.reads['/complaints/c-1'] ?? 0;
    expect(opened, greaterThan(0));

    notes.add({
      'id': 'n-1',
      'at': '2026-11-16T12:00:00Z',
      'by': 'Wren Ashby',
      'text': 'Called her back, booked a revisit.',
    });
    await _tick(tester, s, 'complaints', 'c-1');
    expect(s.reads['/complaints/c-1'], opened + 1);
    expect(find.textContaining('booked a revisit'), findsOneWidget);

    await _tick(tester, s, 'complaints', 'c-2');
    expect(s.reads['/complaints/c-1'], opened + 1, reason: 'another one');
  });

  testWidgets('Phone Records: a call arriving re-reads every page loaded', (
    tester,
  ) async {
    final s = await _pump(
      tester,
      size: const Size(1400, 1000),
      permissions: ['phone.view', 'phone.records.view'],
      handler: (r) {
        final path = r.url.path;
        final page = r.url.queryParameters['page'];
        if (path.endsWith('/phone/calls')) {
          return _json({
            'data': [
              for (var i = 0; i < (page == '1' ? 200 : 3); i++)
                {..._call, 'id': 'ca-$page-$i'},
            ],
          });
        }
        return null;
      },
      home: (api, session) => phone.RecordsTab(api: api, session: session),
    );
    await tester.scrollUntilVisible(
      find.byKey(const Key('records-load-older')),
      300,
      scrollable: find.byType(Scrollable).first,
    );
    await tester.tap(find.byKey(const Key('records-load-older')));
    await tester.pumpAndSettle();

    final before = s.requests.length;
    await _tick(tester, s, 'phone_calls', 'ca-new');
    final pages = [
      for (final u in s.requests.skip(before))
        if (u.path.endsWith('/phone/calls')) u.queryParameters['page'],
    ];
    expect(pages, ['1', '2'], reason: 'older pages stay and re-read too');
  });

  for (final (name, path, type, permissions, home)
      in <
        (
          String,
          String,
          String,
          List<String>,
          Widget Function(ApiClient, Session),
        )
      >[
        (
          'Memberships dashboard',
          '/memberships',
          'memberships',
          ['memberships.view', 'memberships.dashboard.view'],
          (api, session) => MembershipsDashboard(api: api, session: session),
        ),
        (
          'Memberships members',
          '/memberships',
          'memberships',
          ['memberships.view', 'memberships.members.view'],
          (api, session) => MembershipsMembers(api: api, session: session),
        ),
        (
          'Memberships plans',
          '/memberships/plans',
          'membership_plans',
          ['memberships.view', 'memberships.settings.view'],
          (api, session) => MembershipsSettings(api: api, session: session),
        ),
        (
          'Promotions sent',
          '/memberships/promotions',
          'membership_promotions',
          ['memberships.view', 'memberships.promotions.view'],
          (api, session) => MembershipsPromotions(api: api, session: session),
        ),
        (
          'Estimates',
          '/estimates',
          'estimates',
          ['jobs.view', 'estimates.view', 'jobs.financials'],
          (api, session) => EstimatesTab(api: api, session: session),
        ),
        (
          'Inspection reports',
          '/inspections/reports',
          'inspection_reports',
          ['inspection.view', 'inspection.reports.view'],
          (api, session) => inspection.ReportsTab(api: api, session: session),
        ),
        (
          'Services',
          '/services',
          'services',
          ['services.view', 'services.catalogue.view'],
          (api, session) =>
              ServiceList(api: api, session: session, visible: true),
        ),
        (
          'Service groups and labels',
          '/services/groups',
          'service_taxonomy',
          ['services.view', 'services.settings.view'],
          (api, session) =>
              ServiceSettings(api: api, session: session, visible: true),
        ),
      ]) {
    testWidgets('$name: a change of its type re-reads it quietly', (
      tester,
    ) async {
      final s = await _pump(
        tester,
        size: const Size(1400, 1000),
        permissions: permissions,
        handler: (r) => r.url.path.endsWith('/preview')
            ? _json({
                'data': {'rows': <dynamic>[]},
              })
            : null,
        home: home,
      );
      final before = s.reads[path] ?? 0;
      expect(before, greaterThan(0));
      await _tick(tester, s, type, 'x-1');
      expect(s.reads[path], before + 1);
      expect(find.byType(CircularProgressIndicator), findsNothing);
      await _tick(tester, s, 'leads', 'l-1');
      expect(s.reads[path], before + 1, reason: 'another type reads nothing');
    });
  }

  for (final (name, path, type, permissions, handler, home)
      in <
        (
          String,
          String,
          String,
          List<String>,
          http.Response? Function(http.Request),
          Widget Function(ApiClient, Session),
        )
      >[
        (
          'Marketing posts',
          '/social/posts',
          'social_posts',
          ['marketing.view', 'marketing.dashboard.view'],
          (r) => r.url.path.endsWith('/social/posts')
              ? _json({
                  'data': <dynamic>[],
                  'meta': {'tiles': <String, dynamic>{}},
                })
              : null,
          (api, session) => SocialMediaTab(api: api, session: session),
        ),
        (
          'Ads Manager campaigns',
          '/ads-manager/google/campaigns',
          'ad_schedules',
          ['ads_manager.view'],
          (r) => r.url.path.endsWith('/ads/accounts')
              ? _json({
                  'data': [
                    {'connection_id': 'sc-1', 'display_name': 'A-1 Chimney'},
                  ],
                })
              : r.url.path.endsWith('/campaigns')
              ? _json({
                  'data': {'campaigns': <dynamic>[]},
                })
              : null,
          (api, session) => GoogleCampaignsView(
            api: api,
            session: session,
            sourceSwitch: const SizedBox(),
          ),
        ),
        (
          'Email inbox',
          '/email/inbox',
          'mailboxes',
          ['email.view', 'email.inbox.view'],
          (r) => r.url.path.endsWith('/email/mailboxes')
              ? _json({
                  'data': [
                    {
                      'id': 'mb-1',
                      'address': 'office@example.test',
                      'display_name': 'Office',
                      'provider': 'gmail_oauth',
                      'status': 'connected',
                      'can_read': true,
                    },
                  ],
                })
              : r.url.path.endsWith('/email/inbox')
              ? _json({'data': <dynamic>[], 'meta': <String, dynamic>{}})
              : null,
          (api, session) => GmailInbox(api: api, session: session),
        ),
      ]) {
    testWidgets('$name: a change of its type re-reads it quietly', (
      tester,
    ) async {
      final s = await _pump(
        tester,
        size: const Size(1400, 1000),
        permissions: permissions,
        handler: handler,
        home: home,
      );
      final before = s.reads[path] ?? 0;
      expect(before, greaterThan(0));
      await _tick(tester, s, type, 'x-1');
      expect(s.reads[path], greaterThan(before));
      final after = s.reads[path];
      await _tick(tester, s, 'leads', 'l-1');
      expect(s.reads[path], after, reason: 'another type reads nothing');
    });
  }

  testWidgets('Service record: a price changed elsewhere fills the boxes; a '
      'typed name is kept', (tester) async {
    Map<String, dynamic> service(String name, int price) => {
      'id': 's-1',
      'name': name,
      'description': null,
      'price_minor': price,
      'group': null,
      'label': null,
    };
    var row = service('Chimney sweep', 18900);
    late void Function(Map<String, dynamic>) reopen;
    await _pump(
      tester,
      permissions: ['services.view', 'services.edit'],
      handler: (_) => null,
      home: (api, session) => StatefulBuilder(
        builder: (context, setState) {
          reopen = (r) => setState(() => row = r);
          return ServicePane(
            api: api,
            session: session,
            service: row,
            onBack: () {},
            onChanged: (_) {},
            onDeleted: () {},
          );
        },
      ),
    );
    expect(_text(tester, 'Price'), '189.00');

    reopen(service('Chimney sweep', 19900));
    await tester.pumpAndSettle();
    expect(_text(tester, 'Price'), '199.00', reason: 'nothing typed: fills');

    await tester.enterText(
      find.widgetWithText(TextField, 'Name of service'),
      'Chimney sweep, deluxe',
    );
    reopen(service('Chimney sweep', 20900));
    await tester.pumpAndSettle();
    expect(_text(tester, 'Name of service'), 'Chimney sweep, deluxe');
    expect(_text(tester, 'Price'), '199.00', reason: 'the draft is whole');
  });
}

String _text(WidgetTester tester, String label) => tester
    .widget<TextField>(find.widgetWithText(TextField, label))
    .controller!
    .text;

Map<String, dynamic> _vehicle(String label, String make) => {
  'id': 'v-1',
  'label': label,
  'number': 'V-1',
  'status': 'active',
  'make': make,
  'model': 'Transit',
  'year': 2022,
  'body_type': 'van',
  'fuel_type': 'gas',
  'company': {'id': 'co-1', 'name': 'Everdoors'},
  'franchise': {'id': 'fr-1', 'name': 'Connecticut'},
  'ownership': null,
  'assignment': null,
};

const _person = {
  'id': 'u-2',
  'name': 'Bea Okafor',
  'first_name': 'Bea',
  'last_name': 'Okafor',
  'email': 'bea@example.com',
  'is_active': true,
  'missing': 0,
  'role': {'id': null, 'name': null},
  'assignments': [
    {
      'id': 'a-2',
      'type': 'franchise',
      'id_of_scope': 'fr-1',
      'name': 'Connecticut',
    },
  ],
  'joined_at': '2026-06-04T09:00:00+00:00',
};

const _complaint = {
  'id': 'c-1',
  'kind': 'bad_review',
  'status': 'new',
  'title': 'Quinn Harlow',
  'summary': 'Called twice already.',
  'rating': 2,
  'branch': {'id': 'fr-1', 'name': 'Connecticut'},
  'owner': null,
  'raised_at': '2026-10-19T14:40:00Z',
  'updated_at': '2026-10-19T14:40:00Z',
  'solved_at': null,
  'note_count': 0,
  'source': {'type': 'review', 'id': 'rv-9'},
};

const _call = {
  'id': 'ca-1',
  'number_id': 'n-1',
  'connection_id': 'pc-1',
  'direction': 'inbound',
  'from_e164': '+12035550199',
  'to_e164': '+12035550142',
  'status': 'completed',
  'duration_seconds': 252,
  'voicemail': false,
  'recording_sid': null,
  'customer_id': null,
  'created_at': '2026-08-24T09:14:00+00:00',
};

class _Server {
  List<Map<String, dynamic>> feed = const [];
  int cursor = 1;
  final Map<String, int> reads = {};
  final List<Uri> requests = [];
  late ApiClient api;
}

Future<_Server> _pump(
  WidgetTester tester, {
  required List<String> permissions,
  required http.Response? Function(http.Request request) handler,
  required Widget Function(ApiClient api, Session session) home,
  Size size = const Size(1280, 900),
}) async {
  tester.view.physicalSize = size;
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);
  final s = _Server();
  s.api = ApiClient(
    client: MockClient((request) async {
      final path = request.url.path.replaceFirst('/api/v1', '');
      s.reads[path] = (s.reads[path] ?? 0) + 1;
      s.requests.add(request.url);
      if (path == '/me') {
        return _json({
          'data': {..._me, 'permissions': permissions},
        });
      }
      if (path == '/changes') {
        final since = request.url.queryParameters['since'];
        final out = since == null ? const [] : s.feed;
        s.feed = const [];
        if (out.isNotEmpty) s.cursor++;
        return _json({
          'data': {'cursor': s.cursor, 'reload': false, 'changes': out},
        });
      }
      return handler(request) ?? _json({'data': <dynamic>[]});
    }),
  );
  final session = Session(s.api);
  await tester.runAsync(() => session.adopt('tok'));
  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(body: home(s.api, session)),
    ),
  );
  await tester.pumpAndSettle();
  return s;
}

/// One change of [type]/[id] on the feed, one tick, settled.
Future<void> _tick(
  WidgetTester tester,
  _Server s,
  String type,
  String id,
) async {
  s.feed = [
    {'type': type, 'id': id, 'deleted': false},
  ];
  LiveChanges.of(s.api).poll();
  await tester.pumpAndSettle();
}

http.Response _json(Object body, [int status = 200]) => http.Response(
  jsonEncode(body),
  status,
  headers: const {'content-type': 'application/json'},
);

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
  ],
};
