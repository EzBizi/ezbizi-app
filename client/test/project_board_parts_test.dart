import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/project_board_screen.dart';
import 'package:ezbizi_client/widgets/status_pill.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// The parts selector (Jose, 2026-11-17): in stock green, ordered red,
/// same-day pickup yellow, where it goes, and late after three days.
/// Invented people and suppliers only.
void main() {
  testWidgets('a late part reads red, and a part says where it goes', (
    tester,
  ) async {
    final puts = <Map<String, dynamic>>[];
    final api = ApiClient(
      client: MockClient((request) async {
        final path = request.url.path;
        if (path.endsWith('/me')) {
          return _json({
            'data': {
              'id': 'u-1',
              'name': 'Dana',
              'email': 'dana@brightside.example',
              'permissions': ['project_board.view', 'jobs.view', 'jobs.edit'],
              'modules': <dynamic>[],
              'organization': {'id': 'org-1', 'name': 'Brightside'},
            },
          });
        }
        if (path.endsWith('/settings')) return _json({'data': <dynamic>[]});
        if (request.method == 'PUT') {
          puts.add(jsonDecode(request.body) as Map<String, dynamic>);
          return _json({'data': <dynamic>[]});
        }
        return _json({
          'data': [
            {
              'id': 'j-1',
              'number': 'J-1001',
              'customer': 'Lena Park',
              'status': 'Pending',
              'ready_missing': <String>[],
              'parts': [
                {
                  'id': 'p-1',
                  'kind': 'order',
                  'item': 'Stainless cap',
                  'quantity': 1,
                  'supplier': {
                    'id': 's-1',
                    'name': 'Chimney Supply Co',
                    'address': '12 Brick Rd',
                  },
                  'destination': 'supplier',
                  'ordered_at': '2026-11-13T09:00:00Z',
                  'late': true,
                },
                // Merav, 2026-11-19: bought by the technician, to reimburse.
                {
                  'id': 'p-2',
                  'kind': 'stock',
                  'item': 'Mortar',
                  'quantity': 2,
                  'paid_by_user_id': 'u-7',
                  'paid_by': 'Sam Tech',
                },
              ],
            },
          ],
          'meta': {
            'total': 1,
            'last_page': 1,
            'counts': {'unscheduled': 1},
            'places': <dynamic>[],
            'columns': [
              {'key': 'customer', 'label': 'Client', 'visible': true},
              {'key': 'part_status', 'label': 'Part Status', 'visible': true},
            ],
            'labels': <String, dynamic>{},
            'suppliers': [
              {
                'id': 's-1',
                'name': 'Chimney Supply Co',
                'address': '12 Brick Rd',
                'kind': 'order',
              },
              {'id': 's-2', 'name': 'Round Rock Depot', 'kind': 'pickup'},
            ],
            'technicians': [
              {'id': 'u-7', 'name': 'Sam Tech'},
            ],
            'totals': {'paid': 0, 'due': 0, 'total': 0, 'currency': 'USD'},
            'can_edit': true,
          },
        });
      }),
    );
    final session = Session(api);
    await tester.runAsync(() => session.adopt('tok'));

    tester.view.physicalSize = const Size(1600, 1400);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);
    await tester.pumpWidget(
      MaterialApp(
        theme: EzBiziTheme.light(),
        home: Scaffold(
          body: ProjectBoardScreen(api: api, session: session),
        ),
      ),
    );
    await tester.pumpAndSettle();

    final pill = tester.widget<StatusPill>(
      find.descendant(
        of: find.byKey(const ValueKey('project-board-parts:j-1')),
        matching: find.byType(StatusPill),
      ),
    );
    expect(pill.tone, Tone.bad);
    expect(find.text('1 late · 1 ordered · 1 ready'), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('project-board-parts:j-1')));
    await tester.pumpAndSettle();
    expect(
      find.text('Collected at Chimney Supply Co, 12 Brick Rd.'),
      findsOneWidget,
    );

    // Same-day pickup: the order supplier no longer fits, and it goes to
    // the customer.
    final pickup = find.descendant(
      of: find.byKey(const ValueKey('project-board-part-kind-0')),
      matching: find.text('Same-day pickup'),
    );
    await tester.ensureVisible(pickup);
    await tester.pumpAndSettle();
    await tester.tap(pickup);
    await tester.pumpAndSettle();
    await tester.ensureVisible(find.text('Customer location'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Customer location'));
    await tester.pumpAndSettle();
    expect(find.text("At the customer's location."), findsOneWidget);

    expect(find.text('Sam Tech'), findsOneWidget);
    final reimbursed = find.byKey(
      const ValueKey('project-board-part-reimbursed-1'),
    );
    await tester.ensureVisible(reimbursed);
    await tester.pumpAndSettle();
    await tester.tap(reimbursed);
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const Key('project-board-parts-save')));
    await tester.pumpAndSettle();
    expect((puts.single['parts'] as List)[1], {
      'id': 'p-2',
      'kind': 'stock',
      'item': 'Mortar',
      'quantity': 2,
      'price_minor': null,
      'paid_by_user_id': 'u-7',
      'reimbursed': true,
    });
    expect((puts.single['parts'] as List).first, {
      'id': 'p-1',
      'kind': 'pickup',
      'item': 'Stainless cap',
      'quantity': 1,
      'price_minor': null,
      'supplier_id': null,
      'destination': 'customer',
      'received': false,
    });

    // Jose, 2026-11-17: "Just let me drag them". Move right from a
    // heading's menu saves the board's order for everyone.
    await tester.tap(
      find.byKey(const ValueKey('project-board-column-menu-customer')),
    );
    await tester.pumpAndSettle();
    await tester.tap(
      find.byKey(const ValueKey('project-board-column-right-customer')),
    );
    await tester.pumpAndSettle();
    expect(puts.last, {
      'order': ['part_status', 'customer'],
    });

    await tester.pumpWidget(const SizedBox()); // stops the board's live poll
  });
}

http.Response _json(Object body) => http.Response(
  jsonEncode(body),
  200,
  headers: {'content-type': 'application/json'},
);
