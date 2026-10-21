import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/jobs/job_detail_panel.dart';
import 'package:ezbizi_client/widgets/status_pill.dart';
import 'package:ezbizi_client/widgets/tab_bar_like.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// What Workiz v2 knows about a job, on the job panel (UI queue 58; Jose:
/// "as recommended", desktop 1 and phone 4, relayed by Jobs).
///
/// Job, Money and Photos & files tabs. Workiz's fields are facts on the Job
/// tab; the invoice, payments, Workiz's own estimates and time on site sit
/// under the money card; photos are a grid the browser draws itself. The
/// money exists only for a reader with Financials — the server leaves it out
/// otherwise. Every job has Photos & files, for its own (queue 119).
void main() {
  testWidgets("Workiz's fields are facts on the Job tab, in its own names", (
    tester,
  ) async {
    await _pump(tester, workiz: _workiz, money: _money);

    final facts = find.byKey(const Key('job-workiz-facts'));
    expect(facts, findsOneWidget);
    expect(
      find.descendant(of: facts, matching: find.text('SYSTEM TYPE')),
      findsOneWidget,
    );
    expect(
      find.descendant(of: facts, matching: find.text('Wood Burning')),
      findsOneWidget,
    );
    expect(
      find.descendant(of: facts, matching: find.text('Appointment Time: 8-11')),
      findsOneWidget,
    );
    // Before the first thing to edit: what the job IS comes first.
    expect(
      tester.getTopLeft(facts).dy,
      lessThan(
        tester.getTopLeft(find.widgetWithText(TextField, 'What is the job')).dy,
      ),
    );
  });

  testWidgets('the tabs name the money and count the files', (tester) async {
    await _pump(tester, workiz: _workiz, money: _money);

    final tabs = tester.widget<TabBarLike>(find.byKey(const Key('job-tabs')));
    expect(
      [for (final l in tabs.labels) l.$1],
      ['Job', r'Money · $412.00', 'Photos & files · 3', 'Notes'],
    );
  });

  testWidgets('the Money tab lays out the invoice and payments by date', (
    tester,
  ) async {
    await _pump(tester, workiz: _workiz, money: _money);
    await tester.tap(find.textContaining('Money · '));
    await tester.pumpAndSettle();

    expect(
      find.byKey(const Key('job-money')),
      findsOneWidget,
      reason: "queue 31's card, first",
    );
    final detail = find.byKey(const Key('job-workiz-money'));
    final invoice = find.byKey(const ValueKey('workiz-invoice-3381'));
    final card = find.byKey(const ValueKey('workiz-payment-2026-10-02-25000'));
    final office = find.byKey(
      const ValueKey('workiz-payment-2026-10-03-16200'),
    );
    final refund = find.byKey(const ValueKey('workiz-payment-2026-10-04-5000'));

    // In the order they happened; the invoice before the payment on its day.
    final tops = [
      invoice,
      card,
      office,
      refund,
    ].map((f) => tester.getTopLeft(f).dy).toList();
    expect(tops, orderedEquals([...tops]..sort()));

    expect(
      find.descendant(
        of: invoice,
        matching: find.textContaining(r'tax $23.00'),
      ),
      findsOneWidget,
    );
    expect(
      find.descendant(of: card, matching: find.text('Paid · Sam Ortega')),
      findsOneWidget,
    );
    expect(
      find.descendant(of: office, matching: find.text('Paid · pending')),
      findsOneWidget,
    );
    expect(
      find.descendant(of: refund, matching: find.text(r'−$50.00')),
      findsOneWidget,
    );
    expect(
      find.descendant(
        of: detail,
        matching: find.text('FROM WORKIZ · READ ONLY'),
      ),
      findsNothing,
    );
    expect(
      find.descendant(
        of: detail,
        matching: find.text('From Workiz · read only'),
      ),
      findsWidgets,
    );
  });

  testWidgets("Workiz's estimates carry its own word, in colour", (
    tester,
  ) async {
    await _pump(tester, workiz: _workiz, money: _money);
    await tester.tap(find.textContaining('Money · '));
    await tester.pumpAndSettle();

    StatusPill pill(String number) => tester.widget<StatusPill>(
      find.descendant(
        of: find.byKey(ValueKey('workiz-estimate-$number')),
        matching: find.byType(StatusPill),
      ),
    );
    expect(pill('E-2207').label, 'Pending');
    expect(pill('E-2207').tone, Tone.caution);
    expect(pill('E-2190').tone, Tone.ok);
    expect(pill('E-2101').tone, Tone.bad);
  });

  testWidgets('time on site says who and for how long', (tester) async {
    await _pump(tester, workiz: _workiz, money: _money);
    await tester.tap(find.textContaining('Money · '));
    await tester.pumpAndSettle();

    final row = find.byKey(
      const ValueKey('workiz-timesheet-2026-10-02T12:05:00+00:00'),
    );
    expect(
      find.descendant(of: row, matching: find.textContaining('Sam Ortega')),
      findsOneWidget,
    );
    expect(
      find.descendant(of: row, matching: find.textContaining('1 h 35 m')),
      findsOneWidget,
    );
    expect(
      find.descendant(of: row, matching: find.text(r'$62.00')),
      findsOneWidget,
    );
  });

  testWidgets(
    'photos are a grid the browser draws itself, files open as rows',
    (tester) async {
      await _pump(tester, workiz: _workiz, money: _money);
      await tester.tap(find.textContaining('Photos & files'));
      await tester.pumpAndSettle();

      final pictures = tester.widgetList<Image>(find.byType(Image)).toList();
      expect(pictures, hasLength(2), reason: 'two pictures; the PDF is a row');
      for (final picture in pictures) {
        // Workiz's host sends no CORS headers: only an <img> can show them.
        final image = picture.image;
        final provider = image is ResizeImage ? image.imageProvider : image;
        expect(
          (provider as NetworkImage).webHtmlElementStrategy,
          WebHtmlElementStrategy.prefer,
        );
        expect(
          image,
          isA<ResizeImage>(),
          reason: 'decoded small: the files are full size',
        );
      }
      expect(
        find.byKey(const ValueKey('job-file-Inspection Report.pdf')),
        findsOneWidget,
      );

      await tester.tap(find.byKey(const ValueKey('job-photo-0')));
      await tester.pumpAndSettle();
      expect(find.byKey(const Key('job-photo-viewer')), findsOneWidget);
      expect(find.text('1 of 2'), findsOneWidget);
      await tester.tap(find.byTooltip('Next photo'));
      await tester.pumpAndSettle();
      expect(find.text('2 of 2'), findsOneWidget);
      await tester.tap(find.text('All photos'));
      await tester.pumpAndSettle();
      expect(find.byKey(const Key('job-workiz-files')), findsOneWidget);
    },
  );

  testWidgets('without Financials there is no Money tab, and nothing of it', (
    tester,
  ) async {
    await _pump(
      tester,
      workiz: {'fields': _workiz['fields'], 'files': _workiz['files']},
      money: null,
    );

    final tabs = tester.widget<TabBarLike>(find.byKey(const Key('job-tabs')));
    expect(
      [for (final l in tabs.labels) l.$1],
      ['Job', 'Photos & files · 3', 'Notes'],
    );
    expect(find.textContaining('Money'), findsNothing);
  });

  testWidgets(
    'a job Workiz does not have shows no Workiz facts, and still gets '
    'Photos & files for its own',
    (tester) async {
      await _pump(tester, workiz: null, money: null);

      final tabs = tester.widget<TabBarLike>(find.byKey(const Key('job-tabs')));
      expect(
        [for (final l in tabs.labels) l.$1],
        ['Job', 'Photos & files', 'Notes'],
      );
      expect(find.byKey(const Key('job-workiz-facts')), findsNothing);
      expect(
        find.widgetWithText(TextField, 'What is the job'),
        findsOneWidget,
        reason: 'the control',
      );
    },
  );
}

