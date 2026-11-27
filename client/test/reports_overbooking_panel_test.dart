import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/reports/overbooking_report_panel.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

void main() {
  // Jose, 2026-10-01: the daily "Overbooking warning" automation shows in
  // Reports, and opens beside the list like every other report (queue 33).
  testWidgets('the overbooking warning opens on the seven days its email '
      'reports, read from Scheduling itself', (tester) async {
    final asked = <Map<String, String>>[];
    var closed = false;
    final api = ApiClient(
      client: MockClient((request) async {
        final path = request.url.path;
        if (path.endsWith('/me')) {
          return _json({
            'data': {
              ..._me,
              'permissions': ['reports.view', 'scheduling.rescheduling.view'],
              'modules': <Object>[],
            },
          });
        }
        if (path.endsWith('/scheduling/overbooking')) {
          asked.add(request.url.queryParameters);
          return _json({
            'data': {
              'overbooked': [
                {
                  'date': '2026-10-02',
                  'level': 'area',
                  'name': 'North',
                  'franchise_name': 'Harbor East',
                  'booked': 9,
                  'capacity': 6,
                  'over': 3,
                  'closed': false,
                },
              ],
              'fully_booked': [
                {
                  'area_id': 'a-2',
                  'name': 'South',
                  'franchise_name': 'Harbor East',
                  'next_opening': '2026-10-09',
                },
              ],
            },
          });
        }
        return _json({'message': 'Unexpected ${request.method} $path'}, 500);
      }),
    );
    final session = Session(api);
    await tester.runAsync(() => session.adopt('tok'));

    await tester.pumpWidget(
      MaterialApp(
        theme: EzBiziTheme.light(),
        home: Scaffold(
          body: Row(
            children: [
              const Expanded(child: SizedBox()),
              SizedBox(
                width: 460,
                child: OverbookingReportPanel(
                  api: api,
                  session: session,
                  report: const {
                    'id': 'overbooking',
                    'name': 'Overbooking warning',
                    'description': 'Each morning, the days booked past what '
                        'an area takes.',
                    'covers': 'Every service area',
                    'sent_automatically': true,
                    'sent_by': null,
                  },
                  inPlace: false,
                  onClose: () => closed = true,
                ),
              ),
            ],
          ),
        ),
      ),
    );
    await tester.pumpAndSettle();

    // The email's span, from the endpoint Scheduling › Overbooking reads.
    expect(asked, [
      {'days': '7'},
    ]);
    expect(find.text('Every service area'), findsOneWidget);
    expect(find.text('Sent automatically'), findsOneWidget);
    expect(
      find.byKey(const ValueKey('overbooked-2026-10-02-area-North')),
      findsOneWidget,
    );
    expect(find.byKey(const ValueKey('full-a-2')), findsOneWidget);
    // The tab's own heading and spans stay in the tab.
    expect(find.text('Pick a date'), findsNothing);
    expect(find.byTooltip('Refresh'), findsNothing);

    await tester.tap(find.byTooltip('Close Overbooking warning'));
    expect(closed, isTrue);
  });
}

const _me = {
  'id': 'u-1',
  'name': 'Sam Rivera',
  'email': 'sam@example.com',
  'role': {'name': 'Manager'},
  'has_avatar': false,
  'preferences': <String, dynamic>{},
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
};

http.Response _json(Object body, [int status = 200]) => http.Response(
  jsonEncode(body),
  status,
  headers: const {'content-type': 'application/json'},
);
