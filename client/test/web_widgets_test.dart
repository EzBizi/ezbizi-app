import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/web/widgets_tab.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Web Management → Widgets.
///
/// The screen exists so nobody has to type colours into a WordPress shortcode.
/// What is worth asserting is that it shows what the server sent, that a site
/// following the primary SAYS so rather than presenting borrowed values as its
/// own, and that a viewer cannot change any of it.
void main() {
  Future<void> pump(
    WidgetTester tester, {
    required List<String> permissions,
    bool own = false,
    Map<String, Object?>? style,
    String? brand,
    bool twoSites = false,
    Map<String, Object?> preferences = const {},
    Map<String, Object?>? saved,
  }) async {
    final writes = saved ?? <String, Object?>{};

    final client = MockClient((request) async {
      // Matched on the WHOLE path. ApiClient prefixes /api/v1, and a suffix
      // match once let a typo'd path fall through to a fixture that happened
      // to end the same way.
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
            'permissions': permissions,
            'modules': [
              {
                'key': 'web_management',
                'label': 'Web Management',
                'available': true,
                'reachable': true,
              },
            ],
            'preferences': preferences,
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
          'data': [
            {
              'id': 'site-1',
              'host': 'a-1chimney.com',
              'label': null,
              'is_primary': true,
              'public_key': 'pk_x',
              'company': {'id': 'c1', 'name': 'A-1'},
              'franchise': null,
            },
            if (twoSites)
              {
                'id': 'site-2',
                'host': 'nj.a-1chimney.com',
                'label': null,
                'is_primary': false,
                'public_key': 'pk_y',
                'company': {'id': 'c1', 'name': 'A-1'},
                'franchise': null,
              },
          ],
        };
      } else if (path == '/api/v1/web/sites/site-1/widgets' ||
          path == '/api/v1/web/sites/site-2/widgets') {
        body = {
          'own': own,
          'config': {
            'chat': {
              'enabled': true,
              'title': 'Talk to A-1',
              'intro': 'We answer fast.',
              'button': 'Chat',
              'placeholder': 'Type here',
              'position': 'bottom-right',
            },
            'form': {
              'enabled': true,
              'button': 'Send it',
              'thanks': 'Got it.',
              'fields': [
                {'label': 'Name', 'type': 'text', 'required': true},
                {'label': 'What needs doing?', 'type': 'textarea'},
              ],
            },
            'style': style ??
                const {
                  'accent': '#aa0000',
                  'on_accent': '#ffffff',
                  'text': '#111111',
                  'background': '#ffffff',
                  'font': 'inherit',
                  'radius': 12,
                },
          },
          'inherited': own
              ? {
                  'style': {'accent': '#123456'},
                }
              : <String, Object?>{},
          'brand': brand,
          'field_types': const ['text', 'textarea', 'email', 'tel', 'select'],
        };
      } else if (path == '/api/v1/me/preferences') {
        writes.addAll(
          (jsonDecode(request.body) as Map<String, dynamic>)['preferences']
              as Map<String, dynamic>,
        );
        body = {'data': writes};
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

    tester.view.physicalSize = const Size(1280, 2400);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);

    await tester.pumpWidget(MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(body: WidgetsTab(api: api, session: session)),
    ));
    await tester.pumpAndSettle();
  }

  testWidgets('the widget settings come from the server, not the shortcode', (
    tester,
  ) async {
    await pump(tester, permissions: ['web_management.view', 'web_management.edit']);

    expect(find.widgetWithText(TextField, 'Talk to A-1'), findsOneWidget);

    expect(find.widgetWithText(TextField, '#aa0000'), findsOneWidget);

    // The form's questions are editable rows, not a comma-separated string.
    expect(find.widgetWithText(TextField, 'What needs doing?'), findsOneWidget);
    expect(find.text('Add a question'), findsOneWidget);
  });

  /// A branch site showing the primary's colours as if somebody had typed them
  /// here would have people "changing" a value that was never theirs.
  testWidgets('a site following the primary says so', (tester) async {
    await pump(
      tester,
      permissions: ['web_management.view', 'web_management.edit'],
      own: false,
    );

    expect(find.textContaining('Following the primary website'), findsOneWidget);
    expect(find.text('Follow the primary again'), findsNothing);
  });

  /// The accent and the text on it are two free-text boxes whose only job is to
  /// be legible against each other, and nothing else on the screen renders
  /// either one. A pair that cannot be read ships to a lead form on the
  /// customer's own website -- the page nobody at EzBizi will ever load.
  testWidgets('an unreadable pair says so, in the ratio a visitor gets', (
    tester,
  ) async {
    await pump(
      tester,
      permissions: ['web_management.view', 'web_management.edit'],
      style: const {
        'accent': '#ffff00',
        'on_accent': '#ffffff',
        'text': '#111111',
        'background': '#ffffff',
        'font': 'inherit',
        'radius': 12,
      },
    );

    // The NUMBER, not the sentence: a warning that appears with the wrong
    // ratio is a warning that has stopped measuring anything.
    expect(find.textContaining('1.1:1'), findsOneWidget);
    expect(find.textContaining('may not be able to read'), findsOneWidget);

    // The other pair on the same screen is fine and must not be swept up.
    expect(find.textContaining('18.9:1'), findsOneWidget);
  });

  /// Warn, never block: this is the customer's website and their brand, so the
  /// screen reports what a visitor sees and leaves the choice alone.
  testWidgets('a low-contrast pair still saves', (tester) async {
    await pump(
      tester,
      permissions: ['web_management.view', 'web_management.edit'],
      style: const {
        'accent': '#ffff00',
        'on_accent': '#ffffff',
        'text': '#111111',
        'background': '#ffffff',
        'font': 'inherit',
        'radius': 12,
      },
    );

    final save = find.text('Save');
    expect(save, findsOneWidget);
    expect(
      tester.widget<FilledButton>(
        find.ancestor(of: save, matching: find.byType(FilledButton)),
      ).onPressed,
      isNotNull,
    );
  });

  /// `WidgetDefaults::colour` keeps `#rgb` as well as `#rrggbb`. A checker that
  /// only understood the long form would pass every short one unexamined --
  /// silently, because saying nothing is also what it does mid-typing.
  testWidgets('three-digit hex is checked, not skipped', (tester) async {
    await pump(
      tester,
      permissions: ['web_management.view', 'web_management.edit'],
      style: const {
        'accent': '#ff0',
        'on_accent': '#fff',
        'text': '#111111',
        'background': '#ffffff',
        'font': 'inherit',
        'radius': 12,
      },
    );

    expect(find.textContaining('1.1:1'), findsOneWidget);
  });

  /// Half a colour is somebody typing, not somebody wrong.
  testWidgets('a half-typed colour is not a finding', (tester) async {
    await pump(
      tester,
      permissions: ['web_management.view', 'web_management.edit'],
      style: const {
        'accent': '#0b5c',
        'on_accent': '#ffffff',
        'text': '#111111',
        'background': '#ffffff',
        'font': 'inherit',
        'radius': 12,
      },
    );

    expect(find.textContaining('Contrast against the accent'), findsNothing);
    // The finished pair below it still reports.
    expect(find.textContaining('18.9:1'), findsOneWidget);
  });

  testWidgets('a site dressing itself can go back to following', (tester) async {
    await pump(
      tester,
      permissions: ['web_management.view', 'web_management.edit'],
      own: true,
    );

    expect(find.textContaining('Following the primary website'), findsNothing);

    expect(find.text('Follow the primary again'), findsOneWidget);
  });

  /// A business that has told us no colour is getting OURS on THEIR website.
  /// The screen has to say so, because the person who would mind is the one
  /// least likely to be reading this screen.
  testWidgets('the screen says where an untouched colour came from', (
    tester,
  ) async {
    await pump(
      tester,
      permissions: ['web_management.view', 'web_management.edit'],
      brand: null,
    );

    expect(find.textContaining('you have not set one'), findsOneWidget);
    expect(find.textContaining('neutral blue'), findsOneWidget);
  });

  testWidgets('and names the brand colour when there is one', (tester) async {
    await pump(
      tester,
      permissions: ['web_management.view', 'web_management.edit'],
      brand: '#7b2d8e',
    );

    expect(find.textContaining('your brand colour, #7b2d8e'), findsOneWidget);
    expect(find.textContaining('you have not set one'), findsNothing);
  });

  /// Picking a preset must set BOTH halves of the pair.
  ///
  /// If it set only the accent, the previous text colour would survive and the
  /// swatch could warn about a combination we had just offered — which is the
  /// whole reason the presets exist. Starts from an unreadable pair so the
  /// assertion has somewhere to travel from.
  testWidgets('picking a preset fixes the pair, not just the accent', (
    tester,
  ) async {
    await pump(
      tester,
      permissions: ['web_management.view', 'web_management.edit'],
      style: const {
        'accent': '#ffff00',
        'on_accent': '#ffffff',
        'text': '#111111',
        'background': '#ffffff',
        'font': 'inherit',
        'radius': 12,
      },
    );

    // The pair it starts on is one the screen complains about.
    expect(find.textContaining('1.1:1'), findsOneWidget);

    final swatch = find.byTooltip('#00838f');
    await tester.scrollUntilVisible(swatch, 200,
        scrollable: find.byType(Scrollable).last);
    await tester.tap(swatch);
    await tester.pumpAndSettle();

    // Both halves moved: the accent AND the text that has to be legible on it.
    expect(find.widgetWithText(TextField, '#00838f'), findsOneWidget);
    expect(find.widgetWithText(TextField, '#000000'), findsOneWidget);

    // And the complaint is gone, because a picked pair cannot be unreadable.
    expect(find.textContaining('1.1:1'), findsNothing);
  });

  testWidgets('a viewer is offered no presets to pick', (tester) async {
    await pump(tester, permissions: ['web_management.view']);

    expect(find.byTooltip('#00838f'), findsNothing);
  });

  /// Every value on this screen only means something once it is rendered, and
  /// the page it renders on is one nobody at EzBizi will ever load.
  testWidgets('the preview shows the wording and questions being edited', (
    tester,
  ) async {
    await pump(tester, permissions: ['web_management.view', 'web_management.edit']);

    // TWO of each is the assertion: the box somebody types in, and the preview
    // drawing the same value. One would mean the preview never rendered it.
    expect(find.text('Talk to A-1'), findsNWidgets(2));
    expect(find.text('We answer fast.'), findsNWidgets(2));
    expect(find.text('Chat'), findsNWidgets(2));
    expect(find.text('Send it'), findsNWidgets(2));

    // The form section sits below the preview's own fold, and a ListView does
    // not build what it has not scrolled to — so reach it rather than assert
    // from where it cannot be seen.
    // Dragged rather than scrollUntilVisible: every heading in the preview also
    // appears in the editor beside it, so there is no unique target to scroll
    // TO — and a finder that matches both panes cannot say which one moved.
    await tester.drag(find.byType(Scrollable).last, const Offset(0, -420));
    await tester.pumpAndSettle();

    // 'Name *' rather than 'Name': the preview marks a required question the
    // way the visitor's form does, which is part of what is being previewed.
    expect(find.text('Name *'), findsOneWidget);
    expect(find.textContaining('What needs doing?'), findsWidgets);
  });

  /// There is no switch, and nothing may imply there is one.
  ///
  /// Jose removed both on 2026-09-15: putting the widget on a page is the
  /// decision to show it. This is the version of the old test, inverted —
  /// it used to tap the switch and assert the preview said "off on your site".
  /// Every site had both switched off, so every site showed nothing, and the
  /// only notice of that was a grey phrase in a preview that reads as styling.
  testWidgets('nothing offers to switch a widget off', (tester) async {
    await pump(tester, permissions: ['web_management.view', 'web_management.edit']);

    expect(find.text('Preview'), findsOneWidget);

    expect(find.byType(Switch), findsNothing);
    expect(find.text('Show the chat bubble'), findsNothing);
    expect(find.text('Show the contact form'), findsNothing);
    expect(find.text('off on your site'), findsNothing);

    // The control: the editor and the preview are both still drawn, so the
    // assertions above are about an absent switch rather than an absent screen.
    expect(find.text('Talk to A-1'), findsNWidgets(2));
  });

  /// The preview is what makes a colour mean something, so it has to follow the
  /// pick rather than the saved value.
  testWidgets('the preview follows a preset before anything is saved', (
    tester,
  ) async {
    await pump(tester, permissions: ['web_management.view', 'web_management.edit']);

    final swatch = find.byTooltip('#6a1b9a');
    await tester.scrollUntilVisible(swatch, 200,
        scrollable: find.byType(Scrollable).first);
    await tester.tap(swatch);
    await tester.pumpAndSettle();

    // The preview's buttons are painted containers, so the accent reaching them
    // is the assertion — not the hex in the box beside it.
    final painted = tester.widgetList<Container>(find.byType(Container)).where(
      (c) => (c.decoration is BoxDecoration) &&
          (c.decoration as BoxDecoration).color == const Color(0xff6a1b9a),
    );

    expect(painted, isNotEmpty, reason: 'The preview never took the new accent.');
  });

  /// The 260 and the 360 were my guesses about somebody else's monitor.
  testWidgets('both panes can be dragged wider and narrower', (tester) async {
    await pump(tester, permissions: ['web_management.view', 'web_management.edit']);

    double widthOf(Finder f) => tester.getSize(f).width;

    final listHandle = find.byTooltip('Drag to resize the list');
    final previewHandle = find.byTooltip('Drag to resize the preview');

    expect(listHandle, findsOneWidget);
    expect(previewHandle, findsOneWidget);

    // The site list, dragged right, gets wider.
    final list = find.ancestor(
      of: find.text('a-1chimney.com'),
      matching: find.byType(SizedBox),
    );
    final listBefore = widthOf(list.first);

    await tester.drag(listHandle, const Offset(60, 0));
    await tester.pumpAndSettle();

    expect(widthOf(list.first), greaterThan(listBefore));

    // The preview, dragged LEFT, gets wider — the sign flips because the pane
    // is on the other side of its handle.
    final preview = find.ancestor(
      of: find.text('Preview'),
      matching: find.byType(SizedBox),
    );
    final previewBefore = widthOf(preview.first);

    await tester.drag(previewHandle, const Offset(-80, 0));
    await tester.pumpAndSettle();

    expect(widthOf(preview.first), greaterThan(previewBefore));
  });

  /// A pane dragged to nothing is not a smaller pane, it is a lost one, and the
  /// way back is a hairline somebody has to find.
  testWidgets('a pane cannot be dragged away entirely', (tester) async {
    await pump(tester, permissions: ['web_management.view', 'web_management.edit']);

    // Shove both handles far past any sane width.
    await tester.drag(
        find.byTooltip('Drag to resize the list'), const Offset(-2000, 0));
    await tester.pumpAndSettle();
    await tester.drag(
        find.byTooltip('Drag to resize the preview'), const Offset(2000, 0));
    await tester.pumpAndSettle();

    // Both panes are still there and still usable.
    expect(find.text('a-1chimney.com'), findsOneWidget);
    expect(find.text('Preview'), findsOneWidget);

    final list = find.ancestor(
      of: find.text('a-1chimney.com'),
      matching: find.byType(SizedBox),
    );

    expect(tester.getSize(list.first).width, greaterThanOrEqualTo(180.0));
  });

  /// The editor is keyed by site id, so anything held inside it is thrown away
  /// on every click. A width that snapped back would be worse than none.
  testWidgets('a resized preview survives switching website', (tester) async {
    await pump(
      tester,
      permissions: ['web_management.view', 'web_management.edit'],
      twoSites: true,
    );

    final preview = find.ancestor(
      of: find.text('Preview'),
      matching: find.byType(SizedBox),
    );

    await tester.drag(
        find.byTooltip('Drag to resize the preview'), const Offset(-90, 0));
    await tester.pumpAndSettle();

    final resized = tester.getSize(preview.first).width;

    await tester.tap(find.text('nj.a-1chimney.com'));
    await tester.pumpAndSettle();

    expect(tester.getSize(preview.first).width, resized);
  });

  /// A width that is forgotten on restart is a width somebody sets once and
  /// then stops bothering with.
  testWidgets('a dragged width is saved when the drag settles', (tester) async {
    final saved = <String, Object?>{};

    await pump(
      tester,
      permissions: ['web_management.view', 'web_management.edit'],
      saved: saved,
    );

    // Nothing written while nobody has dragged anything.
    expect(saved, isEmpty);

    await tester.drag(
        find.byTooltip('Drag to resize the preview'), const Offset(-70, 0));
    await tester.pumpAndSettle();

    expect(saved['web.widgets.preview_width'], isA<int>());
    expect(saved['web.widgets.preview_width'] as int, greaterThan(360));

    await tester.drag(
        find.byTooltip('Drag to resize the list'), const Offset(50, 0));
    await tester.pumpAndSettle();

    expect(saved['web.widgets.list_width'] as int, greaterThan(260));
  });

  /// The saved value is what the screen opens on, or saving it was pointless.
  testWidgets('a saved width is what the screen opens with', (tester) async {
    await pump(
      tester,
      permissions: ['web_management.view', 'web_management.edit'],
      preferences: const {
        'web.widgets.list_width': 300,
        'web.widgets.preview_width': 500,
      },
    );

    final preview = find.ancestor(
      of: find.text('Preview'),
      matching: find.byType(SizedBox),
    );

    expect(tester.getSize(preview.first).width, 500.0);
  });

  /// A stored value from an older build, or one whose bounds have since been
  /// tightened, must not be able to render a pane nobody can use.
  testWidgets('an impossible saved width is clamped rather than obeyed', (
    tester,
  ) async {
    await pump(
      tester,
      permissions: ['web_management.view', 'web_management.edit'],
      preferences: const {'web.widgets.preview_width': 9999},
    );

    final preview = find.ancestor(
      of: find.text('Preview'),
      matching: find.byType(SizedBox),
    );

    expect(tester.getSize(preview.first).width, 640.0);
  });

  testWidgets('view alone can look and cannot change', (tester) async {
    await pump(tester, permissions: ['web_management.view']);

    expect(find.widgetWithText(TextField, 'Talk to A-1'), findsOneWidget);
    expect(find.text('Save'), findsNothing);
    expect(find.text('Add a question'), findsNothing);

    final field = tester.widget<TextField>(
      find.widgetWithText(TextField, 'Talk to A-1'),
    );

    expect(field.enabled, isFalse);
  });
}
