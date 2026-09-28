import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/customers_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// The list is not a dead end. The API already knew how to show, edit,
/// deactivate, delete and reassign a customer; these pin the missing face.
void main() {
  /// A franchise NAME does not identify a franchise: two companies in one
  /// organization may each have a Florida. The tag is what stops somebody
  /// acting on another franchise's customer, so it has to say which.
  testWidgets('the owner tag names the company as well as the franchise', (
    tester,
  ) async {
    await _pump(tester, customers: const [_alice]);

    expect(find.text('Everdoors · Connecticut'), findsOneWidget);
    expect(
      find.text('Connecticut'),
      findsNothing,
      reason: 'The bare franchise name is the ambiguity being fixed.',
    );
  });

  /// A search escapes the view scope on purpose, so any view can be showing
  /// rows from across the organization — the qualifier cannot be conditional
  /// on standing in the organization view.
  testWidgets('the company is named in a franchise view too', (tester) async {
    await _pump(tester, customers: const [_alice], scope: const {
      'type': 'franchise',
      'id': 'fr-1',
      'label': 'Connecticut',
      'path': ['Everdoors', 'Connecticut'],
    });

    expect(find.text('Everdoors · Connecticut'), findsOneWidget);
  });

  testWidgets('a server that sends no company still reads as it always did', (
    tester,
  ) async {
    await _pump(tester, customers: const [_aliceWithoutCompany]);

    expect(find.text('Connecticut'), findsOneWidget);
  });

  testWidgets('a long company name shortens the tag rather than the window', (
    tester,
  ) async {
    await _pump(
      tester,
      customers: const [_aliceInALongCompany],
      size: const Size(375, 800),
    );

    expect(tester.takeException(), isNull);
  });

  testWidgets('creating a customer offers a street address', (tester) async {
    await _pump(tester);

    await tester.tap(find.text('Add customer'));
    await tester.pumpAndSettle();

    expect(find.text('Street address'), findsOneWidget);
    expect(find.text('City'), findsOneWidget);
    expect(find.text('State'), findsOneWidget);
  });

  testWidgets('opening a row loads the customer and can deactivate them', (
    tester,
  ) async {
    final paths = <String>[];

    await _pump(
      tester,
      customers: [_alice],
      onRequest: (request) {
        paths.add('${request.method} ${request.url.path}');

        if (request.method == 'PATCH' &&
            request.url.path.endsWith('/customers/c-1')) {
          return _json({
            'data': {..._aliceDetail, 'is_active': false},
          });
        }

        return null;
      },
    );

    await tester.tap(find.text('Alice Roofing'));
    await tester.pumpAndSettle();

    expect(find.textContaining('C-1001'), findsWidgets);

    // The WHOLE subtitle, number first. The list row already reads
    // "Everdoors · Connecticut" and so does the org name in the fixture, so
    // asserting only the owner tag would pass against the row behind the panel
    // and prove nothing about the panel. Only the panel prefixes the number.
    expect(find.text('C-1001 · Everdoors · Connecticut'), findsOneWidget);
    expect(find.text('Street address'), findsOneWidget);
    expect(find.text('Deactivate'), findsOneWidget);
    expect(find.text('Reassign owner'), findsOneWidget);
    expect(find.text('Delete'), findsOneWidget);

    await tester.ensureVisible(find.text('Deactivate'));
    await tester.tap(find.text('Deactivate'));
    await tester.pumpAndSettle();

    expect(
      paths.any((p) => p == 'PATCH /api/v1/customers/c-1'),
      isTrue,
      reason: 'Deactivate is a save of is_active, not a missing control.',
    );
  });

  testWidgets('delete is refused in a sentence when the role cannot', (
    tester,
  ) async {
    await _pump(
      tester,
      permissions: const ['customers.view', 'customers.create', 'customers.edit'],
      customers: [_alice],
    );

    await tester.tap(find.text('Alice Roofing'));
    await tester.pumpAndSettle();

    expect(
      find.byTooltip(
        'You do not have permission to delete a customer. That is part of a '
        'role rather than of an account, so somebody who can edit roles can '
        'add it to yours.',
      ),
      findsOneWidget,
    );
  });

  /// The sweep, not the create-time check. An import writes duplicates in bulk
  /// and nobody types them, so the only place they can surface is a list
  /// somebody can ask for -- which means the button has to be on the screen
  /// rather than only in the moment after a save.
  testWidgets('the header opens a sweep of duplicates across the organization', (
    tester,
  ) async {
    await _pump(
      tester,
      // 375 wide, because that is the floor the house holds every screen to and
      // a panel is where it breaks first: SidePanel is 440 by default and goes
      // full-width below that, so this is the case where the group header and
      // the owner tag have the least room they will ever have.
      size: const Size(375, 812),
      customers: const [_alice],
      onRequest: (request) {
        if (!request.url.path.endsWith('/customers/duplicates')) return null;

        return _json({
          'mode': 'phone',
          // Truncating, and saying so with a real number. The screen has to
          // print "3" and not the 1 group it was handed, or a person who has
          // seen one duplicate believes they have seen the problem.
          'limited': true,
          'total': 3,
          'data': [
            {
              'by': 'phone',
              'value': '5551234567',
              'customers': [
                {
                  'id': 'c-1',
                  'number': 'C-1',
                  'name': 'Philip Ash',
                  'owning_franchise': {
                    'id': 'f-1',
                    'name': 'Florida',
                    'company': {'id': 'co-1', 'name': 'A-1 Chimney'},
                  },
                },
                {
                  'id': 'c-2',
                  'number': 'C-2',
                  'name': 'Phil Ash',
                  'owning_franchise': {
                    'id': 'f-2',
                    'name': 'New Jersey',
                    'company': {'id': 'co-1', 'name': 'A-1 Chimney'},
                  },
                },
              ],
            },
          ],
        });
      },
    );

    await tester.tap(find.text('Possible duplicates'));
    await tester.pumpAndSettle();

    expect(find.text('Philip Ash (C-1)'), findsOneWidget);
    expect(find.text('Phil Ash (C-2)'), findsOneWidget);

    // The owner tag on both sides, because merging them is a decision about
    // which branch keeps the relationship and not only which row lives.
    expect(find.text('A-1 Chimney · Florida'), findsOneWidget);
    expect(find.text('A-1 Chimney · New Jersey'), findsOneWidget);

    // Said out loud on the screen, every time: nothing here merges anything.
    expect(find.textContaining('Nothing has been merged'), findsOneWidget);

    expect(find.text('Showing 1 of 3.'), findsOneWidget);
  });

  /// "We looked and found none" and "nobody is looking" are different answers,
  /// and an empty panel shows the first for both. The server sends a sentence
  /// for the second; this pins that the screen prints it rather than an
  /// all-clear it has not earned.
  testWidgets('detection switched off says so instead of showing none found', (
    tester,
  ) async {
    await _pump(
      tester,
      customers: const [_alice],
      onRequest: (request) {
        if (!request.url.path.endsWith('/customers/duplicates')) return null;

        return _json({
          'mode': 'off',
          'limited': false,
          'data': const [],
          'message': 'Duplicate detection is switched off for this organization.',
        });
      },
    );

    await tester.tap(find.text('Possible duplicates'));
    await tester.pumpAndSettle();

    expect(
      find.text('Duplicate detection is switched off for this organization.'),
      findsOneWidget,
    );
    expect(find.text('Nothing looks duplicated.'), findsNothing);
  });


  /// A header click asks the SERVER again. The list is paged, so sorting the
  /// rows on screen would sort the fifty that happened to load and nothing
  /// else; the proof that it did not is the query that went out.
  ///
  /// And the date is written in the ORGANIZATION's format. M/D/YYYY is set
  /// here on purpose: the fallback format is YYYY-MM-DD, so a column that
  /// ignored the setting would print 2026-08-10 and this would go red.
  testWidgets('the Created column sorts by asking the server, newest first', (
    tester,
  ) async {
    final sorts = <String>[];

    await _pump(
      tester,
      customers: [
        {..._alice, 'created_at': '2026-08-10T12:00:00Z'},
      ],
      onRequest: (request) {
        final path = request.url.path;

        if (path.endsWith('/me')) {
          return _json({
            'data': {
              ..._me,
              'permissions': const ['customers.view'],
              'display': {'date_format': 'M/D/YYYY'},
            },
          });
        }

        if (path.endsWith('/customers')) {
          final q = request.url.queryParameters;
          sorts.add('${q['sort']} ${q['direction']}');
        }

        return null;
      },
    );

    expect(find.text('8/10/2026'), findsOneWidget);

    // Name order by default, so the letter heading is there.
    expect(sorts.last, 'name asc');
    expect(find.text('A'), findsOneWidget);

    await tester.tap(find.text('Created'));
    await tester.pumpAndSettle();

    // A date starts newest first -- nobody sorts by created to read 2019.
    expect(sorts.last, 'created desc');

    // And the letter heading is gone: "A" over rows ordered by date would be
    // a heading that is true and says nothing.
    expect(find.text('A'), findsNothing);

    await tester.tap(find.text('Created'));
    await tester.pumpAndSettle();

    expect(sorts.last, 'created asc');
  });

}

