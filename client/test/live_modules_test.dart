import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/live_changes.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/customers/customer_detail_panel.dart';
import 'package:ezbizi_client/screens/customers_screen.dart';
import 'package:ezbizi_client/screens/fleet_screen.dart';
import 'package:ezbizi_client/screens/hr/contracts_tab.dart';
import 'package:ezbizi_client/screens/hr/invitations_tab.dart';
import 'package:ezbizi_client/screens/hr/onboarding_tab.dart';
import 'package:ezbizi_client/screens/hr/pto_tab.dart';
import 'package:ezbizi_client/screens/hr/records_tab.dart';
import 'package:ezbizi_client/screens/hr/work_hours_approval_tab.dart';
import 'package:ezbizi_client/screens/inventory/item_list.dart';
import 'package:ezbizi_client/screens/inventory/location_list.dart';
import 'package:ezbizi_client/screens/inventory/order_list.dart';
import 'package:ezbizi_client/screens/leads_screen.dart';
import 'package:ezbizi_client/screens/project_board_screen.dart';
import 'package:ezbizi_client/screens/scheduling/availability_pane.dart';
import 'package:ezbizi_client/screens/scheduling/rescheduling_pane.dart';
import 'package:ezbizi_client/screens/scheduling/schedule_pane.dart';
import 'package:ezbizi_client/screens/financials/financial_adjustments.dart';
import 'package:ezbizi_client/screens/financials/financial_bills.dart';
import 'package:ezbizi_client/screens/financials/invoice_panel.dart';
import 'package:ezbizi_client/screens/financials/invoices_tab.dart';
import 'package:ezbizi_client/core/timestamps.dart';
import 'package:ezbizi_client/screens/phone/call_history_pane.dart';
import 'package:ezbizi_client/screens/phone/voicemails_pane.dart';
import 'package:ezbizi_client/screens/phone/records_tab.dart' as phone;
import 'package:ezbizi_client/screens/reviews_screen.dart';
import 'package:ezbizi_client/screens/customer_experience/complaints_tab.dart';
import 'package:ezbizi_client/screens/automations_screen.dart';
import 'package:ezbizi_client/screens/marketing/newsletter_tab.dart';
import 'package:ezbizi_client/screens/marketing/qr_codes_tab.dart';
import 'package:ezbizi_client/screens/dashboard_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Every module follows the change feed (Jose, 2026-11-16: "The tab needs to
/// be live basically. This should go tbh for every module in the app"): a
/// write made elsewhere shows on the open screen at the next tick, with no
/// reload, and a scrolled list stays where it was.
void main() {
  testWidgets('Customers: a changed customer shows, scroll kept', (
    tester,
  ) async {
    final rows = [
      for (var i = 0; i < 40; i++)
        {
          'id': 'c$i',
          'name': 'Customer $i',
          'number': 'C-$i',
          'email': null,
          'phone': null,
          'city': 'Dallas',
          'state': 'TX',
          'is_active': true,
          'jobs_count': 0,
          'owning_franchise': {
            'id': 'fr-1',
            'name': 'Texas',
            'company': {'id': 'co-1', 'name': 'A-1'},
          },
        },
    ];
    final s = await _pump(
      tester,
      permissions: ['customers.view'],
      handler: (r) => r.url.path.endsWith('/customers')
          ? _json({
              'data': rows,
              'meta': {'total': 40, 'last_page': 1, 'current_page': 1},
            })
          : null,
      home: (api, session) => CustomersScreen(api: api, session: session),
    );
    await _scrolled(tester, s, () {
      rows[7] = {...rows[7], 'name': 'Customer 7 renamed by Workiz'};
      return {'type': 'customers', 'id': 'c7'};
    }, 'Customer 7 renamed by Workiz');
  });

  testWidgets('Leads: a synced lead shows without the list dimming', (
    tester,
  ) async {
    final rows = [for (var i = 0; i < 30; i++) _lead('l$i', 'Lead person $i')];
    final s = await _pump(
      tester,
      size: const Size(1200, 900),
      permissions: [
        'leads.view',
        'leads.local_services.view',
        'leads.thumbtack.view',
        'leads.website_chat.view',
        'leads.website_form.view',
      ],
      handler: (r) {
        final one = RegExp(r'/leads/([^/]+)$').firstMatch(r.url.path);
        if (one != null) {
          return _json({
            'data': {
              ...rows.firstWhere((l) => l['id'] == one.group(1)),
              'conversations': const <dynamic>[],
              'customer_matches': const <dynamic>[],
            },
          });
        }
        return r.url.path.endsWith('/leads')
            ? _json({
                'data': rows,
                'meta': {
                  'total': rows.length,
                  'per_page': 50,
                  'current_page': 1,
                  'last_page': 1,
                  'connections': [
                    {
                      'provider': 'google_ads',
                      'display_name': 'A1',
                      'status': 'connected',
                    },
                  ],
                },
              })
            : null;
      },
      home: (api, session) => LeadsScreen(api: api, session: session),
    );
    expect(find.text('Lead person 0'), findsWidgets);
    final reads = s.reads['/leads'] ?? 0;
    rows[0] = {...rows[0], 'contact_name': 'Webhook Wanda'};
    s.feed = [
      {'type': 'leads', 'id': 'l0', 'deleted': false},
    ];
    LiveChanges.of(s.api).poll();
    await tester.pump();
    expect(
      find.byType(LinearProgressIndicator),
      findsNothing,
      reason: 'a live re-read never dims the list',
    );
    await tester.pumpAndSettle();
    expect(s.reads['/leads'], reads + 1);
    expect(find.text('Webhook Wanda'), findsWidgets);
  });

  testWidgets('Project Management: a job saved elsewhere re-reads the table', (
    tester,
  ) async {
    var customer = 'Person 3';
    final s = await _pump(
      tester,
      size: const Size(1440, 900),
      permissions: ['project_board.view', 'jobs.view'],
      handler: (r) {
        if (r.url.path.endsWith('/project-board/options')) {
          return _json({'data': <String, dynamic>{}});
        }
        if (!r.url.path.endsWith('/project-board/jobs')) return null;
        return _json({
          'data': [
            for (var i = 0; i < 8; i++)
              {
                'id': 'j$i',
                'number': 'J-$i',
                'customer': i == 3 ? customer : 'Person $i',
                'status': 'Pending',
                'ready_missing': <String>[],
                'tags': <String>[],
              },
          ],
          'meta': {
            'total': 8,
            'last_page': 1,
            'counts': {'unscheduled': 8},
            'places': <dynamic>[],
            'columns': [
              for (final k in ['number', 'customer', 'status'])
                {'key': k, 'label': k, 'visible': true},
            ],
            'labels': <String, dynamic>{},
            'can_edit': false,
            'sortable': <String>[],
          },
        });
      },
      home: (api, session) => ProjectBoardScreen(api: api, session: session),
    );
    expect(find.text('Person 3'), findsOneWidget);
    customer = 'Moved by Workiz';
    s.feed = [
      {'type': 'jobs', 'id': 'j3', 'deleted': false},
    ];
    LiveChanges.of(s.api).poll();
    await tester.pumpAndSettle();
    expect(find.text('Moved by Workiz'), findsOneWidget);
  });

  testWidgets('Scheduling: the open day shows a job booked elsewhere', (
    tester,
  ) async {
    var who = 'Tonya Yates';
    final s = await _pump(
      tester,
      size: const Size(1400, 1000),
      permissions: ['scheduling.view', 'scheduling.schedule.view'],
      handler: (r) => r.url.path.endsWith('/scheduling/board')
          ? _json({'data': _board(who)})
          : null,
      home: (api, session) =>
          SchedulePane(api: api, session: session, visible: true),
    );
    expect(find.textContaining('Tonya Yates'), findsWidgets);
    who = 'Booked by the website';
    s.feed = [
      {'type': 'jobs', 'id': 'J2', 'deleted': false},
    ];
    LiveChanges.of(s.api).poll();
    await tester.pumpAndSettle();
    expect(find.textContaining('Booked by the website'), findsWidgets);
  });

  testWidgets('Inventory: an item changed elsewhere shows on Items', (
    tester,
  ) async {
    var name = '6 mil liner';
    final s = await _pump(
      tester,
      permissions: ['inventory.view'],
      handler: (r) => r.url.path.endsWith('/inventory/items')
          ? _json({
              'data': [
                {
                  'id': 'item-1',
                  'number': 'I-00001',
                  'sku': 'LINER-6',
                  'name': name,
                  'unit': 'each',
                  'status': 'active',
                  'reorder_at': '5.000',
                  'on_hand': '4.000',
                  'company': {'id': 'co-1', 'name': 'Everdoors'},
                  'franchise': {'id': 'fr-1', 'name': 'Connecticut'},
                },
              ],
              'meta': {'total': 1, 'last_page': 1},
            })
          : null,
      home: (api, session) =>
          ItemList(api: api, session: session, visible: true),
    );
    expect(find.text('6 mil liner'), findsOneWidget);
    name = '8 mil liner';
    s.feed = [
      {'type': 'inventory', 'id': 'item-1', 'deleted': false},
    ];
    LiveChanges.of(s.api).poll();
    await tester.pumpAndSettle();
    expect(find.text('8 mil liner'), findsOneWidget);
  });

  testWidgets('Customer record: a change elsewhere fills the fields, an edit '
      'being typed is never overwritten', (tester) async {
    var name = 'Riley Park';
    final s = await _pump(
      tester,
      size: const Size(1200, 1400),
      permissions: ['customers.view', 'customers.edit'],
      handler: (r) => r.url.path.endsWith('/customers/c-1')
          ? _json({
              'data': {
                'id': 'c-1',
                'number': 'C-2041',
                'name': name,
                'phone': '+18605550100',
                'is_active': true,
                'jobs_count': 0,
                'workiz_client_ids': <int>[],
                'owning_franchise': {'id': 'fr-1', 'name': 'Connecticut'},
                'address': {'line1': '9 Oak St', 'city': 'Hartford'},
              },
            })
          : null,
      home: (api, session) => CustomerDetailPanel(
        api: api,
        session: session,
        id: 'c-1',
        onDone: (_) {},
      ),
    );
    expect(find.text('Riley Park'), findsWidgets);
    name = 'Riley Park-Owens';
    await _tick(tester, s, 'customers', 'c-1');
    expect(find.text('Riley Park-Owens'), findsWidgets);
    expect(find.text('Riley Park'), findsNothing);

    // Somebody typing: the next change waits for them.
    await tester.enterText(find.text('9 Oak St'), '11 Elm St');
    name = 'Renamed by Workiz';
    final reads = s.reads['/customers/c-1'];
    await _tick(tester, s, 'customers', 'c-1');
    expect(s.reads['/customers/c-1'], reads, reason: 'not even asked');
    expect(find.text('11 Elm St'), findsOneWidget);
    expect(find.text('Renamed by Workiz'), findsNothing);
  });

  testWidgets('Inventory Orders: an order changed elsewhere shows', (
    tester,
  ) async {
    var number = 'PO-00001';
    final s = await _pump(
      tester,
      permissions: ['inventory.view', 'inventory.orders.view'],
      handler: (r) {
        final path = r.url.path;
        if (path.endsWith('/inventory/purchase-orders')) {
          return _json({
            'data': [
              {
                'id': 'po-1',
                'number': number,
                'status': 'open',
                'vendor': {'id': 'ven-1', 'name': 'Supply Co'},
                'company': {'id': 'co-1', 'name': 'Everdoors'},
                'franchise': {'id': 'fr-1', 'name': 'Connecticut'},
                'lines': <dynamic>[],
              },
            ],
            'meta': {'total': 1, 'last_page': 1},
          });
        }
        if (path.endsWith('/inventory/issues') ||
            path.endsWith('/inventory/sales')) {
          return _json({
            'data': <dynamic>[],
            'meta': {'total': 0, 'last_page': 1},
          });
        }
        return null;
      },
      home: (api, session) =>
          OrderList(api: api, session: session, visible: true),
    );
    expect(find.textContaining('PO-00001'), findsWidgets);
    number = 'PO-00002';
    await _tick(tester, s, 'inventory_orders', 'po-1');
    expect(find.textContaining('PO-00002'), findsWidgets);
    expect(find.textContaining('PO-00001'), findsNothing);
  });

  testWidgets('Inventory Locations: a place renamed elsewhere shows', (
    tester,
  ) async {
    var name = 'Main shop';
    final s = await _pump(
      tester,
      permissions: ['inventory.view', 'inventory.locations.view'],
      handler: (r) => r.url.path.endsWith('/inventory/locations')
          ? _json({
              'data': [
                {
                  'id': 'loc-1',
                  'name': name,
                  'kind': 'shop',
                  'status': 'active',
                  'company': {'id': 'co-1', 'name': 'Everdoors'},
                  'franchise': {'id': 'fr-1', 'name': 'Connecticut'},
                },
              ],
              'meta': {'total': 1, 'last_page': 1},
            })
          : null,
      home: (api, session) =>
          LocationList(api: api, session: session, visible: true),
    );
    expect(find.text('Main shop'), findsOneWidget);
    name = 'North shop';
    await _tick(tester, s, 'inventory_locations', 'loc-1');
    expect(find.text('North shop'), findsOneWidget);
  });

  testWidgets('Re-Scheduling: a job changed elsewhere re-reads the list', (
    tester,
  ) async {
    var customer = 'Ana Ruiz';
    final s = await _pump(
      tester,
      size: const Size(1400, 1000),
      permissions: ['scheduling.view', 'scheduling.rescheduling.view'],
      handler: (r) => r.url.path.endsWith('/scheduling/reschedules')
          ? _json({
              'data': [_reschedule(customer)],
            })
          : null,
      home: (api, session) =>
          ReschedulingPane(api: api, session: session, visible: true),
    );
    expect(find.textContaining('Ana Ruiz'), findsWidgets);
    customer = 'Ana Ruiz-Lopez';
    await _tick(tester, s, 'jobs', 'ez-job-1');
    expect(find.textContaining('Ana Ruiz-Lopez'), findsWidgets);
  });

  testWidgets(
    'Availability: a job booked elsewhere asks the answer again now',
    (tester) async {
      var area = 'Dallas';
      final s = await _pump(
        tester,
        size: const Size(1400, 1200),
        permissions: ['scheduling.view', 'scheduling.availability.view'],
        handler: (r) {
          if (r.url.path.endsWith('/scheduling/availability/suggestion')) {
            return _json({'data': null});
          }
          if (!r.url.path.endsWith('/scheduling/availability')) return null;
          return _json({
            'data': {
              'areas': [
                {
                  'area_id': 'a1',
                  'area_name': area,
                  'franchise_name': 'F',
                  'configured': true,
                  'first': {
                    'date': '2026-10-16',
                    'windows': <dynamic>[],
                    'no_time': 1,
                  },
                  'after': <dynamic>[],
                  'closed': <dynamic>[],
                },
              ],
            },
          });
        },
        home: (api, session) => AvailabilityPane(
          api: api,
          session: session,
          visible: true,
          initialZip: '75201',
        ),
      );
      expect(find.textContaining('DALLAS'), findsOneWidget);
      area = 'Plano';
      await _tick(tester, s, 'jobs', 'j-9');
      expect(find.textContaining('PLANO'), findsOneWidget);
      await tester.pumpWidget(const SizedBox()); // its own refresh timer
    },
  );

  testWidgets('Fleet: a vehicle changed elsewhere re-reads the register and '
      'General', (tester) async {
    final s = await _pump(
      tester,
      permissions: [
        'fleet_management.view',
        'fleet_management.general.view',
        'fleet_management.vehicles.view',
      ],
      handler: (r) => r.url.path.endsWith('/vehicles/summary')
          ? _json(<String, dynamic>{})
          : null,
      home: (api, session) => FleetScreen(api: api, session: session),
    );
    final list = s.reads['/vehicles'] ?? 0;
    final summary = s.reads['/vehicles/summary'] ?? 0;
    await _tick(tester, s, 'vehicles', 'v-1');
    expect(s.reads['/vehicles'], list + 1);
    expect(s.reads['/vehicles/summary'], summary + 1);
    await _tick(tester, s, 'people', 'u-1');
    expect(
      s.reads['/vehicles'],
      list + 1,
      reason: 'another type reads nothing',
    );
  });

  testWidgets(
    'Work Hours approvals: a clock-in elsewhere shows on the open day',
    (tester) async {
      var name = 'Pat Lin';
      final s = await _pump(
        tester,
        permissions: ['work_hours.view', 'work_hours.approval.view'],
        handler: (r) => r.url.path.endsWith('/hr/time-clock/approvals')
            ? _json({
                'data': {
                  'date': '2026-10-21',
                  'label': 'Tuesday, Oct 21',
                  'counts': {'waiting': 1, 'approved': 0, 'no_shows': 0},
                  'rows': [_approvalRow('u-1', name)],
                },
              })
            : null,
        home: (api, session) =>
            WorkHoursApprovalTab(api: api, session: session),
      );
      expect(find.textContaining('Pat Lin'), findsWidgets);
      name = 'Pat Lin-Moreno';
      await _tick(tester, s, 'people', 'u-1');
      expect(find.textContaining('Pat Lin-Moreno'), findsWidgets);
      expect(
        s.requests
            .lastWhere((u) => u.path.endsWith('/approvals'))
            .queryParameters['date'],
        '2026-10-21',
        reason: 'the open day, not today',
      );
    },
  );

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
          'HR Records',
          '/hr/people',
          'people',
          ['hr.view', 'hr.records.view'],
          (api, session) => RecordsTab(api: api, session: session),
        ),
        (
          'HR Invitations',
          '/hr/invitations',
          'hr_invitations',
          ['hr.view', 'hr.invitations.view'],
          (api, session) => InvitationsTab(api: api, session: session),
        ),
        (
          'HR Onboarding',
          '/hr/onboardings',
          'hr_invitations',
          ['hr.view', 'hr.onboarding.view'],
          (api, session) => OnboardingTab(api: api, session: session),
        ),
        (
          'HR Contracts',
          '/hr/contracts',
          'hr_contracts',
          ['hr.view', 'hr.contracts.view'],
          (api, session) => ContractsTab(api: api, session: session),
        ),
        (
          'Work Hours time off',
          '/hr/pto',
          'people',
          ['work_hours.view', 'work_hours.pto.view'],
          (api, session) => PtoTab(api: api, session: session),
        ),
      ]) {
    testWidgets('$name: a change of its type re-reads it quietly', (
      tester,
    ) async {
      final s = await _pump(
        tester,
        size: const Size(1400, 1000),
        permissions: permissions,
        handler: (_) => null,
        home: home,
      );
      final before = s.reads[path] ?? 0;
      expect(before, greaterThan(0));
      await _tick(tester, s, type, 'x-1');
      expect(s.reads[path], before + 1);
      expect(find.byType(LinearProgressIndicator), findsNothing);
    });
  }

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
          'Invoices',
          '/invoices',
          'invoices',
          ['financials.view', 'invoices.view'],
          (api, session) => InvoiceListTab(api: api, me: session.me!),
        ),
        (
          'Financials Bills',
          '/financials/bills',
          'financial_bills',
          ['financials.view', 'financials.bills.view'],
          (api, session) => FinancialBillsTab(api: api, me: session.me!),
        ),
        (
          'Financials Adjustments',
          '/financials/adjustments',
          'financial_adjustments',
          ['financials.view', 'financials.bases.view'],
          (api, session) => FinancialAdjustmentsList(api: api),
        ),
      ]) {
    testWidgets('$name: a change of its type re-reads it quietly', (
      tester,
    ) async {
      final s = await _pump(
        tester,
        size: const Size(1400, 1000),
        permissions: permissions,
        handler: (_) => null,
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

  testWidgets(
    'Invoice record: paid online elsewhere shows on the open invoice',
    (tester) async {
      var status = 'sent';
      final s = await _pump(
        tester,
        size: const Size(1400, 1000),
        permissions: ['financials.view', 'invoices.view'],
        handler: (r) => r.url.path.endsWith('/invoices/i-1')
            ? _json({
                'data': {
                  'id': 'i-1',
                  'number': 'INV-00007',
                  'status': status,
                  'currency': 'USD',
                  'issued_on': '2026-11-16',
                  'bill_to': {'name': 'Pat Customer'},
                  'lines': <dynamic>[],
                  'payments': <dynamic>[],
                  'subtotal_cents': 12500,
                  'tax_cents': 0,
                  'total_cents': 12500,
                  'paid_cents': status == 'paid' ? 12500 : 0,
                  'balance_cents': status == 'paid' ? 0 : 12500,
                },
              })
            : null,
        home: (api, session) => InvoicePanel(
          api: api,
          me: session.me!,
          invoiceId: 'i-1',
          inPlace: true,
          onClose: () {},
          onChanged: (_) {},
        ),
      );
      final before = s.reads['/invoices/i-1']!;
      await _tick(tester, s, 'invoices', 'i-2');
      expect(s.reads['/invoices/i-1'], before, reason: 'another invoice');
      status = 'paid';
      await _tick(tester, s, 'invoices', 'i-1');
      expect(s.reads['/invoices/i-1'], before + 1);
    },
  );

  for (final (name, path, type, home)
      in <(String, String, String, Widget Function(ApiClient, Session))>[
        (
          'Phone call history',
          '/phone/calls',
          'phone_calls',
          (api, session) => CallHistoryPane(
            api: api,
            formats: DisplayFormats.fallback,
            onPick: (_) {},
          ),
        ),
        (
          'Phone voicemails',
          '/phone/calls',
          'phone_calls',
          (api, session) => VoicemailsPane(api: api, session: session),
        ),
        (
          'Phone records (texts)',
          '/phone/messages',
          'phone_messages',
          (api, session) => phone.RecordsTab(api: api, session: session),
        ),
      ]) {
    testWidgets('$name: a webhook-written change re-reads it quietly', (
      tester,
    ) async {
      final s = await _pump(
        tester,
        size: const Size(1400, 1000),
        permissions: ['phone.view', 'phone.records.view', 'phone.phone.view'],
        handler: (_) => null,
        home: home,
      );
      final before = s.reads[path] ?? 0;
      expect(before, greaterThan(0));
      await _tick(tester, s, type, 'x-1');
      expect(s.reads[path], before + 1);
      await _tick(tester, s, 'leads', 'l-1');
      expect(s.reads[path], before + 1, reason: 'another type reads nothing');
    });
  }

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
          'Reviews',
          '/reviews',
          'reviews',
          ['reviews.view', 'customer_experience.view'],
          (api, session) => ReviewsScreen(api: api, session: session),
        ),
        (
          'Complaints',
          '/complaints',
          'complaints',
          ['customer_experience.view', 'customer_experience.complaints.view'],
          (api, session) => ComplaintsTab(api: api, session: session),
        ),
        (
          'Automations',
          '/automations',
          'automations',
          ['automations.view', 'automations.rules.view'],
          (api, session) => AutomationsScreen(api: api, session: session),
        ),
        (
          'Newsletters',
          '/newsletters',
          'newsletters',
          ['newsletter.view', 'newsletter.dashboard.view'],
          (api, session) => NewsletterTab(api: api, session: session),
        ),
        (
          'QR codes',
          '/marketing/qr-codes',
          'qr_codes',
          ['marketing.view', 'marketing.qr_codes.view'],
          (api, session) => QrCodesTab(api: api, session: session),
        ),
        (
          'Dashboard glances',
          '/dashboard',
          'jobs',
          ['dashboard.view'],
          (api, session) => DashboardScreen(api: api, session: session),
        ),
      ]) {
    testWidgets('$name: a change of its type re-reads it quietly', (
      tester,
    ) async {
      final s = await _pump(
        tester,
        size: const Size(1400, 1000),
        permissions: permissions,
        handler: (r) => r.url.path.endsWith('/dashboard')
            ? _json({'period': 'day', 'glances': <dynamic>[]})
            : null,
        home: home,
      );
      final before = s.reads[path] ?? 0;
      expect(before, greaterThan(0));
      await _tick(tester, s, type, 'x-1');
      expect(s.reads[path], before + 1);
      await _tick(tester, s, 'inventory_locations', 'loc-1');
      expect(s.reads[path], before + 1, reason: 'another type reads nothing');
    });
  }

  testWidgets('a change of another type reads nothing again', (tester) async {
    final s = await _pump(
      tester,
      permissions: ['inventory.view'],
      handler: (r) => r.url.path.endsWith('/inventory/items')
          ? _json({
              'data': <dynamic>[],
              'meta': {'total': 0, 'last_page': 1},
            })
          : null,
      home: (api, session) =>
          ItemList(api: api, session: session, visible: true),
    );
    final reads = s.reads['/inventory/items'];
    s.feed = [
      {'type': 'leads', 'id': 'l1', 'deleted': false},
    ];
    LiveChanges.of(s.api).poll();
    await tester.pumpAndSettle();
    expect(s.reads['/inventory/items'], reads);
  });
}

