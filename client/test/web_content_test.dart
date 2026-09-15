import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/web/content_tab.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Web Management → Pages and → Posts.
///
/// One widget serves both tabs, so the assertions worth having are the ones
/// that would pass if the two were secretly the same screen: that the type
/// reaches the server, and that switching it refetches rather than leaving one
/// list on screen under the other's name.
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
      final path = request.url.path;
      Map<String, Object?> body;

      if (path == '/api/v1/me') {
        body = {
          'data': {
            'id': 'u-1',
            'name': 'Jose Silva',
            'email': 'jose@example.com',
            'has_avatar': false,
            'role': {'id': 'role-1', 'name': 'Administrator'},
            'permissions': const ['web_management.view', 'web_management.edit'],
            'modules': const [
              {
                'key': 'web_management',
                'label': 'Web Management',
                'available': true,
                'reachable': true,
              },
            ],
            'preferences': const <String, dynamic>{},
            'organization': {
              'id': 'org-1',
              'name': 'A-1 Chimney Group',
              'franchise_label': 'Franchise',
              'is_read_only': false,
            },
            'scope': null,
          },
        };
      } else if (path == '/api/v1/web/sites') {
        body = {
          'data': const [
            {
              'id': 'site-1',
              'host': 'a-1chimney.com',
              'label': null,
              'is_primary': true,
              'public_key': 'pk_x',
              'company': {'id': 'c1', 'name': 'A-1'},
              'franchise': null,
            },
          ],
        };
      } else if (path == '/api/v1/web/sites/site-1/content') {
        asked.add(request.url.query);

        if (status != 200) {
          return http.Response(
            jsonEncode({'message': message}),
            status,
            headers: {'content-type': 'application/json'},
          );
        }

        body = {'items': items, 'total': total, 'pages': pages};
      } else {
        return http.Response('{}', 404,
            headers: {'content-type': 'application/json'});
      }

      return http.Response(
        jsonEncode(body),
        200,
        headers: {'content-type': 'application/json'},
      );
    });

    final api = ApiClient(client: client);
    final session = Session(api);

    await tester.runAsync(() => session.adopt('tok'));

    tester.view.physicalSize = const Size(1280, 1400);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);

    await tester.pumpWidget(MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(
        body: ContentTab(api: api, session: session, type: type),
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

  /// The one word that differs between the two tabs has to reach the server,
  /// or Posts is Pages wearing a different label.
  testWidgets('each tab asks for its own type', (tester) async {
    final pagesAsked = await pump(tester, type: 'page');
    expect(pagesAsked.first, contains('type=page'));

    final postsAsked = await pump(tester, type: 'post');
    expect(postsAsked.first, contains('type=post'));
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