const _alice = {
  'id': 'c-1',
  'name': 'Alice Roofing',
  'number': 'C-1001',
  'email': 'alice@example.com',
  'phone': '203-555-0100',
  'is_active': true,
  'jobs_count': 0,
  'owning_franchise': {
    'id': 'fr-1',
    'name': 'Connecticut',
    'company': {'id': 'co-1', 'name': 'Everdoors'},
  },
};

/// The same customer from a server that does not send the company yet.
///
/// Written out rather than spread over [_alice]: a const map cannot override a
/// key it already has, and the whole point of this fixture is the one key.
const _aliceWithoutCompany = {
  'id': 'c-1',
  'name': 'Alice Roofing',
  'number': 'C-1001',
  'email': 'alice@example.com',
  'phone': '203-555-0100',
  'is_active': true,
  'jobs_count': 0,
  'owning_franchise': {'id': 'fr-1', 'name': 'Connecticut'},
};

/// A long company name, to prove the tag shortens rather than shoving the row
/// off the edge of a phone.
const _aliceInALongCompany = {
  'id': 'c-1',
  'name': 'Alice Roofing',
  'number': 'C-1001',
  'email': 'alice@example.com',
  'phone': '203-555-0100',
  'is_active': true,
  'jobs_count': 0,
  'owning_franchise': {
    'id': 'fr-1',
    'name': 'Connecticut',
    'company': {
      'id': 'co-1',
      'name': 'A-1 Chimney Sweep and Masonry Restoration Incorporated',
    },
  },
};

