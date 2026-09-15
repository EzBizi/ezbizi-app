import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/web/content_tab.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// The Pages and Posts sections of Web Management → SEO.
///
/// One widget serves both, so the assertion worth having is the one that would
/// pass if they were secretly the same list: that the type reaches the server.
/// The site is chosen by SEO's own rail, so this widget is handed it.
void main() {
  Future<List<String>> pump(
    WidgetTester tester, {
    required String type,
    List<Map<String, Object?>> items = const [],
    int total = 0,
    int pages = 0,
    int status = 200,
    String message = '',
  }) async {
    final asked = <String>[];

    final client = MockClient((request) async {
      if (request.url.path != '/api/v1/web/sites/site-1/content') {
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
        jsonEncode({'items': items, 'total': total, 'pages': pages}),
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
        body: SiteContentList(
          api: ApiClient(client: client),
          siteId: 'site-1',
          host: 'a-1chimney.com',
          type: type,
        ),
      ),
    ));
    await tester.pumpAndSettle();

    return asked;
  }

  testWidgets('it lists what the website holds', (tester) async {
    await pump(
      tester,
      type: 'page',
      total: 2,
      items: const [
        {
          'id': 12,
          'title': 'Services',
          'slug': 'services',
          'status': 'publish',
          'link': 'https://a-1chimney.com/services',
          'modified': '2026-09-10T11:00:00',
        },
        {
          'id': 13,
          'title': 'About',
          'slug': 'about',
          'status': 'draft',
          'link': 'https://a-1chimney.com/about',
          'modified': '2026-09-11T09:00:00',
        },
      ],
    );

    expect(find.text('Services'), findsOneWidget);
    expect(find.text('2 pages'), findsOneWidget);
    expect(find.textContaining('/services'), findsOneWidget);

    // Only what is NOT live is marked. A badge on every row is a column of
    // noise saying what the reader already assumes.
    expect(find.text('draft'), findsOneWidget);
    expect(find.text('publish'), findsNothing);
  });

  /// The one word that differs between the two has to reach the server, or
  /// Posts is Pages wearing a different label.
  ///
  /// Two tests, not two pumps: pumpWidget replaces the tree but Flutter UPDATES
  /// an element of the same type in the same position, so a second pump keeps
  /// the first one's state and never refetches.
  testWidgets('the Pages section asks for pages', (tester) async {
    final asked = await pump(tester, type: 'page');

    expect(asked.first, contains('type=page'));
  });

  testWidgets('the Posts section asks for posts', (tester) async {
    final asked = await pump(tester, type: 'post');

    expect(asked.first, contains('type=post'));
  });

  /// WordPress allows an untitled draft, and a blank row reads as a rendering
  /// fault rather than as the page it is.
  testWidgets('an untitled draft is named rather than blank', (tester) async {
    await pump(
      tester,
      type: 'post',
      total: 1,
      items: const [
        {
          'id': 4,
          'title': '',
          'slug': 'x',
          'status': 'draft',
          'link': '',
          'modified': '2026-09-11T09:00:00',
        },
      ],
    );

    expect(find.text('Untitled'), findsOneWidget);
  });

  /// The site is the record, so a failure names the site rather than showing
  /// an empty list that looks like a website with nothing on it.
  testWidgets('a site that cannot be read says so, and is retryable', (
    tester,
  ) async {
    await pump(
      tester,
      type: 'page',
      status: 502,
      message: 'The account on a-1chimney.com was refused.',
    );

    expect(find.textContaining('a-1chimney.com'), findsWidgets);
    expect(find.textContaining('refused'), findsOneWidget);

    // Not the empty state: "nothing here" and "we could not look" are
    // different answers and must not share a screen.
    expect(find.text('No pages to show.'), findsNothing);
  });

  testWidgets('an empty website says so in its own words', (tester) async {
    await pump(tester, type: 'post', total: 0);

    expect(find.text('No posts to show.'), findsOneWidget);
  });

  /// A pager that cannot move is a control teaching people it is broken.
  ///
  /// Two tests rather than two pumps in one: pumpWidget REPLACES the tree but
  /// Flutter updates an element of the same type in the same position, so the
  /// second pump kept the first one's state and the second assertion was
  /// reading the first fixture.
  testWidgets('one page of results shows no pager', (tester) async {
    await pump(tester, type: 'page', total: 3, pages: 1, items: const [
      {
        'id': 1,
        'title': 'One',
        'slug': 'one',
        'status': 'publish',
        'link': '',
        'modified': '',
      },
    ]);

    expect(find.textContaining('Page 1 of'), findsNothing);
  });

  testWidgets('more than one page shows where you are', (tester) async {
    await pump(tester, type: 'page', total: 90, pages: 2, items: const [
      {
        'id': 1,
        'title': 'One',
        'slug': 'one',
        'status': 'publish',
        'link': '',
        'modified': '',
      },
    ]);

    expect(find.text('Page 1 of 2'), findsOneWidget);
  });
}
