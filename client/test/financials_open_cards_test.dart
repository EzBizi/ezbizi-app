import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/financials_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Every field type the server can send, the shapes AirtableMirror leaves
/// behind (text kept in a typed column, an "#ERROR!" string), nulls, and 30+
/// columns: what a mirrored base actually opens on.
Map<String, dynamic> _wideTable({required bool mirror, bool cut = false}) {
  final fields = <Map<String, dynamic>>[
    {
      'id': 'f0',
      'name': 'Job',
      'type': 'text',
      'options': {},
      'is_primary': true,
      'locked': false,
    },
    {
      'id': 'f1',
      'name': 'Notes',
      'type': 'long_text',
      'options': {},
      'is_primary': false,
      'locked': false,
    },
    {
      'id': 'f2',
      'name': 'Qty',
      'type': 'number',
      'options': {},
      'is_primary': false,
      'locked': false,
    },
    {
      'id': 'f3',
      'name': 'Amount',
      'type': 'currency',
      'options': {'symbol': r'$', 'precision': 2},
      'is_primary': false,
      'locked': false,
    },
    {
      'id': 'f4',
      'name': 'Share',
      'type': 'percent',
      'options': {},
      'is_primary': false,
      'locked': false,
    },
    {
      'id': 'f5',
      'name': 'Paid on',
      'type': 'date',
      'options': {},
      'is_primary': false,
      'locked': false,
    },
    {
      'id': 'f6',
      'name': 'Paid',
      'type': 'checkbox',
      'options': {},
      'is_primary': false,
      'locked': false,
    },
    {
      'id': 'f7',
      'name': 'Status',
      'type': 'single_select',
      'is_primary': false,
      'locked': false,
      'options': {
        'choices': [
          {'name': 'Open'},
          {'name': 'Closed'},
        ],
      },
    },
    {
      'id': 'f8',
      'name': 'Tags',
      'type': 'multi_select',
      'is_primary': false,
      'locked': false,
      'options': {
        'choices': [
          {'name': 'North'},
          {'name': 'South'},
        ],
      },
    },
    {
      'id': 'f9',
      'name': 'Email',
      'type': 'email',
      'options': {},
      'is_primary': false,
      'locked': false,
    },
    {
      'id': 'f10',
      'name': 'Phone',
      'type': 'phone',
      'options': {},
      'is_primary': false,
      'locked': false,
    },
    {
      'id': 'f11',
      'name': 'Link',
      'type': 'url',
      'options': {},
      'is_primary': false,
      'locked': false,
    },
    {
      'id': 'f12',
      'name': 'Stars',
      'type': 'rating',
      'options': {},
      'is_primary': false,
      'locked': false,
    },
    {
      'id': 'f13',
      'name': 'Double',
      'type': 'formula',
      'options': {'formula': '{Qty} * 2'},
      'is_primary': false,
      'locked': true,
    },
    {
      'id': 'f14',
      'name': 'Receipt',
      'type': 'attachment',
      'options': {},
      'is_primary': false,
      'locked': true,
    },
    // An Airtable rollup mirrored as text and locked.
    {
      'id': 'f15',
      'name': 'Rollup',
      'type': 'text',
      'options': {},
      'is_primary': false,
      'locked': true,
    },
    for (var i = 16; i < 34; i++)
      {
        'id': 'f$i',
        'name': 'Column $i',
        'type': i.isEven ? 'number' : 'text',
        'options': {},
        'is_primary': false,
        'locked': false,
      },
  ];
  Map<String, dynamic> row(int n) => {
    'id': 'r$n',
    'created_at': '2026-10-0${n + 1}T12:00:00+00:00',
    'values': n == 2
        ? <String, dynamic>{}
        : {
            'f0': 'Gate fix $n',
            'f1': 'Line one\nline two',
            // AirtableMirror::cell keeps text that does not fit its column.
            'f2': n == 1 ? 'N/A' : 3,
            'f3': n == 1 ? '#ERROR!' : 1250.5,
            'f4': 12.5,
            'f5': '2026-10-0${n + 1}',
            'f6': n == 0,
            'f7': n == 1 ? 'Archived' : 'Open',
            'f8': ['North', 'South'],
            'f9': 'pat@example.com',
            'f10': '555-0100',
            'f11': 'https://example.com/x',
            'f12': 4,
            'f14': [
              {
                'id': 'att1',
                'filename': 'receipt.png',
                'url': 'https://example.com/r.png',
                'type': 'image/png',
                'size': 1200,
              },
            ],
            'f15': 'recA, recB',
            for (var i = 16; i < 34; i++) 'f$i': i.isEven ? i * 10 : 'v$i',
          },
  };
  return {
    'id': 't-wide',
    'base_id': 'b-books',
    'name': 'Ledger',
    'mirror': mirror
        ? {
            'base_id': 'appBooks',
            'table_id': 'tblLedger',
            'synced_at': '2026-10-21T12:00:00+00:00',
          }
        : null,
    'cut_over': cut
        ? {'at': '2026-10-20T12:00:00+00:00', 'airtable': 'appBooks/tblLedger'}
        : null,
    'fields': fields,
    'views': [
      {'id': 'v1', 'name': 'Grid view', 'type': 'grid', 'config': {}},
      {
        'id': 'v2',
        'name': 'Sorted',
        'type': 'grid',
        'config': {
          'sorts': [
            {'field': 'f2', 'dir': 'desc'},
          ],
          'filters': [
            {'field': 'f3', 'op': 'gt', 'value': '0'},
          ],
          'group_field': 'f7',
          'hidden': ['f1'],
        },
      },
    ],
    'records': [for (var n = 0; n < 40; n++) row(n % 3 == 2 ? 2 : n % 3)],
  };
}

