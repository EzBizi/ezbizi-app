import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/web/cities_pane.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// The Cities pane of Web Management → SEO.
///
/// Toula has 8,956 city pages filled in already, so the assertions that matter
/// are about READING them: grouped the way she grouped them, searchable, and
/// present even when the customer's own website cannot be reached. Nothing in
/// this pane writes, and there is no endpoint it could write to.
void main() {
  /// @return the query strings the pane asked with, in order.
  Future<List<String>> pump(
    WidgetTester tester, {
    List<Map<String, Object?>> cities = const [],
    String? note,
    int status = 200,
    String message = '',
  }) async {
    final asked = <String>[];

    final client = MockClient((request) async {
      if (request.url.path != '/api/v1/web/sites/site-1/cities') {
        return http.Response('{}', 404,
            headers: {'content-type': 'application/json'});
      }

      asked.add(request.url.query);

      if (status != 200) {
        return http.Response(
          jsonEncode({'message': message}),
          status,
          headers: {'content-type': 'application/json'},
        );
      }

      return http.Response(
        jsonEncode({
          'cities': cities,
          'scanned': request.url.query.contains('scan=1'),
          'note': note,
        }),
        200,
        headers: {'content-type': 'application/json'},
      );
    });

    tester.view.physicalSize = const Size(1000, 1400);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);

    await tester.pumpWidget(MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(
        body: CitiesPane(
          api: ApiClient(client: client),
          siteId: 'site-1',
          host: 'nj.a-1chimney.com',
          canEdit: true,
        ),
      ),
    ));
    await tester.pumpAndSettle();

    return asked;
  }

  /// Two regions and a town she has not filed yet, in the shape the server
  /// sends them — already sorted, with the ungrouped last.
  const sample = [
    {
      'path': '/service-areas/fairburn-ga',
      'city': 'Fairburn',
      'state': 'GA',
      'phone': '',
      'group': 'North Fulton',
      'filled': 2,
      'in_ezbizi': true,
      'title': null,
    },
    {
      'path': '/service-areas/toms-river-nj',
      'city': 'Toms River',
      'state': 'NJ',
      'phone': '732-555-0100',
      'group': 'South Jersey',
      'filled': 4,
      'in_ezbizi': true,
      'title': null,
    },
    {
      'path': '/service-areas/bare-nj',
      'city': 'Bare',
      'state': '',
      'phone': '',
      'group': '',
      'filled': 1,
      'in_ezbizi': true,
      'title': null,
    },
  ];

  testWidgets('cities are grouped by her own region label', (tester) async {
    await pump(tester, cities: sample);

    expect(find.text('North Fulton'), findsOneWidget);
    expect(find.text('South Jersey'), findsOneWidget);

    // 7,904 of the 8,956 carry a group, so the ones that do not are a real and
    // shorter list — the towns still to be filed — and worth naming.
    expect(find.text('Not grouped yet'), findsOneWidget);

    // Collapsed by default: ten regions of sixty towns is not a list, and the
    // count on the header is what somebody is actually reading.
    expect(find.text('Toms River, NJ'), findsNothing);
    expect(find.text('3 cities'), findsOneWidget);
  });

  testWidgets('a group opens to show its towns', (tester) async {
    await pump(tester, cities: sample);

    await tester.tap(find.text('South Jersey'));
    await tester.pumpAndSettle();

    expect(find.text('Toms River, NJ'), findsOneWidget);
    expect(find.textContaining('732-555-0100'), findsOneWidget);
    // How much of the page is answered, so the thin ones can be seen.
    expect(find.text('4 values'), findsOneWidget);

    // The other region stays shut.
    expect(find.text('Fairburn, GA'), findsNothing);
  });

  /// A search that collapsed its own results would hide the thing somebody
  /// just asked for.
  testWidgets('searching opens the groups it matches', (tester) async {
    await pump(tester, cities: sample);

    await tester.enterText(find.byType(TextField), 'toms');
    await tester.pumpAndSettle();

    expect(find.text('Toms River, NJ'), findsOneWidget);
    expect(find.text('North Fulton'), findsNothing);
    expect(find.text('1 of 3'), findsOneWidget);
  });

  /// Filtered in the pane, so it costs no request — the whole list arrived in
  /// the one the pane makes on open.
  testWidgets('searching asks the server for nothing', (tester) async {
    final asked = await pump(tester, cities: sample);

    await tester.enterText(find.byType(TextField), 'fairburn');
    await tester.pumpAndSettle();

    expect(asked, hasLength(1));
  });

  testWidgets('a town can be found by its region or its path', (tester) async {
    await pump(tester, cities: sample);

    await tester.enterText(find.byType(TextField), 'north fulton');
    await tester.pumpAndSettle();
    expect(find.text('Fairburn, GA'), findsOneWidget);

    await tester.enterText(find.byType(TextField), 'bare-nj');
    await tester.pumpAndSettle();
    expect(find.text('Bare'), findsOneWidget);
  });

  /// The scan is the worklist half: which towns the website has pages for that
  /// EzBizi publishes nothing about.
  testWidgets('the scan is a button, and asks for scan=1', (tester) async {
    final asked = await pump(tester, cities: sample);

    expect(asked.first, isNot(contains('scan')));

    await tester.tap(find.byTooltip(
      'Look on the website for city pages EzBizi has nothing for yet',
    ));
    await tester.pumpAndSettle();

    expect(asked.last, contains('scan=1'));
  });

  testWidgets('a town the website has and EzBizi does not is marked', (
    tester,
  ) async {
    await pump(tester, cities: const [
      {
        'path': '/service-areas/brick-nj',
        'city': '',
        'state': '',
        'phone': '',
        'group': '',
        'filled': 0,
        'in_ezbizi': false,
        'title': 'Brick, NJ',
      },
    ]);

    await tester.tap(find.text('Not grouped yet'));
    await tester.pumpAndSettle();

    // Named by the website's own title, because EzBizi holds no city for it.
    expect(find.text('Brick, NJ'), findsOneWidget);
    expect(find.text('nothing yet'), findsOneWidget);
  });

  /// A failed scan is a NOTE on a good answer. The cities beside it came from
  /// our own database and are already right.
  testWidgets('a note does not replace the list', (tester) async {
    await pump(
      tester,
      cities: sample,
      note: 'The account on nj.a-1chimney.com was refused.',
    );

    expect(find.textContaining('refused'), findsOneWidget);
    expect(find.text('South Jersey'), findsOneWidget);
  });

  testWidgets('a refusal is a retryable error, not an empty list', (
    tester,
  ) async {
    await pump(
      tester,
      status: 403,
      message: 'You do not have access to this website.',
    );

    expect(find.textContaining('access'), findsOneWidget);
    expect(find.textContaining('publishes nothing'), findsNothing);
  });

  testWidgets('a site with no cities says what to do about it', (tester) async {
    await pump(tester);

    expect(find.textContaining('publishes nothing about a city'),
        findsOneWidget);
  });

  /// The row opens the page's own EzBizi screen, the same one Pages opens, and
  /// the icon beside it opens the real page. Her workflow does not change.
  testWidgets('a row opens the page editor inside the pane', (tester) async {
    await pump(tester, cities: sample);

    await tester.tap(find.text('South Jersey'));
    await tester.pumpAndSettle();

    expect(find.byTooltip('Open the page on the website'), findsWidgets);

    await tester.tap(find.text('Toms River, NJ'));
    await tester.pumpAndSettle();

    expect(
      find.text('nj.a-1chimney.com/service-areas/toms-river-nj'),
      findsOneWidget,
    );
  });
}
