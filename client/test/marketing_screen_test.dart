import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/main.dart';
import 'package:ezbizi_client/screens/marketing_screen.dart';
import 'package:ezbizi_client/widgets/dashboard_card.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Marketing — Newsletter is declared and not built; Social Media Management
/// has its first screen.
///
/// ── What these are about ─────────────────────────────────────────
///
/// A placeholder is the easiest thing in the app to ship broken, because
/// "broken" and "not built yet" look identical from the outside: a screen with
/// nothing on it. So the assertions below are mostly about SENTENCES — that
/// somebody who opens Newsletter learns what it will be, learns what has to
/// exist before it can run, and is never handed a blank page for any reason,
/// including a request that failed.
///
/// The second half is about the automation surface, and it is the part with a
/// wrong answer worth catching. Marketing declared its events at the moment it
/// declared itself, none of them fire, and a screen that listed them without
/// saying so would be worse than one that hid them: somebody would go to the
/// automation builder, write a rule on "Newsletter sent", switch it on, and
/// wait forever.
void main() {
  group('the tab says what it is rather than showing nothing', () {
    testWidgets('the sub-module is named, and named as unbuilt', (
      tester,
    ) async {
      await _pumpScreen(tester, _handler());

      // The strip, so somebody told "the newsletter is under Marketing" can
      // see they have arrived.
      expect(find.widgetWithText(ChoiceChip, 'Newsletter'), findsOneWidget);
      expect(
        find.widgetWithText(ChoiceChip, 'Social Media Management'),
        findsOneWidget,
      );

      // And the state, in the same words the HR dashboard's unbuilt panel uses.
      expect(find.text('Not built yet'), findsOneWidget);
    });

    testWidgets('Social Media Management is a second sub-tab, not a blank page', (
      tester,
    ) async {
      await _pumpScreen(tester, _handler());

      await tester.tap(find.widgetWithText(ChoiceChip, 'Social Media Management'));
      await tester.pumpAndSettle();

      expect(find.widgetWithText(ChoiceChip, 'Posts'), findsOneWidget);
      expect(find.widgetWithText(ChoiceChip, 'Accounts'), findsOneWidget);
      expect(find.text('Pages'), findsOneWidget);
      expect(find.text('Compose'), findsOneWidget);
      expect(find.text('Weekly Calendar'), findsOneWidget);
      expect(find.text('Monthly Calendar'), findsOneWidget);
      expect(find.text('Publish now'), findsOneWidget);
      expect(find.text('Not built yet'), findsNothing);
    });

    testWidgets('the month calendar can be moved back and forth', (
      tester,
    ) async {
      await _pumpScreen(tester, _handler());

      await tester.tap(find.widgetWithText(ChoiceChip, 'Social Media Management'));
      await tester.pumpAndSettle();

      final now = DateTime.now();
      final thisMonth = _monthTitle(now);
      final nextMonth = _monthTitle(DateTime(now.year, now.month + 1, 1));
      final previousMonth = _monthTitle(DateTime(now.year, now.month - 1, 1));

      expect(find.text(thisMonth), findsOneWidget);

      await tester.tap(find.byTooltip('Next month'));
      await tester.pumpAndSettle();
      expect(find.text(nextMonth), findsOneWidget);
      expect(find.text(thisMonth), findsNothing);

      await tester.tap(find.byTooltip('Previous month'));
      await tester.pumpAndSettle();
      await tester.tap(find.byTooltip('Previous month'));
      await tester.pumpAndSettle();
      expect(find.text(previousMonth), findsOneWidget);
    });

    testWidgets('the week calendar stays on this week', (tester) async {
      await _pumpScreen(tester, _handler());

      await tester.tap(find.widgetWithText(ChoiceChip, 'Social Media Management'));
      await tester.pumpAndSettle();

      expect(find.byTooltip('Previous week'), findsNothing);
      expect(find.byTooltip('Next week'), findsNothing);
      expect(find.text(_weekRange(_mondayOf(DateTime.now()))), findsOneWidget);

      await tester.tap(find.byTooltip('Next month'));
      await tester.pumpAndSettle();
      expect(find.text(_weekRange(_mondayOf(DateTime.now()))), findsOneWidget);
    });

    testWidgets('Publish now is disabled until this view has a page', (
      tester,
    ) async {
      await _pumpScreen(tester, _handler());

      await tester.tap(find.widgetWithText(ChoiceChip, 'Social Media Management'));
      await tester.pumpAndSettle();

      final publish = tester.widget<FilledButton>(
        find.widgetWithText(FilledButton, 'Publish now'),
      );
      expect(publish.onPressed, isNull);
      expect(
        find.textContaining('nowhere to publish'),
        findsOneWidget,
      );
    });

    testWidgets('Accounts lists the catalogue without hiding unreadiness', (
      tester,
    ) async {
      await _pumpScreen(tester, _handler());

      await tester.tap(find.widgetWithText(ChoiceChip, 'Social Media Management'));
      await tester.pumpAndSettle();
      await tester.tap(find.widgetWithText(ChoiceChip, 'Accounts'));
      await tester.pumpAndSettle();

      expect(find.text('Pinterest'), findsOneWidget);
      expect(find.text('Facebook Page'), findsOneWidget);
      expect(find.textContaining('no X app'), findsOneWidget);
      expect(find.textContaining('EzBizi has no Pinterest'), findsOneWidget);
    });

    testWidgets('Pinterest Connect stays off until the installation is ready', (
      tester,
    ) async {
      await _pumpScreen(tester, _handler());

      await tester.tap(find.widgetWithText(ChoiceChip, 'Social Media Management'));
      await tester.pumpAndSettle();
      await tester.tap(find.widgetWithText(ChoiceChip, 'Accounts'));
      await tester.pumpAndSettle();
      await tester.tap(find.widgetWithText(FilledButton, 'Connect a page to Everdoors'));
      await tester.pumpAndSettle();

      final connect = tester.widget<FilledButton>(
        find.descendant(
          of: find.widgetWithText(ListTile, 'Pinterest'),
          matching: find.widgetWithText(FilledButton, 'Connect'),
        ),
      );
      expect(connect.onPressed, isNull);
    });

    testWidgets('Pinterest Connect is enabled when the catalogue says it is usable', (
      tester,
    ) async {
      await _pumpScreen(tester, _handler(pinterestUsable: true));

      await tester.tap(find.widgetWithText(ChoiceChip, 'Social Media Management'));
      await tester.pumpAndSettle();
      await tester.tap(find.widgetWithText(ChoiceChip, 'Accounts'));
      await tester.pumpAndSettle();
      await tester.tap(find.widgetWithText(FilledButton, 'Connect a page to Everdoors'));
      await tester.pumpAndSettle();

      final connect = tester.widget<FilledButton>(
        find.descendant(
          of: find.widgetWithText(ListTile, 'Pinterest'),
          matching: find.widgetWithText(FilledButton, 'Connect'),
        ),
      );
      expect(connect.onPressed, isNotNull);
    });

    testWidgets('Posts lays out on a 375-point window without overflowing', (
      tester,
    ) async {
      await _pumpScreen(tester, _handler(), size: const Size(375, 2400));

      await tester.ensureVisible(
        find.widgetWithText(ChoiceChip, 'Social Media Management'),
      );
      await tester.tap(find.widgetWithText(ChoiceChip, 'Social Media Management'));
      await tester.pumpAndSettle();

      expect(tester.takeException(), isNull);
      expect(find.text('Compose'), findsOneWidget);
      expect(find.text('Weekly Calendar'), findsOneWidget);
      expect(find.text('Monthly Calendar'), findsOneWidget);
    });

    testWidgets('it says what Newsletter will be', (tester) async {
      await _pumpTab(tester, _handler());

      expect(
        find.textContaining('writes to its own customers'),
        findsOneWidget,
        reason: 'A badge with no sentence beside it says only that something '
            'is missing, not what.',
      );

      // The owner's actual requirement: the mail leaves from the company's
      // address, not the platform's.
      expect(find.textContaining('own address'), findsOneWidget);
    });

    testWidgets('it names what has to exist before it can run', (tester) async {
      await _pumpTab(tester, _handler());

      expect(find.text('Before it can run'), findsOneWidget);

      // Both, and each says WHY rather than just naming a gap.
      expect(find.text('Somebody to send to'), findsOneWidget);
      expect(find.textContaining('agreed to receive marketing mail'),
          findsOneWidget);

      expect(find.text('Something written to send'), findsOneWidget);
      expect(find.textContaining('no record of what went out'), findsOneWidget);
    });
  });

  group('the automation surface it has already declared', () {
    testWidgets('the declared events are listed in the module’s own words', (
      tester,
    ) async {
      await _pumpTab(tester, _handler());

      expect(find.text('Already in the automation builder'), findsOneWidget);

      expect(find.text('Newsletter sent'), findsOneWidget);
      expect(find.text('Somebody unsubscribed'), findsOneWidget);

      // The description too, not just the name. The label says what it is
      // called; only this says when it fires.
      expect(find.textContaining('went out to its audience'), findsOneWidget);
    });

    testWidgets('every event nothing raises yet is marked as such', (
      tester,
    ) async {
      await _pumpTab(tester, _handler());

      expect(
        find.text('Does not fire yet'),
        findsNWidgets(2),
        reason: 'An unmarked trigger is one somebody builds a rule on and '
            'never hears from again.',
      );
    });

    /// The control for the test above. A mark drawn unconditionally would pass
    /// it while telling a permanent lie about an event that had started firing.
    testWidgets('an event that has started firing loses its mark', (
      tester,
    ) async {
      await _pumpTab(tester, _handler(sentIsRaised: true));

      expect(find.text('Newsletter sent'), findsOneWidget);
      expect(
        find.text('Does not fire yet'),
        findsOneWidget,
        reason: 'The mark comes from the server’s flag, so the one event that '
            'now fires must lose it and the other must keep it.',
      );
    });

    testWidgets('what a rule will be able to do is listed, unmarked', (
      tester,
    ) async {
      await _pumpTab(tester, _handler());

      expect(find.text('And what a rule will be able to do here'),
          findsOneWidget);
      expect(find.text('Send a newsletter'), findsOneWidget);
      expect(find.text('Add somebody to a mailing list'), findsOneWidget);

      // Two triggers carry the mark and neither action does — an action under
      // an event that never fires cannot run, and saying it twice is how two
      // answers start disagreeing.
      expect(find.text('Does not fire yet'), findsNWidgets(2));
    });

    /// The control against a card that is right by being empty: a server that
    /// sent nothing would satisfy every "findsNothing" in this file.
    testWidgets('a build that declares nothing says so rather than drawing an '
        'empty card', (tester) async {
      await _pumpTab(tester, _handler(triggers: const [], actions: const []));

      expect(find.text('Newsletter sent'), findsNothing);
      expect(find.textContaining('nothing here for a rule to be built on'),
          findsOneWidget);
    });
  });

  /// The coupling that has silently broken before.
  ///
  /// The sidebar is the intersection of what the server declares with what this
  /// bundle has a screen for, and a module missing from `moduleScreens` is a
  /// tab that quietly does not appear — which is how Dashboard vanished for
  /// everybody, Administrator included, the first time that list came off the
  /// server. `ClientModuleScreensTest` catches the mistake in one direction
  /// only: a screen with no module. This is the other direction, for this tab.
  group('the tab is actually reachable from the sidebar', () {
    testWidgets('a role granted Marketing gets the destination, and it opens '
        'on the placeholder', (tester) async {
      tester.view.physicalSize = const Size(1280, 900);
      tester.view.devicePixelRatio = 1.0;
      addTearDown(tester.view.reset);

      final api = ApiClient(client: MockClient(_handler()));
      final session = Session(api);

      await tester.runAsync(() => session.adopt('tok'));

      await tester.pumpWidget(
        MaterialApp(
          theme: EzBiziTheme.light(),
          home: ListenableBuilder(
            listenable: session,
            builder: (_, _) => HomeShell(api: api, session: session),
          ),
        ),
      );
      await tester.pumpAndSettle();

      final inSidebar = find.descendant(
        of: find.byType(NavigationSidebar),
        matching: find.text('Marketing'),
      );

      expect(
        inSidebar,
        findsOneWidget,
        reason: 'Marketing has no entry in moduleScreens, so the tab the '
            'server declares is drawn nowhere and the screen is unreachable.',
      );

      await tester.tap(inSidebar);
      await tester.pumpAndSettle();

      // Landed on the sub-module, in its unbuilt state — not on a blank body.
      expect(find.text('Not built yet'), findsOneWidget);
      expect(find.text('Before it can run'), findsOneWidget);
    });
  });

  group('nothing is ever a blank page', () {
    testWidgets('a refusal is the server’s sentence and a way back', (
      tester,
    ) async {
      await _pumpTab(
        tester,
        _handler(
          status: 403,
          message: 'You do not have permission to open Marketing. That is part '
              'of a role rather than of an account, so somebody who can edit '
              'roles can add it to yours.',
        ),
      );

      expect(find.textContaining('permission to open Marketing'), findsOneWidget);
      expect(find.text('Try again'), findsOneWidget);

      // R8 travels: the sentence names the remedy and never a permission key.
      expect(find.textContaining('marketing.view'), findsNothing);
    });

    /// The other end of R7, and a claim that was false when it was first
    /// written.
    ///
    /// A ListView hands its children a TIGHT cross-axis width, and
    /// `BoxConstraints.enforce` clamps an additional max into the parent's
    /// range — so `ConstrainedBox(maxWidth: 760)` inside a 1352-point list
    /// resolves to 1352 and caps nothing at all. The comment said the cards
    /// stopped growing; they did not. It is an Align now, and this is what
    /// holds it: a cap nothing measures is a cap that quietly stops working.
    testWidgets('on a wide window the prose stops at a readable width', (
      tester,
    ) async {
      await _pumpTab(tester, _handler(), size: const Size(1600, 1600));

      expect(
        tester.getRect(find.text('Before it can run')).left,
        lessThan(200),
        reason: 'The content is centred rather than sitting against the '
            'margin every other tab uses.',
      );

      expect(
        tester.getSize(find.byType(DashboardCard).first).width,
        lessThanOrEqualTo(760),
        reason: 'The card grew with the window, so the paragraphs inside it '
            'are one very long line each.',
      );
    });

    /// R7. A phone is the narrow case this whole screen is prose, and prose
    /// beside a badge is what overflows.
    testWidgets('it lays out on a 375-point window without overflowing', (
      tester,
    ) async {
      await _pumpTab(tester, _handler(), size: const Size(375, 2400));

      expect(
        tester.takeException(),
        isNull,
        reason: 'The screen overflowed at 375 points.',
      );

      // Not merely "no exception" — a screen that rendered nothing also throws
      // nothing. The content is there, and it is inside the window.
      expect(find.text('Not built yet'), findsOneWidget);
      expect(find.text('Newsletter sent'), findsOneWidget);

      for (final label in ['Newsletter sent', 'Somebody unsubscribed']) {
        expect(
          tester.getRect(find.text(label)).right,
          lessThanOrEqualTo(375),
          reason: '“$label” is laid out past the right edge of the window.',
        );
      }
    });
  });
}