Map<String, dynamic> _home() => {
  'areas': [
    {
      'key': 'disputes',
      'name': 'Quarrels & Refunds',
      'icon': 'undo',
      'colour_hint': 0,
      'figure': 'This week: \$120',
      'source': 'both',
      'refreshed_at': '2026-10-21T12:00:00+00:00',
      'tables': [
        {
          'key': 'appQ/tblQ',
          'name': 'Quarrels',
          'count': 40,
          'table_id': 't-wide',
          'route': '/financials/tables/t-wide',
          'may_open': true,
          'may_mirror': true,
          'refused': null,
        },
        {
          'key': 'adjustments',
          'name': 'Recorded in EzBizi',
          'count': 0,
          'table_id': null,
          'route': '/financials/adjustments',
          'may_open': true,
          'refused': null,
        },
      ],
    },
    {
      'key': 'deposits',
      'name': 'Pebble Deposits',
      'icon': 'savings',
      'colour_hint': 1,
      'figure': null,
      'source': 'airtable',
      'refreshed_at': null,
      'tables': [
        {
          'key': 'appD/tblD',
          'name': 'Deposits',
          'count': 40,
          'table_id': 't-wide',
          'route': '/financials/tables/t-wide',
          'may_open': true,
          'may_mirror': true,
          'refused': null,
        },
      ],
    },
  ],
  'bases': [
    {
      'id': 'b-books',
      'name': 'Pebble Bookkeeping',
      'colour_hint': 'teal',
      'figure': '1 table · 40 records',
      'source': 'airtable',
      'refreshed_at': '2026-10-21T12:00:00+00:00',
    },
  ],
  'may_create_base': true,
};

Future<void> _pump(
  WidgetTester tester,
  Size size,
  Map<String, dynamic> table,
) async {
  tester.view.physicalSize = size;
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);
  http.Response json(Object body, [int status = 200]) => http.Response(
    jsonEncode(body),
    status,
    headers: const {'content-type': 'application/json'},
  );
  final api = ApiClient(
    client: MockClient((request) async {
      final path = request.url.path;
      if (path.endsWith('/me')) {
        return json({
          'data': {
            'id': 'u-1',
            'name': 'Pat',
            'email': 'pat@example.com',
            'role': {'name': 'Administrator'},
            'has_avatar': false,
            'preferences': <String, dynamic>{
              'financials.column_widths': [],
              'help.tours_seen': [],
            },
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
            'franchises': <Map>[],
            'modules': [
              {
                'key': 'financials',
                'label': 'Financials',
                'available': true,
                'reachable': true,
              },
            ],
            'permissions': [
              'financials.view',
              'financials.bases.view',
              'financials.bills.view',
              'financials.settings.view',
              'financials.create',
              'financials.edit',
              'financials.delete',
            ],
          },
        });
      }
      if (path.endsWith('/financials/home')) return json({'data': _home()});
      if (path.endsWith('/financials/bases')) {
        return json({
          'data': [
            {
              'id': 'b-books',
              'name': 'Pebble Bookkeeping',
              'colour': 'teal',
              'company_id': 'co-1',
              'franchise_id': null,
              'place': 'Everdoors',
              'tables': [
                {'id': 't-wide', 'name': 'Ledger'},
              ],
            },
          ],
        });
      }
      if (path.endsWith('/financials/adjustments')) return json({'data': []});
      if (path.contains('/financials/tables/')) return json({'data': table});
      return json({'message': 'Nothing stubbed for $path.'}, 500);
    }),
  );
  final session = Session(api);
  await tester.runAsync(() => session.adopt('tok'));
  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(
        body: FinancialsScreen(api: api, session: session),
      ),
    ),
  );
  await tester.pumpAndSettle();
}

void main() {
  for (final size in const [Size(1600, 1000), Size(390, 844)]) {
    for (final (label, table) in [
      ('mirrored', _wideTable(mirror: true)),
      ('cut over', _wideTable(mirror: false, cut: true)),
      ('native', _wideTable(mirror: false)),
    ]) {
      final at = '${size.width.toInt()}x${size.height.toInt()} $label';
      testWidgets('$at: a base card opens its wide table', (tester) async {
        await _pump(tester, size, table);
        await tester.tap(find.text('Pebble Bookkeeping'));
        await tester.pumpAndSettle();
        expect(find.text('Gate fix 0'), findsWidgets);
        await tester.tap(find.text('Sorted'));
        await tester.pumpAndSettle();
        expect(find.text('Gate fix 0'), findsWidgets);
      });
      testWidgets('$at: area cards open in place', (tester) async {
        await _pump(tester, size, table);
        for (final name in ['Quarrels & Refunds', 'Pebble Deposits']) {
          await tester.tap(find.text(name));
          await tester.pumpAndSettle();
          expect(find.text('Gate fix 0'), findsWidgets);
          await tester.tap(find.byKey(const ValueKey('financials-back')));
          await tester.pumpAndSettle();
        }
        await tester.tap(find.text('Quarrels & Refunds'));
        await tester.pumpAndSettle();
        await tester.tap(find.text('Recorded in EzBizi · 0'));
        await tester.pumpAndSettle();
      });
    }
  }
}
