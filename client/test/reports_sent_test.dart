import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/deep_links.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/reports/sent_report_panel.dart';
import 'package:ezbizi_client/screens/reports_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Every report EzBizi sends, in the Reports list (Jose, 2026-11-25: "right
/// now we are sending out a lot of reports on whatsapp, and yet they can't be
/// seen on the app. the reports tab is showing only 2").
void main() {
  testWidgets('every sent report is listed, counted by kind and channel', (
    tester,
  ) async {
    await _pump(tester);

    for (final name in [
      'Overbooking warning',
      'WhatsApp: Revenue collected',
      'Customer waiting - Ana',
      'Weekly AI summary',
      'Same-Day reports',
    ]) {
      expect(find.text(name), findsOneWidget, reason: name);
    }
    String chip(String key) =>
        ((tester.widget(find.byKey(ValueKey(key))) as dynamic).label as Text)
            .textSpan!
            .toPlainText();
    expect(chip('library-kind-all'), 'All  5');
    expect(chip('library-kind-sent'), 'Sent by rules and EzBizi  4');
    // Overbooking's email counts with the rules' own.
    expect(chip('library-channel-whatsapp'), 'WhatsApp  3');
    expect(chip('library-channel-email'), 'Email  2');
    expect(chip('library-channel-group'), 'WhatsApp group  1');

    // The WhatsApp filter narrows to what goes on WhatsApp.
    await tester.tap(find.byKey(const ValueKey('library-channel-whatsapp')));
    await tester.pumpAndSettle();
    expect(find.text('Overbooking warning'), findsNothing);
    expect(find.text('WhatsApp: Revenue collected'), findsOneWidget);
    expect(find.text('Same-Day reports'), findsOneWidget);
    await tester.tap(find.byKey(const ValueKey('library-channel-whatsapp')));
    await tester.pumpAndSettle();

    // Who it goes to is searchable, and shown.
    await tester.enterText(
      find.byKey(const Key('reports-library-search')),
      'mia',
    );
    await tester.pumpAndSettle();
    expect(find.text('Customer waiting - Ana'), findsOneWidget);
    expect(find.text('WhatsApp: Revenue collected'), findsNothing);
    expect(find.textContaining('To Mia Torres'), findsOneWidget);
  });

  testWidgets(
    'a report opens onto what it sent: when, to whom, read, and what it said',
    (tester) async {
      final asked = <Map<String, String>>[];
      await _pump(tester, asked: asked);

      await tester.tap(find.text('WhatsApp: Revenue collected'));
      await tester.pumpAndSettle();

      expect(find.byType(SentReportPanel), findsOneWidget);
      expect(asked.single, {'report': 'automation:r-rev'});
      expect(find.text('SENT · 2'), findsOneWidget);
      expect(find.text('Mia Torres'), findsWidgets);
      expect(find.text('Read'), findsOneWidget);
      expect(find.text('Failed'), findsOneWidget);
      expect(find.textContaining('Total \$12,400'), findsNothing);

      await tester.tap(find.byKey(const ValueKey('sent-report-send-m-1')));
      await tester.pumpAndSettle();
      expect(find.textContaining('Total \$12,400'), findsOneWidget);

      await tester.tap(find.byKey(const ValueKey('sent-report-open-rule')));
      await tester.pumpAndSettle();
      final link = DeepLinks.take('automations');
      expect([link?.type, link?.id], ['automation', 'r-rev']);
    },
  );
  testWidgets('on a phone a report opens in place of the list', (tester) async {
    await _pump(tester, width: 390);

    await tester.tap(find.text('Customer waiting - Ana'));
    await tester.pumpAndSettle();

    expect(find.byType(SentReportPanel), findsOneWidget);
    expect(find.text('Same-Day reports'), findsNothing);
    expect(find.text('A lead waited too long for a reply'), findsOneWidget);
  });
}