// ── driving the screen ─────────────────────────────────────────────

/// The whole destination, tab strip and all.
Future<void> _pumpScreen(
  WidgetTester tester,
  MockClientHandler handler, {
  Size size = const Size(1400, 1600),
}) =>
    _pump(tester, handler, size, (api, session) =>
        MarketingScreen(api: api, session: session));

/// Just the sub-tab, which is where everything under test lives.
Future<void> _pumpTab(
  WidgetTester tester,
  MockClientHandler handler, {
  Size size = const Size(1400, 1600),
}) =>
    _pump(tester, handler, size, (api, session) =>
        NewsletterTab(api: api, session: session));

Future<void> _pump(
  WidgetTester tester,
  MockClientHandler handler,
  Size size,
  Widget Function(ApiClient, Session) build,
) async {
  tester.view.physicalSize = size;
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(client: MockClient(handler));
  final session = Session(api);

  await tester.runAsync(() => session.adopt('tok'));

  expect(session.signedIn, isTrue, reason: 'The /me stub did not sign in.');

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(body: build(api, session)),
    ),
  );
  await tester.pumpAndSettle();
}

/// The server, carrying the surface the module really declares.
///
/// The wording is copied from ModuleServiceProvider deliberately and only here:
/// a fixture is where a hand-typed payload belongs, and `MarketingModuleTest`
/// is what holds the real endpoint to the real registry. What must NOT happen
/// is the client keeping its own copy, which is why nothing under `lib/` names
/// a trigger.
MockClientHandler _handler({
  int status = 200,
  String message = 'Marketing is unavailable.',
  bool sentIsRaised = false,
  bool pinterestUsable = false,
  List<Map<String, Object?>>? triggers,
  List<Map<String, Object?>>? actions,
}) {
  final surface = {
    'module': {'key': 'marketing', 'label': 'Marketing'},
    'triggers': triggers ??
        [
          {
            'key': 'newsletter.sent',
            'label': 'Newsletter sent',
            'description':
                'A newsletter went out to its audience. It carries which one, '
                'how many addresses it went to and the identity it was sent '
                'from — not whether anybody read it.',
            'raised': sentIsRaised,
          },
          {
            'key': 'newsletter.unsubscribed',
            'label': 'Somebody unsubscribed',
            'description':
                'A recipient asked to stop receiving marketing mail. Nothing '
                'raises this yet, so a rule built on it will not run.',
            'raised': false,
          },
        ],
    'actions': actions ??
        [
          {
            'key': 'newsletter.send',
            'label': 'Send a newsletter',
            'description':
                'Sends a mailing that has already been written and approved, '
                'to the audience it was written for.',
          },
          {
            'key': 'newsletter.add_to_audience',
            'label': 'Add somebody to a mailing list',
            'description':
                'Puts the person a rule fired about onto a named mailing list.',
          },
        ],
  };

  return (request) async {
    final path = request.url.path;

    if (path.endsWith('/me')) return _json({'data': _me});

    if (path.endsWith('/marketing/newsletter')) {
      return status == 200
          ? _json({'data': surface})
          : _json({'message': message}, status);
    }

    if (path.endsWith('/social/providers')) {
      return _json({
        'data': [
          {
            'value': 'facebook_page',
            'label': 'Facebook Page',
            'usable': false,
            'unusable_because':
                'EzBizi has no Meta app set up on this installation yet.',
            'connects_by': 'sign_in',
            'start_path': '/social/facebook/start',
          },
          {
            'value': 'pinterest',
            'label': 'Pinterest',
            'usable': pinterestUsable,
            'unusable_because': pinterestUsable
                ? null
                : 'EzBizi has no Pinterest connection set up on this '
                    'installation yet.',
            'connects_by': 'sign_in',
            'start_path': '/social/pinterest/start',
          },
          {
            'value': 'x',
            'label': 'X',
            'usable': false,
            'unusable_because':
                'EzBizi has no X app set up on this installation yet.',
            'connects_by': 'sign_in',
            'start_path': '/social/x/start',
          },
        ],
      });
    }

    if (path.endsWith('/social/connections')) {
      return _json({'data': <Map<String, Object?>>[]});
    }

    return _json({'message': 'Unexpected ${request.method} $path'}, 500);
  };
}