class _Server {
  List<Map<String, dynamic>> feed = const [];
  int cursor = 1;
  final Map<String, int> reads = {};
  final List<Uri> requests = [];
  late ApiClient api;
}

/// Scrolls the list, makes one change, ticks the feed, and checks the new
/// [text] shows with the list where it stood.
Future<void> _scrolled(
  WidgetTester tester,
  _Server s,
  Map<String, Object> Function() change,
  String text,
) async {
  final scroll = tester.state<ScrollableState>(find.byType(Scrollable).last);
  scroll.position.jumpTo(300);
  await tester.pump();
  final before = scroll.position.pixels;
  expect(before, greaterThan(0));
  s.feed = [
    {...change(), 'deleted': false},
  ];
  LiveChanges.of(s.api).poll();
  await tester.pumpAndSettle();
  expect(find.text(text), findsOneWidget);
  expect(
    tester.state<ScrollableState>(find.byType(Scrollable).last).position.pixels,
    before,
  );
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
  String id, {
  bool deleted = false,
}) async {
  s.feed = [
    {'type': type, 'id': id, 'deleted': deleted},
  ];
  LiveChanges.of(s.api).poll();
  await tester.pumpAndSettle();
}

Map<String, dynamic> _approvalRow(String id, String name) => {
  'person': {'id': id, 'name': name, 'role': 'Technician'},
  'shifts': [
    {
      'id': 's-$id',
      'clocked_in': '8:00',
      'clocked_out': '16:00',
      'clocked_in_local': '2026-10-21 08:00',
      'clocked_out_local': '2026-10-21 16:00',
      'ended_by': 'person',
      'edited': null,
    },
  ],
  'worked_seconds': 8 * 3600,
  'scheduled': {'start': '08:00', 'end': '16:00'},
  'late_seconds': 0,
  'early_seconds': 0,
  'no_show': false,
  'status': 'waiting',
  'approved_by': null,
  'approved_at': null,
  'adjustments': <Object>[],
};