const _aliceDetail = {
  ..._alice,
  'address': {
    'line1': '12 Oak St',
    'line2': null,
    'city': 'Hartford',
    'state': 'CT',
    'postal_code': '06103',
  },
  'notes': null,
};

Future<void> _pump(
  WidgetTester tester, {
  List<String> permissions = const [
    'customers.view',
    'customers.create',
    'customers.edit',
    'customers.delete',
  ],
  List<Map<String, dynamic>> customers = const [],
  http.Response? Function(http.Request request)? onRequest,
  Map<String, Object?>? scope,
  Size size = const Size(1280, 900),
}) async {
  tester.view.physicalSize = size;
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(
    client: MockClient((request) async {
      final override = onRequest?.call(request);
      if (override != null) return override;

      final path = request.url.path;

      if (path.endsWith('/me')) {
        return _json({
          'data': {..._me, 'permissions': permissions, 'scope': scope ?? _me['scope']},
        });
      }

      if (path.endsWith('/exports')) {
        return _json({
          'resources': [
            {
              'key': 'customers',
              'label': 'Customers',
              'columns': ['number', 'name'],
              'importable': true,
              'import_permission': 'customers.create',
              'import_columns': ['number', 'name', 'email'],
            },
          ],
          'organization': {
            'export_refusal': null,
            'inventory_refusal': null,
          },
        });
      }

      if (path.endsWith('/customers/c-1')) {
        return _json({'data': _aliceDetail});
      }

      if (path.endsWith('/customers')) {
        return _json({
          'data': customers,
          'meta': {'total': customers.length, 'last_page': 1},
        });
      }

      return _json({'message': 'Nothing stubbed for $path.'}, 500);
    }),
  );

  final session = Session(api);
  await tester.runAsync(() => session.adopt('tok'));
  expect(session.signedIn, isTrue);

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(body: CustomersScreen(api: api, session: session)),
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
  'companies': <dynamic>[],
  'franchises': [
    {'id': 'fr-1', 'name': 'Connecticut', 'company_id': 'co-1'},
  ],
};