http.Response _json(Object body, [int status = 200]) => http.Response(
      jsonEncode(body),
      status,
      headers: {'content-type': 'application/json'},
    );

const _me = {
  'id': 'u-1',
  'name': 'Jose Silva',
  'email': 'jose@example.com',
  'has_avatar': false,
  'role': {'id': 'role-1', 'name': 'Administrator'},
  'permissions': ['marketing.view'],
  'modules': [
    {
      'key': 'marketing',
      'label': 'Marketing',
      'available': true,
      'reachable': true,
    },
  ],
  'preferences': <String, dynamic>{},
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
  'companies': <Map<String, Object?>>[],
  'franchises': <Map<String, Object?>>[],
};

const _monthNames = [
  'January',
  'February',
  'March',
  'April',
  'May',
  'June',
  'July',
  'August',
  'September',
  'October',
  'November',
  'December',
];

const _shortMonths = [
  'Jan',
  'Feb',
  'Mar',
  'Apr',
  'May',
  'Jun',
  'Jul',
  'Aug',
  'Sep',
  'Oct',
  'Nov',
  'Dec',
];

String _monthTitle(DateTime d) => '${_monthNames[d.month - 1]} ${d.year}';

DateTime _mondayOf(DateTime d) {
  final day = DateTime(d.year, d.month, d.day);
  return day.subtract(Duration(days: day.weekday - DateTime.monday));
}

String _weekRange(DateTime monday) {
  final sunday = monday.add(const Duration(days: 7 - 1));
  if (monday.month == sunday.month) {
    return '${monday.day}–${sunday.day} ${_shortMonths[monday.month - 1]}';
  }
  return '${monday.day} ${_shortMonths[monday.month - 1]} – '
      '${sunday.day} ${_shortMonths[sunday.month - 1]}';
}
