import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/web/widgets_tab.dart';
import 'package:ezbizi_client/widgets/search_picker.dart';
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
    Map<String, Object?> chat = const {},
    List<Map<String, dynamic>>? sent,
    Size size = const Size(1280, 2400),
    // Which widget card to open before the test looks at anything.
    //
    // The screen is a grid of cards now (Jose, 2026-09-23), so one
    // widget's fields are a tap away rather than all six being present at
    // once. A test that asserts on a field now says which widget it
    // belongs to — information the old flat column let it leave out.
    String? open,
    double textScale = 1.0,
    // The form's questions, when a test needs other than the fixture's two.
    List<Map<String, Object?>>? formFields,
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
        if (request.method == 'PUT') {
          sent?.add(jsonDecode(request.body) as Map<String, dynamic>);
        }
        body = {
          'own': own,
          'config': {
            // Every key WidgetDefaults::resolve() emits for chat. A fixture
            // that carries fewer is a fixture agreeing with itself.
            'chat': {
              'enabled': true,
              'title': 'Talk to A-1',
              'intro': 'We answer fast.',
              'button': 'Chat',
              'placeholder': 'Type here',
              'position': 'bottom-right',
              'lead_in': 'Quick details first, so we can get back to you.',
              'name_label': 'Your name',
              'contact_label': 'Phone or email',
              'message_label': 'How can we help?',
              'start': 'Start chat',
              // The server always sends both, empty meaning always on.
              'hours': const <Object?>[],
              'timezone': '',
              ...chat,
            },
            'form': {
              'enabled': true,
              'button': 'Send it',
              'thanks': 'Got it.',
              'fields': formFields ??
                  [
                    {'label': 'Name', 'type': 'text', 'required': true},
                    {'label': 'What needs doing?', 'type': 'textarea'},
                  ],
            },
            // Exactly what WidgetPresets::resolve() guarantees: four widgets,
            // each with a `default`, whatever is stored. A fixture with fewer
            // would let the screen invent the missing ones and agree with
            // itself about values the server never sent.
            'presets': const {
              'social_links': {
                'default': {
                  'style': 'plain',
                  'shape': 'circle',
                  'size': 28,
                  'gap': 10,
                  'labels': false,
                },
              },
              'city_list': {
                'default': {
                  'layout': 'columns',
                  'columns': 3,
                  'group': true,
                  'link': true,
                },
              },
              'hours_table': {
                'default': {'today': true, 'compact': false},
              },
              'google_map': {
                'default': {'height': 320, 'zoom': 14},
              },
              'review_faces': {
                'default': {
                  'title': 'How was your experience?',
                  'text': 'Tap a face to let us know.',
                  'happy_label': 'Great!',
                  'sad_label': 'Not so great',
                },
              },
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
          // Two of the generated style options, as WidgetPresets::schema()
          // describes them; the hand-drawn options carry no `part`.
          'schema': const {
            'social_links': {
              'size': {'type': 'range', 'min': 16, 'max': 64},
              'icon_color': {'type': 'color', 'part': 'icon', 'prop': 'color'},
              // A non-colour icon option too, as the real schema has: the
              // icons' group keeps these once colours move to "Colours".
              'icon_size': {'type': 'range', 'min': 0, 'max': 120, 'part': 'icon', 'prop': 'size'},
              'box_devices': {
                'type': 'choice',
                'choices': ['', 'desktop', 'mobile'],
                'part': 'box',
                'prop': 'devices',
              },
            },
          },
        };
      } else if (path.endsWith('/widgets/chat') ||
          path.endsWith('/widgets/refresh')) {
        sent?.add({
          'path': path,
          ...jsonDecode(request.body) as Map<String, dynamic>,
        });

        // Two sites in the fixture, so the server's own count is 2 — the
        // number the screen then quotes back.
        body = path.endsWith('/widgets/refresh')
            ? {'asked': 2}
            : {
                'changed': 2,
                'enabled': (jsonDecode(request.body) as Map)['enabled'],
                'own': true,
                'config': const <String, Object?>{},
                'inherited': const <String, Object?>{},
                'brand': null,
                'field_types': const <String>[],
              };
      } else if (path == '/api/v1/web/sites/site-1/team') {
        if (request.method == 'PUT') {
          sent?.add({'path': path, ...jsonDecode(request.body) as Map<String, dynamic>});
        }
        body = {
          'data': [
            {'name': 'Ana Ruiz', 'role': 'Technician', 'bio': null, 'photo_url': null, 'category': 'Field'},
          ],
        };
      } else if (path == '/api/v1/web/sites/site-1/items/faq') {
        if (request.method == 'PUT') {
          sent?.add({'path': path, ...jsonDecode(request.body) as Map<String, dynamic>});
        }
        body = {
          'data': [
            {'title': 'Do you sweep in winter?', 'body': 'Yes, all year.', 'category': 'General'},
          ],
        };
      } else if (path == '/api/v1/web/sites/site-1/locations') {
        if (request.method == 'PUT') {
          sent?.add({'path': path, ...jsonDecode(request.body) as Map<String, dynamic>});
        }
        body = {
          'data': {
            'site': [
              {'name': 'North Jersey', 'city': 'Newark', 'latitude': 40.73},
            ],
            'company': [
              {'name': 'Atlanta store', 'city': 'Atlanta'},
            ],
          },
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

    tester.view.physicalSize = size;
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);

    await tester.pumpWidget(MaterialApp(
      theme: EzBiziTheme.light(),
      // copyWith rather than a fresh MediaQueryData: a bare one replaces the
      // whole thing, including the view size, and the four drag tests measure
      // panes against it. They went red for a reason that had nothing to do
      // with text.
      home: Builder(
        builder: (context) => MediaQuery(
          data: MediaQuery.of(context).copyWith(
            textScaler: TextScaler.linear(textScale),
          ),
          child: Scaffold(body: WidgetsTab(api: api, session: session)),
        ),
      ),
    ));
    await tester.pumpAndSettle();
 
    if (open != null) {
      await tester.tap(find.text(open));
      await tester.pumpAndSettle();
    }
  }

  /// ── The cards ────────────────────────────────────────────────────
  ///
  /// Jose, 2026-09-23: *"i'd like to see each widget as a card, so i can click
  /// on them and focus on it, and so i dont see all the previews at once on
  /// the right side ... each widget should have a preview, right now only live
  /// chat and contact form do"*.
  testWidgets('every widget is a card, and nothing is open yet', (
    tester,
  ) async {
    await pump(tester, permissions: const ['web_management.view']);

    for (final name in const [
      'Live chat',
      'Contact form',
      'Social links',
      'Towns served',
      'Opening hours',
      'Map',
      'Look',
    ]) {
      expect(find.text(name), findsOneWidget, reason: '$name has no card.');
    }

    // Nothing is opened for you. The fields and the preview are both absent,
    // which is the half of the ask about not seeing everything at once.
    expect(find.widgetWithText(TextField, 'Talk to A-1'), findsNothing);
    expect(find.text('Preview'), findsNothing);
  });

  /// A grid that answered "which widgets are there" and not "what are they set
  /// to" would put that second answer one click further away than the long
  /// column it replaces.
  testWidgets('each card says what its widget is set to', (tester) async {
    await pump(tester, permissions: const ['web_management.view']);

    expect(find.text('On, at all hours'), findsOneWidget);
    expect(find.text('2 questions'), findsOneWidget);
    expect(find.textContaining('#aa0000'), findsOneWidget);
    // Not a fixed count: one per RENDERED widget, and that list grows every
    // time Jose asks for another one — it went 4 → 7 the day he asked for all
    // fourteen. A number here would fail on the addition rather than on a
    // defect, which is a guard that costs more than it catches.
    expect(find.text('Default look'), findsWidgets);
  });

  /// One pump per test on purpose.
  ///
  /// Pumping a second MaterialApp into the same test reuses the element tree,
  /// so `WidgetsTab`'s State survives and never re-loads — the second
  /// fixture is fetched and the first one is what you assert against. It reads
  /// as the screen ignoring the config.
  testWidgets('a chat that is off says so on its card', (tester) async {
    await pump(
      tester,
      permissions: const ['web_management.view'],
      chat: const {'enabled': false},
    );

    expect(find.text('Not on this website'), findsOneWidget);
  });

  testWidgets('a chat on a schedule says so on its card', (tester) async {
    await pump(
      tester,
      permissions: const ['web_management.view'],
      chat: {
        'hours': [
          for (var i = 0; i < 7; i++)
            {'closed': false, 'open': '08:00', 'close': '17:00'},
        ],
        'timezone': 'America/New_York',
      },
    );

    expect(find.text('On, during set hours'), findsOneWidget);
  });

  /// Jose, 2026-09-23: *"The cards should be uniform, same sizes"*.
  ///
  /// They were 260 wide and as tall as whatever they held. `Wrap` does not
  /// stretch its children, and the blurbs run to one line or two — "Where the
  /// business is." against "The bubble a visitor opens to start a
  /// conversation." — so the row came out ragged along the bottom.
  ///
  /// Measured rather than asserted by eye: every card's rendered size, all
  /// seven compared to the first. A test that only checked the tallest and the
  /// shortest would pass for a grid that had gone uniform at the WRONG height
  /// and started clipping.
  /// The height is a count of text lines, so it has to grow with the text.
  ///
  /// Marketing made the cards uniform at a fixed 132 and flagged this as the
  /// thing they had not solved. They were right: measured at an OS text scale
  /// of 1.8 the card overflowed its Column by 46 pixels, and the part that
  /// went was the status line — the one thing the grid exists to show.
  ///
  /// A Flutter overflow is not a test failure by default; it paints stripes
  /// and carries on. So this catches the exception explicitly rather than
  /// asserting on a size, because a size assertion would pass on a card that
  /// is the right height and clipping.
  testWidgets('the cards hold their content at a large text scale', (
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

    await pump(
      tester,
      permissions: const ['web_management.view'],
      textScale: 1.8,
    );

    expect(
      overflows,
      isEmpty,
      reason: 'A card clipped its own content at 1.8x text.',
    );

    // The control: still uniform at that scale, so the fix is not "let every
    // card size itself" wearing a passing test.
    final heights = {
      for (final name in const ['Live chat', 'Map'])
        tester
            .getSize(
              find
                  .ancestor(of: find.text(name), matching: find.byType(SizedBox))
                  .first,
            )
            .height,
    };

    expect(heights, hasLength(1));
  });

  testWidgets('every widget card is the same size', (tester) async {
    await pump(tester, permissions: const ['web_management.view']);

    final labels = [
      'Live chat',
      'Contact form',
      'Social links',
      'Towns served',
      'Opening hours',
      'Map',
      'Look',
    ];

    final sizes = [
      for (final label in labels)
        tester.getSize(
          find.ancestor(of: find.text(label), matching: find.byType(Card)).first,
        ),
    ];

    for (var i = 1; i < sizes.length; i++) {
      expect(
        sizes[i],
        sizes.first,
        reason: '${labels[i]} is ${sizes[i]} and Live chat is ${sizes.first}.',
      );
    }

    // And the size is the one that FITS, not merely a shared one. The tallest
    // content is a two-line blurb plus the status line; a uniform grid shorter
    // than that would be uniform and clipped.
    expect(sizes.first.height, greaterThan(100));
  });

  testWidgets('opening a card shows that widget and the way back', (
    tester,
  ) async {
    await pump(tester, permissions: const ['web_management.view']);

    await tester.tap(find.text('Towns served'));
    await tester.pumpAndSettle();

    // Its own settings, and nobody else's.
    expect(find.text('Arrangement'), findsOneWidget);
    expect(find.widgetWithText(TextField, 'Talk to A-1'), findsNothing);

    await tester.tap(find.byTooltip('Back to the widgets'));
    await tester.pumpAndSettle();

    expect(find.text('Live chat'), findsOneWidget);
    expect(find.text('Arrangement'), findsNothing);
  });

  /// THE ONE THAT WOULD HAVE CAUGHT THE OLD SCREEN: four of the six widgets
  /// had no preview at all, and the pane drew the other two together.
  /// THE ONE THAT WOULD HAVE CAUGHT THE OLD SCREEN: four of the six widgets
  /// had no preview at all, and the pane drew the other two together.
  ///
  /// One test per widget, because two pumps in one test reuse the State and
  /// the second fixture never reaches the screen. Each asserts on something
  /// only ITS preview draws, so a pane that fell back to an empty box fails
  /// rather than passing on the heading alone.
  testWidgets('the chat previews, and the form is not beside it', (
    tester,
  ) async {
    await pump(tester, permissions: const ['web_management.view'], open: 'Live chat');

    expect(find.text('Preview'), findsOneWidget);
    expect(find.text('Talk to A-1'), findsWidgets);
    expect(find.text('Send it'), findsNothing);
  });

  testWidgets('social links previews its icons', (tester) async {
    await pump(tester, permissions: const ['web_management.view'], open: 'Social links');

    expect(find.text('Preview'), findsOneWidget);
    expect(find.byIcon(Icons.facebook), findsOneWidget);
  });

  testWidgets('towns served previews its towns', (tester) async {
    await pump(tester, permissions: const ['web_management.view'], open: 'Towns served');

    expect(find.text('Springfield'), findsOneWidget);
  });

  testWidgets('opening hours previews the week', (tester) async {
    await pump(tester, permissions: const ['web_management.view'], open: 'Opening hours');

    expect(find.text('8:00 AM – 5:00 PM'), findsWidgets);
  });

  testWidgets('the map previews at the zoom set', (tester) async {
    await pump(tester, permissions: const ['web_management.view'], open: 'Map');

    expect(find.text('Zoom 14'), findsOneWidget);
  });

  testWidgets('look previews what every widget wears', (tester) async {
    await pump(tester, permissions: const ['web_management.view'], open: 'Look');

    expect(find.text('A panel, in your colours'), findsOneWidget);
  });

  /// ── The switch that covers the business ─────────────────────────
  ///
  /// Jose, 2026-09-23: he switched the chat off on `a-1chimney.com` and went
  /// on receiving chats all day. A-1 Chimney has 47 websites; the other 46
  /// followed a primary that was still on. Nothing was broken — the screen
  /// simply never said that switching one site leaves forty-six alone.
  testWidgets('a company with more than one website is offered both at once', (
    tester,
  ) async {
    await pump(
      tester,
      permissions: const ['web_management.view', 'web_management.edit'],
      twoSites: true,
      open: 'Live chat',
    );

    // The COUNT is the part that would have stopped that day, so it is the
    // part asserted: a button reading "all websites" says nothing a person
    // can check against what they believe.
    expect(find.text('Turn the chat off on all 2 websites'), findsOneWidget);
    expect(find.text('Turn it on on all 2'), findsOneWidget);
  });

  /// One website, no company-wide button. Offering "all 1 websites" would be
  /// a control that cannot do anything the switch above it does not.
  testWidgets('a company with one website is offered only its own switch', (
    tester,
  ) async {
    await pump(
      tester,
      permissions: const ['web_management.view', 'web_management.edit'],
      open: 'Live chat',
    );

    expect(find.textContaining('websites'), findsNothing);
    expect(find.text('Show the chat on this website'), findsOneWidget);
  });

  testWidgets('switching it off everywhere asks for the company scope', (
    tester,
  ) async {
    final sent = <Map<String, dynamic>>[];

    await pump(
      tester,
      permissions: const ['web_management.view', 'web_management.edit'],
      twoSites: true,
      sent: sent,
      open: 'Live chat',
    );

    await tester.tap(find.text('Turn the chat off on all 2 websites'));
    await tester.pumpAndSettle();

    final call = sent.single;

    expect(call['path'], endsWith('/widgets/chat'));
    expect(call['enabled'], isFalse);
    expect(call['scope'], 'company');

    // And it says what happened, in sites rather than in a tick.
    expect(find.textContaining('off on 2 websites'), findsOneWidget);
  });

  testWidgets('and back on the same way', (tester) async {
    final sent = <Map<String, dynamic>>[];

    await pump(
      tester,
      permissions: const ['web_management.view', 'web_management.edit'],
      twoSites: true,
      sent: sent,
      open: 'Live chat',
    );

    await tester.tap(find.text('Turn it on on all 2'));
    await tester.pumpAndSettle();

    expect(sent.single['enabled'], isTrue);
    expect(sent.single['scope'], 'company');
  });

  /// A viewer is shown the state and offered neither button.
  testWidgets('a viewer cannot switch the company', (tester) async {
    await pump(
      tester,
      permissions: const ['web_management.view'],
      twoSites: true,
      open: 'Live chat',
    );

    expect(find.textContaining('Turn the chat off'), findsNothing);
    expect(find.textContaining('Rebuild'), findsNothing);
  });

  /// ── Rebuilding the pages ────────────────────────────────────────
  ///
  /// Jose asked for a cache button "or make it so when any change it done and
  /// saved it clears it". The second half has been true since plugin 1.13.0.
  /// This is for the case that does not cover — a page stale for a reason
  /// EzBizi never saw.
  testWidgets('the rebuild button asks every site of the company', (
    tester,
  ) async {
    final sent = <Map<String, dynamic>>[];

    await pump(
      tester,
      permissions: const ['web_management.view', 'web_management.edit'],
      twoSites: true,
      sent: sent,
      open: 'Live chat',
    );

    await tester.ensureVisible(find.text('Rebuild all 2 websites'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Rebuild all 2 websites'));
    await tester.pumpAndSettle();

    expect(sent.single['path'], endsWith('/widgets/refresh'));
    expect(sent.single['scope'], 'company');
  });

  /// IT MUST NOT CLAIM THE CACHE IS CLEARED.
  ///
  /// EzBizi cannot reach into a customer's website — the plugin pulls, every
  /// five minutes. "Cleared" would be a claim about something this side cannot
  /// observe, and the person reading it would refresh the page and see the old
  /// one.
  testWidgets('the rebuild message promises minutes, not done', (tester) async {
    await pump(
      tester,
      permissions: const ['web_management.view', 'web_management.edit'],
      twoSites: true,
      open: 'Live chat',
    );

    await tester.ensureVisible(find.text('Rebuild all 2 websites'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Rebuild all 2 websites'));
    await tester.pumpAndSettle();

    expect(find.textContaining('within a few minutes'), findsOneWidget);
    expect(find.textContaining('Cleared'), findsNothing);
  });

  /// ── The first three of the fourteen ──────────────────────────────
  ///
  /// Jose, 2026-09-23, after we walked A1 Tools' three live sites: "DO ALL OF
  /// THEM, END TO END". Contact details, the call to action and the phone
  /// button are first because they are on every page of a-1chimney.com,
  /// everdoors.com and crestln.com.
  testWidgets('the three new widgets each have a card', (tester) async {
    await pump(tester, permissions: const ['web_management.view']);

    for (final name in const [
      'Contact details',
      'Call to action',
      'Phone button',
    ]) {
      expect(find.text(name), findsOneWidget, reason: '$name has no card.');
    }
  });

  testWidgets('contact details previews the block it publishes', (
    tester,
  ) async {
    await pump(
      tester,
      permissions: const ['web_management.view'],
      open: 'Contact details',
    );

    expect(find.text('(888) 984-4344'), findsOneWidget);
    expect(find.text('office@example.com'), findsOneWidget);

    // The labels, because `labels` defaults on and they are half of what the
    // arrangement decides.
    expect(find.text('Phone'), findsWidgets);
  });

  /// Switching a detail off has to REMOVE it, not blank it.
  ///
  /// A mail icon over an empty space is the state the plugin refuses to
  /// render, and the preview has to agree or the screen teaches the wrong
  /// thing about what the page will do.
  testWidgets('a detail switched off leaves the preview', (tester) async {
    await pump(
      tester,
      permissions: const ['web_management.view', 'web_management.edit'],
      open: 'Contact details',
    );

    expect(find.text('office@example.com'), findsOneWidget);

    await tester.tap(find.widgetWithText(SwitchListTile, 'Include the email address'));
    await tester.pumpAndSettle();

    expect(find.text('office@example.com'), findsNothing);
    // The others stay, so this is about the one that was switched rather than
    // about the block having stopped rendering.
    expect(find.text('(888) 984-4344'), findsOneWidget);
  });

  /// THE PLACEMENT IS THE WHOLE DECISION, so the preview draws where it sits.
  ///
  /// A button previewed in place three times would say nothing about the
  /// difference between inline, a bar along the bottom and a panel over the
  /// page — which is the only thing somebody is choosing here.
  testWidgets('the call to action previews where it sits', (tester) async {
    await pump(
      tester,
      permissions: const ['web_management.view'],
      open: 'Call to action',
    );

    // Inline by default: the button, and no mock page around it.
    expect(find.text('Call us today'), findsOneWidget);
    expect(find.text('your page'), findsNothing);
  });

  testWidgets('choosing the bar draws it against a page', (tester) async {
    await pump(
      tester,
      permissions: const ['web_management.view', 'web_management.edit'],
      open: 'Call to action',
    );

    await tester.tap(find.byType(SearchPickerField<String>).first);
    await tester.pumpAndSettle();
    await tester.tap(find.text('bar').last);
    await tester.pumpAndSettle();

    expect(find.text('your page'), findsOneWidget);
    // The exact sentence the PREVIEW writes, not a phrase. "along the bottom"
    // also appears in the card's own blurb and in the picker option, so a
    // containing-match finds three and none of them is the preview — the same
    // trap as a guard matching its own docblock.
    expect(
      find.text('Fixed along the bottom of every page it is placed on.'),
      findsOneWidget,
    );
  });

  /// The wording is free text, and the preview has to follow it — a button
  /// that previewed "Call us today" while the page said something else would
  /// be worse than no preview.
  testWidgets('the wording typed is the wording previewed', (tester) async {
    await pump(
      tester,
      permissions: const ['web_management.view', 'web_management.edit'],
      open: 'Call to action',
    );

    await tester.enterText(
      find.widgetWithText(TextFormField, 'Wording'),
      'Ring the sweeps',
    );
    await tester.pumpAndSettle();

    expect(find.text('Ring the sweeps'), findsWidgets);
    expect(find.text('Call us today'), findsNothing);
  });

  /// Jose, 2026-09-24: the smiley widget on a-1chimney.com/reviews.
  testWidgets('review faces has a card and previews both faces', (
    tester,
  ) async {
    await pump(
      tester,
      permissions: const ['web_management.view'],
      open: 'Review faces',
    );

    expect(find.text('How was your experience?'), findsWidgets);
    expect(find.text('Great!'), findsWidgets);
    expect(find.text('Not so great'), findsWidgets);
  });

  /// Jose, 2026-09-25: "whatever is missing that is being used add it".
  /// The team, branches and store locator draw lists, kept on their cards.
  testWidgets('the team card holds the team and saves it whole', (tester) async {
    final sent = <Map<String, dynamic>>[];
    await pump(
      tester,
      permissions: const ['web_management.view', 'web_management.edit'],
      open: 'Team',
      sent: sent,
    );

    expect(find.text('Ana Ruiz'), findsOneWidget);

    await tester.tap(find.text('Save list'));
    await tester.pumpAndSettle();

    final put = sent.singleWhere((s) => '${s['path']}'.endsWith('/team'));
    final members = (put['members'] as List).cast<Map>();
    expect(members.single['name'], 'Ana Ruiz');
    // Empty boxes go as null, not as an empty string the server would keep.
    expect(members.single['bio'], isNull);
  });

  testWidgets('branches are this website\'s own, not the company\'s stores', (
    tester,
  ) async {
    final sent = <Map<String, dynamic>>[];
    await pump(
      tester,
      permissions: const ['web_management.view', 'web_management.edit'],
      open: 'Branches',
      sent: sent,
    );

    expect(find.text('North Jersey'), findsOneWidget);
    expect(find.text('Atlanta store'), findsNothing);

    await tester.tap(find.text('Save list'));
    await tester.pumpAndSettle();

    final put = sent.singleWhere((s) => '${s['path']}'.endsWith('/locations'));
    expect(put['scope'], 'site');
    // A coordinate goes as a number.
    expect((put['locations'] as List).single['latitude'], 40.73);
  });

  testWidgets('the store locator edits the company\'s stores', (tester) async {
    final sent = <Map<String, dynamic>>[];
    await pump(
      tester,
      permissions: const ['web_management.view', 'web_management.edit'],
      open: 'Store locator',
      sent: sent,
    );

    expect(find.text('Atlanta store'), findsOneWidget);

    await tester.tap(find.text('Save list'));
    await tester.pumpAndSettle();

    expect(
      sent.singleWhere((s) => '${s['path']}'.endsWith('/locations'))['scope'],
      'company',
    );
  });

  /// Jose, 2026-09-25: "yes all of it" — the FAQ keeps its questions here.
  testWidgets('the FAQ card holds the questions and saves them to its kind', (
    tester,
  ) async {
    final sent = <Map<String, dynamic>>[];
    await pump(
      tester,
      permissions: const ['web_management.view', 'web_management.edit'],
      open: 'FAQ',
      sent: sent,
    );

    expect(find.text('Do you sweep in winter?'), findsOneWidget);

    await tester.tap(find.text('Save list'));
    await tester.pumpAndSettle();

    final put = sent.singleWhere((s) => '${s['path']}'.endsWith('/items/faq'));
    expect((put['items'] as List).single['body'], 'Yes, all year.');
  });

  testWidgets('view alone can read a list and not change it', (tester) async {
    await pump(tester, permissions: const ['web_management.view'], open: 'Team');

    expect(find.text('Ana Ruiz'), findsOneWidget);
    expect(find.text('Save list'), findsNothing);
    expect(find.text('Add a person'), findsNothing);
  });

  testWidgets('the phone button previews at the size set', (tester) async {
    await pump(
      tester,
      permissions: const ['web_management.view'],
      open: 'Phone button',
    );

    expect(find.text('(888) 984-4344'), findsOneWidget);
  });

  /// The preview follows the knobs, which is the only reason it is worth
  /// drawing. Asserted on values the DEFAULT preset decides, so a preview that
  /// ignored the stored preset would fail rather than pass on shape alone.
  testWidgets('a rendered preview obeys its preset', (tester) async {
    await pump(
      tester,
      permissions: const ['web_management.view'],
      open: 'Social links',
    );

    // `labels: false` by default, so the network names are not drawn.
    expect(find.text('Facebook'), findsNothing);
  });

  testWidgets('the towns preview groups them when the preset says to', (
    tester,
  ) async {
    await pump(
      tester,
      permissions: const ['web_management.view'],
      open: 'Towns served',
    );

    // `group: true` by default, so the counties head their towns.
    expect(find.text('Hampden County'), findsOneWidget);
  });

  testWidgets('the widget settings come from the server, not the shortcode', (
    tester,
  ) async {
    await pump(
      tester,
      open: 'Live chat', permissions: ['web_management.view', 'web_management.edit', 'web_management.seo.view', 'web_management.widgets.view']);

    expect(find.widgetWithText(TextField, 'Talk to A-1'), findsOneWidget);
  });

  /// Split from the chat's own test when the screen became cards: the three
  /// values it used to assert together live under three different widgets now,
  /// and one test that opened one card could only ever see one of them.
  testWidgets('the form questions are editable rows, not a string', (
    tester,
  ) async {
    await pump(
      tester,
      open: 'Contact form',
      permissions: const ['web_management.view', 'web_management.edit'],
    );

    expect(find.widgetWithText(TextField, 'What needs doing?'), findsOneWidget);
    expect(find.text('Add a question'), findsOneWidget);
  });

  testWidgets('the look settings come from the server too', (tester) async {
    await pump(
      tester,
      open: 'Look',
      permissions: const ['web_management.view', 'web_management.edit'],
    );

    expect(find.widgetWithText(TextField, '#aa0000'), findsOneWidget);
  });

  /// A branch site showing the primary's colours as if somebody had typed them
  /// here would have people "changing" a value that was never theirs.
  testWidgets('a site following the primary says so', (tester) async {
    await pump(
      tester,
      permissions: ['web_management.view', 'web_management.edit', 'web_management.seo.view', 'web_management.widgets.view'],
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
      open: 'Look',
      permissions: ['web_management.view', 'web_management.edit', 'web_management.seo.view', 'web_management.widgets.view'],
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
      open: 'Look',
      permissions: ['web_management.view', 'web_management.edit', 'web_management.seo.view', 'web_management.widgets.view'],
      style: const {
        'accent': '#ffff00',
        'on_accent': '#ffffff',
        'text': '#111111',
        'background': '#ffffff',
        'font': 'inherit',
        'radius': 12,
      },
    );

    // Save is lazily built at the bottom of the editor, and the sections below
    // this one put it past the fold even on a tall test window.
    await tester.scrollUntilVisible(find.text('Save'), 300, scrollable: _editor);

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
      open: 'Look',
      permissions: ['web_management.view', 'web_management.edit', 'web_management.seo.view', 'web_management.widgets.view'],
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
      open: 'Look',
      permissions: ['web_management.view', 'web_management.edit', 'web_management.seo.view', 'web_management.widgets.view'],
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
      open: 'Look',
      permissions: ['web_management.view', 'web_management.edit', 'web_management.seo.view', 'web_management.widgets.view'],
      own: true,
    );

    expect(find.textContaining('Following the primary website'), findsNothing);

    await tester.scrollUntilVisible(
      find.text('Follow the primary again'),
      300,
      scrollable: _editor,
    );

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
      open: 'Look',
      permissions: ['web_management.view', 'web_management.edit', 'web_management.seo.view', 'web_management.widgets.view'],
      brand: null,
    );

    expect(find.textContaining('you have not set one'), findsOneWidget);
    expect(find.textContaining('neutral blue'), findsOneWidget);
  });

  testWidgets('and names the brand colour when there is one', (tester) async {
    await pump(
      tester,
      open: 'Look',
      permissions: ['web_management.view', 'web_management.edit', 'web_management.seo.view', 'web_management.widgets.view'],
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
      open: 'Look',
      permissions: ['web_management.view', 'web_management.edit', 'web_management.seo.view', 'web_management.widgets.view'],
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

    // Scrolled to the FIELD rather than to the swatch, because ensureVisible
    // parks its target against the leading edge — aiming at the swatch puts
    // the two boxes this test reads above the top of the pane, where a finder
    // cannot see them.
    await tester.scrollUntilVisible(
      find.text('Accent colour'),
      200,
      scrollable: _editor,
    );
    await tester.tap(find.byTooltip('#00838f'));
    await tester.pumpAndSettle();

    // Both halves moved: the accent AND the text that has to be legible on it.
    expect(find.widgetWithText(TextField, '#00838f'), findsOneWidget);
    expect(find.widgetWithText(TextField, '#000000'), findsOneWidget);

    // And the complaint is gone, because a picked pair cannot be unreadable.
    expect(find.textContaining('1.1:1'), findsNothing);
  });

  testWidgets('a viewer is offered no presets to pick', (tester) async {
    await pump(
      tester,
      open: 'Look', permissions: ['web_management.view', 'web_management.seo.view', 'web_management.widgets.view']);

    expect(find.byTooltip('#00838f'), findsNothing);
  });

  /// Every value on this screen only means something once it is rendered, and
  /// the page it renders on is one nobody at EzBizi will ever load.
  testWidgets('the preview shows the wording and questions being edited', (
    tester,
  ) async {
    await pump(
      tester,
      open: 'Live chat', permissions: ['web_management.view', 'web_management.edit', 'web_management.seo.view', 'web_management.widgets.view']);

    // TWO of each is the assertion: the box somebody types in, and the preview
    // drawing the same value. One would mean the preview never rendered it.
    expect(find.text('Talk to A-1'), findsNWidgets(2));
    expect(find.text('We answer fast.'), findsNWidgets(2));
    expect(find.text('Chat'), findsNWidgets(2));

    // 'Send it' is the FORM's button and is no longer here, which is the whole
    // point of the change: the pane previews the widget that is open and not
    // the one beside it.
    expect(find.text('Send it'), findsNothing);
  });

  /// The other half of the old combined test, under its own card.
  testWidgets('the form preview draws the questions being edited', (
    tester,
  ) async {
    await pump(
      tester,
      open: 'Contact form',
      permissions: const ['web_management.view', 'web_management.edit'],
    );

    // TWO of each: the box somebody types in, and the preview drawing the same
    // value. One would mean the preview never rendered it.
    expect(find.text('Send it'), findsNWidgets(2));

    // 'Name *' rather than 'Name': the preview marks a required question the
    // way the visitor's form does, which is part of what is being previewed.
    expect(find.text('Name *'), findsOneWidget);
    expect(find.textContaining('What needs doing?'), findsWidgets);

    // And the chat is not here.
    expect(find.text('Talk to A-1'), findsNothing);
  });

  /// The preview shows what a visitor meets FIRST, which is the three
  /// questions and not the message thread.
  ///
  /// Jose compared ours to the chat on A1 Tools: that one asks who you are
  /// before the conversation, ours opened straight into a message box, and a
  /// chat with nobody's name on it cannot be answered if they close the tab.
  /// The preview is where he checks that wording, so it has to be the step
  /// the wording belongs to.
  testWidgets('the preview asks before it chats', (tester) async {
    await pump(
      tester,
      open: 'Live chat', permissions: ['web_management.view', 'web_management.edit', 'web_management.seo.view', 'web_management.widgets.view']);

    // The editable copy, drawn as the visitor will meet it.
    expect(find.textContaining('Quick details first'), findsWidgets);
    expect(find.text('Your name *'), findsOneWidget);
    // Jose, 2026-10-01: phone, ZIP and email, each its own box, for Leads.
    expect(find.text('Phone *'), findsOneWidget);
    expect(find.text('ZIP code *'), findsOneWidget);
    expect(find.text('Email *'), findsOneWidget);
    expect(find.text('Phone or email *'), findsNothing);
    expect(find.text('How can we help? *'), findsOneWidget);
    expect(find.text('Start chat'), findsWidgets);

    // The control: the closed launcher is still previewed beside it, so these
    // assertions are about the panel's contents rather than a blank pane.
    expect(find.text('Chat'), findsWidgets);
  });

  /// There is no switch, and nothing may imply there is one.
  ///
  /// Jose removed both on 2026-09-15: putting the widget on a page is the
  /// decision to show it. This is the version of the old test, inverted —
  /// it used to tap the switch and assert the preview said "off on your site".
  /// Every site had both switched off, so every site showed nothing, and the
  /// only notice of that was a grey phrase in a preview that reads as styling.
  /// ── Chat hours ───────────────────────────────────────────────────
  ///
  /// Jose, 2026-09-17: the bubble should be on the website during working
  /// hours and gone outside them, "cause if no one is there to answer it's
  /// bad". What this side has to get right is the SHAPE that reaches the
  /// server — the browser on the customer's site reads it and hides the bubble,
  /// and it fails open on anything it cannot read.
  testWidgets('a site with no chat hours says the chat is always on', (
    tester,
  ) async {
    await pump(
      tester,
      open: 'Live chat', permissions: ['web_management.view', 'web_management.edit']);

    expect(find.textContaining('on every page, at every hour'), findsOneWidget);
    expect(find.text('Set chat hours'), findsOneWidget);

    // No grid until somebody asks for one: seven rows of times nobody has
    // chosen would read as a schedule that is already in force.
    expect(find.text('Mon'), findsNothing);
  });

  /// Both keys or neither, and this is the assertion that catches the half.
  ///
  /// The server drops a schedule with no zone and a zone with no schedule, so a
  /// screen that set them one at a time would produce a save that silently
  /// discarded what was just typed — green everywhere, nothing gated.
  testWidgets('setting hours sends a week AND a zone', (tester) async {
    final sent = <Map<String, dynamic>>[];

    await pump(
      tester,
      open: 'Live chat',
      permissions: ['web_management.view', 'web_management.edit'],
      sent: sent,
    );

    await tester.tap(find.text('Set chat hours'));
    await tester.pumpAndSettle();

    // Save is lazily built at the bottom of the editor, so an unscrolled tap
    // finds no element at all. See `_editor` for why the pane is named by its
    // key rather than by a heading inside it.
    await tester.scrollUntilVisible(find.text('Save'), 300, scrollable: _editor);
    await tester.tap(find.text('Save'));
    await tester.pumpAndSettle();

    final chat = (sent.single['config'] as Map)['chat'] as Map;
    final hours = chat['hours'] as List;

    expect(hours, hasLength(7));
    expect(chat['timezone'], isNotEmpty);

    // Padded to two digits. The browser's own check wants `08:00` and reads
    // `8:00` as unparseable — which fails open and leaves the bubble up all
    // night, the exact thing this feature exists to stop.
    expect((hours.first as Map)['open'], '08:00');
    expect((hours.first as Map)['close'], '17:00');
    expect((hours.first as Map)['closed'], isFalse);

    // A guess somebody can correct in two clicks: weekdays on, weekend off.
    expect((hours.last as Map)['closed'], isTrue);
  });

  testWidgets('an existing schedule is drawn as a week with its zone', (
    tester,
  ) async {
    await pump(
      tester,
      open: 'Live chat',
      permissions: ['web_management.view', 'web_management.edit'],
      chat: {
        'hours': [
          for (var i = 0; i < 5; i++)
            {'closed': false, 'open': '08:00', 'close': '17:00'},
          {'closed': true, 'open': '00:00', 'close': '00:00'},
          {'closed': true, 'open': '00:00', 'close': '00:00'},
        ],
        'timezone': 'America/New_York',
      },
    );

    expect(find.text('Mon'), findsOneWidget);
    expect(find.text('Sun'), findsOneWidget);
    expect(find.text('8:00 AM'), findsWidgets);
    expect(find.text('5:00 PM'), findsWidgets);
    expect(find.text('No chat this day'), findsNWidgets(2));

    // The zone is shown as a place rather than as an identifier, because
    // "America/New_York" is not how anybody describes where their office is.
    expect(find.textContaining('Eastern'), findsOneWidget);
  });

  /// Clearing IS the always-on setting, and it must clear BOTH.
  ///
  /// A zone left behind with no schedule gates nothing and would sit in the
  /// config looking like a setting that had stopped working.
  testWidgets('showing at all hours clears the week and the zone', (
    tester,
  ) async {
    final sent = <Map<String, dynamic>>[];

    await pump(
      tester,
      open: 'Live chat',
      permissions: ['web_management.view', 'web_management.edit'],
      sent: sent,
      chat: {
        'hours': [
          for (var i = 0; i < 7; i++)
            {'closed': false, 'open': '08:00', 'close': '17:00'},
        ],
        'timezone': 'America/New_York',
      },
    );

    await tester.tap(find.text('Show at all hours'));
    await tester.pumpAndSettle();

    // Save is lazily built at the bottom of the editor, so an unscrolled tap
    // finds no element at all. See `_editor` for why the pane is named by its
    // key rather than by a heading inside it.
    await tester.scrollUntilVisible(find.text('Save'), 300, scrollable: _editor);
    await tester.tap(find.text('Save'));
    await tester.pumpAndSettle();

    final chat = (sent.single['config'] as Map)['chat'] as Map;

    expect(chat['hours'], isEmpty);
    expect(chat['timezone'], '');
  });

  testWidgets('a viewer is shown the hours and cannot change them', (
    tester,
  ) async {
    await pump(
      tester,
      open: 'Live chat',
      permissions: ['web_management.view'],
      chat: {
        'hours': [
          for (var i = 0; i < 7; i++)
            {'closed': false, 'open': '08:00', 'close': '17:00'},
        ],
        'timezone': 'America/New_York',
      },
    );

    expect(find.text('Mon'), findsOneWidget);
    expect(find.text('Show at all hours'), findsNothing);

    final box = tester.widget<Checkbox>(find.byType(Checkbox).first);
    expect(box.onChanged, isNull);
  });

  /// ── The chat's switch, and the form's absent one ─────────────────
  ///
  /// Mia, 2026-09-18: "i also want to have a toggle to turn it on or off, on
  /// being the default, (by turning it off, it basically disappears on the
  /// website, effectively disabling the chat)".
  ///
  /// This is the older "nothing offers to switch a widget off" test, narrowed
  /// rather than deleted. Both switches were removed on 2026-09-15 because
  /// every site had both off and so every site showed nothing; the chat's came
  /// back with a default of `true`, which cannot produce that state. The form
  /// still has none, and `WidgetDefaults::chat()` says why: the chat injects
  /// itself into every page's footer, so a switch is the only way off, while
  /// the form appears where somebody placed a shortcode.
  testWidgets('only the chat can be switched off, and the form cannot', (
    tester,
  ) async {
    await pump(
      tester,
      open: 'Live chat', permissions: ['web_management.view', 'web_management.edit', 'web_management.seo.view', 'web_management.widgets.view']);

    expect(find.text('Preview'), findsOneWidget);

    expect(
      find.widgetWithText(SwitchListTile, 'Show the chat on this website'),
      findsOneWidget,
    );

    expect(find.text('Show the contact form'), findsNothing);
    expect(find.textContaining('form on this website'), findsNothing);

    // The phrase the old preview used to carry. It read as styling, which is
    // how a hundred switched-off sites went unnoticed.
    expect(find.text('off on your site'), findsNothing);

    // The control: the editor and the preview are both still drawn, so the
    // assertions above are about an absent switch rather than an absent screen.
    expect(find.text('Talk to A-1'), findsNWidgets(2));
  });

  /// ON is the default, and that is the whole difference from the switches
  /// that were removed. A site nobody has configured must arrive with a chat.
  testWidgets('the chat switch is on for a site with no config of its own', (
    tester,
  ) async {
    await pump(
      tester,
      open: 'Live chat',
      permissions: ['web_management.view', 'web_management.edit'],
      own: false,
    );

    final control = tester.widget<SwitchListTile>(
      find.widgetWithText(SwitchListTile, 'Show the chat on this website'),
    );

    expect(control.value, isTrue);
    expect(control.onChanged, isNotNull);
  });

  /// Asserted on the REQUEST BODY, not on the switch.
  ///
  /// A switch that flips and never reaches `config.chat.enabled` looks
  /// identical on screen to one that works, and the site keeps its chat.
  testWidgets('switching the chat off sends chat.enabled false on save', (
    tester,
  ) async {
    final sent = <Map<String, dynamic>>[];

    await pump(
      tester,
      open: 'Live chat',
      permissions: ['web_management.view', 'web_management.edit'],
      sent: sent,
    );

    await tester.tap(
      find.widgetWithText(SwitchListTile, 'Show the chat on this website'),
    );
    await tester.pumpAndSettle();

    await tester.scrollUntilVisible(find.text('Save'), 300, scrollable: _editor);
    await tester.tap(find.text('Save'));
    await tester.pumpAndSettle();

    final chat = (sent.single['config'] as Map)['chat'] as Map;

    expect(chat['enabled'], isFalse);

    // The wording travels with it. The server stores the whole config through
    // the sanitiser, so a payload that sent the flag alone would blank every
    // other field on the chat.
    expect(chat['title'], 'Talk to A-1');
  });

  /// Off means off on the WEBSITE, and still editable here.
  ///
  /// Somebody switches the chat off because the wording is wrong, fixes the
  /// wording, and switches it back on. A section greyed out forces the
  /// opposite order, with the unfinished wording live while they work.
  testWidgets('an off chat leaves the preview but not the fields', (
    tester,
  ) async {
    await pump(
      tester,
      open: 'Live chat',
      permissions: ['web_management.view', 'web_management.edit'],
      chat: const {'enabled': false},
    );

    // ONE, not two: the box somebody types in, and no bubble beside it. Two
    // would mean the preview is still drawing a chat this site does not have.
    expect(find.text('Talk to A-1'), findsOneWidget);
    expect(find.textContaining('nothing here for a visitor to open'),
        findsOneWidget);

    // Said in the editor as well, because the preview is a pane somebody can
    // drag shut.
    expect(find.textContaining('No visitor sees a bubble'), findsOneWidget);

    final title = tester.widget<TextField>(
      find.widgetWithText(TextField, 'Talk to A-1'),
    );

    expect(title.enabled, isTrue);
  });

  // ── Rendered widgets: presets ────────────────────────────────────

  /// The name is the only argument a rendered shortcode takes, so the string
  /// this screen prints is the string somebody pastes. If it disagreed with
  /// the stored name by one character the page would quietly render the
  /// default instead.
  testWidgets('a named preset is added and wears its own shortcode', (
    tester,
  ) async {
    await pump(
      tester,
      open: 'Social links', permissions: ['web_management.view', 'web_management.edit']);

    await _reachPresets(tester, 'social_links');

    await tester.enterText(
      find.byKey(const ValueKey('preset-add:social_links')),
      'footer',
    );
    await tester.tap(_addPreset('social_links'));
    await tester.pumpAndSettle();

    expect(find.byTooltip('Copies [ezbizi_social_links preset="footer"]'), findsOneWidget);

    // The default keeps the bare shortcode, which is what every page that has
    // not asked for anything is already using.
    expect(find.byTooltip('Copies [ezbizi_social_links]'), findsOneWidget);

    // Named on the widget it belongs to: "footer" means one thing to a row of
    // icons and another to a list of towns, so the two do not share a name.
    expect(find.byTooltip('Copies [ezbizi_city_list preset="footer"]'), findsNothing);
  });

  /// `default` is what catches a typo — `preset="foter"` renders it rather
  /// than rendering nothing into somebody's footer. Offering to delete it
  /// would be offering to remove the safety net, and the server would put it
  /// straight back.
  testWidgets('the default preset offers no way to delete or rename it', (
    tester,
  ) async {
    await pump(
      tester,
      open: 'Social links', permissions: ['web_management.view', 'web_management.edit']);

    await _reachPresets(tester, 'social_links');

    await tester.enterText(
      find.byKey(const ValueKey('preset-add:social_links')),
      'footer',
    );
    await tester.tap(_addPreset('social_links'));
    await tester.pumpAndSettle();

    for (final tooltip in const ['Delete this preset', 'Rename this preset']) {
      expect(
        find.descendant(
          of: find.byKey(const ValueKey('preset:social_links:default')),
          matching: find.byTooltip(tooltip),
        ),
        findsNothing,
        reason: '"$tooltip" is offered on a preset the server will not drop.',
      );

      // The control: the named preset beside it has both, so the assertion
      // above is about `default` rather than about buttons that never render.
      expect(
        find.descendant(
          of: find.byKey(const ValueKey('preset:social_links:footer')),
          matching: find.byTooltip(tooltip),
        ),
        findsOneWidget,
      );
    }
  });

  /// `WidgetPresets::name` lowercases and STRIPS — "Footer Icons" is stored as
  /// `footericons`, not `footer-icons`. A box that accepted the typed form
  /// would print a shortcode nobody could paste: the page would ask for a
  /// preset by a name the server never stored and get the default.
  testWidgets('a name typed with capitals and spaces is normalised in the box',
      (tester) async {
    await pump(
      tester,
      open: 'Social links', permissions: ['web_management.view', 'web_management.edit']);

    await _reachPresets(tester, 'social_links');

    final box = find.byKey(const ValueKey('preset-add:social_links'));

    await tester.enterText(box, 'Footer Icons');
    await tester.pumpAndSettle();

    expect(
      tester.widget<TextField>(box).controller?.text,
      'footericons',
      reason: 'The name changes on save rather than while it is being typed.',
    );

    await tester.tap(_addPreset('social_links'));
    await tester.pumpAndSettle();

    expect(
      find.byTooltip('Copies [ezbizi_social_links preset="footericons"]'),
      findsOneWidget,
    );
  });

  /// A preset that is edited on a site following the primary makes that site
  /// its own, exactly as every other field here does — one mechanism, not two.
  testWidgets('a preset edited on a following site goes up with the config', (
    tester,
  ) async {
    final sent = <Map<String, dynamic>>[];

    await pump(
      tester,
      open: 'Social links',
      permissions: ['web_management.view', 'web_management.edit'],
      own: false,
      sent: sent,
    );

    expect(find.textContaining('Following the primary website'), findsOneWidget);

    await _reachPresets(tester, 'social_links');

    await tester.enterText(
      find.byKey(const ValueKey('preset-add:social_links')),
      'footer',
    );
    await tester.tap(_addPreset('social_links'));
    await tester.pumpAndSettle();

    await tester.scrollUntilVisible(find.text('Save'), 300, scrollable: _editor);
    await tester.tap(find.text('Save'));
    await tester.pumpAndSettle();

    final presets = (sent.single['config'] as Map)['presets'] as Map;
    final social = presets['social_links'] as Map;

    // The inherited default travelled with it. A payload carrying only the new
    // name would hand this site a `default` it never chose.
    expect(social.keys, containsAll(<String>['default', 'footer']));
    expect((social['default'] as Map)['size'], 28);
  });

  /// Jose, 2026-09-25: "i want all the options, for all the widgets". The
  /// style options come from the server's schema, grouped by the part they
  /// style, and a colour typed into one goes up with the preset.
  testWidgets('a style option from the schema is drawn and saved', (
    tester,
  ) async {
    final sent = <Map<String, dynamic>>[];

    await pump(
      tester,
      open: 'Social links',
      permissions: ['web_management.view', 'web_management.edit'],
      own: true,
      sent: sent,
    );

    await _reachPresets(tester, 'social_links');

    final group = find.text('Style — icons');
    await tester.scrollUntilVisible(group, 300, scrollable: _editor);
    expect(find.text('Style — the whole widget'), findsOneWidget);
    // An option without a part is the widget's own, not a style group.
    expect(find.text('Style — size'), findsNothing);

    // Toula, 2026-10-05: every colour sits open under "Colours", named by
    // part, not folded inside these groups where nobody found them.
    expect(find.text('Colours'), findsWidgets);
    final iconColour = find.widgetWithText(TextField, 'Icons — colour');
    await tester.scrollUntilVisible(iconColour, -300, scrollable: _editor);
    await tester.enterText(iconColour, '#ff0000');
    await tester.pumpAndSettle();

    // Jose, 2026-10-05: "she changes color from orange to blue, but the live
    // preview remains orange". The preview's icons take the colour at once.
    expect(
      find.byWidgetPredicate((w) => w is Icon && w.color == const Color(0xFFFF0000)),
      findsWidgets,
      reason: 'the preview shows the icon colour just chosen',
    );

    // And only once: the folded group no longer repeats it.
    await tester.scrollUntilVisible(group, 300, scrollable: _editor);
    await tester.tap(group);
    await tester.pumpAndSettle();
    expect(find.widgetWithText(TextField, 'Colour'), findsNothing);

    await tester.scrollUntilVisible(find.text('Save'), 300, scrollable: _editor);
    await tester.tap(find.text('Save'));
    await tester.pumpAndSettle();

    final social = ((sent.single['config'] as Map)['presets'] as Map)['social_links'] as Map;
    expect((social['default'] as Map)['icon_color'], '#ff0000');
  });

  /// Renaming is a dialog because it changes a KEY. Applied per keystroke it
  /// would walk through `s`, `si`, `sid` as three differently named presets,
  /// each advertising its own shortcode on the way past.
  testWidgets('a named preset can be renamed and then deleted', (tester) async {
    await pump(
      tester,
      // Opening hours, because that is the widget this test works on. Only
      // the open widget's presets are on screen now.
      open: 'Opening hours', permissions: ['web_management.view', 'web_management.edit']);

    await _reachPresets(tester, 'hours_table');

    await tester.enterText(
      find.byKey(const ValueKey('preset-add:hours_table')),
      'footer',
    );
    await tester.tap(_addPreset('hours_table'));
    await tester.pumpAndSettle();

    expect(find.byTooltip('Copies [ezbizi_hours_table preset="footer"]'), findsOneWidget);

    await tester.tap(find.descendant(
      of: find.byKey(const ValueKey('preset:hours_table:footer')),
      matching: find.byTooltip('Rename this preset'),
    ));
    await tester.pumpAndSettle();

    await tester.enterText(
      find.descendant(
        of: find.byType(AlertDialog),
        matching: find.byType(TextField),
      ),
      'Side Bar',
    );
    await tester.tap(find.widgetWithText(FilledButton, 'Rename'));
    await tester.pumpAndSettle();

    expect(find.byTooltip('Copies [ezbizi_hours_table preset="sidebar"]'), findsOneWidget);
    expect(find.byTooltip('Copies [ezbizi_hours_table preset="footer"]'), findsNothing);

    await tester.tap(find.descendant(
      of: find.byKey(const ValueKey('preset:hours_table:sidebar')),
      matching: find.byTooltip('Delete this preset'),
    ));
    await tester.pumpAndSettle();

    expect(find.byTooltip('Copies [ezbizi_hours_table preset="sidebar"]'), findsNothing);

    // The default outlives every named one, which is what makes a mistyped
    // `preset=` render something rather than nothing.
    expect(find.byTooltip('Copies [ezbizi_hours_table]'), findsOneWidget);
  });

  testWidgets('a viewer is offered no way to add or delete a preset', (
    tester,
  ) async {
    await pump(
      tester,
      open: 'Social links', permissions: ['web_management.view']);

    await _reachPresets(tester, 'social_links');

    expect(find.byKey(const ValueKey('preset-add:social_links')), findsNothing);
    expect(find.byTooltip('Delete this preset'), findsNothing);

    // Still shown the shortcode: reading which one a page should use is not
    // editing anything.
    expect(find.byTooltip('Copies [ezbizi_social_links]'), findsOneWidget);
  });

  /// Four widgets, each with a dropdown, a slider or two and a switch, is the
  /// section most likely to run off the side of a phone.
  testWidgets('the presets lay out at 375 without overflowing', (tester) async {
    await pump(
      tester,
      open: 'Social links',
      permissions: ['web_management.view', 'web_management.edit'],
      size: const Size(375, 2400),
    );

    await _reachPresets(tester, 'social_links');

    await tester.enterText(
      find.byKey(const ValueKey('preset-add:social_links')),
      'a-very-long-preset-name-indeed',
    );
    await tester.pumpAndSettle();
    await tester.tap(_addPreset('social_links'));
    await tester.pumpAndSettle();

    expect(
      tester.takeException(),
      isNull,
      reason: 'A RenderFlex overflow here is the yellow-and-black bar on a '
          'phone, and it takes the rest of the pane with it.',
    );

    expect(
      find.byTooltip('Copies [ezbizi_social_links preset="a-very-long-preset-name-indeed"]'),
      findsOneWidget,
    );
  });

  /// The preview is what makes a colour mean something, so it has to follow the
  /// pick rather than the saved value.
  testWidgets('the preview follows a preset before anything is saved', (
    tester,
  ) async {
    await pump(
      tester,
      open: 'Look', permissions: ['web_management.view', 'web_management.edit', 'web_management.seo.view', 'web_management.widgets.view']);

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
    await pump(
      tester,
      open: 'Live chat', permissions: ['web_management.view', 'web_management.edit', 'web_management.seo.view', 'web_management.widgets.view']);

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
    await pump(
      tester,
      open: 'Live chat', permissions: ['web_management.view', 'web_management.edit', 'web_management.seo.view', 'web_management.widgets.view']);

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
      open: 'Live chat',
      permissions: ['web_management.view', 'web_management.edit', 'web_management.seo.view', 'web_management.widgets.view'],
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

    /*
     * A different website opens on its own CARDS, not on whatever card was
     * open for the last one — the editor is keyed by site id, so its state
     * goes with the site. That is deliberate: "Live chat" on a-1chimney.com
     * and on nj.a-1chimney.com are two different configurations, and landing
     * straight in one of them after clicking a different host in the rail
     * would make it easy to edit the site you were not looking at.
     *
     * The preference under test is the PREVIEW WIDTH, which lives on the tab
     * rather than on the editor, so it has to survive that reset.
     */
    expect(find.text('Preview'), findsNothing);

    await tester.tap(find.text('Live chat'));
    await tester.pumpAndSettle();

    expect(tester.getSize(preview.first).width, resized);
  });

  /// A width that is forgotten on restart is a width somebody sets once and
  /// then stops bothering with.
  testWidgets('a dragged width is saved when the drag settles', (tester) async {
    final saved = <String, Object?>{};

    await pump(
      tester,
      open: 'Live chat',
      permissions: ['web_management.view', 'web_management.edit', 'web_management.seo.view', 'web_management.widgets.view'],
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
      open: 'Live chat',
      permissions: ['web_management.view', 'web_management.edit', 'web_management.seo.view', 'web_management.widgets.view'],
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
      open: 'Live chat',
      permissions: ['web_management.view', 'web_management.edit', 'web_management.seo.view', 'web_management.widgets.view'],
      preferences: const {'web.widgets.preview_width': 9999},
    );

    final preview = find.ancestor(
      of: find.text('Preview'),
      matching: find.byType(SizedBox),
    );

    expect(tester.getSize(preview.first).width, 640.0);
  });

  testWidgets('view alone can look and cannot change', (tester) async {
    await pump(
      tester,
      open: 'Live chat', permissions: ['web_management.view', 'web_management.seo.view', 'web_management.widgets.view']);

    expect(find.widgetWithText(TextField, 'Talk to A-1'), findsOneWidget);
    expect(find.text('Save'), findsNothing);
    expect(find.text('Add a question'), findsNothing);

    final field = tester.widget<TextField>(
      find.widgetWithText(TextField, 'Talk to A-1'),
    );

    expect(field.enabled, isFalse);
  });

  /// A coarse check that the questions are wired up. The GUARD is the focus
  /// test at the bottom of this file, which was written last and is the only
  /// one of five attempts that fails against the bug.
  ///
  /// What Jose photographed: typing "First name" left "Fi" in the box and the
  /// rest somewhere else. The row was a TextFormField whose KEY contained its
  /// own text, so every keystroke wrote back, the parent rebuilt, the key was
  /// now different, and Flutter threw the field away and built a new one —
  /// losing focus once per character.
  ///
  /// I tried to reproduce that by typing character by character and it PASSED
  /// against the broken shape: `enterText` writes to whichever editable the
  /// harness finds, so it never experiences the focus loss a keyboard does.
  /// Both behavioural tests below pass against the bug, which makes them
  /// descriptions rather than guards, and they are kept only as coarse checks
  /// that the box is wired up at all.
  ///
  /// FOUR ATTEMPTS COULD NOT FAIL AGAINST IT, and they are listed because the
  /// next person will reach for one of them: typing character by character
  /// passes, because `enterText` writes to whichever editable the harness finds;
  /// asserting a controller passes, because TextFormField builds a
  /// controller-driven TextField underneath; counting them passes for the same
  /// reason; comparing the element across a keystroke passes too.
  ///
  /// The fifth works, and it is at the bottom of this file. The deploy desk
  /// pointed at `structure_canvas_test`, which already inspects where focus
  /// actually sits — so the question to ask was not what the WIDGET looks like
  /// after a keystroke but where FOCUS is, which is the thing the bug destroys.
  /// Verified by putting the original shape back and watching it fail with
  /// "Focus left the question after one character."
  /// A save used to send each question as label, type and required, and
  /// nothing else — so a select's choices were wiped the first time anybody
  /// saved the form. A1's appointment form has 59 of them.
  testWidgets('a question keeps its choices and settings through a save', (
    tester,
  ) async {
    final sent = <Map<String, dynamic>>[];

    await pump(
      tester,
      open: 'Contact form',
      permissions: ['web_management.view', 'web_management.edit'],
      own: true,
      sent: sent,
      formFields: [
        {
          'label': 'Service Need',
          'type': 'select',
          'required': true,
          'options': ['Chimney Sweep', 'Chimney Repair'],
          'placeholder': 'Pick one',
          'width': 50,
        },
      ],
    );

    await tester.scrollUntilVisible(find.text('Save'), 300, scrollable: _editor);
    await tester.tap(find.text('Save'));
    await tester.pumpAndSettle();

    final fields = ((sent.single['config'] as Map)['form'] as Map)['fields'] as List;
    final service = fields.single as Map;

    expect(service['options'], ['Chimney Sweep', 'Chimney Repair']);
    expect(service['placeholder'], 'Pick one');
    expect(service['width'], 50);
    expect(service['required'], isTrue);
  });

  testWidgets('a question is driven by its own controller', (tester) async {
    await pump(
      tester,
      open: 'Contact form', permissions: ['web_management.view', 'web_management.edit', 'web_management.seo.view', 'web_management.widgets.view']);

    final questions = find.byWidgetPredicate(
      (w) => w is TextField && w.decoration?.labelText == 'Question',
    );

    expect(questions, findsWidgets);

    for (final element in questions.evaluate()) {
      expect(
        (element.widget as TextField).controller,
        isNotNull,
        reason: 'A question rebuilt from initialValue loses text on rebuild.',
      );
    }

    // Both questions from the fixture are on screen and wired up. This is the
    // whole claim — see the docblock for why it is not more.
    expect(questions.evaluate().length, 2);

    final box = find.widgetWithText(TextField, 'Question').first;

    await tester.tap(box);
    await tester.pumpAndSettle();

    // Emptied first: the fixture's question already says something, and
    // appending to it would have been a test of my own arithmetic.
    await tester.enterText(box, '');
    await tester.pumpAndSettle();

    for (final letter in 'First name'.split('')) {
      await tester.enterText(box, _typed(tester, box) + letter);
      await tester.pumpAndSettle();
    }

    expect(
      _typed(tester, box),
      'First name',
      reason: 'Characters went somewhere other than the box being typed into.',
    );
  });

  /// The bug the old key was PUT there to fix, which the fix must not bring
  /// back: remove the first question and the second must not inherit its text.
  testWidgets('removing a question does not move its text into another', (
    tester,
  ) async {
    await pump(
      tester,
      open: 'Contact form', permissions: ['web_management.view', 'web_management.edit', 'web_management.seo.view', 'web_management.widgets.view']);

    final before = find
        .byWidgetPredicate((w) => w is TextField && w.decoration?.labelText == 'Question')
        .evaluate()
        .map((e) => (e.widget as TextField).controller?.text)
        .toList();

    expect(before.length, greaterThan(1), reason: 'The fixture needs two questions.');

    await tester.tap(find.byTooltip('Remove').first);
    await tester.pumpAndSettle();

    final after = find
        .byWidgetPredicate((w) => w is TextField && w.decoration?.labelText == 'Question')
        .evaluate()
        .map((e) => (e.widget as TextField).controller?.text)
        .toList();

    expect(after.length, before.length - 1);
    expect(
      after.first,
      before[1],
      reason: 'The surviving row wears the text of the row that was removed.',
    );
  });

  /// THE FIFTH ATTEMPT, on the deploy desk's suggestion: assert the FOCUS NODE
  /// across a keystroke rather than the element.
  ///
  /// The bug loses focus once per character, so this asks the question the
  /// other four asked sideways — after typing into the box, is focus still in
  /// the box? The helper shape is the one `structure_canvas_test` already uses
  /// for tab order.
  testWidgets('focus stays in the question being typed into', (tester) async {
    await pump(
      tester,
      open: 'Contact form', permissions: ['web_management.view', 'web_management.edit', 'web_management.seo.view', 'web_management.widgets.view']);

    final box = find.byWidgetPredicate(
      (w) => w is TextField && w.decoration?.labelText == 'Question',
    ).first;

    await tester.tap(box);
    await tester.pumpAndSettle();

    expect(_focusIsIn(tester, box), isTrue, reason: 'Tapping did not focus it.');

    await tester.enterText(box, 'F');
    await tester.pumpAndSettle();

    expect(
      _focusIsIn(tester, box),
      isTrue,
      reason: 'Focus left the question after one character.',
    );
  });
}

/// The editor pane's own scrollable.
///
/// Named by the pane's key rather than by a heading inside it. The anchor used
/// to be `find.text('Live chat')`, which worked only while the form was short
/// enough that nothing had to scroll: a heading at the top of a ListView is
/// disposed once the list moves past it, so the finder driving the scroll
/// vanishes part-way through the scroll it is driving. `.last` is no better —
/// this screen has three scrollables and which one is last is paint order
/// rather than anything a test knows.
/// `.first` because every TextField carries a Scrollable of its own for the
/// text inside it, and the pane's own is the outermost of them.
final Finder _editor = find
    .descendant(
      of: find.byKey(const ValueKey('widgets-editor')),
      matching: find.byType(Scrollable),
    )
    .first;

/// Scroll down until one widget's presets are built.
///
/// The whole Rendered widgets section is a single child of the editor's
/// ListView, so reaching any part of it builds all four cards — but a ListView
/// builds nothing it has not scrolled near, and a finder cannot match what was
/// never built.
Future<void> _reachPresets(WidgetTester tester, String widget) =>
    tester.scrollUntilVisible(
      find.byKey(ValueKey('preset:$widget:default')),
      300,
      scrollable: _editor,
    );

/// The Add beside one widget's new-preset box.
///
/// Scoped to its own row: there is one of these per widget, so a bare
/// `find.text('Add')` matches four buttons and taps whichever came first.
Finder _addPreset(String widget) => find.descendant(
      of: find
          .ancestor(
            of: find.byKey(ValueKey('preset-add:$widget')),
            matching: find.byType(Row),
          )
          .first,
      matching: find.text('Add'),
    );

/// What the box actually contains, read from its controller rather than from
/// the widget tree's text — a stale render would otherwise pass.
String _typed(WidgetTester tester, Finder box) =>
    tester.widget<TextField>(box).controller?.text ?? '';

/// Whether focus currently sits inside the widget this finder names.
///
/// Borrowed in shape from `structure_canvas_test`, which uses it for tab order.
/// The node is private to the framework's own field, so what is asserted is
/// that focus is somewhere INSIDE the control — which is the whole of what a
/// person typing needs to be true.
bool _focusIsIn(WidgetTester tester, Finder finder) {
  final focused = FocusManager.instance.primaryFocus?.context;
  final targets = finder.evaluate().toSet();

  if (focused == null || targets.isEmpty) return false;

  var inside = false;

  focused.visitAncestorElements((element) {
    if (!targets.contains(element)) return true;

    inside = true;

    return false;
  });

  return inside;
}
