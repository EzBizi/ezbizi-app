import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/web/templates_pane.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Text written once and used on as many pages as want it.
///
/// What separates a template from a page field is where it is STORED, so the
/// assertion that matters is that this screen sends no path. With one, the text
/// would belong to a single page and `[ezbizi_template_1]` would render nothing
/// on the other eight — silently, since a shortcode with no value prints
/// nothing at all.
void main() {
  late List<Map<String, dynamic>> sent;

  Future<void> pump(
    WidgetTester tester, {
    Map<String, dynamic> values = const {},
    List<Map<String, dynamic>> templates = const [],
    bool canEdit = true,
  }) async {
    sent = [];

    final client = MockClient((request) async {
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
              ...templates,
            ],
            'values': values,
            'pages': <String, dynamic>{},
            'inherited': <String, dynamic>{},
          },
        }),
        200,
        headers: {'content-type': 'application/json'},
      );
    });

    tester.view.physicalSize = const Size(1000, 1600);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);

    await tester.pumpWidget(MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(
        body: TemplatesPane(
          api: ApiClient(client: client),
          siteId: 'site-1',
          host: 'a-1chimney.com',
          canEdit: canEdit,
        ),
      ),
    ));
    await tester.pumpAndSettle();
  }

  Map<String, dynamic> template(int n) => {
        'key': 'template_$n',
        'label': 'Template $n',
        'hint': 'Shared text.',
        'requirement': 'optional',
        'section': 'general',
        'shortcode': '[ezbizi_template_$n]',
        'path': null,
        'template': true,
      };

  testWidgets('an empty site says so rather than showing nothing', (
    tester,
  ) async {
    await pump(tester);

    expect(find.text('No templates yet.'), findsOneWidget);
    expect(find.text('Add a template'), findsOneWidget);
  });

  testWidgets('a template shows its text and the shortcode it becomes', (
    tester,
  ) async {
    await pump(
      tester,
      values: const {'template_1': 'Serving the tri-state area since 1974.'},
      templates: [template(1)],
    );

    expect(find.text('Serving the tri-state area since 1974.'), findsOneWidget);
    expect(find.byTooltip('Copies [ezbizi_template_1]'), findsOneWidget);
  });

  /// THE one. A path here would tie the text to one page, and the shortcode
  /// would print nothing on every other page that uses it.
  testWidgets('what it saves belongs to the site, not a page', (tester) async {
    await pump(tester, templates: [template(1)]);

    await tester.enterText(find.byType(TextField).first, 'Licensed and insured.');
    await tester.pumpAndSettle();

    await tester.tap(find.text('Save'));
    await tester.pumpAndSettle();

    expect(sent, hasLength(1));
    expect(sent.first.containsKey('path'), isFalse);
    expect((sent.first['values'] as Map)['template_1'], 'Licensed and insured.');
  });

  /// The name is a shortcode already pasted into pages we cannot see, so a gap
  /// is cheap and renumbering is silent.
  testWidgets('a new template never takes a name already in use', (
    tester,
  ) async {
    await pump(
      tester,
      values: const {'template_1': 'One.', 'template_3': 'Three.'},
      templates: [template(1), template(3)],
    );

    await tester.tap(find.text('Add a template'));
    await tester.pumpAndSettle();

    expect(find.byTooltip('Copies [ezbizi_template_4]'), findsOneWidget);
    expect(find.byTooltip('Copies [ezbizi_template_2]'), findsNothing);
  });

  /// Emptying it is what removes it — the server deletes a blank value, and a
  /// template is its text.
  testWidgets('removing one sends the blank that deletes it', (tester) async {
    await pump(
      tester,
      values: const {'template_1': 'One.'},
      templates: [template(1)],
    );

    await tester.tap(find.text('Remove'));
    await tester.pumpAndSettle();

    await tester.tap(find.text('Save'));
    await tester.pumpAndSettle();

    expect((sent.first['values'] as Map)['template_1'], '');
  });

  testWidgets('a viewer is shown them and cannot change them', (tester) async {
    await pump(
      tester,
      values: const {'template_1': 'One.'},
      templates: [template(1)],
      canEdit: false,
    );

    expect(find.text('Add a template'), findsNothing);
    expect(find.text('Remove'), findsNothing);

    // The control: the template is drawn, so the absences above are about the
    // controls rather than about an empty screen.
    expect(find.text('One.'), findsOneWidget);

    final box = tester.widget<TextField>(find.byType(TextField).first);
    expect(box.enabled, isFalse);
  });
}
