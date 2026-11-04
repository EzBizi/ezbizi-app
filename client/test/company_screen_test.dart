import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/company_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Company (design 175): the reports-to tree, step owners and KPIs.
void main() {
  testWidgets('people are drawn under who they report to', (tester) async {
    await _pump(tester, _handler());

    expect(find.text('Dana Brightside'), findsOneWidget);
    expect(find.text('Lee Harbor'), findsOneWidget);
    // Lee reports to Dana, so is drawn indented with the arrow.
    final lee = find.byKey(const ValueKey('company-person-u-2'));
    expect(
      find.descendant(
        of: lee,
        matching: find.byIcon(Icons.subdirectory_arrow_right),
      ),
      findsOneWidget,
    );
    expect(find.text('Reports to Dana Brightside'), findsOneWidget);
  });

  testWidgets('a step owner is saved with PUT hr/ownership', (tester) async {
    Map<String, dynamic>? sent;
    await _pump(tester, _handler(onPut: (b) => sent = b));

    await tester.tap(find.text('Processes'));
    await tester.pumpAndSettle();
    expect(find.text('No owner'), findsNWidgets(4));

    await tester.tap(find.byKey(const ValueKey('company-step-payment')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('company-owner-person')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Lee Harbor').last);
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('company-owner-save')));
    await tester.pumpAndSettle();

    expect(sent, {'kind': 'process', 'key': 'payment', 'user_id': 'u-2'});
  });

  testWidgets('a refused reports-to shows under the field', (tester) async {
    await _pump(tester, _handler(patchStatus: 422));

    await tester.tap(find.text('Set who they report to'));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('company-owner-person')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Lee Harbor').last);
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('company-owner-save')));
    await tester.pumpAndSettle();

    expect(find.text('That would make a circle.'), findsOneWidget);
  });

  testWidgets('KPIs drop the nameless row and draw the company line as the '
      'Total at the bottom', (tester) async {
    tester.view.physicalSize = const Size(390, 844);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);
    final api = ApiClient(
      client: MockClient((request) async {
        if (request.url.path.endsWith('/reports/experimental/calls')) {
          return _json({
            'people': [
              {'person': '', 'answered': 9},
              {'person': 'Brightside Home', 'answered': 6},
              {'person': 'Dana Brightside', 'answered': 4},
            ],
            'calls': [],
          });
        }
        return _json({
          'data': {'keys': [], 'goals': []},
        });
      }),
    );
    await tester.pumpWidget(
      MaterialApp(
        home: Scaffold(
          body: CompanyKpis(api: api, companyNames: const ['Brightside-Home']),
        ),
      ),
    );
    await tester.pumpAndSettle();

    expect(find.byKey(const ValueKey('company-kpi-')), findsNothing);
    expect(find.text('Total · Brightside Home'), findsOneWidget);
    expect(
      tester.getTopLeft(find.text('Total · Brightside Home')).dy,
      greaterThan(tester.getTopLeft(find.text('Dana Brightside')).dy),
    );
    final line = find.byKey(const ValueKey('company-kpi-Brightside Home'));
    expect(
      find.descendant(of: line, matching: find.text('No goal')),
      findsNothing,
    );
  });

  testWidgets('KPIs say "No goal" for a person', (tester) async {
    await _pump(tester, _handler());

    await tester.tap(find.text('KPIs'));
    await tester.pumpAndSettle();

    final row = find.byKey(const ValueKey('company-kpi-Dana Brightside'));
    expect(
      find.descendant(of: row, matching: find.text('No goal')),
      findsOneWidget,
    );
    expect(
      find.descendant(
        of: row,
        matching: find.textContaining('booking rate 50%'),
      ),
      findsOneWidget,
    );
  });
}

http.Response _json(Object body, [int status = 200]) => http.Response(
  jsonEncode(body),
  status,
  headers: {'content-type': 'application/json'},
);

MockClientHandler _handler({
  void Function(Map<String, dynamic>)? onPut,
  int patchStatus = 200,
}) {
  return (request) async {
    final path = request.url.path;
    if (path.endsWith('/me')) return _json({'data': _me});
    if (path.endsWith('/hr/ownership') && request.method == 'PUT') {
      onPut?.call(jsonDecode(request.body) as Map<String, dynamic>);
      return _json({'data': null});
    }
    if (path.endsWith('/hr/ownership')) {
      return _json({
        'data': {
          'people': [
            {
              'id': 'u-1',
              'name': 'Dana Brightside',
              'role': {'id': 'r-1', 'name': 'Office manager'},
              'reports_to_user_id': null,
              'assigned': [],
            },
            {
              'id': 'u-2',
              'name': 'Lee Harbor',
              'role': {'id': 'r-2', 'name': 'Dispatcher'},
              'reports_to_user_id': 'u-1',
              'assigned': [
                {
                  'type': 'franchise',
                  'id': 'f-1',
                  'company': 'Brightside',
                  'branch': 'North',
                },
              ],
            },
          ],
          'owners': [
            {
              'id': 'o-1',
              'kind': 'process',
              'key': 'lead',
              'scope_type': null,
              'scope_id': null,
              'user': {'id': 'u-1', 'name': 'Dana Brightside'},
            },
          ],
          'steps': ['lead', 'booking', 'job', 'payment', 'review'],
        },
      });
    }
    if (path.contains('/hr/people/') && request.method == 'PATCH') {
      return patchStatus == 200
          ? _json({'data': {}})
          : _json({
              'message': 'That would make a circle.',
              'errors': {
                'reports_to_user_id': ['That would make a circle.'],
              },
            }, patchStatus);
    }
    if (path.endsWith('/reports/experimental/calls')) {
      return _json({
        'people': [
          {'person': 'Dana Brightside', 'answered': 4},
        ],
        'calls': [
          {'answered_by': 'Dana Brightside', 'booked': true},
          {'answered_by': 'Dana Brightside', 'booked': true},
          {'answered_by': 'Dana Brightside', 'booked': false},
        ],
      });
    }
    if (path.endsWith('/reports/goals')) {
      return _json({
        'data': {'keys': [], 'goals': []},
      });
    }
    return _json({'data': []});
  };
}

const _me = {
  'id': 'u-1',
  'name': 'Dana Brightside',
  'email': 'dana@brightside.example',
  'has_avatar': false,
  'role': {'id': 'r-1', 'name': 'Administrator'},
  'permissions': [
    'company.view',
    'company.ownership.view',
    'company.processes.view',
    'company.kpis.view',
    'hr.view',
    'hr.edit',
  ],
  'modules': [
    {
      'key': 'company',
      'label': 'Company',
      'available': true,
      'reachable': true,
    },
  ],
  'preferences': <String, dynamic>{},
  'organization': {
    'id': 'org-1',
    'name': 'Brightside',
    'franchise_label': 'Branch',
    'is_read_only': false,
  },
  'scope': {
    'type': 'organization',
    'id': 'org-1',
    'label': 'Brightside',
    'path': ['Brightside'],
  },
  'companies': [],
  'franchises': [],
};

Future<void> _pump(WidgetTester tester, MockClientHandler handler) async {
  tester.view.physicalSize = const Size(1400, 1600);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);
  final api = ApiClient(client: MockClient(handler));
  final session = Session(api);
  await tester.runAsync(() => session.adopt('tok'));
  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(
        body: CompanyScreen(api: api, session: session),
      ),
    ),
  );
  await tester.pumpAndSettle();
}