Map<String, dynamic> _reschedule(String customer) => {
  'area_id': 'a-mg',
  'area_name': 'Maple Grove',
  'job': {
    'kind': 'job',
    'job_id': 'ez-job-1',
    'workiz_job_id': 'job-1',
    'lead_id': null,
    'number': '4127',
    'status': 'Scheduled',
    'time': '08:00',
    'end_time': '10:00',
    'service': 'Chimney sweep',
    'technicians': ['Sam Ortiz'],
    'notes': null,
    'customer': {
      'name': customer,
      'phone': '555-0142',
      'email': null,
      'address': '1180 Larkspur Ln',
    },
    'date': '2026-10-14',
    'latitude': 41.9,
    'longitude': -87.6,
  },
  'current_nearest_miles': null,
  'suggested': {
    'date': '2026-10-16',
    'window': {'start_time': '10:00', 'end_time': '12:00', 'remaining': 2},
    'window_index': 1,
    'day': 'Thursday',
    'nearby': 3,
    'nearest_miles': 0.8,
    'technicians': ['Sam Ortiz'],
    'jobs': [
      {
        'latitude': 41.91,
        'longitude': -87.6,
        'miles': 0.8,
        'nearby': true,
        'customer': 'Leo Brandt',
        'time': '08:00',
      },
    ],
  },
};

