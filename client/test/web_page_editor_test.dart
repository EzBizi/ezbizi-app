import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/web/page_editor.dart';
import 'package:ezbizi_client/widgets/shortcode_button.dart';
import 'package:ezbizi_client/widgets/search_picker.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// One page of a customer's website, inside EzBizi.
///
/// The assertions worth having are about the two things that reach a live page:
/// what gets SENT (a path, or the values land site-wide and publish everywhere)
/// and what the field is NAMED (the name is a shortcode somebody pastes into a
/// template, so reusing one silently changes what a published page says).
void main() {
  late List<Map<String, dynamic>> sent;
  late bool closed;

  Future<void> pump(
    WidgetTester tester, {
    String path = '/about-us',
    Map<String, dynamic> pageValues = const {},
    List<Map<String, dynamic>> extraFields = const [],
    bool optionsOk = true,
    bool canEdit = true,
    int? wpAuthor,
    List<int> wpCategories = const [],
  }) async {
    sent = [];
    closed = false;

    final client = MockClient((request) async {
      if (request.url.path.endsWith('/content/options')) {
        if (!optionsOk) {
          return http.Response(
            jsonEncode({'message': 'a-1chimney.com has not paired yet.'}),
            502,
            headers: {'content-type': 'application/json'},
          );
        }

        return http.Response(
          jsonEncode({
            'authors': [
              {'id': 3, 'name': "Pat O'Brien", 'slug': 'pat'},
            ],
            'categories': [
              {'id': 7, 'name': 'Chimney repair', 'slug': 'chimney-repair'},
            ],
          }),
          200,
          headers: {'content-type': 'application/json'},
        );
      }

      if (request.method == 'PUT') {
        sent.add(jsonDecode(request.body) as Map<String, dynamic>);
      }

      return http.Response(
        jsonEncode({
          'data': {
            'fields': [
              {
                'key': 'phone',
                'label': 'Phone number',
                'hint': '',
                'requirement': 'required',
                'section': 'general',
                'shortcode': '[ezbizi_phone]',
              },
              {
                'key': 'group',
                'label': 'Group',
                'hint': 'Your own label.',
                'requirement': 'optional',
                'section': 'page',
                'shortcode': '[ezbizi_group]',
              },
              {
                'key': 'author',
                'label': 'Author',
                'hint': 'From the website.',
                'requirement': 'optional',
                'section': 'page',
                'shortcode': '[ezbizi_author]',
              },
              {
                'key': 'category',
                'label': 'Category',
                'hint': 'From the website.',
                'requirement': 'optional',
                'section': 'page',
                'shortcode': '[ezbizi_category]',
              },
              ...extraFields,
            ],
            'values': {'phone': '555-0100'},
            'pages': {path: pageValues},
            'inherited': <String, dynamic>{},
          },
        }),
        200,
        headers: {'content-type': 'application/json'},
      );
    });

    tester.view.physicalSize = const Size(1000, 2200);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);

    await tester.pumpWidget(MaterialApp(
      theme: EzBiziTheme.light(),
      // A Scaffold around it, because that is where it lives: inside the SEO
      // tab's pane. It has none of its own, which is the point of the
      // containment test below.
      home: Scaffold(
        body: PageEditor(
        api: ApiClient(client: client),
        siteId: 'site-1',
        host: 'a-1chimney.com',
        path: path,
        title: 'About us',
        link: 'https://a-1chimney.com/about-us',
        canEdit: canEdit,
        wpAuthor: wpAuthor,
        wpCategories: wpCategories,
          onClose: () => closed = true,
        ),
      ),
    ));
    await tester.pumpAndSettle();
  }

  testWidgets('an empty box says what the page will publish instead', (
    tester,
  ) async {
    await pump(tester);

    // Blank means inherit, and the blank has to be legible as inheritance
    // rather than as a value somebody forgot to fill in.
    expect(find.text('The site publishes: 555-0100'), findsOneWidget);
  });

  /// THE one that matters: without a path these values land site-wide and
  /// publish on every page of the website.
  testWidgets('what it saves is scoped to the page', (tester) async {
    await pump(tester);

    await tester.enterText(find.byType(TextField).first, 'Trade only');
    await tester.pumpAndSettle();

    await tester.tap(find.byType(FilledButton));
    await tester.pumpAndSettle();

    expect(sent, hasLength(1));
    expect(sent.first['path'], '/about-us');
    expect((sent.first['values'] as Map)['group'], 'Trade only');
  });

  /// The name is a shortcode somebody pastes into a template. Removing the
  /// second of three must not hand the third's name to a new field whose words
  /// are different.
  testWidgets('a new text field never takes a name already in use', (
    tester,
  ) async {
    await pump(
      tester,
      pageValues: const {
        'about_us_field_1': 'First.',
        'about_us_field_3': 'Third.',
      },
      extraFields: const [
        {
          'key': 'about_us_field_1',
          'label': 'About us — field 1',
          'hint': '',
          'requirement': 'optional',
          'section': 'page',
          'shortcode': '[ezbizi_about_us_field_1]',
          'path': '/about-us',
        },
        {
          'key': 'about_us_field_3',
          'label': 'About us — field 3',
          'hint': '',
          'requirement': 'optional',
          'section': 'page',
          'shortcode': '[ezbizi_about_us_field_3]',
          'path': '/about-us',
        },
      ],
    );

    await tester.tap(find.text('Add a text field'));
    await tester.pumpAndSettle();

    expect(find.byTooltip('Copies [ezbizi_about_us_field_4]'), findsOneWidget);
    expect(find.byTooltip('Copies [ezbizi_about_us_field_2]'), findsNothing);
  });

  /// The shortcode is the only reason the field is worth filling in, so it is
  /// beside the box rather than somewhere else.
  testWidgets('each text field shows the shortcode it becomes', (tester) async {
    await pump(
      tester,
      pageValues: const {'about_us_field_1': 'We have swept since 1974.'},
      extraFields: const [
        {
          'key': 'about_us_field_1',
          'label': 'About us — field 1',
          'hint': '',
          'requirement': 'optional',
          'section': 'page',
          'shortcode': '[ezbizi_about_us_field_1]',
          'path': '/about-us',
        },
      ],
    );

    expect(find.byTooltip('Copies [ezbizi_about_us_field_1]'), findsOneWidget);
    expect(find.text('We have swept since 1974.'), findsOneWidget);
  });

  testWidgets('the author comes from the website', (tester) async {
    await pump(tester);

    // Opened, because a dropdown draws its options only when somebody asks
    // for them — asserting on the closed control would pass on an empty list.
    await tester.tap(find.byType(SearchPickerField<String>).first);
    await tester.pumpAndSettle();

    expect(find.text("Pat O'Brien"), findsWidgets);

    await tester.tap(find.text("Pat O'Brien").last);
    await tester.pumpAndSettle();

    await tester.tap(find.byType(FilledButton));
    await tester.pumpAndSettle();

    // What reaches the server is the choice, against this page.
    expect(sent.first['path'], '/about-us');
    expect((sent.first['values'] as Map)['author'], "Pat O'Brien");
  });

  /// A site that has not paired cannot offer a list of authors, and that must
  /// not stop somebody editing a phone number on the same screen.
  testWidgets('an unpaired site still lets the rest be edited', (tester) async {
    await pump(tester, optionsOk: false);

    expect(find.textContaining('has not paired'), findsOneWidget);

    // The control: the rest of the screen is drawn, so the sentence above is
    // about the dropdowns rather than about a failed load.
    expect(find.text('The site publishes: 555-0100'), findsOneWidget);
    expect(find.text('Add a text field'), findsOneWidget);
  });

  testWidgets('a viewer is shown the page and cannot change it', (tester) async {
    await pump(tester, canEdit: false);

    expect(find.text('Add a text field'), findsNothing);
    expect(find.byType(FilledButton), findsNothing);

    final box = tester.widget<TextField>(find.byType(TextField).first);
    expect(box.enabled, isFalse);
  });

  /// Jose named this the moment he saw it: going full screen breaks
  /// containment. It draws in the pane the list was in, so it has a way back
  /// of its own and no Scaffold of its own.
  testWidgets('it is a pane and not a screen', (tester) async {
    await pump(tester);

    // One Scaffold — the one this test put around it — and no app bar. The
    // editor contributes neither, so it cannot take the window.
    expect(find.byType(Scaffold), findsOneWidget);
    expect(find.byType(AppBar), findsNothing);

    await tester.tap(find.byTooltip('Back to the list'));
    await tester.pumpAndSettle();

    expect(closed, isTrue);
  });

  /// "the fields do not have the shortcodes to be able to copy them like on the
  /// general one" — a value you cannot paste anywhere is a value with nowhere
  /// to go.
  testWidgets('every field carries the shortcode it publishes as', (
    tester,
  ) async {
    await pump(tester);

    expect(find.byTooltip('Copies [ezbizi_phone]'), findsOneWidget);
    expect(find.byTooltip('Copies [ezbizi_group]'), findsOneWidget);

    // Copyable, not printed: the control is the point.
    expect(find.byType(ShortcodeButton), findsWidgets);
  });

  /// "some pages already have them, so they should be loaded, cause right now
  /// they all show as empty even if that is not true".
  testWidgets('the author the website already has is shown', (tester) async {
    await pump(tester, wpAuthor: 3, wpCategories: const [7]);

    expect(find.text("Pat O'Brien"), findsOneWidget);
    expect(find.text('Chimney repair'), findsOneWidget);
    expect(
      find.text('From the website. Change it to publish something else.'),
      findsNWidgets(2),
    );
  });

  /// Showing it is not the same as claiming it. Opening a page and leaving must
  /// write nothing, or every page visited would gain an override of a value it
  /// already had.
  testWidgets('showing the website value saves nothing on its own', (
    tester,
  ) async {
    await pump(tester, wpAuthor: 3);

    // No save control, because nothing has been edited.
    expect(find.byType(FilledButton), findsNothing);
    expect(sent, isEmpty);
  });

  /// EzBizi's own answer wins, or a page somebody deliberately re-assigned
  /// would keep showing the website's author back at them.
  testWidgets('a stored override beats the website', (tester) async {
    await pump(
      tester,
      wpAuthor: 3,
      pageValues: const {'author': 'Chimney repair'},
    );

    expect(
      find.text('From the website. Change it to publish something else.'),
      findsNothing,
    );
  });
}