const _workiz = <String, dynamic>{
  'fields': [
    {'name': 'System Type', 'value': 'Wood Burning'},
    {'name': 'Time Frames', 'value': 'Appointment Time: 8-11'},
    {'name': 'Pricing', 'value': r'$299 Chimney Sweep'},
  ],
  'files': [
    {'name': 'crown.jpg', 'url': 'https://st.example.test/files/crown.jpg'},
    {'name': 'firebox.png', 'url': 'https://st.example.test/files/firebox.png'},
    {
      'name': 'Inspection Report.pdf',
      'url': 'https://st.example.test/files/report.pdf',
    },
    {'name': 'trick.jpg', 'url': 'javascript:alert(1)'},
  ],
  'currency': 'USD',
  'invoices': [
    {
      'number': '3381',
      'total_minor': 41200,
      'due_minor': 0,
      'tax_minor': 2300,
      'tip_minor': 0,
      'issued_on': '2026-10-02',
      'due_on': '2026-10-02',
    },
  ],
  'payments': [
    {
      'paid_on': '2026-10-04',
      'amount_minor': 5000,
      'kind': 'refund',
      'status': 'succeeded',
      'collected_by': null,
    },
    {
      'paid_on': '2026-10-02',
      'amount_minor': 25000,
      'kind': 'charge',
      'status': 'succeeded',
      'collected_by': 'Sam Ortega',
    },
    {
      'paid_on': '2026-10-03',
      'amount_minor': 16200,
      'kind': 'charge',
      'status': 'pending',
      'collected_by': null,
    },
  ],
  'estimates': [
    {
      'number': 'E-2207',
      'total_minor': 185000,
      'status': 'Pending',
      'dated_on': '2026-10-02',
    },
    {
      'number': 'E-2190',
      'total_minor': 29900,
      'status': 'Won',
      'dated_on': '2026-09-28',
    },
    {
      'number': 'E-2101',
      'total_minor': 99000,
      'status': 'Declined',
      'dated_on': '2026-08-14',
    },
  ],
  'timesheets': [
    {
      'who': 'Sam Ortega',
      'starts_at': '2026-10-02T12:05:00+00:00',
      'ends_at': '2026-10-02T13:40:00+00:00',
      'labor_cost_minor': 6200,
    },
  ],
};