Map<String, dynamic> _lead(String id, String name) => {
  'id': id,
  'is_unread': false,
  'can_reply': false,
  'starred': false,
  'note': null,
  'note_by': null,
  'note_updated_at': null,
  'contact_name': name,
  'phone': '+1 (555) 123-4567',
  'category': 'xcat:service_area_business_home_inspector',
  'service': 'fireplace_chimney',
  'status': 'new',
  'received_at': '2026-09-07T09:00:00Z',
  'last_contact_at': null,
  'last_contact_channel': null,
  'last_contact_from': null,
  'last_contact_preview': null,
  'franchise': {'id': 'f-1', 'name': 'Connecticut'},
  'company': {'id': 'c-1', 'name': 'A-1 Chimney'},
};

Map<String, dynamic> _board(String unassigned) => {
  'date': '2026-09-25',
  'franchises': [
    {'id': 'fr-ct', 'name': 'Connecticut'},
  ],
  'windows': [
    {'start_time': '08:00', 'end_time': '11:00'},
  ],
  'technicians': <dynamic>[],
  'scheduled': <dynamic>[],
  'unassigned': [
    {
      'id': 'J2',
      'number': '100077558',
      'customer': unassigned,
      'job_type': 'Chimney Sweep',
      'status': 'Submitted',
      'time': '08:00',
      'window': 0,
      'address': '18521 Nadol Dr',
      'city': 'Southfield',
    },
  ],
  'counts': {'scheduled': 0, 'unassigned': 1},
  'can_dispatch': false,
};

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
