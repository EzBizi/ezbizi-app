import 'dart:convert';
import 'dart:io';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/monitoring/ownership_tab.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:ezbizi_client/widgets/list_search.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Search boxes are the standard field height (Jose, 2026-10-27).
///
/// Monitoring › General's "Find a person" painted about 22 px tall beside a
/// normal Leads search. The cause was a shared pattern on ~50 search boxes:
/// `isDense` plus a horizontal-only `contentPadding` (no vertical padding)
/// inside a `SizedBox(height: 40)`. The decorator sized itself to the text,
/// ~24 px, and the fixed height only added empty space around it.
void main() {
  testWidgets('Find a person is as tall as the Leads search', (tester) async {
    tester.view.physicalSize = const Size(1600, 900);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);

    final api = ApiClient(
      client: MockClient((request) async {
        final path = request.url.path;
        final body = path.endsWith('/monitoring/ownership')
            ? {
                'data': [
                  {
                    'user': {'id': 'u-1', 'name': 'Marco Reyes'},
                    'role': 'Dispatcher',
                    'online': true,
                  },
                ],
              }
            : {'data': <String, dynamic>{}};
        return http.Response(
          jsonEncode(body),
          200,
          headers: {'content-type': 'application/json'},
        );
      }),
    );
    final session = Session(api);

    await tester.pumpWidget(
      MaterialApp(
        theme: EzBiziTheme.light(),
        home: Scaffold(
          body: Column(
            children: [
              // The Leads search's own decoration (leads_screen.dart).
              const SizedBox(
                width: 300,
                child: TextField(
                  key: Key('leads-reference'),
                  decoration: InputDecoration(
                    isDense: true,
                    prefixIcon: Icon(Icons.search, size: 20),
                    hintText: 'Search name, phone or message',
                    border: OutlineInputBorder(),
                  ),
                ),
              ),
              Expanded(
                child: OwnershipTab(api: api, session: session),
              ),
            ],
          ),
        ),
      ),
    );
    await tester.pumpAndSettle();

    double decoratorHeight(String key) => tester
        .getSize(
          find.descendant(
            of: find.byKey(Key(key)),
            matching: find.byType(InputDecorator),
          ),
        )
        .height;

    final leads = decoratorHeight('leads-reference');
    expect(find.text('Marco Reyes'), findsOneWidget);
    expect(leads, greaterThanOrEqualTo(40));
    // Leads itself went to 44 on 2026-11-02 (6af32366a); every list search is
    // now ListSearch at that height.
    expect(decoratorHeight('ownership-search'), ListSearch.height);
  });

  test('no search box drops its vertical padding again', () {
    final thin = RegExp(
      r'contentPadding: (const )?EdgeInsets\.symmetric\(horizontal: [0-9.]+\)',
    );
    final offenders = <String>[];
    for (final file in Directory('lib').listSync(recursive: true)) {
      if (file is! File || !file.path.endsWith('.dart')) continue;
      final lines = file.readAsLinesSync();
      for (var i = 0; i < lines.length; i++) {
        if (!thin.hasMatch(lines[i])) continue;
        final from = i - 10 < 0 ? 0 : i - 10;
        final to = i + 10 > lines.length ? lines.length : i + 10;
        if (lines.sublist(from, to).join('\n').contains('Icons.search')) {
          offenders.add('${file.path}:${i + 1}');
        }
      }
    }
    expect(offenders, isEmpty);
  });
}
