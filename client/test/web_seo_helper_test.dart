import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/web/seo_helper_pane.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Web Management › SEO › SEO Helper (design 29, Jose's picks 1 and 4).
///
/// The site keeps the values and its plugin prints them; the pane reads,
/// checks and writes them through Web Management's endpoints (a90958e4). So
/// the assertions are about what is asked of the server and what is drawn
/// from its answer.
void main() {
  Map<String, Object?> row(
    int id,
    String name, {
    String score = 'good',
    String description = 'A description of the right length for this page.',
    String keyphrase = 'chimney sweep',
    bool noindex = false,
    List<Map<String, Object?>> issues = const [],
  }) => {
    'id': id,
    'type': 'page',
    'name': name,
    'url':
        'https://ridgelinechimney.com/${name.toLowerCase().replaceAll(' ', '-')}',
    'seo': {
      'title': '%%title%% %%sep%% %%sitename%%',
      'description': description,
      'keyphrase': keyphrase,
      'noindex': noindex,
      'canonical': '',
    },
    'final': {'title': '$name | Ridgeline Chimney', 'description': description},
    'score': score,
    'issues': issues,
  };

  final overview = {
    'summary': {
      'pages': 3,
      'good': 1,
      'ok': 1,
      'bad': 1,
      'missing_description': 1,
      'missing_keyphrase': 0,
      'shared_title': 0,
      'shared_description': 0,
      'shared_keyphrase': 0,
      'hidden': 1,
    },
    'groups': {'keyphrase': [], 'title': [], 'description': []},
    'site_issues': [
      {
        'key': 'stale_year_posts',
        'status': 'bad',
        'text': 'The default title for posts says 2024.',
        'fix': {
          'field': 'post_title_template',
          'value': 'UPDATED %%currentyear%% Best %%title%%',
        },
      },
    ],
    'pages': [
      row(
        12,
        'Chimney Caps',
        score: 'bad',
        description: '',
        issues: [
          {
            'key': 'description_length',
            'status': 'bad',
            'text': 'Write a description.',
          },
        ],
      ),
      row(13, 'Chimney Sweep', score: 'ok'),
      row(14, 'About Us', noindex: true),
    ],
  };

  Map<String, Object?> detail(int id, {String? title}) => {
    ...row(id, id == 12 ? 'Chimney Caps' : 'Chimney Services'),
    if (title != null) 'final': {'title': title, 'description': ''},
    'analysis': {
      'score': 'bad',
      'words': 812,
      'seo': [
        {
          'key': 'keyphrase_unique',
          'status': 'bad',
          'text': '1 other page targets the same keyphrase.',
        },
        {'key': 'title_length', 'status': 'good', 'text': 'Good length.'},
      ],
      'readability': [
        {'key': 'paragraphs', 'status': 'ok', 'text': 'Two long paragraphs.'},
      ],
    },
    'shared_with': {
      'keyphrase': [
        {'id': 14, 'name': 'Chimney Services'},
      ],
      'title': [],
      'description': [],
    },
  };

  /// @return every request as "METHOD path" with its JSON body.
  Future<List<(String, Map<String, dynamic>?)>> pump(
    WidgetTester tester, {
    bool canEdit = true,
    Size size = const Size(1200, 1000),
  }) async {
    final asked = <(String, Map<String, dynamic>?)>[];
    http.Response json(Object body, [int status = 200]) => http.Response(
      jsonEncode(body),
      status,
      headers: {'content-type': 'application/json'},
    );

    final client = MockClient((request) async {
      final path = request.url.path.replaceFirst('/api/v1', '');
      asked.add((
        '${request.method} $path',
        request.body.isEmpty
            ? null
            : jsonDecode(request.body) as Map<String, dynamic>,
      ));
      const base = '/web/sites/site-1/seo-helper';

      return switch ((request.method, path)) {
        ('GET', base) => json({'data': overview}),
        ('PUT', '$base/site') => json({
          'data': {...overview, 'site_issues': []},
        }),
        ('GET', '$base/12') => json({'data': detail(12)}),
        ('GET', '$base/14') => json({'data': detail(14)}),
        ('POST', '$base/12/analyze') => json({
          'data': detail(12, title: 'Chimney Caps | Ridgeline Chimney'),
        }),
        ('PUT', '$base/12') => json({'data': detail(12)}),
        _ => json({'message': 'Unexpected'}, 500),
      };
    });

    tester.view.physicalSize = size;
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);

    await tester.pumpWidget(
      MaterialApp(
        theme: EzBiziTheme.light(),
        home: Scaffold(
          body: SeoHelperPane(
            api: ApiClient(client: client),
            siteId: 'site-1',
            host: 'ridgelinechimney.com',
            canEdit: canEdit,
          ),
        ),
      ),
    );
    await tester.pumpAndSettle();

    return asked;
  }

  Future<void> tap(WidgetTester tester, Finder finder) async {
    await tester.ensureVisible(finder);
    await tester.pumpAndSettle();
    await tester.tap(finder);
    await tester.pumpAndSettle();
  }

  testWidgets('the health counts filter the pages behind them', (tester) async {
    await pump(tester);

    expect(
      find.text('1 good · 1 could be better · 1 need work'),
      findsOneWidget,
    );
    expect(find.text('Chimney Sweep'), findsOneWidget);

    await tap(tester, find.byKey(const Key('seo-tile-noDescription')));

    expect(find.text('Chimney Caps'), findsOneWidget);
    expect(find.text('Chimney Sweep'), findsNothing);
    expect(find.text('About Us'), findsNothing);
  });

  testWidgets('a site issue is fixed in one click, as the server wrote it', (
    tester,
  ) async {
    final asked = await pump(tester);

    await tap(tester, find.byKey(const Key('seo-fix-stale_year_posts')));

    final put = asked.lastWhere(
      (r) => r.$1 == 'PUT /web/sites/site-1/seo-helper/site',
    );
    expect(put.$2, {
      'post_title_template': 'UPDATED %%currentyear%% Best %%title%%',
    });
    expect(find.text('The default title for posts says 2024.'), findsNothing);
  });

  // Jose's containment rule: an editor draws in its pane, never over the app.
  testWidgets('a page opens in the pane, with its own way back', (
    tester,
  ) async {
    await pump(tester);

    await tap(tester, find.byKey(const ValueKey('seo-row-12')));

    expect(find.byType(Scaffold), findsOneWidget);
    expect(find.byType(AppBar), findsNothing);
    expect(
      find.descendant(
        of: find.byKey(const Key('seo-serp')),
        matching: find.text('Chimney Caps | Ridgeline Chimney'),
      ),
      findsOneWidget,
    );
    // The stored variables read as words in the field.
    expect(
      tester
          .widget<TextField>(find.byKey(const Key('seo-title')))
          .controller
          ?.text,
      '{Page title} {Separator} {Site name}',
    );

    await tap(tester, find.byTooltip('Back to the pages'));
    expect(find.text('Chimney Sweep'), findsOneWidget);
  });

  testWidgets('typing is re-checked after a pause, and Save sends only what '
      'changed', (tester) async {
    final asked = await pump(tester);
    await tap(tester, find.byKey(const ValueKey('seo-row-12')));

    await tester.enterText(
      find.byKey(const Key('seo-title')),
      '{Page title} in Oak Park {Separator} {Site name}',
    );
    await tester.pump(const Duration(milliseconds: 700));
    await tester.pumpAndSettle();

    final draft = asked.lastWhere(
      (r) => r.$1 == 'POST /web/sites/site-1/seo-helper/12/analyze',
    );
    expect(draft.$2?['title'], '%%title%% in Oak Park %%sep%% %%sitename%%');

    await tap(tester, find.byKey(const Key('seo-save')));

    final save = asked.lastWhere(
      (r) => r.$1 == 'PUT /web/sites/site-1/seo-helper/12',
    );
    expect(save.$2, {'title': '%%title%% in Oak Park %%sep%% %%sitename%%'});
  });

  testWidgets('an insert chip puts the variable in, in words', (tester) async {
    await pump(tester);
    await tap(tester, find.byKey(const ValueKey('seo-row-12')));

    await tap(tester, find.byKey(const Key('seo-insert-year')));

    expect(
      tester
          .widget<TextField>(find.byKey(const Key('seo-title')))
          .controller
          ?.text,
      endsWith('{Year}'),
    );
  });

  testWidgets('a shared keyphrase names the other page and opens it', (
    tester,
  ) async {
    final asked = await pump(tester);
    await tap(tester, find.byKey(const ValueKey('seo-row-12')));

    await tap(tester, find.text('Chimney Services ›'));

    expect(
      asked.any((r) => r.$1 == 'GET /web/sites/site-1/seo-helper/14'),
      isTrue,
    );
  });

  testWidgets('somebody who may only read gets no Save and no typing', (
    tester,
  ) async {
    await pump(tester, canEdit: false);

    expect(find.byKey(const Key('seo-fix-stale_year_posts')), findsNothing);

    await tap(tester, find.byKey(const ValueKey('seo-row-12')));

    expect(find.byKey(const Key('seo-save')), findsNothing);
    expect(
      tester.widget<TextField>(find.byKey(const Key('seo-title'))).enabled,
      isFalse,
    );
  });

  testWidgets('on a phone the list and the editor are one column that fits', (
    tester,
  ) async {
    final overflows = <String>[];
    final previous = FlutterError.onError;
    FlutterError.onError = (details) {
      if ('${details.exception}'.contains('overflowed')) {
        overflows.add('${details.exception}');
        return;
      }
      previous?.call(details);
    };
    addTearDown(() => FlutterError.onError = previous);

    await pump(tester, size: const Size(390, 844));
    // The list is lazy (a site can have a thousand pages), so scroll to the
    // row as somebody would.
    await tester.scrollUntilVisible(
      find.byKey(const ValueKey('seo-row-12')),
      200,
      scrollable: find.byType(Scrollable).first,
    );
    await tap(tester, find.byKey(const ValueKey('seo-row-12')));
    FlutterError.onError = previous;

    // One column: the preview comes before the fields.
    expect(
      tester.getTopLeft(find.byKey(const Key('seo-serp'))).dy,
      lessThan(tester.getTopLeft(find.byKey(const Key('seo-title'))).dy),
    );
    expect(overflows, isEmpty);
  });
}
