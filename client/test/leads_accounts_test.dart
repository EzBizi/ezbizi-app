import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/widgets/leads_accounts.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// The connected Google Ads accounts — desktop layout 1, mobile layout 4.
///
/// The defect this exists for: one connect created twenty-two accounts in the
/// same second and the screen showed one of them, chosen by whichever the query
/// returned first. Twenty-one were invisible.
void main() {
  testWidgets('every account is listed, not the first one the query returned', (
    tester,
  ) async {
    await _pump(tester, connections: _many(22));

    // The producing group is open; the rest are behind the fold with a count
    // that names them, so nothing is silently absent.
    expect(find.text('Producing leads'), findsOneWidget);
    expect(find.text('19 that have never sent a lead'), findsOneWidget);

    await tester.tap(find.text('19 that have never sent a lead'));
    await tester.pumpAndSettle();

    expect(
      find.byType(LeadsAccountRow),
      findsNWidgets(22),
      reason:
          'Twenty-two accounts exist and all twenty-two must be reachable. '
          'Showing one was the reported defect.',
    );
  });

  testWidgets('search reaches through the fold', (tester) async {
    await _pump(tester, connections: _many(22));

    // Folded, and the fold says so rather than leaving you to guess.
    expect(find.text('Search reaches in here'), findsOneWidget);
    expect(find.byType(LeadsAccountRow), findsNWidgets(3));

    await tester.enterText(find.byType(TextField).first, 'quiet-17');
    await tester.pumpAndSettle();

    // Counted as rows rather than as text: a matching row legitimately shows
    // its name AND its number, and the search box holds the same string.
    expect(
      find.byType(LeadsAccountRow),
      findsOneWidget,
      reason:
          'A fold is only legitimate while the thing behind it stays findable. '
          'Hiding a match from the person who searched for it is the defect '
          'the fold was supposed to avoid, one level down.',
    );
    expect(find.text('400-000-0017'), findsOneWidget);
  });

  testWidgets('a placement you cannot see is stated, and cannot be changed', (
    tester,
  ) async {
    await _pump(
      tester,
      connections: [
        // A real id with a null name: the server names a franchise only when
        // the reader can reach it. This is an answer, not missing data.
        _account('405-172-9356', leads: 5, franchiseId: 'fr-elsewhere'),
      ],
    );

    expect(find.text('Placed somewhere you cannot see'), findsOneWidget);

    final change = tester.widget<TextButton>(
      find.widgetWithText(TextButton, 'Change'),
    );
    expect(
      change.onPressed,
      isNull,
      reason:
          'Offering to move something whose current value you cannot see is '
          'offering to guess.',
    );
  });

  testWidgets('an unnamed account offers a name; a named one keeps its number', (
    tester,
  ) async {
    await _pump(
      tester,
      connections: [
        _account('405-172-9356', leads: 5),
        _account('118-904-2210', leads: 3, label: 'Connecticut'),
      ],
    );

    // Unnamed: the number is the name, and the way to fix that is on the row —
    // naming twenty-two accounts has to be a scroll, not a trip per account.
    expect(find.text('405-172-9356'), findsOneWidget);
    expect(find.widgetWithText(TextButton, 'Name it'), findsOneWidget);

    // Named: both, because the name is how you find it and the number is how
    // you check it against Google Ads.
    expect(find.text('Connecticut'), findsOneWidget);
    expect(find.text('118-904-2210'), findsOneWidget);
    expect(find.widgetWithText(TextButton, 'Rename'), findsOneWidget);
  });

  testWidgets('a move of 3 leads says so; a move of 0 says something else', (
    tester,
  ) async {
    await _pump(
      tester,
      connections: [_account('405-172-9356', leads: 3)],
      moved: 3,
    );

    await tester.tap(find.widgetWithText(TextButton, 'Change'));
    await tester.pumpAndSettle();
    await tester.tap(find.widgetWithText(ChoiceChip, 'Connecticut'));
    await tester.pumpAndSettle();

    expect(
      find.text('3 leads moved to Connecticut.'),
      findsOneWidget,
      reason:
          'Placement is retroactive, so the count that moved is the whole '
          'confirmation somebody looks for.',
    );
  });

  testWidgets('a placement that moved nothing still says it took', (
    tester,
  ) async {
    await _pump(
      tester,
      connections: [_account('405-172-9356', leads: 0)],
      moved: 0,
    );

    // An account with no leads is, by definition, in the folded group.
    await tester.tap(find.text('1 that have never sent a lead'));
    await tester.pumpAndSettle();

    await tester.tap(find.widgetWithText(TextButton, 'Change'));
    await tester.pumpAndSettle();
    await tester.tap(find.widgetWithText(ChoiceChip, 'Connecticut'));
    await tester.pumpAndSettle();

    expect(
      find.text('Moved to Connecticut. No leads to move yet.'),
      findsOneWidget,
      reason:
          '0 and null are different answers. Silence after a change that took '
          'reads as a failure.',
    );
  });

  testWidgets('re-submitting the same placement announces nothing', (
    tester,
  ) async {
    await _pump(
      tester,
      connections: [_account('405-172-9356', leads: 4)],
      moved: null,
    );

    await tester.tap(find.widgetWithText(TextButton, 'Change'));
    await tester.pumpAndSettle();
    await tester.tap(find.widgetWithText(ChoiceChip, 'Connecticut'));
    await tester.pumpAndSettle();

    expect(
      find.textContaining('moved to'),
      findsNothing,
      reason:
          'leads_moved null means nothing happened. Saying "0 moved" would '
          'report a non-event as an outcome.',
    );
  });

  test('the band counts placements, which is the number that changes', () {
    expect(
      placedCount([
        _account('a', leads: 1),
        _account('b', leads: 1, franchiseId: 'fr-1', franchiseName: 'CT'),
        _account('c', leads: 1, franchiseId: 'fr-elsewhere'),
      ]),
      2,
      reason:
          'An account placed outside the reader\'s reach is still placed. '
          'Counting only the ones it can name would under-report.',
    );
  });
}