const _money = <String, dynamic>{
  'total': 41200,
  'subtotal': 38900,
  'paid': 41200,
  'due': 0,
  'state': 'paid',
  'currency': 'USD',
  'lines': <dynamic>[],
};

Future<void> _pump(
  WidgetTester tester, {
  required Map<String, dynamic>? workiz,
  required Map<String, dynamic>? money,
}) async {
  tester.view.physicalSize = const Size(900, 1400);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(
    client: MockClient((request) async {
      final path = request.url.path;
      if (path.endsWith('/me')) {
        return _json({
          'data': {
            'id': 'u-1',
            'name': 'Dana Reyes',
            'email': 'dana@example.com',
            'role': {'name': 'Dispatcher'},
            'has_avatar': false,
            'preferences': <String, dynamic>{},
            'permissions': [
              'jobs.view',
              'jobs.edit',
              if (money != null) 'jobs.financials',
            ],
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
          },
        });
      }
      if (path.endsWith('/jobs/1')) {
        return _json({
          'data': {
            'id': '1',
            'number': 'J-10482',
            'title': 'Sweep and inspection',
            'status': 'Done',
            'status_label': 'Done',
            'scheduled_for': null,
            'franchise': {'id': 'fr-1', 'name': 'Harbor Chimney'},
            'customer': {
              'id': 'c-1',
              'name': 'Riley Park',
              'owned_by_this_franchise': true,
            },
            'service_area': null,
            'description': null,
            'duration_minutes': null,
            'allowed_statuses': ['Pending', 'Done'],
            'allowed_service_areas': <dynamic>[],
            'money': money,
            'workiz': workiz,
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
        body: JobDetailPanel(
          api: api,
          session: session,
          id: '1',
          onDone: (_) {},
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