Future<void> _pump(
  WidgetTester tester, {
  List<Map<String, String>>? asked,
  double width = 1400,
}) async {
  tester.view.physicalSize = Size(width, 1200);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(
    client: MockClient((request) async {
      final path = request.url.path;
      if (path.endsWith('/me')) {
        return _json({
          'data': {
            ..._me,
            'permissions': const [
              'reports.view',
              'reports.library.view',
              'scheduling.rescheduling.view',
              'automations.view',
            ],
            'modules': <Object>[],
            'display': {'date_format': 'M/D/YYYY', 'time_format': '12h'},
          },
        });
      }
      if (path == '/api/v1/scheduling/reports') {
        return _json({
          'data': const [_warning],
        });
      }
      if (path == '/api/v1/reports') return _json({'data': const []});
      if (path == '/api/v1/reports/sent') return _json({'data': _sent});
      if (path == '/api/v1/reports/sent/history') {
        asked?.add(request.url.queryParameters);
        return _json({'data': _history, 'total': 2});
      }
      return _json({'message': 'Nothing stubbed for $path.'}, 500);
    }),
  );

  final session = Session(api);
  await tester.runAsync(() => session.adopt('tok'));

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(
        body: ReportsScreen(api: api, session: session),
      ),
    ),
  );
  await tester.pumpAndSettle();
}

Map<String, dynamic> _rule(String id, String name) => {
  'id': id,
  'name': name,
  'schedule': null,
  'next_run_at': null,
  'is_active': true,
  'disabled_at': null,
};

final _sent = [
  {
    'key': 'automation:r-rev',
    'kind': 'rule',
    'name': 'WhatsApp: Revenue collected',
    'about': 'Revenue collected · yesterday by location',
    'covers': 'Every company',
    'to': ['Jose', 'Ben Carter'],
    'channels': ['whatsapp'],
    'trigger': null,
    'rule': _rule('r-rev', 'WhatsApp: Revenue collected'),
    'is_active': true,
    'sent_7d': 14,
    'sent_90d': 30,
    'last_sent_at': '2026-11-25T12:02:00Z',
  },
  {
    'key': 'automation:r-ana',
    'kind': 'rule',
    'name': 'Customer waiting - Ana',
    'about': '',
    'covers': 'Every company',
    'to': ['Mia Torres'],
    'channels': ['whatsapp'],
    'trigger': 'A lead waited too long for a reply',
    'rule': _rule('r-ana', 'Customer waiting - Ana'),
    'is_active': true,
    'sent_7d': 151,
    'sent_90d': 400,
    'last_sent_at': '2026-11-25T13:13:00Z',
  },
  {
    'key': 'automation:r-ai',
    'kind': 'rule',
    'name': 'Weekly AI summary',
    'about': 'A message to a WhatsApp group',
    'covers': 'Every company',
    'to': ['Weekly summary'],
    'channels': ['email', 'group'],
    'trigger': 'The weekly AI summary is ready',
    'rule': _rule('r-ai', 'Weekly AI summary'),
    'is_active': true,
    'sent_7d': 4,
    'sent_90d': 12,
    'last_sent_at': '2026-11-23T12:00:00Z',
  },
  {
    'key': 'same-day',
    'kind': 'other',
    'name': 'Same-Day reports',
    'about': 'Each branch its Same-Day Report, then the company summary',
    'covers': 'Everdoors',
    'to': <String>[],
    'channels': ['whatsapp'],
    'trigger': 'Every evening',
    'rule': null,
    'is_active': true,
    'sent_7d': 0,
    'sent_90d': 3,
    'last_sent_at': '2026-11-01T01:05:00Z',
  },
];

final _history = [
  {
    'id': 'm-1',
    'at': '2026-11-25T12:02:00Z',
    'channel': 'whatsapp',
    'to': 'Mia Torres',
    'status': 'read',
    'status_reason': null,
    'delivered_at': '2026-11-25T12:02:05Z',
    'read_at': '2026-11-25T12:10:00Z',
    'subject': null,
    'body': 'Revenue collected · Mon, Nov 24\nTotal \$12,400',
  },
  {
    'id': 'm-2',
    'at': '2026-11-24T12:02:00Z',
    'channel': 'whatsapp',
    'to': '+13055550123',
    'status': 'failed',
    'status_reason': 'Message undeliverable',
    'delivered_at': null,
    'read_at': null,
    'subject': null,
    'body': null,
  },
];

const _warning = {
  'id': 'overbooking',
  'name': 'Overbooking warning',
  'description': 'Service areas overbooked or fully booked in the next 7 days',
  'covers': 'Every service area',
  'sent_automatically': true,
  'sent_by': null,
};

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
};

http.Response _json(Object body, [int status = 200]) => http.Response(
  jsonEncode(body),
  status,
  headers: const {'content-type': 'application/json'},
);