/// One connections row, in the shape `meta.connections` actually sends.
Map<String, dynamic> _account(
  String number, {
  required int leads,
  String? label,
  String? franchiseId,
  String? franchiseName,
}) => {
  'id': 'conn-$number',
  'provider': 'google_ads',
  'display_name': number,
  'status': 'active',
  'label': label,
  'leads_franchise_id': franchiseId,
  'leads_franchise_name': franchiseName,
  'leads_count': leads,
};

/// Three producing accounts and the rest silent — the real shape at A-1.
List<Map<String, dynamic>> _many(int total) => [
  for (var i = 0; i < total; i++)
    _account(
      '400-000-${i.toString().padLeft(4, '0')}',
      leads: i < 3 ? (10 - i) : 0,
      label: i < 3 ? 'busy-$i' : 'quiet-$i',
    ),
];

Future<void> _pump(
  WidgetTester tester, {
  required List<Map<String, dynamic>> connections,
  int? moved,
}) async {
  tester.view.physicalSize = const Size(1200, 2400);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(
    client: MockClient((request) async {
      if (request.url.path.endsWith('/me')) {
        return _json({
          'data': {..._me, 'permissions': const ['leads.view', 'leads.edit']},
        });
      }
      if (request.url.path.contains('leads-franchise')) {
        return _json({
          'data': {
            'id': 'conn-1',
            'provider': 'google_ads',
            'display_name': '405-172-9356',
            'leads_franchise_id': 'fr-1',
            'leads_moved': moved,
          },
        });
      }
      return _json({'message': 'Nothing stubbed for ${request.url.path}.'}, 500);
    }),
  );

  final session = Session(api);
  await tester.runAsync(() => session.adopt('tok'));
  expect(session.signedIn, isTrue);

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(
        body: SingleChildScrollView(
          child: LeadsAccountsList(
            api: api,
            session: session,
            connections: connections,
            onChanged: () async {},
          ),
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

const _me = {
  'id': 'u-1',
  'name': 'Jose',
  'email': 'jose@ezbizi.com',
  'role': {'name': 'Administrator'},
  'has_avatar': false,
  'preferences': <String, dynamic>{},
  'organization': {
    'id': 'org-1',
    'name': 'A1-Group',
    'franchise_label': 'Franchise',
    'is_read_only': false,
  },
  'scope': {
    'type': 'organization',
    'id': 'org-1',
    'label': 'A1-Group',
    'path': ['A1-Group'],
  },
  'companies': <Map<String, Object?>>[],
  'franchises': [
    {'id': 'fr-1', 'name': 'Connecticut', 'company_id': 'co-1'},
  ],
};
