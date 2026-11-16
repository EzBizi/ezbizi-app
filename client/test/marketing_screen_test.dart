import 'dart:convert';
import 'dart:typed_data';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/files.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/main.dart';
import 'package:ezbizi_client/screens/email/message_html.dart';
import 'package:ezbizi_client/screens/marketing/newsletter_blocks.dart';
import 'package:ezbizi_client/screens/marketing/newsletter_import_panel.dart';
import 'package:ezbizi_client/screens/marketing/newsletter_tab.dart';
import 'package:ezbizi_client/screens/marketing/social_media_tab.dart';
import 'package:ezbizi_client/screens/marketing_screen.dart';
import 'package:ezbizi_client/widgets/calendar_day_field.dart';
import 'package:ezbizi_client/widgets/dashboard_card.dart';
import 'package:ezbizi_client/widgets/month_calendar.dart';
import 'package:ezbizi_client/widgets/refused.dart';
import 'package:ezbizi_client/widgets/side_panel.dart';
import 'package:ezbizi_client/widgets/social_network_mark.dart';
import 'package:ezbizi_client/widgets/status_pill.dart';
import 'package:flutter/material.dart';
import 'package:flutter/gestures.dart';
import 'package:flutter/rendering.dart';
import 'package:flutter_svg/flutter_svg.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:video_player/video_player.dart';

/// Marketing — Newsletter and Social Media Management, both built.
///
/// ── What these are about ─────────────────────────────────────────
///
/// Most of these assert SENTENCES rather than pixels: which refusal a disabled
/// control carries, and whether it is the server's words or the screen's. §4 —
/// a control that is offered must be a control that works, and when it cannot,
/// it has to say which of several reasons applies.
///
/// This file described a Newsletter placeholder until 2026-09-04. The
/// placeholder's tests went with it rather than being adapted: they asserted
/// that the screen said it was NOT BUILT, which is now the opposite of the
/// truth, and a test kept alive past the thing it describes is how a suite
/// starts defending the wrong behaviour.
void main() {
  _jose1116Tests();

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
    });

    /*
     * Each chip follows ITS OWN grant, and this is asserted in both
     * directions on purpose.
     *
     * Newsletter is a module with `newsletter.view`; Social Media Management
     * is Marketing's own pane behind `marketing.view`. They used to be drawn
     * together inside one `reaches('marketing')`, which failed both ways: a
     * role with only `marketing.view` was offered a Newsletter chip whose
     * every request the server then refused, and a role with only
     * `newsletter.view` got no chip at all, because the module loop only draws
     * modules that have a `moduleScreens` entry and Newsletter has none.
     *
     * The second case is the one a single-direction test misses. A suite that
     * only checked "marketing alone does not show Newsletter" would pass for a
     * screen that had stopped drawing Newsletter entirely.
     *
     * Varying `reachable` and not `permissions`: `Me.reaches` reads the flag
     * the server computed from the grant, never the permission list. The first
     * version of these two varied `permissions` and moved nothing on screen.
     */
    testWidgets('a chip is drawn for the grant it belongs to, and only that one', (
      tester,
    ) async {
      await _pumpScreen(tester, _handler(reachable: const ['marketing']));

      expect(find.widgetWithText(ChoiceChip, 'Newsletter'), findsNothing);
      expect(
        find.widgetWithText(ChoiceChip, 'Social Media Management'),
        findsOneWidget,
      );
    });

    testWidgets('the newsletter grant alone still reaches the Newsletter tab', (
      tester,
    ) async {
      await _pumpScreen(tester, _handler(reachable: const ['newsletter']));

      expect(find.widgetWithText(ChoiceChip, 'Newsletter'), findsOneWidget);
      expect(
        find.widgetWithText(ChoiceChip, 'Social Media Management'),
        findsNothing,
      );
    });

    testWidgets('Social Media Management is a second sub-tab, not a blank page', (
      tester,
    ) async {
      await _pumpScreen(tester, _handler());

      await tester.tap(find.widgetWithText(ChoiceChip, 'Social Media Management'));
      await tester.pumpAndSettle();

      // UI queue 76 (Jose's pick 2): one row of tabs, opening on Dashboard,
      // where the Accounts card says what is connected.
      expect(find.widgetWithText(ChoiceChip, 'Posts'), findsNothing);
      expect(find.widgetWithText(ChoiceChip, 'Dashboard'), findsOneWidget);
      expect(find.widgetWithText(ChoiceChip, 'Accounts'), findsOneWidget);
      expect(find.text('No pages connected in Everdoors yet'), findsOneWidget);
      expect(find.widgetWithText(TextButton, 'Connect'), findsOneWidget);
      expect(find.widgetWithText(ChoiceChip, 'Compose'), findsOneWidget);
      expect(find.text('Publish now'), findsNothing);

      await _openCompose(tester);
      expect(find.text('How it will look'), findsOneWidget);
      expect(find.text('Publish now'), findsOneWidget);
      expect(find.text('Weekly Calendar'), findsNothing);

      await _openCalendar(tester);
      expect(find.text('Weekly Calendar'), findsOneWidget);
      expect(find.text('Monthly Calendar'), findsOneWidget);
    });

    testWidgets('the month calendar can be moved back and forth', (
      tester,
    ) async {
      await _pumpScreen(tester, _handler());

      await tester.tap(find.widgetWithText(ChoiceChip, 'Social Media Management'));
      await tester.pumpAndSettle();
      await _openCalendar(tester);

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
      await _openCalendar(tester);

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
      // `canEdit` so the reason under test is the one this names. Without it
      // the first refusal is the missing permission, which is a different test.
      await _pumpScreen(tester, _handler(canEdit: true));

      await tester.tap(find.widgetWithText(ChoiceChip, 'Social Media Management'));
      await tester.pumpAndSettle();
      await _openCompose(tester);

      final publish = tester.widget<FilledButton>(
        find.widgetWithText(FilledButton, 'Publish now'),
      );
      expect(publish.onPressed, isNull);

      // The sentence is on the Refused wrapper rather than printed under the
      // button. It used to be both, and the duplicate went when compose became
      // real: a screen that repeats a refusal in two places is the drift
      // Refused's docblock is about.
      expect(_publishRefusal(tester), contains('nowhere to publish'));
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
      expect(find.text('Facebook'), findsOneWidget);
      expect(find.text('Facebook Page'), findsNothing);
      expect(find.byType(SocialNetworkMark), findsWidgets);
      expect(find.byType(SvgPicture), findsWidgets);
      expect(find.textContaining('no X app'), findsOneWidget);
      expect(find.textContaining('EzBizi has no Pinterest'), findsOneWidget);
    });

    testWidgets('a network with a page connected is left out of the '
        'Catalogue, because the account above already answers for it', (
      tester,
    ) async {
      await _pumpScreen(tester, _handler(connections: const [_facebookPage]));

      await tester.tap(find.widgetWithText(ChoiceChip, 'Social Media Management'));
      await tester.pumpAndSettle();
      await tester.tap(find.widgetWithText(ChoiceChip, 'Accounts'));
      await tester.pumpAndSettle();

      // The page itself, in the card that owns it.
      expect(
        find.descendant(
          of: find.widgetWithText(DashboardCard, 'Accounts in Everdoors'),
          matching: find.text('Everdoors Doors'),
        ),
        findsOneWidget,
      );
      expect(find.text('Facebook · @everdoors'), findsOneWidget);

      // And the catalogue does not repeat the network underneath it.
      expect(
        find.descendant(
          of: find.widgetWithText(DashboardCard, 'Catalogue'),
          matching: find.text('Facebook'),
        ),
        findsNothing,
      );
      expect(
        find.textContaining('EzBizi has no Meta app'),
        findsNothing,
        reason: 'A network with a page connected cannot also be a network '
            'nothing can be connected on, and the catalogue was saying both '
            'about the same one.',
      );
    });

    testWidgets('a TikTok account shows its own avatar on both cards; a '
        'provider with none keeps its network mark', (tester) async {
      const avatar = 'https://p16.tiktokcdn.test/ezbizi.jpeg?x-expires=1';
      await _pumpScreen(
        tester,
        _handler(
          connections: const [
            {..._tiktokDirect, 'avatar_url': avatar},
            _facebookPage,
          ],
        ),
      );

      String? urlOf(Finder card) {
        final images = find.descendant(
          of: card,
          matching: find.byKey(const ValueKey('social-account-avatar')),
        );
        if (images.evaluate().isEmpty) return null;
        return (tester.widget<Image>(images.first).image as NetworkImage).url;
      }

      await tester.tap(find.widgetWithText(ChoiceChip, 'Social Media Management'));
      await tester.pumpAndSettle();
      expect(
        urlOf(find.byKey(const ValueKey('tour-marketing-social-accounts-card'))),
        avatar,
      );

      await tester.tap(find.widgetWithText(ChoiceChip, 'Accounts'));
      await tester.pumpAndSettle();
      final card = find.widgetWithText(DashboardCard, 'Accounts in Everdoors');
      expect(urlOf(card), avatar);
      expect(
        find.descendant(
          of: card,
          matching: find.byKey(const ValueKey('social-account-avatar')),
        ),
        findsOneWidget,
        reason: 'Facebook sends no avatar_url, so it keeps the network mark.',
      );
      expect(
        find.descendant(of: card, matching: find.byType(SocialNetworkMark)),
        findsWidgets,
      );
    });

    /*
     * The reason a network cannot be connected is shown WHOLE on a phone.
     *
     * UI/UX, Android audit 2026-09-28: the subtitle was cut at two lines
     * beside "Not ready", and the server's sentence puts what to DO at the end
     * — so the half that was lost was exactly the half that helps.
     *
     * Asserted on the painted paragraph, not the widget's text. `find.text`
     * matches the string a Text was GIVEN, which is the full sentence whether
     * or not it was ellipsised on screen, so it passes against the bug. What
     * distinguishes is whether the paragraph ran out of lines.
     */
    testWidgets('a reason not to connect is shown whole on a phone', (tester) async {
      await _pumpScreen(tester, _handler(), size: const Size(375, 1600));

      // At phone width the chip strips scroll sideways, so a chip can be off
      // screen and a bare tap() misses it with only a warning.
      for (final chip in ['Social Media Management', 'Accounts']) {
        final target = find.widgetWithText(ChoiceChip, chip);
        await tester.ensureVisible(target);
        await tester.pumpAndSettle();
        await tester.tap(target);
        await tester.pumpAndSettle();
      }

      final reason = find.descendant(
        of: find.widgetWithText(DashboardCard, 'Catalogue'),
        matching: find.textContaining('no Meta app'),
      );
      expect(reason, findsOneWidget);

      final paragraph = tester.renderObject<RenderParagraph>(
        find.descendant(of: reason, matching: find.byType(RichText)),
      );
      expect(
        paragraph.didExceedMaxLines,
        isFalse,
        reason: 'The reason was cut short again; the end of it says what to do.',
      );
    });

    testWidgets('a network with nothing connected keeps its Catalogue row and '
        'the sentence that goes with it', (tester) async {
      await _pumpScreen(
        tester,
        _handler(pinterestUsable: true, connections: const [_facebookPage]),
      );

      await tester.tap(find.widgetWithText(ChoiceChip, 'Social Media Management'));
      await tester.pumpAndSettle();
      await tester.tap(find.widgetWithText(ChoiceChip, 'Accounts'));
      await tester.pumpAndSettle();

      final catalogue = find.widgetWithText(DashboardCard, 'Catalogue');

      // One that could be connected right now, and says so.
      expect(
        find.descendant(of: catalogue, matching: find.text('Pinterest')),
        findsOneWidget,
      );
      expect(
        find.descendant(of: catalogue, matching: find.text('Ready to connect')),
        findsOneWidget,
      );

      // And one that could not, still carrying the reason rather than
      // disappearing along with the connected network.
      expect(
        find.descendant(of: catalogue, matching: find.text('X')),
        findsOneWidget,
      );
      expect(
        find.descendant(
          of: catalogue,
          matching: find.textContaining('no X app'),
        ),
        findsOneWidget,
      );
    });

    testWidgets('with every network connected the Catalogue says so instead '
        'of drawing an empty list', (tester) async {
      await _pumpScreen(
        tester,
        _handler(
          connections: const [_facebookPage, _pinterestBoard, _xAccount],
        ),
      );

      await tester.tap(find.widgetWithText(ChoiceChip, 'Social Media Management'));
      await tester.pumpAndSettle();
      await tester.tap(find.widgetWithText(ChoiceChip, 'Accounts'));
      await tester.pumpAndSettle();

      final catalogue = find.widgetWithText(DashboardCard, 'Catalogue');

      expect(
        find.textContaining('Every network EzBizi can post to is connected'),
        findsOneWidget,
      );
      expect(
        find.descendant(of: catalogue, matching: find.byType(ListTile)),
        findsNothing,
        reason: 'An empty catalogue is a card that has stopped saying '
            'anything; the sentence is what it says instead.',
      );

      // The three pages are still accounted for, one card up.
      final accounts = find.widgetWithText(
        DashboardCard,
        'Accounts in Everdoors',
      );
      for (final page in [
        'Everdoors Doors',
        'Everdoors Boards',
        'Everdoors HQ',
      ]) {
        expect(
          find.descendant(of: accounts, matching: find.text(page)),
          findsOneWidget,
        );
      }

      // Nothing became unreachable: a second account on any of them is still
      // one Connect away, and the panel lists every network regardless.
      await tester.tap(
        find.widgetWithText(FilledButton, 'Connect a page to Everdoors'),
      );
      await tester.pumpAndSettle();

      for (final network in ['Facebook', 'Pinterest', 'X']) {
        expect(find.widgetWithText(ListTile, network), findsOneWidget);
      }
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

    /// Bluesky is the one network that connects by app password rather than by
    /// opening a sign-in, and its tile was the only one that never asked
    /// whether it was already connected — so an account that WAS connected
    /// still showed an empty form and a Connect button, directly under TikTok
    /// and Pinterest saying "Already connected".
    testWidgets('a connected Bluesky account says so instead of offering the '
        'form again', (tester) async {
      await _pumpScreen(
        tester,
        _handler(bluesky: true, connections: const [_blueskyAccount]),
      );

      await _openConnectPanel(tester);

      expect(find.textContaining('Already connected'), findsOneWidget);
      expect(
        find.widgetWithText(FilledButton, 'Reconnect'),
        findsOneWidget,
        reason: 'The same word the OAuth tiles use for the same situation.',
      );
      expect(
        find.widgetWithText(TextField, 'App password'),
        findsNothing,
        reason: 'An empty form under a connected account reads as disconnected.',
      );
    });

    /// The form is what Reconnect opens, rather than what the tile is.
    testWidgets('Reconnect opens the form for a new app password, and Cancel '
        'puts it away', (tester) async {
      await _pumpScreen(
        tester,
        _handler(bluesky: true, connections: const [_blueskyAccount]),
      );

      await _openConnectPanel(tester);
      await tester.tap(find.widgetWithText(FilledButton, 'Reconnect'));
      await tester.pumpAndSettle();

      expect(find.widgetWithText(TextField, 'Handle'), findsOneWidget);
      expect(find.widgetWithText(TextField, 'App password'), findsOneWidget);
      expect(
        find.widgetWithText(FilledButton, 'Replace'),
        findsOneWidget,
        reason: 'Not Connect — there is a connection, and this replaces it.',
      );

      await tester.tap(find.widgetWithText(TextButton, 'Cancel'));
      await tester.pumpAndSettle();

      expect(find.widgetWithText(TextField, 'App password'), findsNothing);
      expect(find.textContaining('Already connected'), findsOneWidget);
    });

    /// The control: nothing connected, and the form is the tile, as before.
    testWidgets('an unconnected Bluesky is still the form on its own', (
      tester,
    ) async {
      await _pumpScreen(tester, _handler(bluesky: true));

      await _openConnectPanel(tester);

      expect(find.widgetWithText(TextField, 'App password'), findsOneWidget);
      expect(find.widgetWithText(FilledButton, 'Connect'), findsWidgets);
      expect(find.widgetWithText(FilledButton, 'Reconnect'), findsNothing);
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
      await _openCompose(tester);

      expect(tester.takeException(), isNull);
      expect(find.widgetWithText(ChoiceChip, 'Compose'), findsOneWidget);
      expect(find.text('How it will look'), findsOneWidget);

      // A month grid does not fit a phone: the calendar is an agenda there
      // (design 27, Jose's pick 4).
      await _openCalendar(tester);
      expect(tester.takeException(), isNull);
      expect(find.text('Your posts'), findsOneWidget);
      expect(find.text('Monthly Calendar'), findsNothing);
    });

    testWidgets(
      'the month calendar fits a 1400×900 Posts pane without scrolling',
      (tester) async {
        await _pumpScreen(tester, _handler(), size: const Size(1400, 900));

        await tester.tap(
          find.widgetWithText(ChoiceChip, 'Social Media Management'),
        );
        await tester.pumpAndSettle();
        await _openCalendar(tester);

        expect(tester.takeException(), isNull);

        final now = DateTime.now();
        final lastDay = DateTime(now.year, now.month + 1, 0).day;
        final first = find.byKey(const Key('month-day-1'));
        final last = find.byKey(Key('month-day-$lastDay'));

        expect(first, findsOneWidget);
        expect(last, findsOneWidget);

        expect(tester.getRect(first).top, greaterThanOrEqualTo(0));
        expect(tester.getRect(last).bottom, lessThanOrEqualTo(900));

        final card = tester.getRect(
          find.ancestor(
            of: find.text('Monthly Calendar'),
            matching: find.byType(DashboardCard),
          ),
        );
        final grid = tester.getRect(find.byKey(const Key('month-grid')));
        expect(
          grid.left,
          closeTo(card.left + DashboardCard.padding, 2),
          reason: 'The month grid should start at the card’s left padding.',
        );
        expect(
          grid.right,
          closeTo(card.right - DashboardCard.padding, 2),
          reason: 'The month grid should use the full card width, like the week.',
        );
      },
    );

  });

  /// Composing and publishing.
  ///
  /// The rule these are about is §4's: a control that is offered must be a
  /// control that works. Publish has four different reasons it cannot be
  /// pressed — no permission, nothing connected, nothing connected that EzBizi
  /// can post to, nothing ticked — with four different remedies, so one greyed
  /// button and no sentence would leave somebody guessing which they are in.
  group('compose', () {
    testWidgets('Publish says the permission is missing before anything else', (
      tester,
    ) async {
      // The default role here holds marketing.view and nothing else.
      await _pumpScreen(tester, _handler(connections: const [_pinterestBoard]));
      await _openPosts(tester);
      await _openCompose(tester);

      expect(
        _publishRefusal(tester),
        contains('do not have permission to publish to a social account'),
      );
    });

    testWidgets('a network with no publisher is ticked off, with its reason', (
      tester,
    ) async {
      await _pumpScreen(
        tester,
        _handler(canEdit: true, connections: const [_facebookPage]),
      );
      await _openPosts(tester);
      await _openCompose(tester);

      // The tickbox is present and dead rather than absent: somebody looking
      // for the Facebook page they just connected finds it, and finds out why.
      final box = tester.widget<FilterChip>(
        find.widgetWithText(FilterChip, 'Everdoors Doors'),
      );
      expect(box.onSelected, isNull);

      expect(
        tester
            .widget<Refused>(
              find.ancestor(
                of: find.widgetWithText(FilterChip, 'Everdoors Doors'),
                matching: find.byType(Refused),
              ),
            )
            .reason,
        contains('not built yet'),
      );

      expect(
        _publishRefusal(tester),
        contains('can be posted to from EzBizi yet'),
      );
    });

    testWidgets('an account ticked and nothing written is still refused', (
      tester,
    ) async {
      await _pumpScreen(
        tester,
        _handler(canEdit: true, connections: const [_pinterestBoard]),
      );
      await _openPosts(tester);
      await _openCompose(tester);

      expect(_publishRefusal(tester), contains('Tick at least one account'));

      await tester.tap(
        find.widgetWithText(FilterChip, 'Everdoors Boards'),
      );
      await tester.pumpAndSettle();

      expect(
        _publishRefusal(tester),
        contains('Write something, or attach a picture'),
      );
    });

    testWidgets('words plus a ticked account sends the post', (tester) async {
      http.Request? sent;

      await _pumpScreen(
        tester,
        _handler(
          canEdit: true,
          connections: const [_pinterestBoard],
          onPublish: (request) => sent = request,
        ),
      );
      await _openPosts(tester);
      await _openCompose(tester);

      await tester.enterText(
        find.widgetWithText(TextField, 'What should go out?'),
        'New flue liner, same day.',
      );
      await tester.tap(
        find.widgetWithText(FilterChip, 'Everdoors Boards'),
      );
      await tester.pumpAndSettle();

      expect(_publishRefusal(tester), isNull);

      await tester.tap(find.widgetWithText(FilledButton, 'Publish now'));
      await tester.pumpAndSettle();

      expect(sent, isNotNull, reason: 'Publish sent nothing.');

      final body = jsonDecode(sent!.body) as Map<String, dynamic>;
      expect(body['body'], 'New flue liner, same day.');
      expect(body['connections'], ['conn-2']);

      // The composer is the result now (design 27). This server never
      // answers, so it waits out the poll before offering the form again.
      await _tick(tester, times: 21);
      await tester.tap(find.widgetWithText(FilledButton, 'Write another'));
      await tester.pumpAndSettle();

      // Emptied only because the send worked.
      expect(
        tester
            .widget<TextField>(
              find.widgetWithText(TextField, 'What should go out?'),
            )
            .controller
            ?.text,
        isEmpty,
      );
    });

    testWidgets('a refused send keeps the words and shows the server sentence', (
      tester,
    ) async {
      await _pumpScreen(
        tester,
        _refusingHandler(
          'Pinterest needs a picture or a video. Attach one and try again.',
        ),
      );
      await _openPosts(tester);
      await _openCompose(tester);

      await tester.enterText(
        find.widgetWithText(TextField, 'What should go out?'),
        'Just words',
      );
      await tester.tap(
        find.widgetWithText(FilterChip, 'Everdoors Boards'),
      );
      await tester.pumpAndSettle();

      await tester.tap(find.widgetWithText(FilledButton, 'Publish now'));
      await tester.pumpAndSettle();

      // The server's sentence, not one this screen wrote.
      expect(find.textContaining('Pinterest needs a picture'), findsOneWidget);

      // And the post is still in the box, because the fix is one attachment
      // away and retyping it is not part of the remedy.
      expect(
        tester
            .widget<TextField>(
              find.widgetWithText(TextField, 'What should go out?'),
            )
            .controller
            ?.text,
        'Just words',
      );
    });

    testWidgets('what went out is listed, and a failure shows its reason', (
      tester,
    ) async {
      await _pumpScreen(
        tester,
        _handler(
          canEdit: true,
          connections: const [_pinterestBoard],
          posts: const [
            {
              'id': 'post-1',
              'body': 'Chimney sweeping season is here.',
              'has_media': true,
              'author': 'Jose Silva',
              'targets': [
                {
                  'account': 'Everdoors Boards',
                  'provider_label': 'Pinterest',
                  'status': 'failed',
                  'failure':
                      'That account has no boards, and a Pin has to live on '
                      'one.',
                },
              ],
            },
          ],
        ),
      );
      await _openPosts(tester);

      expect(find.widgetWithText(StatusPill, 'Failed'), findsOneWidget);
      expect(find.text('Chimney sweeping season is here.'), findsOneWidget);

      // The whole sentence, not the word "failed".
      expect(find.textContaining('has no boards'), findsOneWidget);
    });
  });

  /// "No modals, ever" — and a confirmation is not an exception to it.
  ///
  /// This was an AlertDialog until a coherency audit found it. The regression
  /// is invisible to every other assertion on this screen, because a dialog and
  /// a panel both show the same words and both return the same answer; only the
  /// type tells them apart, so only a test on the type holds the rule.
  // Review 2026-11-09 C3 (A24): an expired account looks expired, with the
  // way back on its row and above the dashboard.
  testWidgets('a broken account shows its health, a banner and Reconnect', (
    tester,
  ) async {
    final checked = DateTime.now()
        .subtract(const Duration(hours: 2))
        .toUtc()
        .toIso8601String();
    await _pumpScreen(
      tester,
      _handler(
        canEdit: true,
        connections: [
          {
            ..._pinterestBoard,
            'status': 'broken',
            'status_reason': 'Pinterest sign-in expired',
            'last_checked_at': checked,
          },
        ],
      ),
    );
    await _openPosts(tester);

    expect(find.byKey(const ValueKey('social-broken-banner')), findsOneWidget);
    expect(
      find.textContaining('Everdoors Boards needs reconnecting'),
      findsOneWidget,
    );

    await tester.tap(find.widgetWithText(ChoiceChip, 'Accounts'));
    await tester.pumpAndSettle();
    expect(
      find.textContaining(
        'Needs reconnect: Pinterest sign-in expired · checked 2h ago',
      ),
      findsOneWidget,
    );
    expect(
      find.byKey(const ValueKey('social-reconnect-conn-2')),
      findsOneWidget,
    );
    await tester.tap(find.byKey(const ValueKey('social-reconnect-conn-2')));
    await tester.pumpAndSettle();
    expect(find.byType(SidePanel), findsOneWidget);
  });

  testWidgets('a healthy account reads Healthy and offers no Reconnect', (
    tester,
  ) async {
    await _pumpScreen(
      tester,
      _handler(canEdit: true, connections: const [_pinterestBoard]),
    );
    await _openPosts(tester);
    expect(find.byKey(const ValueKey('social-broken-banner')), findsNothing);
    await tester.tap(find.widgetWithText(ChoiceChip, 'Accounts'));
    await tester.pumpAndSettle();
    expect(find.textContaining('Healthy'), findsOneWidget);
    expect(find.byKey(const ValueKey('social-reconnect-conn-2')), findsNothing);
  });

  testWidgets('disconnect confirms in a panel, never a dialog', (tester) async {
    await _pumpScreen(
      tester,
      _handler(canEdit: true, connections: const [_pinterestBoard]),
    );

    await tester.tap(
      find.widgetWithText(ChoiceChip, 'Social Media Management'),
    );
    await tester.pumpAndSettle();
    await tester.tap(find.widgetWithText(ChoiceChip, 'Accounts'));
    await tester.pumpAndSettle();

    await tester.tap(find.widgetWithText(TextButton, 'Disconnect'));
    await tester.pumpAndSettle();

    expect(find.byType(AlertDialog), findsNothing);
    expect(find.byType(SidePanel), findsOneWidget);
    expect(find.text('Disconnect Everdoors Boards?'), findsOneWidget);
    expect(find.widgetWithText(TextButton, 'Keep it'), findsOneWidget);
  });

  /// The defect that reached production on 2026-08-28, and Jose's request.
  ///
  /// Publishing is a job per account, so a 201 means QUEUED. The screen read
  /// the Sent list once and never again, so a target that failed two seconds
  /// later sat on screen saying "waiting to send" forever. Jose watched that
  /// and asked why it was still waiting — TikTok had already refused it.
  ///
  /// A failure displayed as progress is the worst shape available: an error
  /// sends somebody looking, a spinner tells them to wait.
  /// UI queue 76 (Jose, 2026-10-12, "2 definitely"): Social opens on a
  /// dashboard whose timeline puts what is coming above each day's posts.
  group('the social dashboard', () {
    // Bug review 2026-11-09 #7: a turned-down draft never went out.
    testWidgets('a disapproved post reads Not posted, never Sent', (
      tester,
    ) async {
      await _pumpSocial(
        tester,
        _handler(
          canEdit: true,
          connections: const [_pinterestBoard],
          posts: [
            {
              ..._landed('disapproved'),
              'id': 'd1',
              'body': 'Weekly Google post',
            },
          ],
        ),
      );

      expect(find.widgetWithText(StatusPill, 'Not posted'), findsOneWidget);
      expect(find.widgetWithText(StatusPill, 'Sent'), findsNothing);
    });

    // Review 2026-11-09 C7 (A21): the edit picks its time like the composer.
    testWidgets('editing a scheduled post picks the time from a calendar', (
      tester,
    ) async {
      await _pumpSocial(
        tester,
        _handler(
          canEdit: true,
          connections: const [_pinterestBoard],
          posts: [
            {
              ..._landed('scheduled', scheduledFor: '2099-03-04 09:00'),
              'id': 'c1',
              'body': 'Free inspection week',
            },
          ],
        ),
      );
      await tester.tap(find.text('Free inspection week').first);
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const Key('social-post-edit')));
      await tester.pumpAndSettle();

      final field = find.descendant(
        of: find.byType(SidePanel),
        matching: find.byType(CalendarDayField),
      );
      expect(field, findsOneWidget);
      expect(
        tester.widget<CalendarDayField>(field).controller.text,
        '2099-03-04 09:00',
      );
    });

    // Bug review 2026-11-09 #9: fixing the words does not re-send the time,
    // which the server would re-read; a moved time is sent.
    testWidgets('editing only the words leaves the time out of the save', (
      tester,
    ) async {
      final patches = <Map<String, dynamic>>[];
      final inner = _handler(
        canEdit: true,
        connections: const [_pinterestBoard],
        posts: [
          {
            ..._landed('scheduled', scheduledFor: '2099-03-04 09:00'),
            'id': 'c1',
            'body': 'Free inspection week',
          },
        ],
      );
      await _pumpSocial(tester, (request) async {
        if (request.method == 'PATCH') {
          patches.add(jsonDecode(request.body) as Map<String, dynamic>);
          return _json({'data': {}});
        }
        return inner(request);
      });

      Future<void> edit(String? when) async {
        await tester.tap(find.text('Free inspection week').first);
        await tester.pumpAndSettle();
        await tester.tap(find.byKey(const Key('social-post-edit')));
        await tester.pumpAndSettle();
        await tester.enterText(
          find.byKey(const Key('social-edit-body')),
          'Free inspection week, all week',
        );
        if (when != null) {
          await tester.enterText(find.byKey(const Key('social-edit-when')), when);
        }
        await tester.tap(find.byKey(const Key('social-edit-save')));
        await tester.pumpAndSettle();
      }

      await edit(null);
      expect(patches.last.containsKey('scheduled_for'), isFalse);
      expect(patches.last['body'], 'Free inspection week, all week');

      await edit('2099-03-05 10:00');
      expect(patches.last['scheduled_for'], '2099-03-05 10:00');
    });

    testWidgets('what is coming sits above today, and a refusal is said with '
        'its Retry', (tester) async {
      await _pumpSocial(
        tester,
        _handler(
          canEdit: true,
          connections: const [_pinterestBoard],
          posts: [
            {
              ..._landed('failed', failure: 'That account has no boards.'),
              'id': 'f1',
              'body': 'A look inside a flue camera inspection',
            },
            {
              ..._landed('sent', url: 'https://www.pinterest.com/pin/9/'),
              'id': 's1',
              'body': 'Chimney season is here',
            },
            {
              ..._landed('scheduled', scheduledFor: '2099-03-04 09:00'),
              'id': 'c1',
              'body': 'Free inspection week',
            },
            {
              ..._landed('awaiting_approval'),
              'id': 'w1',
              'body': 'Weekly Google post',
            },
          ],
        ),
      );

      final today = tester.getRect(find.text('TODAY')).top;
      expect(tester.getRect(find.text('COMING UP')).top, lessThan(today));
      expect(
        tester.getRect(find.text('Free inspection week')).top,
        lessThan(today),
      );
      expect(
        tester.getRect(find.text('Chimney season is here')).top,
        greaterThan(today),
      );
      expect(find.textContaining('Goes out 2099-03-04 09:00'), findsOneWidget);

      // The network's sentence and a Retry on the refused post only.
      expect(find.textContaining('That account has no boards.'), findsOneWidget);
      expect(find.byKey(const ValueKey('social-retry-f1')), findsOneWidget);
      expect(find.byKey(const ValueKey('social-retry-s1')), findsNothing);
      // A sent post opens where it went out, from the timeline itself.
      expect(
        find.byKey(const ValueKey('social-open-s1-target-1')),
        findsOneWidget,
      );
      expect(find.byTooltip('Open post on Pinterest'), findsOneWidget);
      expect(find.widgetWithText(StatusPill, 'Scheduled'), findsOneWidget);

      // A post waiting for approval has not gone out: never "Sent".
      expect(
        find.widgetWithText(StatusPill, 'Waiting for approval'),
        findsOneWidget,
      );
      expect(find.widgetWithText(StatusPill, 'Sent'), findsOneWidget);

      // A fact beside the filters, and the composer one tab away.
      expect(find.text('it can post'), findsOneWidget);
      await tester.tap(find.widgetWithText(FilledButton, 'New post'));
      await tester.pumpAndSettle();
      expect(find.widgetWithText(FilledButton, 'Publish now'), findsOneWidget);
    });
  });

  group('a queued post is followed until the queue answers', () {
    testWidgets('a target that fails is shown failing, not still waiting', (
      tester,
    ) async {
      var call = 0;

      // Queued on the first read, failed on every read after — which is
      // exactly what production did.
      final handler = _handler(
        canEdit: true,
        connections: const [_pinterestBoard],
        postsFor: () => [
          {
            'id': 'post-1',
            'body': 'Chimney sweeping season is here.',
            'has_media': true,
            'targets': [
              {
                'account': 'Everdoors Boards',
                'provider_label': 'Pinterest',
                'status': call++ == 0 ? 'queued' : 'failed',
                'failure': call == 1
                    ? null
                    : 'That account has no boards, and a Pin has to live on one.',
              },
            ],
          },
        ],
      );

      await _pumpSocial(tester, handler);

      // The poll is a Timer, and `pumpAndSettle` only advances while frames are
      // scheduled — a pending timer schedules none. So the clock is moved by
      // hand, which is also a fair model of what the user does: waits.
      await _tick(tester);

      // It polled, saw the truth, and said so.
      expect(find.textContaining('has no boards'), findsOneWidget);
      expect(find.textContaining('waiting to send'), findsNothing);
      expect(call, greaterThan(1), reason: 'It never re-read the Sent list.');
    });

    testWidgets('a queue that never answers says so instead of spinning', (
      tester,
    ) async {
      // Stuck queued forever — the shape of a dead worker.
      final handler = _handler(
        canEdit: true,
        connections: const [_pinterestBoard],
        postsFor: () => [
          {
            'id': 'post-1',
            'body': 'Anyone there?',
            'has_media': false,
            'targets': [
              {
                'account': 'Everdoors Boards',
                'provider_label': 'Pinterest',
                'status': 'queued',
                'failure': null,
              },
            ],
          },
        ],
      );

      await _pumpSocial(tester, handler);

      // Past the give-up limit, which is 20 tries at three seconds.
      await _tick(tester, times: 22);

      // It gives up and says why, rather than drawing an indefinite wait or
      // hammering the server forever.
      expect(find.textContaining('queue has stopped answering'), findsOneWidget);
    });
  });

  /// Design 27, Jose's picks 1 and 4. His words, via the Meta session: "there
  /// was no hint that the post had been successful ... i didn't see anything
  /// appear on the calendar even though you posted it and it succeeded".
  group('posting feels finished', () {
    testWidgets('the composer becomes the result, and the post lands on today', (
      tester,
    ) async {
      var reads = 0;

      await _pumpSocial(
        tester,
        _handler(
          canEdit: true,
          connections: const [_pinterestBoard],
          postsFor: () => switch (reads++) {
            0 => const [],
            1 => [_landed('queued')],
            _ => [_landed('sent', url: 'https://www.pinterest.com/pin/1/')],
          },
        ),
      );
      await _openCompose(tester);

      await _write(tester);
      await tester.tap(find.widgetWithText(FilledButton, 'Publish now'));
      await tester.pumpAndSettle();

      // Each account resolving, where the form was.
      expect(find.text('Posting… 0 of 1 done'), findsOneWidget);
      expect(find.text('Posting…'), findsOneWidget);
      expect(find.widgetWithText(TextField, 'What should go out?'), findsNothing);

      await _tick(tester);
      await tester.pumpAndSettle();

      expect(find.text('Posted to 1 account'), findsOneWidget);
      expect(find.text('Pinterest is live.'), findsOneWidget);
      expect(find.text('View on Pinterest'), findsOneWidget);

      // On today's square at the same moment, outlined.
      await _openCalendar(tester);
      final chip = find.descendant(
        of: find.byKey(Key('month-day-${DateTime.now().day}')),
        matching: find.textContaining('New flue liner'),
      );
      expect(chip, findsOneWidget);
      final box = tester.widget<Material>(
        find.ancestor(of: chip, matching: find.byType(Material)).first,
      );
      expect((box.shape! as RoundedRectangleBorder).side.width, greaterThan(0));
    });

    testWidgets('a refusal from the network reads as not posted, with why', (
      tester,
    ) async {
      var reads = 0;

      await _pumpSocial(
        tester,
        _handler(
          canEdit: true,
          connections: const [_pinterestBoard],
          postsFor: () => reads++ == 0
              ? const []
              : [
                  _landed(
                    'failed',
                    failure:
                        'That account has no boards, and a Pin has to live '
                        'on one.',
                  ),
                ],
        ),
      );
      await _openCompose(tester);

      await _write(tester);
      await tester.tap(find.widgetWithText(FilledButton, 'Publish now'));
      await tester.pumpAndSettle();

      expect(find.text('Not posted'), findsOneWidget);
      expect(
        find.text('Pinterest did not take it. The reason is below.'),
        findsOneWidget,
      );
      expect(find.textContaining('has no boards'), findsWidgets);
    });

    testWidgets('a post deleted after it went out still reads as posted', (
      tester,
    ) async {
      var reads = 0;

      await _pumpSocial(
        tester,
        _handler(
          canEdit: true,
          connections: const [_pinterestBoard],
          postsFor: () => reads++ == 0 ? const [] : [_landed('removed')],
        ),
      );
      await _openCompose(tester);

      await _write(tester);
      await tester.tap(find.widgetWithText(FilledButton, 'Publish now'));
      await tester.pumpAndSettle();

      expect(find.text('Not posted'), findsNothing);
      expect(find.text('Posted · deleted from Pinterest'), findsOneWidget);
    });

    testWidgets('a scheduled post says when, and the calendar goes to it', (
      tester,
    ) async {
      // Far enough ahead to be another month, so the calendar has to move.
      final when = DateTime.now().add(const Duration(days: 40));
      final wall = '${when.year}-${_two(when.month)}-${_two(when.day)} 09:00';
      var reads = 0;

      await _pumpSocial(
        tester,
        _scheduling(
          _handler(
            canEdit: true,
            connections: const [_pinterestBoard],
            postsFor: () => reads++ == 0
                ? const []
                : [_landed('scheduled', scheduledFor: wall)],
          ),
        ),
      );
      await _openCompose(tester);

      await _write(tester);
      await tester.tap(find.widgetWithText(ChoiceChip, 'Later'));
      await tester.pumpAndSettle();
      await _typeWhen(tester, when, hour: '09', minute: '00');
      await tester.tap(find.widgetWithText(FilledButton, 'Schedule'));
      await tester.pumpAndSettle();

      expect(find.textContaining('Scheduled for '), findsOneWidget);
      expect(find.textContaining(' on 1 account'), findsOneWidget);

      await tester.tap(
        find.widgetWithText(OutlinedButton, 'See it on the calendar'),
      );
      await tester.pumpAndSettle();

      expect(
        find.descendant(
          of: find.byKey(Key('month-day-${when.day}')),
          matching: find.textContaining('New flue liner'),
        ),
        findsOneWidget,
      );
    });

    testWidgets('on a phone publishing takes the pane, and Done lands on the '
        'agenda', (tester) async {
      var reads = 0;

      await _pumpSocial(
        tester,
        _handler(
          canEdit: true,
          connections: const [_pinterestBoard],
          postsFor: () => switch (reads++) {
            0 => const [],
            1 => [_landed('queued')],
            // Earlier posts too: a Sent list long enough to push the agenda
            // below what a lazy list builds, as on a real account.
            _ => [
              _landed('sent'),
              for (var i = 0; i < 8; i++)
                {
                  ..._landed('sent'),
                  'id': 'old-$i',
                  'created_at': '2026-01-0${i + 1}T12:00:00Z',
                },
            ],
          },
        ),
        size: const Size(375, 900),
      );
      await _openCompose(tester);

      await _write(tester);
      final publish = find.widgetWithText(FilledButton, 'Publish now');
      await tester.ensureVisible(publish);
      await tester.pumpAndSettle();
      await tester.tap(publish);
      await tester.pumpAndSettle();

      expect(find.text('Posting to your accounts'), findsOneWidget);
      expect(find.text('0 / 1'), findsOneWidget);
      // The pane, not the tabs above it: the form is gone while it lands.
      expect(find.widgetWithText(FilledButton, 'Publish now'), findsNothing);

      await _tick(tester);
      await tester.pumpAndSettle();
      expect(find.text('Posted to 1 account'), findsOneWidget);

      await tester.tap(find.widgetWithText(FilledButton, 'Done'));
      await tester.pumpAndSettle();

      // Landed ON the agenda, not merely somewhere below: built lazily, it
      // had no context to scroll to, and Done left the page at the top.
      final agenda = tester.getRect(find.text('Your posts'));
      expect(agenda.top, inInclusiveRange(0, 900 - agenda.height));
      expect(find.text('Today'), findsOneWidget);
      final row = tester.widget<Material>(
        find.byKey(const ValueKey('agenda-post-1')),
      );
      expect((row.shape! as RoundedRectangleBorder).side.width, 2);
      expect(tester.takeException(), isNull);
    });

    // Review 2026-11-09 C8 (A23): a day on the calendar is where a post starts.
    testWidgets('tapping a calendar day opens Compose set to 9:00 that day', (
      tester,
    ) async {
      await _pumpSocial(
        tester,
        _handler(canEdit: true, connections: const [_pinterestBoard]),
      );
      await _openCalendar(tester);
      final now = DateTime.now();
      await tester.tap(find.byKey(Key('month-day-${now.day}')));
      await tester.pumpAndSettle();

      String two(int n) => n.toString().padLeft(2, '0');
      final field = tester.widget<CalendarDayField>(
        find.byType(CalendarDayField),
      );
      expect(
        field.controller.text,
        '${now.year}-${two(now.month)}-${two(now.day)} 09:00',
      );
      expect(
        tester
            .widget<ChoiceChip>(find.widgetWithText(ChoiceChip, 'Later'))
            .selected,
        isTrue,
      );
    });

    // Review 2026-11-09 C2: the month is asked for by its days, so a post
    // older than the newest twenty is still drawn on its day.
    testWidgets('the calendar asks for the days it draws', (tester) async {
      final ranges = <String>[];
      final inner = _handler(
        canEdit: true,
        connections: const [_pinterestBoard],
      );
      await _pumpSocial(tester, (request) async {
        final from = request.url.queryParameters['from'];
        if (request.url.path.endsWith('/social/posts') && from != null) {
          ranges.add('$from..${request.url.queryParameters['to']}');
          return _json({
            'data': [_landed('sent')],
            'meta': {'tiles': <String, int>{}},
          });
        }
        return inner(request);
      });

      await _openCalendar(tester);
      expect(ranges, isNotEmpty);
      expect(
        find.descendant(
          of: find.byKey(Key('month-day-${DateTime.now().day}')),
          matching: find.textContaining('New flue liner'),
        ),
        findsOneWidget,
      );

      await tester.tap(find.byTooltip('Next month'));
      await tester.pumpAndSettle();
      expect(ranges.length, greaterThan(1));
    });

    // Review 2026-11-09 C6 (A16, A17): a campaign's listings are one line.
    testWidgets('a post to many listings reads as one Google line', (
      tester,
    ) async {
      final post = {
        ..._landed('sent'),
        'targets': [
          for (var i = 0; i < 5; i++)
            {
              'id': 'gbp-$i',
              'connection_id': 'conn-g',
              'account': 'Listing $i',
              'provider': 'google_business_profile',
              'provider_label': 'Google Business Profile',
              'status': i == 4 ? 'failed' : 'sent',
              'failure': i == 4 ? 'Suspended' : null,
              'url': null,
            },
        ],
      };
      await _pumpSocial(
        tester,
        _handler(
          canEdit: true,
          connections: const [_pinterestBoard],
          posts: [post],
        ),
      );

      expect(
        tester
            .widget<Text>(
              find.byKey(const ValueKey('social-post-listings-post-1')),
            )
            .data,
        'Google · 5 listings ✓4 ✗1',
      );
      expect(find.text('Listing 0'), findsNothing);
      expect(find.text('ACCOUNTS'), findsOneWidget);
    });

    // Review 2026-11-09 C9 (B5): every failed account again, in one action.
    testWidgets('Retry all failed tries each failed account and no other', (
      tester,
    ) async {
      final retried = <String>[];
      Map<String, Object?> target(String id, String status) => {
        'id': id,
        'connection_id': 'conn-2',
        'account': 'Account $id',
        'provider': 'pinterest',
        'provider_label': 'Pinterest',
        'status': status,
        'failure': status == 'failed' ? 'Refused' : null,
        'url': null,
      };
      final inner = _handler(
        canEdit: true,
        connections: const [_pinterestBoard],
        posts: [
          {
            ..._landed('sent'),
            'targets': [
              target('a', 'failed'),
              target('b', 'failed'),
              target('c', 'sent'),
            ],
          },
        ],
      );
      await _pumpSocial(tester, (request) async {
        if (request.method == 'POST' && request.url.path.endsWith('/retry')) {
          retried.add(
            request.url.pathSegments[request.url.pathSegments.length - 2],
          );
          return _json({'data': {}});
        }
        return inner(request);
      });

      await _openCalendar(tester);
      await tester.tap(
        find.descendant(
          of: find.byKey(Key('month-day-${DateTime.now().day}')),
          matching: find.textContaining('New flue liner'),
        ),
      );
      await tester.pumpAndSettle();
      expect(find.text('Retry all failed (2)'), findsOneWidget);
      await tester.tap(find.byKey(const Key('social-post-retry-all')));
      await tester.pumpAndSettle();
      expect(retried, ['a', 'b']);
    });

    // Review 2026-11-09 C5 (A13): approve inside Marketing.
    testWidgets('a post waiting for approval is approved from its panel', (
      tester,
    ) async {
      String? decided;
      final inner = _handler(
        canEdit: true,
        connections: const [_pinterestBoard],
        posts: [_landed('awaiting_approval')],
      );
      await _pumpSocial(tester, (request) async {
        if (request.method == 'POST' &&
            request.url.path.contains('/business-profile/post-queue/')) {
          decided = request.url.path;
          return _json({
            'data': {'id': 'target-1', 'status': 'queued'},
          });
        }
        if (request.method == 'GET' &&
            request.url.path.endsWith('/social/posts')) {
          return _json({
            'data': [_landed('awaiting_approval')],
            'meta': {
              'tiles': {'scheduled': 1, 'sent': 0, 'failed': 0, 'awaiting': 1},
            },
          });
        }
        return inner(request);
      });

      expect(find.text('NEEDS APPROVAL'), findsOneWidget);
      await _openCalendar(tester);
      await tester.tap(
        find.descendant(
          of: find.byKey(Key('month-day-${DateTime.now().day}')),
          matching: find.textContaining('New flue liner'),
        ),
      );
      await tester.pumpAndSettle();
      expect(find.text('Waiting for approval'), findsOneWidget);
      await tester.tap(find.byKey(const Key('social-approve-target-1')));
      await tester.pumpAndSettle();
      expect(
        decided,
        endsWith('/business-profile/post-queue/target-1/approve'),
      );
    });

    testWidgets('a post on the calendar opens in a panel with each account', (
      tester,
    ) async {
      await _pumpSocial(
        tester,
        _handler(
          canEdit: true,
          connections: const [_pinterestBoard],
          posts: [_landed('sent', url: 'https://www.pinterest.com/pin/1/')],
        ),
      );

      await _openCalendar(tester);
      await tester.tap(
        find.descendant(
          of: find.byKey(Key('month-day-${DateTime.now().day}')),
          matching: find.textContaining('New flue liner'),
        ),
      );
      await tester.pumpAndSettle();

      expect(find.byType(AlertDialog), findsNothing);
      expect(
        find.descendant(
          of: find.byType(SidePanel),
          matching: find.text('View on Pinterest'),
        ),
        findsOneWidget,
      );
    });

    testWidgets('a failed account can be tried again, and is followed', (
      tester,
    ) async {
      var retried = false;
      var after = 0;

      await _pumpSocial(
        tester,
        _retrying(
          _handler(
            canEdit: true,
            connections: const [_pinterestBoard],
            postsFor: () => [
              !retried
                  ? _landed('failed', failure: 'Pinterest did not answer.')
                  : _landed(after++ == 0 ? 'queued' : 'sent'),
            ],
          ),
          onRetry: () => retried = true,
        ),
      );

      await _openCalendar(tester);
      await tester.tap(
        find.descendant(
          of: find.byKey(Key('month-day-${DateTime.now().day}')),
          matching: find.textContaining('New flue liner'),
        ),
      );
      await tester.pumpAndSettle();
      await tester.tap(find.widgetWithText(TextButton, 'Try again'));
      await tester.pumpAndSettle();

      // The panel closed and the composer follows it, like a new post.
      expect(retried, isTrue);
      expect(find.byType(SidePanel), findsNothing);
      expect(find.text('Posting… 0 of 1 done'), findsOneWidget);

      await _tick(tester);
      await tester.pumpAndSettle();
      expect(find.text('Posted to 1 account'), findsOneWidget);
    });

    testWidgets('without the right to post, a failure offers no Try again', (
      tester,
    ) async {
      await _pumpSocial(
        tester,
        _handler(
          connections: const [_pinterestBoard],
          posts: [_landed('failed', failure: 'Pinterest did not answer.')],
        ),
      );

      await _openCalendar(tester);
      await tester.tap(
        find.descendant(
          of: find.byKey(Key('month-day-${DateTime.now().day}')),
          matching: find.textContaining('New flue liner'),
        ),
      );
      await tester.pumpAndSettle();

      expect(
        find.descendant(
          of: find.byType(SidePanel),
          matching: find.text('Pinterest did not answer.'),
        ),
        findsOneWidget,
      );
      expect(find.text('Try again'), findsNothing);
    });

    // The composer column did not scroll: twenty posts in its Sent list are
    // taller than a laptop screen, and the card ran off the bottom.
    testWidgets('a long Sent list scrolls instead of running off the screen', (
      tester,
    ) async {
      await _pumpSocial(
        tester,
        _handler(
          canEdit: true,
          connections: const [_pinterestBoard],
          posts: [
            for (var i = 0; i < 12; i++) {..._landed('sent'), 'id': 'post-$i'},
          ],
        ),
        size: const Size(1400, 900),
      );

      expect(tester.takeException(), isNull);
      expect(find.text('New post'), findsOneWidget);
    });
  });

  group('what posting somewhere actually does is said beforehand', () {
    // A1 takeover, "compose once for many locations": Business Profile
    // listings beside the accounts, and the listing's button.
    testWidgets('a listing can be ticked, and its button needs an https address', (
      tester,
    ) async {
      final inner = _handler(canEdit: true, connections: const [_pinterestBoard]);
      await _pumpSocial(tester, (request) async {
        if (request.url.path.endsWith('/social/places')) {
          return _json({
            'data': [
              {
                'id': 'place-1',
                'title': 'Brightside Home – Springfield',
                'label': 'Brightside Home · Springfield, IL',
                'branch': 'Springfield',
                'address': '14 Elm St, Springfield',
                'connection_id': 'conn-g',
                'state': 'verified',
                'postable': true,
              },
              {
                'id': 'place-2',
                'title': 'Brightside Home – Riverton',
                'address': '9 Oak Ave, Riverton',
                'connection_id': 'conn-g',
                'state': 'suspended',
                'postable': false,
              },
            ],
          });
        }
        return inner(request);
      });
      await _openCompose(tester);
      await tester.tap(find.byKey(const ValueKey('social-places-expand')));
      await tester.pumpAndSettle();

      expect(find.textContaining('Riverton · Suspended'), findsOneWidget);
      // Each listing says which it is, under its branch (Jose, 2026-10-19).
      expect(find.text('Springfield, IL'), findsOneWidget);
      expect(find.text('Springfield'), findsOneWidget);
      expect(find.byKey(const ValueKey('social-place-cta')), findsNothing);

      final words = find.widgetWithText(TextField, 'What should go out?');
      await tester.enterText(words, 'Fall sweep special.');
      await tester.tap(find.byKey(const ValueKey('social-place-place-1')));
      await tester.pumpAndSettle();
      expect(_publishRefusal(tester), isNull);

      // A button that goes to a web page needs one.
      expect(find.byKey(const ValueKey('social-place-cta')), findsOneWidget);
      await tester.tap(find.byKey(const ValueKey('social-place-cta')));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Book').last);
      await tester.pumpAndSettle();
      expect(_publishRefusal(tester), contains('https://'));

      await tester.enterText(
        find.byKey(const ValueKey('social-place-cta-url')),
        'https://brightside.example/book',
      );
      await tester.pumpAndSettle();
      expect(_publishRefusal(tester), isNull);
    });

    // Jose, 2026-10-26: one switch for every listing, a count, and Expand.
    group('the listings switch', () {
      List<Map<String, Object?>> places() => [
        for (final (i, city) in ['Stamford', 'Norwalk', 'Danbury'].indexed)
          {
            'id': 'ct-$i',
            'title': 'A1 Chimney',
            'label': 'A1 Chimney · $city, CT',
            'branch': 'Connecticut',
            'company': 'A1 Chimney',
            'address': '1 Main St, $city, CT',
            'state': 'verified',
            'postable': true,
          },
        {
          'id': 'ma-0',
          'title': 'A1 Chimney',
          'label': 'A1 Chimney · Boston, MA',
          'branch': 'Massachusetts',
          'company': 'A1 Chimney',
          'address': '2 Elm St, Boston, MA',
          'state': 'verified',
          'postable': true,
        },
        {
          'id': 'ma-1',
          'title': 'A1 Chimney',
          'label': 'A1 Chimney',
          'branch': 'Massachusetts',
          'company': 'A1 Chimney',
          'address': '',
          'state': 'unverified',
          'postable': false,
        },
      ];

      Map<String, dynamic>? sent;

      Future<void> open(
        WidgetTester tester, {
        Size size = const Size(1400, 1600),
      }) async {
        sent = null;
        final inner = _handler(canEdit: true, connections: const [_pinterestBoard]);
        await _pumpSocial(tester, (request) async {
          final path = request.url.path;
          if (path.endsWith('/social/places')) return _json({'data': places()});
          if (path.endsWith('/me/preferences')) return _json({'data': {}});
          if (request.method == 'POST' && path.endsWith('/social/posts')) {
            sent = jsonDecode(request.body) as Map<String, dynamic>;
          }
          return inner(request);
        }, size: size);
        await _openCompose(tester);
      }

      String count(WidgetTester tester) => tester
          .widget<Text>(find.byKey(const ValueKey('social-places-count')))
          .data!;
      bool switchOn(WidgetTester tester) => tester
          .widget<Switch>(find.byKey(const ValueKey('social-places-all')))
          .value;

      testWidgets('starts collapsed with nothing ticked; the switch ticks all and none', (
        tester,
      ) async {
        await open(tester);

        expect(find.byKey(const ValueKey('social-place-ct-0')), findsNothing);
        expect(count(tester), '0 of 4 selected');
        expect(switchOn(tester), isFalse);

        await tester.tap(find.byKey(const ValueKey('social-places-all')));
        await tester.pumpAndSettle();
        // Every listing that can take a post; the unverified one stays off.
        expect(count(tester), '4 of 4 selected');
        expect(switchOn(tester), isTrue);

        await tester.tap(find.byKey(const ValueKey('social-places-all')));
        await tester.pumpAndSettle();
        expect(count(tester), '0 of 4 selected');
      });

      testWidgets('expanded: groups, a group tick, partial state, city names', (
        tester,
      ) async {
        await open(tester);
        await tester.tap(find.byKey(const ValueKey('social-places-expand')));
        await tester.pumpAndSettle();

        expect(find.text('A1 Chimney · Connecticut'), findsOneWidget);
        expect(find.text('A1 Chimney · Massachusetts'), findsOneWidget);
        expect(find.text('Collapse'), findsOneWidget);
        // Named by where, not by the name they all share.
        expect(find.text('Stamford, CT'), findsOneWidget);
        expect(find.text('Boston, MA'), findsOneWidget);
        expect(find.text('A1 Chimney · Unverified'), findsOneWidget);
        expect(find.text('A1 Chimney · Stamford, CT'), findsNothing);

        await tester.tap(find.byKey(const ValueKey('social-places-group-0')));
        await tester.pumpAndSettle();
        expect(count(tester), '3 of 4 selected');
        expect(switchOn(tester), isFalse);
        final groupBox = find.descendant(
          of: find.byKey(const ValueKey('social-places-group-0')),
          matching: find.byType(Checkbox),
        );
        expect(tester.widget<Checkbox>(groupBox).value, isTrue);

        await tester.tap(find.byKey(const ValueKey('social-place-ct-1')));
        await tester.pumpAndSettle();
        expect(count(tester), '2 of 4 selected');
        expect(tester.widget<Checkbox>(groupBox).value, isNull);
      });

      testWidgets('search narrows the listings', (tester) async {
        await open(tester);
        await tester.tap(find.byKey(const ValueKey('social-places-expand')));
        await tester.pumpAndSettle();

        await tester.enterText(
          find.byKey(const ValueKey('social-places-search')),
          'bost',
        );
        await tester.pumpAndSettle();
        expect(find.text('Boston, MA'), findsOneWidget);
        expect(find.text('Stamford, CT'), findsNothing);
        expect(find.text('A1 Chimney · Connecticut'), findsNothing);
      });

      testWidgets('Publish sends the ticked listing ids as before', (tester) async {
        await open(tester);
        await tester.enterText(
          find.widgetWithText(TextField, 'What should go out?'),
          'Fall sweep special.',
        );
        await tester.tap(find.byKey(const ValueKey('social-places-all')));
        await tester.pumpAndSettle();
        await tester.tap(find.widgetWithText(FilledButton, 'Publish now'));
        await tester.pumpAndSettle();

        expect(
          (sent?['places'] as List?)?.cast<String>(),
          ['ct-0', 'ct-1', 'ct-2', 'ma-0'],
        );
      });

      testWidgets('a phone draws the row and the expanded list without overflow', (
        tester,
      ) async {
        await open(tester, size: const Size(390, 844));
        expect(tester.takeException(), isNull);
        await tester.ensureVisible(
          find.byKey(const ValueKey('social-places-expand')),
        );
        await tester.pumpAndSettle();
        await tester.tap(find.byKey(const ValueKey('social-places-expand')));
        await tester.pumpAndSettle();
        expect(tester.takeException(), isNull);
        expect(find.text('Stamford, CT'), findsOneWidget);
      });

      // Review 2026-11-09 C1 (A2, A3, A8, A9).
      testWidgets('a listing that cannot post is named apart and cannot be ticked', (
        tester,
      ) async {
        await open(tester);
        expect(
          tester
              .widget<Text>(find.byKey(const ValueKey('social-places-blocked')))
              .data,
          '1 cannot post (suspended or unverified)',
        );
        await tester.tap(find.byKey(const ValueKey('social-places-expand')));
        await tester.pumpAndSettle();
        await tester.tap(find.byKey(const ValueKey('social-place-ma-1')));
        await tester.pumpAndSettle();
        expect(count(tester), '0 of 4 selected');
      });

      testWidgets('listings alone fill the bar and the preview', (tester) async {
        await open(tester);
        expect(find.text('No account ticked yet'), findsOneWidget);
        await tester.tap(find.byKey(const ValueKey('social-places-all')));
        await tester.pumpAndSettle();
        expect(find.text('Going to 4 listings · now'), findsOneWidget);
        expect(
          find.text(
            'Tick an account to see the post the way it will look there.',
          ),
          findsNothing,
        );
        expect(find.text('4 listings'), findsWidgets);
      });
    });

    // UI queue 105: a picture from the library is sent by its id.
    testWidgets('From library picks an item, and Publish sends its id', (
      tester,
    ) async {
      Map<String, dynamic>? sent;
      final inner = _handler(canEdit: true, connections: const [_pinterestBoard]);
      await _pumpSocial(tester, (request) async {
        final path = request.url.path;
        if (path.endsWith('/social/library')) {
          return _json({
            'data': [
              {
                'id': 'lib-1',
                'name': 'Truck at dawn',
                'kind': 'video',
                'size': 1000,
                'scope_type': 'company',
                'created_at': '2026-10-16T12:00:00Z',
              },
            ],
          });
        }
        if (request.method == 'POST' && path.endsWith('/social/posts')) {
          sent = jsonDecode(request.body) as Map<String, dynamic>;
        }
        return inner(request);
      });
      await _openCompose(tester);

      await tester.tap(find.byKey(const ValueKey('social-from-library')));
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const ValueKey('library-item-lib-1')));
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const ValueKey('library-use')));
      await tester.pumpAndSettle();

      expect(find.text('From the library: Truck at dawn'), findsOneWidget);
      expect(_publishRefusal(tester), contains('Tick at least one'));

      await tester.tap(find.byKey(const ValueKey('social-account-conn-2')));
      await tester.pumpAndSettle();
      expect(_publishRefusal(tester), isNull);
      await tester.tap(find.widgetWithText(FilledButton, 'Publish now'));
      await tester.pumpAndSettle();
      expect(sent?['library_item'], 'lib-1');
    });

    // Bug review 2026-11-09 #6: a library video has no upload, so the post
    // goes as JSON, and TikTok's choices must ride in it too.
    testWidgets('a library video to TikTok carries the TikTok choices', (
      tester,
    ) async {
      Map<String, dynamic>? sent;
      final inner = _handler(canEdit: true, connections: const [_tiktokDirect]);
      await _pumpSocial(tester, (request) async {
        final path = request.url.path;
        if (path.endsWith('/social/library')) {
          return _json({
            'data': [
              {
                'id': 'lib-1',
                'name': 'Truck at dawn',
                'kind': 'video',
                'size': 1000,
                'scope_type': 'company',
                'created_at': '2026-10-16T12:00:00Z',
              },
            ],
          });
        }
        if (request.method == 'POST' && path.endsWith('/social/posts')) {
          sent = jsonDecode(request.body) as Map<String, dynamic>;
        }
        return inner(request);
      });
      await _openCompose(tester);

      await tester.tap(find.byKey(const ValueKey('social-from-library')));
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const ValueKey('library-item-lib-1')));
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const ValueKey('library-use')));
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const ValueKey('social-account-conn-7')));
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const ValueKey('tiktok-privacy-ezbizi_us')));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Everyone').last);
      await tester.pumpAndSettle();
      expect(_publishRefusal(tester), isNull);

      await tester.tap(find.widgetWithText(FilledButton, 'Publish now'));
      await tester.pumpAndSettle();

      final choices = (sent?['tiktok'] as Map<String, dynamic>?)?['conn-7'];
      expect(choices, isA<Map<String, dynamic>>());
      expect(choices['privacy_level'], 'PUBLIC_TO_EVERYONE');
      expect(choices['allow_comment'], isFalse);
    });

    // Bug review 2026-11-09 #8: Pinterest's 800 and a ticked listing's
    // Google 1500 are checked before Publish, like X's 280.
    // Review 2026-11-09 C4: the limit and the caution are the server's.
    testWidgets('the server sets the limit and says what a network will skip', (
      tester,
    ) async {
      await _pumpSocial(
        tester,
        _handler(
          canEdit: true,
          connections: const [
            {
              ..._pinterestBoard,
              'body_limit': 10,
              'refuses': {
                'text':
                    'Pinterest does not post words on their own. Attach a picture or a video.',
                'image': null,
                'video': null,
              },
            },
          ],
        ),
      );
      await _openCompose(tester);
      await _write(tester);

      expect(_publishRefusal(tester), contains('10 characters at most'));
      expect(
        find.byKey(const ValueKey('social-caution-conn-2')),
        findsOneWidget,
      );
      expect(
        find.textContaining(
          'does not post words on their own. Attach a '
          'picture or a video. It will be skipped.',
        ),
        findsOneWidget,
      );
    });

    testWidgets('too long for Pinterest, or for a ticked listing, is refused', (
      tester,
    ) async {
      final inner = _handler(canEdit: true, connections: const [_pinterestBoard]);
      await _pumpSocial(tester, (request) async {
        if (request.url.path.endsWith('/social/places')) {
          return _json({
            'data': [
              {
                'id': 'place-1',
                'title': 'Brightside Home',
                'label': 'Brightside Home · Springfield, IL',
                'branch': 'Springfield',
                'address': '14 Elm St, Springfield',
                'connection_id': 'conn-g',
                'state': 'verified',
                'postable': true,
              },
            ],
          });
        }
        return inner(request);
      });
      await _openCompose(tester);

      final words = find.widgetWithText(TextField, 'What should go out?');
      await tester.enterText(words, 'a' * 1501);
      await tester.tap(find.byKey(const ValueKey('social-places-expand')));
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const ValueKey('social-place-place-1')));
      await tester.pumpAndSettle();
      expect(
        _publishRefusal(tester),
        contains('Google Business Profile takes 1500'),
      );

      await tester.tap(find.byKey(const ValueKey('social-place-place-1')));
      await tester.tap(find.widgetWithText(FilterChip, 'Everdoors Boards'));
      await tester.pumpAndSettle();
      expect(_publishRefusal(tester), contains('Pinterest takes 800'));
    });

    testWidgets('the TikTok drafts detour is on the account, before Publish', (
      tester,
    ) async {
      await _pumpSocial(
        tester,
        _handler(canEdit: true, connections: const [_tiktokDrafts]),
      );
      await _openCompose(tester);

      // The server's sentence, on the row, while the post can still be changed.
      expect(
        find.textContaining('TikTok drafts, not straight to the profile'),
        findsOneWidget,
      );
    });

    testWidgets('TikTok Direct Post asks privacy with no default before Publish', (
      tester,
    ) async {
      await _pumpSocial(
        tester,
        _handler(canEdit: true, connections: const [_tiktokDirect]),
        pickMedia: () async =>
            PickedBytes('sweep.mp4', List<int>.filled(2048, 7)),
      );
      await _openCompose(tester);
      await tester.tap(find.byKey(const ValueKey('social-account-conn-7')));
      await tester.pumpAndSettle();
      await tester.tap(
        find.widgetWithText(OutlinedButton, 'Attach a picture or video'),
      );
      await tester.pumpAndSettle();

      expect(find.text('Posting to TikTok as EzBizi US'), findsOneWidget);
      expect(_publishRefusal(tester), contains('Choose who can see'));
      expect(find.textContaining('Music Usage Confirmation'), findsOneWidget);

      // Duet is switched off on the account, so its tick cannot be set.
      final duet = tester.widget<CheckboxListTile>(
        find.widgetWithText(CheckboxListTile, 'Duet'),
      );
      expect(duet.onChanged, isNull);

      await tester.tap(find.byKey(const ValueKey('tiktok-privacy-ezbizi_us')));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Everyone').last);
      await tester.pumpAndSettle();
      expect(_publishRefusal(tester), isNull);

      // Disclosure on with nothing chosen blocks again.
      await tester.tap(find.byKey(const ValueKey('tiktok-disclose-ezbizi_us')));
      await tester.pumpAndSettle();
      expect(_publishRefusal(tester), contains('promotes yourself'));

      await tester.tap(find.textContaining('Branded content —'));
      await tester.pumpAndSettle();
      expect(find.textContaining('Paid partnership'), findsOneWidget);
      expect(find.textContaining('Branded Content Policy'), findsOneWidget);
      expect(_publishRefusal(tester), isNull);
    });

    testWidgets('TikTok offers profile or inbox; the inbox hides privacy', (
      tester,
    ) async {
      await _pumpSocial(
        tester,
        _handler(canEdit: true, connections: const [_tiktokDirect]),
        pickMedia: () async =>
            PickedBytes('sweep.mp4', List<int>.filled(2048, 7)),
      );
      await _openCompose(tester);
      await tester.tap(find.byKey(const ValueKey('social-account-conn-7')));
      await tester.pumpAndSettle();
      await tester.tap(
        find.widgetWithText(OutlinedButton, 'Attach a picture or video'),
      );
      await tester.pumpAndSettle();

      // Both ways in are offered; the profile starts with its privacy picker.
      expect(find.text('Post to profile'), findsOneWidget);
      expect(find.text('Send to TikTok inbox as a draft'), findsOneWidget);
      expect(find.byKey(const ValueKey('tiktok-privacy-ezbizi_us')),
          findsOneWidget);

      await tester.tap(find.text('Send to TikTok inbox as a draft'));
      await tester.pumpAndSettle();

      expect(find.byKey(const ValueKey('tiktok-privacy-ezbizi_us')),
          findsNothing);
      expect(find.byKey(const ValueKey('tiktok-disclose-ezbizi_us')),
          findsNothing);
      expect(find.widgetWithText(CheckboxListTile, 'Comment'), findsNothing);
      expect(
        find.text(
          "TikTok sends it to the account's inbox; finish posting in the "
          'TikTok app.',
        ),
        findsOneWidget,
      );
      // Nothing to choose for a draft, so nothing blocks Publish.
      expect(_publishRefusal(tester), isNull);
    });

    testWidgets('a network with no detour says nothing rather than reassuring', (
      tester,
    ) async {
      await _pumpSocial(
        tester,
        _handler(canEdit: true, connections: const [_pinterestBoard]),
      );

      expect(find.textContaining('drafts'), findsNothing);
    });
  });

  /// Jose, verbatim: "The video should display, not just said it's attached,
  /// we should have a playback window as well to review the videos or images
  /// we upload."
  ///
  /// A filename is not confirmation. This goes to a public account under the
  /// tenant's own name, so the check has to be one a person can perform.
  group('an attached file can be looked at', () {
    testWidgets('an attached image is displayed, not just named', (
      tester,
    ) async {
      await _pumpSocial(
        tester,
        _handler(canEdit: true, connections: const [_pinterestBoard]),
        pickMedia: () async => PickedBytes('flue.png', _onePixelPng),
      );
      await _openCompose(tester);

      expect(find.byType(Image), findsNothing);

      await tester.tap(
        find.widgetWithText(OutlinedButton, 'Attach a picture or video'),
      );
      await tester.pumpAndSettle();

      expect(find.byType(Image), findsOneWidget);
      expect(find.text('flue.png'), findsOneWidget);
    });

    testWidgets('a video is named and sized rather than shown as an image', (
      tester,
    ) async {
      await _pumpSocial(
        tester,
        _handler(canEdit: true, connections: const [_pinterestBoard]),
        pickMedia: () async =>
            PickedBytes('sweep.mp4', List<int>.filled(2048, 7)),
      );
      await _openCompose(tester);

      await tester.tap(
        find.widgetWithText(OutlinedButton, 'Attach a picture or video'),
      );
      await tester.pumpAndSettle();

      // No Image widget — decoding a frame needs a player package, which is a
      // decision rather than a detail. It says what it has instead of pretending.
      expect(find.byType(Image), findsNothing);
      expect(find.textContaining('sweep.mp4'), findsWidgets);
      expect(find.textContaining('2 KB'), findsOneWidget);
    });

    /// Jose, 2026-11-10: "I attached a video, on the preview on the right
    /// side, it should actually show the video".
    Future<List<_FakeVideo>> attachVideo(
      WidgetTester tester, {
      bool fails = false,
    }) async {
      final made = <_FakeVideo>[];
      await _pumpSocial(
        tester,
        _handler(
          canEdit: true,
          connections: const [_pinterestBoard, _tiktokDirect],
        ),
        pickMedia: () async =>
            PickedBytes('sweep.mp4', List<int>.filled(2048, 7)),
        videoPlayer: (uri) {
          final video = _FakeVideo(uri, fails: fails);
          made.add(video);
          return video;
        },
      );
      await _openCompose(tester);
      await tester.tap(find.byKey(const ValueKey('social-account-conn-2')));
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const ValueKey('social-account-conn-7')));
      await tester.pumpAndSettle();
      await tester.tap(
        find.widgetWithText(OutlinedButton, 'Attach a picture or video'),
      );
      await tester.pumpAndSettle();
      return made;
    }

    testWidgets('one player plays the video in the box and every card', (
      tester,
    ) async {
      final made = await attachVideo(tester);

      expect(made, hasLength(1));
      final players = tester
          .widgetList<VideoPlayer>(find.byType(VideoPlayer))
          .toList();
      // The attachment box and both network cards.
      expect(players, hasLength(3));
      for (final p in players) {
        expect(identical(p.controller, made.single), isTrue);
      }
      expect(made.single.volume, 0);
      expect(find.text('0:42'), findsNWidgets(3));

      await tester.tap(find.byKey(const ValueKey('social-video-toggle')).first);
      await tester.pump();
      expect(made.single.value.isPlaying, isTrue);
    });

    testWidgets('Remove disposes the player once nothing draws it', (
      tester,
    ) async {
      final made = await attachVideo(tester);
      expect(made.single.disposed, isFalse);

      await tester.tap(find.widgetWithText(TextButton, 'Remove'));
      await tester.pumpAndSettle();

      expect(find.byType(VideoPlayer), findsNothing);
      expect(made.single.disposed, isTrue);
    });

    testWidgets('a video that will not load says Preview not available', (
      tester,
    ) async {
      await attachVideo(tester, fails: true);

      expect(find.byType(VideoPlayer), findsNothing);
      expect(find.text('Preview not available'), findsNWidgets(3));
      expect(find.textContaining('sweep.mp4'), findsWidgets);
    });
  });

  /// Scheduling. The promise is a wall clock in THIS view's timezone, and the
  /// screen must not imply anything else — no timezone is sent, and the button
  /// has to stop saying "Publish now" the moment it would not.
  group('a post can be sent later', () {
    testWidgets('the composer offers a time, and says whose clock it is', (
      tester,
    ) async {
      await _pumpSocial(
        tester,
        _handler(canEdit: true, connections: const [_pinterestBoard]),
      );
      await _openCompose(tester);

      // Hidden until asked for: most posts go now, and a date field on every
      // one of them is a question nobody asked.
      expect(find.text('Send at'), findsNothing);

      await tester.tap(find.widgetWithText(ChoiceChip, 'Later'));
      await tester.pumpAndSettle();

      expect(find.text('Send at'), findsOneWidget);
      expect(find.textContaining('This view’s clock — Everdoors'), findsOneWidget);
    });

    testWidgets('the button stops saying "now" once a time is set', (
      tester,
    ) async {
      http.Request? sent;

      await _pumpSocial(
        tester,
        _handler(
          canEdit: true,
          connections: const [_pinterestBoard],
          onPublish: (request) => sent = request,
        ),
      );
      await _openCompose(tester);

      await tester.enterText(
        find.widgetWithText(TextField, 'What should go out?'),
        'Autumn sweeps, booking now.',
      );
      await tester.tap(
        find.widgetWithText(FilterChip, 'Everdoors Boards'),
      );
      await tester.pumpAndSettle();

      // Before scheduling it is an immediate send, and says so.
      expect(find.widgetWithText(FilledButton, 'Publish now'), findsOneWidget);

      await tester.tap(find.widgetWithText(ChoiceChip, 'Later'));
      await tester.pumpAndSettle();

      // CalendarDayField is deliberately four number boxes, not one field a
      // reader has to invent a format for — so the test types what a person
      // types, box by box.
      final when = DateTime.now().add(const Duration(days: 3));
      await _typeWhen(tester, when, hour: '09', minute: '00');

      // "Publish now" on a post that will sit until Thursday is the same class
      // of lie as a spinner over a failed send.
      expect(find.widgetWithText(FilledButton, 'Schedule'), findsOneWidget);
      expect(find.widgetWithText(FilledButton, 'Publish now'), findsNothing);

      await tester.tap(find.widgetWithText(FilledButton, 'Schedule'));
      await tester.pumpAndSettle();

      final body = jsonDecode(sent!.body) as Map<String, dynamic>;
      expect(body['scheduled_for'], contains('09:00'));

      // No timezone on the wire. The server decides which clock the view is on,
      // because a client that sent one could send a different one than the
      // screen was showing.
      expect(body.containsKey('timezone'), isFalse);
      expect(body.containsKey('scheduled_timezone'), isFalse);
    });

    testWidgets('a scheduled post reads as scheduled, not as waiting', (
      tester,
    ) async {
      var reads = 0;

      await _pumpSocial(
        tester,
        _handler(
          canEdit: true,
          connections: const [_pinterestBoard],
          postsFor: () {
            reads++;
            return [
              {
                'id': 'post-1',
                'body': 'Autumn sweeps.',
                'has_media': true,
                'scheduled_for': '2099-03-04 09:00',
                'targets': [
                  {
                    'account': 'Everdoors Boards',
                    'provider_label': 'Pinterest',
                    'status': 'scheduled',
                    'failure': null,
                  },
                ],
              },
            ];
          },
        ),
      );

      expect(find.widgetWithText(StatusPill, 'Scheduled'), findsOneWidget);
      expect(find.textContaining('waiting to send'), findsNothing);
      expect(find.textContaining('Goes out 2099-03-04 09:00'), findsOneWidget);

      // And it does NOT start the poll: a post waiting for a clock is not
      // waiting for the worker, so polling would spend twenty requests to
      // learn nothing and then wrongly announce the queue had stopped.
      final after = reads;
      await _tick(tester, times: 3);
      expect(reads, after, reason: 'A scheduled post should not be polled.');
    });

    /// A wall time is only half a promise: the other half is which clock.
    ///
    /// The server has always sent `scheduled_timezone` and this client never
    /// read it, so "Goes out 09:00" was the same sentence whether the post was
    /// filed against the branch's own zone or the platform default fixed
    /// offset — which is an hour off that branch from March to November.
    ///
    /// `Etc/GMT+5` is UTC MINUS 5. If this ever reads "UTC+5" the label is
    /// printing POSIX's inverted sign straight through and naming a clock ten
    /// hours from the real one.
    testWidgets('the scheduled post names the clock it was filed against', (
      tester,
    ) async {
      await _pumpSocial(
        tester,
        _handler(
          canEdit: true,
          connections: const [_pinterestBoard],
          postsFor: () => [
            {
              'id': 'post-1',
              'body': 'Autumn sweeps.',
              'has_media': true,
              'scheduled_for': '2099-03-04 09:00',
              'scheduled_timezone': 'Etc/GMT+5',
              'targets': [
                {
                  'account': 'Everdoors Boards',
                  'provider_label': 'Pinterest',
                  'status': 'scheduled',
                  'failure': null,
                },
              ],
            },
          ],
        ),
      );

      expect(
        find.textContaining(
          'Goes out 2099-03-04 09:00 (UTC−5, no daylight saving)',
        ),
        findsOneWidget,
      );
    });
  });


  /// Posts, layout A (Mia, 2026-10-02): a character count per ticked network,
  /// a likeness per network beside the composer, and every post under tiles.
  group('posts layout A', () {
    final xPostable = {
      ..._xAccount,
      'can_publish': true,
      'cannot_publish_because': null,
    };
    const linkedInPostable = {
      'id': 'conn-6',
      'display_name': 'Everdoors on LinkedIn',
      'provider': 'linkedin',
      'provider_label': 'LinkedIn',
      'handle': null,
      'can_publish': true,
      'cannot_publish_because': null,
      'posting_note': null,
    };

    testWidgets('a body over a ticked network’s limit is red and blocks '
        'Publish, and unticking that network lets it go', (tester) async {
      await _pumpSocial(
        tester,
        _handler(
          canEdit: true,
          connections: [xPostable, _pinterestBoard],
        ),
      );
      await _openCompose(tester);

      await tester.tap(find.widgetWithText(FilterChip, 'Everdoors HQ'));
      await tester.tap(find.widgetWithText(FilterChip, 'Everdoors Boards'));
      await tester.pumpAndSettle();
      await tester.enterText(
        find.widgetWithText(TextField, 'What should go out?'),
        'a' * 281,
      );
      await tester.pumpAndSettle();

      final x = tester.widget<Text>(find.byKey(const ValueKey('social-count-x')));
      expect(x.data, 'X 281/280');
      expect(x.style?.fontWeight, FontWeight.w700);
      // Under Pinterest's 800: counted against it, not warned.
      expect(
        tester
            .widget<Text>(find.byKey(const ValueKey('social-count-pinterest')))
            .data,
        'Pinterest 281/800',
      );
      expect(_publishRefusal(tester), contains('X takes 280 characters'));
      expect(
        tester
            .widget<FilledButton>(
              find.widgetWithText(FilledButton, 'Publish now'),
            )
            .onPressed,
        isNull,
      );

      await tester.tap(find.widgetWithText(FilterChip, 'Everdoors HQ'));
      await tester.pumpAndSettle();

      expect(find.byKey(const ValueKey('social-count-x')), findsNothing);
      expect(_publishRefusal(tester), isNull);
    });

    testWidgets('the preview draws every ticked network at once', (tester) async {
      await _pumpSocial(
        tester,
        _handler(
          canEdit: true,
          connections: const [_pinterestBoard, linkedInPostable],
        ),
      );
      await _openCompose(tester);

      expect(find.textContaining('Tick an account to see'), findsOneWidget);

      await tester.tap(find.widgetWithText(FilterChip, 'Everdoors Boards'));
      await tester.tap(find.widgetWithText(FilterChip, 'Everdoors on LinkedIn'));
      await tester.enterText(
        find.widgetWithText(TextField, 'What should go out?'),
        'Autumn sweeps.',
      );
      await tester.pumpAndSettle();

      // Each ticked network in its own shape, side by side (UI queue 76).
      final pinterest = find.byKey(const ValueKey('social-likeness-pinterest'));
      expect(pinterest, findsOneWidget);
      expect(
        find.descendant(
          of: pinterest,
          matching: find.text('14 / 800 characters'),
        ),
        findsOneWidget,
      );

      final linkedIn = find.byKey(const ValueKey('social-likeness-linkedin'));
      expect(linkedIn, findsOneWidget);
      expect(
        find.descendant(
          of: linkedIn,
          matching: find.text('Everdoors on LinkedIn'),
        ),
        findsOneWidget,
      );
      expect(
        find.descendant(of: linkedIn, matching: find.text('Autumn sweeps.')),
        findsOneWidget,
      );
      expect(
        find.descendant(
          of: linkedIn,
          matching: find.text('14 / 3000 characters'),
        ),
        findsOneWidget,
      );
      expect(find.textContaining('A close likeness'), findsOneWidget);
    });

    testWidgets('the tiles carry the server’s counts, and a tile asks the '
        'server for its posts', (tester) async {
      final asked = <String?>[];
      Map<String, Object?> post(String id, String status) => {
        ..._landed(status, failure: status == 'failed' ? 'No boards.' : null),
        'id': id,
        'body': 'Post $id',
      };
      final list = [
        post('s1', 'sent'),
        post('s2', 'sent'),
        post('f1', 'failed'),
        post('c1', 'scheduled'),
      ];
      final inner = _handler(canEdit: true, connections: const [_pinterestBoard]);

      await _pumpSocial(tester, (request) async {
        if (request.method == 'GET' &&
            request.url.path.endsWith('/social/posts')) {
          final status = request.url.queryParameters['status'];
          asked.add(status);

          // Thirty-seven sent across the whole history; twenty drawn.
          return _json({
            'data': [
              for (final p in list)
                if (status == null || _tileOf(p) == status) p,
            ],
            'meta': {
              'tiles': {'scheduled': 1, 'sent': 37, 'failed': 1},
            },
          });
        }

        return inner(request);
      });

      String count(String key) => tester
          .widget<Text>(
            find.descendant(
              of: find.byKey(Key('social-posts-tile-$key')),
              matching: find.byWidgetPredicate(
                (w) => w is Text && RegExp(r'^\d+$').hasMatch(w.data ?? ''),
              ),
            ),
          )
          .data!;

      expect(count('sent'), '37');
      expect(count('failed'), '1');
      expect(count('scheduled'), '1');
      expect(find.byKey(const ValueKey('social-post-s1')), findsOneWidget);

      await tester.tap(find.byKey(const Key('social-posts-tile-failed')));
      await tester.pumpAndSettle();

      expect(asked.last, 'failed');
      expect(find.byKey(const ValueKey('social-post-f1')), findsOneWidget);
      expect(find.byKey(const ValueKey('social-post-s1')), findsNothing);
      expect(find.byKey(const ValueKey('social-post-c1')), findsNothing);
      expect(find.textContaining('No boards.'), findsOneWidget);

      // Tapping it again shows everything.
      await tester.tap(find.byKey(const Key('social-posts-tile-failed')));
      await tester.pumpAndSettle();
      expect(find.byKey(const ValueKey('social-post-s1')), findsOneWidget);
    });

    // UI queue 100: an All tile, and a post some accounts took and one
    // refused says so rather than reading as a plain failure.
    testWidgets('All counts every tile, and a part-sent post is Partly '
        'posted', (tester) async {
      final sent = _landed('sent');
      final failed = _landed('failed', failure: 'No boards.');
      final partly = {
        ...sent,
        'id': 'p1',
        'body': 'Post p1',
        'targets': [
          ...(sent['targets'] as List<dynamic>),
          ...(failed['targets'] as List<dynamic>),
        ],
      };
      final inner = _handler(canEdit: true, connections: const [_pinterestBoard]);

      await _pumpSocial(tester, (request) async {
        if (request.method == 'GET' &&
            request.url.path.endsWith('/social/posts')) {
          return _json({
            'data': [partly],
            'meta': {
              'tiles': {'scheduled': 1, 'sent': 37, 'failed': 1},
            },
          });
        }

        return inner(request);
      });

      expect(
        find.descendant(
          of: find.byKey(const Key('social-posts-tile-all')),
          matching: find.text('39'),
        ),
        findsOneWidget,
      );
      expect(find.text('Partly posted · 1 of 2'), findsOneWidget);

      // Board puts it under Posted, not Failed.
      await tester.tap(find.text('Board'));
      await tester.pumpAndSettle();
      expect(
        find.descendant(
          of: find.byKey(const ValueKey('social-board-posted')),
          matching: find.byKey(const ValueKey('social-post-p1')),
        ),
        findsOneWidget,
      );
    });

    testWidgets('Retry on a failed row sends that account again', (
      tester,
    ) async {
      var retried = false;

      await _pumpSocial(
        tester,
        _retrying(
          _handler(
            canEdit: true,
            connections: const [_pinterestBoard],
            postsFor: () => [
              retried
                  ? _landed('queued')
                  : _landed('failed', failure: 'Pinterest did not answer.'),
            ],
          ),
          onRetry: () => retried = true,
        ),
      );

      await tester.tap(find.byKey(const ValueKey('social-retry-post-1')));
      await tester.pumpAndSettle();

      expect(retried, isTrue);
      expect(find.text('Posting… 0 of 1 done'), findsOneWidget);
    });

    // UI queue 100: a post's panel deletes from a network that allows it,
    // says so for one that does not, and cancels a post still waiting.
    testWidgets('the post panel deletes from the network, and cancels a '
        'scheduled post', (tester) async {
      final calls = <String>[];
      final posted = _landed('sent', url: 'https://pin.example/1');
      final both = {
        ...posted,
        'targets': [
          // The server says which can be taken down; the screen asks no list.
          {
            ...((posted['targets'] as List<dynamic>).single
                as Map<String, Object?>),
            'can_delete': true,
          },
          {
            'id': 'target-2',
            'account': '@everdoors',
            'provider': 'instagram_professional',
            'provider_label': 'Instagram',
            'status': 'sent',
            'can_delete': false,
          },
        ],
      };
      final later = {
        ..._landed('scheduled', scheduledFor: '2030-01-02 09:00'),
        'id': 'post-2',
      };
      final inner = _handler(
        canEdit: true,
        connections: const [_pinterestBoard],
        postsFor: () => [both, later],
      );

      await _pumpSocial(tester, (request) async {
        if (request.method == 'DELETE') {
          calls.add(request.url.path);
          return _json({'data': <String, Object?>{}});
        }
        return inner(request);
      });

      await tester.tap(find.byKey(const ValueKey('social-post-post-1')));
      await tester.pumpAndSettle();

      expect(
        find.text(
          'Instagram does not let EzBizi delete posts. Delete it in '
          'Instagram itself.',
        ),
        findsOneWidget,
      );
      expect(
        find.byKey(const ValueKey('social-target-delete-target-2')),
        findsNothing,
      );
      expect(find.byKey(const Key('social-post-cancel')), findsNothing);

      await tester.tap(
        find.byKey(const ValueKey('social-target-delete-target-1')),
      );
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const Key('social-confirm-yes')));
      await tester.pumpAndSettle();

      expect(calls, [
        endsWith('/social/posts/post-1/targets/target-1/remote'),
      ]);

      await tester.tap(find.byKey(const ValueKey('social-post-post-2')));
      await tester.pumpAndSettle();
      expect(find.byKey(const Key('social-post-edit')), findsOneWidget);
      await tester.tap(find.byKey(const Key('social-post-cancel')));
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const Key('social-confirm-yes')));
      await tester.pumpAndSettle();

      expect(calls.last, endsWith('/social/posts/post-2'));
    });
  });

  /// Each pane follows its own grant (Jose, 2026-09-29: tabs grantable "like
  /// all the others"). The server refuses what the screen hides; these assert
  /// the screen neither offers a pane nor ASKS for data it cannot have — a
  /// request made only to be refused would also draw an error over the pane
  /// the role can use.
  group('panes follow their own grants', () {
    MockClientHandler recording(MockClientHandler inner, List<String> paths) =>
        (request) {
          paths.add('${request.method} ${request.url.path}');
          return inner(request);
        };

    testWidgets('Social Media with only Accounts shows only Accounts and never asks for posts', (
      tester,
    ) async {
      final paths = <String>[];
      await _pumpSocial(
        tester,
        recording(_handler(tabs: const ['marketing.accounts.view']), paths),
      );

      expect(find.widgetWithText(ChoiceChip, 'Accounts'), findsOneWidget);
      // The three that were Posts (UI queue 76).
      for (final pane in ['Dashboard', 'Compose', 'Calendar']) {
        expect(find.widgetWithText(ChoiceChip, pane), findsNothing);
      }
      expect(paths.where((p) => p.endsWith('/social/posts')), isEmpty,
          reason: 'The post list was requested without a post pane.');
    });

    // The control for the test above, in its own test: a second pump in the
    // same test reuses the State, so initState would not run and "no request"
    // would be true for a reason unrelated to the grant.
    testWidgets('with Posts granted the post list IS asked for', (tester) async {
      final paths = <String>[];
      await _pumpSocial(tester, recording(_handler(), paths));

      expect(paths.where((p) => p.endsWith('/social/posts')), isNotEmpty);
    });

    // Each of the three post panes is its own grant since UI queue 76, and
    // each reads the list; the dashboard offers New post only with Compose.
    testWidgets('with only Dashboard the list is read and New post is not '
        'offered', (tester) async {
      final paths = <String>[];
      await _pumpSocial(
        tester,
        recording(_handler(tabs: const ['marketing.dashboard.view']), paths),
      );

      expect(find.widgetWithText(ChoiceChip, 'Compose'), findsNothing);
      expect(find.text('Timeline'), findsOneWidget);
      expect(find.widgetWithText(FilledButton, 'New post'), findsNothing);
      expect(paths.where((p) => p.endsWith('/social/posts')), isNotEmpty);
    });

    testWidgets('Newsletter with only Dashboard shows only Dashboard', (tester) async {
      await _pumpNewsletter(
        tester,
        _handler(tabs: const ['newsletter.dashboard.view'], newsletters: const [_draft]),
      );

      expect(find.widgetWithText(ChoiceChip, 'Dashboard'), findsOneWidget);
      expect(find.widgetWithText(ChoiceChip, 'Composer'), findsNothing);
      expect(find.widgetWithText(ChoiceChip, 'Settings'), findsNothing);
    });

    testWidgets('Newsletter with only Settings asks for no mailings and shows no error', (
      tester,
    ) async {
      final paths = <String>[];
      await _pumpNewsletter(
        tester,
        recording(_handler(tabs: const ['newsletter.settings.view']), paths),
      );

      expect(find.widgetWithText(ChoiceChip, 'Settings'), findsOneWidget);
      expect(paths.where((p) => p.endsWith('/newsletters')), isEmpty,
          reason: 'The list is refused to this role; asking draws an error over Settings.');
      expect(paths.where((p) => p.endsWith('/newsletter/audiences')), isEmpty);
    });
  });

  /// Delete the open newsletter. `newsletter.delete` was declared and grantable
  /// with nothing to press; the server's destroy() existed all along.
  group('deleting a newsletter', () {
    testWidgets('asks first, then sends the DELETE', (tester) async {
      final paths = <String>[];
      await _pumpComposer(
        tester,
        (request) {
          paths.add('${request.method} ${request.url.path}');
          if (request.method == 'DELETE') {
            return Future.value(http.Response(jsonEncode({'data': {'id': 'n-1'}}), 200,
                headers: {'content-type': 'application/json'}));
          }
          return _handler(canEdit: true, newsletters: const [_draft])(request);
        },
      );

      await tester.tap(find.widgetWithText(TextButton, 'Delete'));
      await tester.pumpAndSettle();

      // Nothing sent yet: the first tap only arms it.
      expect(paths.where((p) => p.startsWith('DELETE')), isEmpty);

      await tester.tap(find.widgetWithText(TextButton, 'Tap again to delete'));
      await tester.pumpAndSettle();

      expect(paths.where((p) => p.startsWith('DELETE') && p.contains('/newsletters/')), hasLength(1));
    });

    testWidgets('is not offered to a role without the delete permission', (tester) async {
      await _pumpComposer(tester, _handler(newsletters: const [_draft]));

      final button = tester.widget<TextButton>(find.widgetWithText(TextButton, 'Delete'));
      expect(button.onPressed, isNull);
    });
  });

  /// Newsletter — layout 3, and the promises it makes.
  ///
  /// The preview is the whole reason this layout was chosen: a newsletter is
  /// the one artefact in the product that looks nothing like its editor, and a
  /// form that yields an email you cannot see until you send it is how a broken
  /// mailing reaches twelve hundred people.
  group('newsletter', () {
    testWidgets('the email is shown beside the editor, from the server', (
      tester,
    ) async {
      await _pumpComposer(
        tester,
        _handler(
          canEdit: true,
          newsletters: const [_draft],
          previewHtml: '<h1>Autumn sweeps</h1>',
        ),
      );

      // The email is never out of sight: drawn from the blocks by default
      // (layout A, 2026-10-02), the server's own HTML one switch away.
      expect(find.text('Newsletter'), findsWidgets);
      expect(find.text('What they will get'), findsOneWidget);
      expect(find.byType(NewsletterEmail), findsOneWidget);

      await tester.tap(find.widgetWithText(FilterChip, 'Server preview'));
      await tester.pumpAndSettle();

      // And it is the SERVER's HTML in the viewer the Email module uses, not
      // markup this screen built. A Dart renderer would drift from the one
      // that makes the real email.
      expect(find.byType(MessageHtmlView), findsOneWidget);
      expect(
        tester.widget<MessageHtmlView>(find.byType(MessageHtmlView)).html,
        contains('Autumn sweeps'),
      );
    });

    /*
     * The preview is given ROOM, which the test above cannot see.
     *
     * That one asserts the server's HTML reaches `MessageHtmlView`, and it
     * passed throughout a period when the pane was blank on the web for every
     * user. It could not have caught it: on the test platform
     * `message_html_stub.dart` is a `SizedBox.shrink()`, so the widget paints
     * nothing here whether the real one works or not. A test that stops at
     * "the string arrived" is testing the wiring and calling it the feature.
     *
     * What was wrong was the CARD. `DashboardCard` sizes to its contents
     * unless `expand` is set, and the web implementation is an iframe inside
     * `SizedBox.expand`, which collapses when its height is unbounded. Header
     * drawn, body zero-high, nothing thrown.
     *
     * So this asserts the one thing that is true on every platform and would
     * have gone red: the constraints handed to the viewer are bounded and
     * tall. The stub still paints nothing — that is not what is being
     * measured.
     */
    /*
     * "Saved" describes the OPEN newsletter, so with none open it says nothing.
     *
     * UI/UX, Android audit 2026-09-28: an empty composer read "No newsletters
     * in … yet.  Saved  [+ New]" — a status with no noun. Both sides asserted,
     * because the absence alone passes for a toolbar that has stopped showing
     * the save state at all.
     *
     * Two tests rather than one with two pumps: a second pump in the same test
     * reuses the State, so the "open" half inherited the empty half's null
     * selection and failed for a reason that had nothing to do with the toolbar.
     */
    testWidgets('the save state is absent when nothing is open', (tester) async {
      await _pumpComposer(tester, _handler(canEdit: true));

      expect(find.text('Saved'), findsNothing,
          reason: 'Nothing is open, so there is nothing to have saved.');
    });

    testWidgets('the save state shows once a newsletter is open', (tester) async {
      await _pumpComposer(
        tester,
        _handler(canEdit: true, newsletters: const [_draft]),
      );

      expect(find.text('Saved'), findsOneWidget,
          reason: 'A newsletter is open and saved; the state must show.');
    });

    testWidgets('a block written as HTML is drawn, not described', (
      tester,
    ) async {
      await tester.pumpWidget(
        const MaterialApp(
          home: Scaffold(
            body: EmailBlockView(
              block: {'type': 'text', 'html': '<p>Hand-made sweep offer</p>'},
            ),
          ),
        ),
      );
      await tester.pumpAndSettle();

      expect(
        find.textContaining('Hand-made sweep offer', findRichText: true),
        findsOneWidget,
      );
    });

    testWidgets('the preview is given the height its viewer needs', (
      tester,
    ) async {
      await _pumpComposer(
        tester,
        _handler(
          canEdit: true,
          newsletters: const [_draft],
          previewHtml: '<h1>Autumn sweeps</h1>',
        ),
      );

      await tester.tap(find.widgetWithText(FilterChip, 'Server preview'));
      await tester.pumpAndSettle();

      final box = tester.renderObject<RenderBox>(find.byType(MessageHtmlView));

      expect(
        box.constraints.hasBoundedHeight,
        isTrue,
        reason: 'An unbounded height is what let the iframe collapse to zero.',
      );
      expect(
        box.constraints.maxHeight,
        greaterThan(200),
        reason: 'Bounded but tiny would draw a sliver and read as blank too.',
      );
    });

    testWidgets('Send says which of its reasons is stopping it', (
      tester,
    ) async {
      // No permission is the first refusal, because it is the one nothing on
      // the screen can fix.
      await _pumpComposer(
        tester,
        _handler(newsletters: const [_draft]),
      );

      expect(
        _refusalOn(tester, 'Send now'),
        contains('do not have permission to send a newsletter'),
      );
    });

    testWidgets('with no mailing account it carries the server sentence', (
      tester,
    ) async {
      await _pumpComposer(
        tester,
        _handler(
          canEdit: true,
          newsletters: const [_draft],
          sendingRefusedBecause:
              'No mailing account is connected in Everdoors, so there is '
              'nowhere to send from.',
        ),
      );

      // The server's words, not the screen's — the same rule the social
      // composer follows, so the two cannot drift apart.
      expect(
        _refusalOn(tester, 'Send now'),
        contains('nowhere to send from'),
      );
    });

    testWidgets('with an account but no list it asks for the list', (
      tester,
    ) async {
      await _pumpComposer(
        tester,
        _handler(
          canEdit: true,
          newsletters: const [_draftWithoutAudience],
          audiences: const [_audience],
        ),
      );

      expect(_refusalOn(tester, 'Send now'), contains('Choose who this goes to'));
    });

    // UI queue 173: "+ New list" opens a side panel, posts the list (the tag
    // only when typed), and the new list is picked with its tag in the label.
    for (final tag in ['fall-sweep', '']) {
      testWidgets('a new list is created and picked (tag "$tag")', (
        tester,
      ) async {
        Map<String, dynamic>? posted;
        final base = _handler(
          canEdit: true,
          newsletters: const [_draftWithoutAudience],
          audiences: const [_audience],
        );
        await _pumpComposer(tester, (request) async {
          final path = request.url.path;
          if (path.endsWith('/newsletter/mailchimp/lists')) {
            return _json({
              'data': [
                {'id': 'mc-9', 'name': 'Brightside main list', 'members': 40},
              ],
            });
          }
          if (request.method == 'POST' &&
              path.endsWith('/newsletter/audiences')) {
            posted = jsonDecode(request.body) as Map<String, dynamic>;
            return http.Response(
              jsonEncode({
                'data': {'id': 'a-new', 'name': 'Fall', 'kind': 'customers'},
              }),
              201,
              headers: {'content-type': 'application/json'},
            );
          }
          return base(request);
        });

        await tester.tap(find.byKey(const ValueKey('newsletter-new-list')));
        await tester.pumpAndSettle();
        expect(find.byType(SidePanel), findsOneWidget);

        await tester.enterText(find.byKey(const ValueKey('new-list-name')), 'Fall');
        await tester.tap(find.byKey(const ValueKey('new-list-mailchimp')));
        await tester.pumpAndSettle();
        await tester.tap(find.text('Brightside main list').last);
        await tester.pumpAndSettle();
        await tester.enterText(find.byKey(const ValueKey('new-list-tag')), tag);
        await tester.tap(find.byKey(const ValueKey('new-list-create')));
        await tester.pumpAndSettle();

        expect(posted, isNotNull);
        expect(posted!['name'], 'Fall');
        expect(posted!['kind'], 'customers');
        expect(posted!['mailchimp_list_id'], 'mc-9');
        expect(posted!.containsKey('tag'), tag.isNotEmpty);
        if (tag.isNotEmpty) expect(posted!['tag'], tag);

        expect(find.byType(SidePanel), findsNothing);
        final label = tag.isEmpty ? 'Fall · built from your customers'
            : 'Fall · $tag · built from your customers';
        expect(find.text(label), findsOneWidget);
      });
    }

    testWidgets('a block can be added, and columns are offered', (
      tester,
    ) async {
      await _pumpComposer(
        tester,
        _handler(canEdit: true, newsletters: const [_draft]),
      );

      // The full vocabulary the server can render, columns included — Jose
      // asked for columns specifically.
      for (final label in ['Heading', 'Text', 'Image', 'Button', 'Columns']) {
        expect(
          find.widgetWithText(OutlinedButton, label),
          findsOneWidget,
          reason: '$label is missing from the block palette.',
        );
      }
    });

    testWidgets('HTML mode replaces the blocks and says so', (tester) async {
      await _pumpComposer(
        tester,
        _handler(canEdit: true, newsletters: const [_draft]),
      );

      await tester.tap(find.text('Write HTML'));
      await tester.pumpAndSettle();

      // Two sources of truth for one email is how somebody edits the markup,
      // adds a block, and silently loses the markup. The screen says which
      // wins rather than leaving it to be discovered.
      expect(find.textContaining('it is what goes out'), findsOneWidget);
    });

    // ── Newsletter audit 2026-11-10 (Rodrigo) ─────────────────────

    testWidgets('the composer says which version goes out, and Back to blocks '
        'clears the HTML with an Undo', (tester) async {
      final puts = <Map<String, dynamic>>[];
      final base = _handler(canEdit: true, newsletters: const [_draftWithHtml]);
      await _pumpComposer(tester, (request) async {
        if (request.method == 'PUT') {
          puts.add(jsonDecode(request.body) as Map<String, dynamic>);
        }
        return base(request);
      });

      expect(find.byKey(const ValueKey('newsletter-mode')), findsOneWidget);
      expect(find.text('Sending: custom HTML'), findsOneWidget);

      await tester.tap(find.text('Back to blocks'));
      await tester.pumpAndSettle(const Duration(seconds: 1));
      expect(find.text('Sending: the blocks'), findsOneWidget);
      expect(puts.last['html_override'], anyOf(isNull, ''));

      await tester.tap(find.byKey(const ValueKey('newsletter-undo')));
      await tester.pumpAndSettle(const Duration(seconds: 1));
      expect(find.text('Sending: custom HTML'), findsOneWidget);
      expect(puts.last['html_override'], '<p>Mine</p>');
    });

    testWidgets('pasted HTML is checked, previewed, refused with a script, and '
        'undoable once used', (tester) async {
      await _pumpComposer(
        tester,
        _handler(canEdit: true, newsletters: const [_draft]),
      );

      await tester.tap(find.byKey(const ValueKey('newsletter-import')));
      await tester.pumpAndSettle();

      await tester.tap(find.byKey(const ValueKey('newsletter-import-check')));
      await tester.pumpAndSettle();
      expect(find.text('There is no HTML to import.'), findsOneWidget);

      await tester.enterText(
        find.byKey(const ValueKey('newsletter-import-source')),
        '<p>x</p><script>alert(1)</script>',
      );
      await tester.tap(find.byKey(const ValueKey('newsletter-import-check')));
      await tester.pumpAndSettle();
      expect(find.byKey(const ValueKey('newsletter-import-error')), findsOneWidget);
      expect(find.byKey(const ValueKey('newsletter-import-use')), findsNothing);

      await tester.enterText(
        find.byKey(const ValueKey('newsletter-import-source')),
        '<p>Imported</p>',
      );
      await tester.tap(find.byKey(const ValueKey('newsletter-import-check')));
      await tester.pumpAndSettle();
      expect(
        tester
            .widgetList<MessageHtmlView>(find.byType(MessageHtmlView))
            .any((v) => v.html == '<p>Imported</p>'),
        isTrue,
      );
      expect(find.textContaining('unsubscribe footer'), findsWidgets);

      await tester.tap(find.byKey(const ValueKey('newsletter-import-use')));
      await tester.pumpAndSettle();
      expect(find.text('Sending: custom HTML'), findsOneWidget);

      await tester.tap(find.byKey(const ValueKey('newsletter-undo')));
      await tester.pumpAndSettle();
      expect(find.text('Sending: the blocks'), findsOneWidget);
      expect(find.text('Autumn sweeps'), findsWidgets);
    });

    testWidgets('an SVG design becomes editable blocks', (tester) async {
      final base = _handler(canEdit: true, newsletters: const [_draft]);
      await _pumpComposer(tester, (request) async {
        if (request.url.path.endsWith('/newsletter/import/svg')) {
          return _json({
            'data': {
              'blocks': [
                {'type': 'heading', 'text': 'Spring sale', 'size': 32, 'align': 'center'},
                {'type': 'button', 'text': 'Book now', 'url': 'https://e.com', 'align': 'center'},
              ],
              'artwork_svg': null,
              'alt': 'Spring',
              'warnings': ['Custom fonts are not carried over.'],
            },
          });
        }
        return base(request);
      });

      await tester.tap(find.byKey(const ValueKey('newsletter-import')));
      await tester.pumpAndSettle();
      await tester.tap(find.text('SVG design'));
      await tester.pumpAndSettle();
      await tester.enterText(
        find.byKey(const ValueKey('newsletter-import-source')),
        '<svg xmlns="http://www.w3.org/2000/svg"><text>Spring sale</text></svg>',
      );
      await tester.tap(find.byKey(const ValueKey('newsletter-import-check')));
      await tester.pumpAndSettle();
      expect(find.textContaining('Custom fonts'), findsOneWidget);

      await tester.tap(find.byKey(const ValueKey('newsletter-import-use')));
      await tester.pumpAndSettle();
      expect(find.byKey(const ValueKey('newsletter-import-panel')), findsNothing);
      expect(find.text('Spring sale'), findsOneWidget);
      expect(
        find.descendant(
          of: find.byType(NewsletterEmail),
          matching: find.text('Autumn sweeps'),
        ),
        findsNothing,
      );

      await tester.tap(find.byKey(const ValueKey('newsletter-undo')));
      await tester.pumpAndSettle();
      expect(
        find.descendant(
          of: find.byType(NewsletterEmail),
          matching: find.text('Autumn sweeps'),
        ),
        findsOneWidget,
      );
    });

    testWidgets('SVG artwork is rasterised, uploaded and placed first with '
        'its alt text', (tester) async {
      Map<String, dynamic>? uploaded;
      List<Map<String, dynamic>>? used;
      final api = ApiClient(client: MockClient((request) async {
        if (request.url.path.endsWith('/newsletter/import/svg')) {
          return _json({
            'data': {
              'blocks': [
                {'type': 'text', 'text': 'Hello', 'align': 'center'},
              ],
              'artwork_svg': '<svg xmlns="http://www.w3.org/2000/svg" width="4" height="4"><rect width="4" height="4"/></svg>',
              'alt': 'Spring art',
              'warnings': [],
            },
          });
        }
        if (request.url.path.endsWith('/newsletter/import/artwork')) {
          uploaded = jsonDecode(request.body) as Map<String, dynamic>;
          return _json({
            'data': {'url': 'https://app.test/api/v1/newsletter/artwork/o/x.png'},
          }, 201);
        }
        return _json({}, 404);
      }));
      await tester.pumpWidget(MaterialApp(
        home: Scaffold(
          body: SingleChildScrollView(
            child: NewsletterImportPanel(
              api: api,
              rasterise: (svg, width) async => Uint8List.fromList([1, 2, 3]),
              onUseHtml: (_) {},
              onUseBlocks: (b) => used = b,
              onClose: () {},
            ),
          ),
        ),
      ));
      await tester.tap(find.text('SVG design'));
      await tester.pumpAndSettle();
      await tester.enterText(
        find.byKey(const ValueKey('newsletter-import-source')),
        '<svg/>',
      );
      await tester.tap(find.byKey(const ValueKey('newsletter-import-check')));
      await tester.pumpAndSettle();
      await tester.ensureVisible(find.byKey(const ValueKey('newsletter-import-use')));
      await tester.tap(find.byKey(const ValueKey('newsletter-import-use')));
      await tester.pumpAndSettle();

      expect(uploaded!['png'], base64Encode([1, 2, 3]));
      expect(used!.first, {
        'type': 'image',
        'src': 'https://app.test/api/v1/newsletter/artwork/o/x.png',
        'alt': 'Spring art',
      });
      expect(used![1]['text'], 'Hello');
    });

    testWidgets('preflight lists the checks and a test goes to the one typed '
        'address', (tester) async {
      Map<String, dynamic>? tested;
      final base = _handler(
        canEdit: true,
        newsletters: const [_draft],
        audiences: const [_audience],
      );
      await _pumpComposer(tester, (request) async {
        final path = request.url.path;
        if (path.endsWith('/preflight')) {
          return _json({
            'data': {
              'checks': [
                {'key': 'footer', 'ok': true, 'label': 'Address and unsubscribe footer', 'detail': '1 Main St'},
                {'key': 'from_name', 'ok': true, 'label': 'From name', 'detail': 'A-1 Chimney'},
              ],
            },
          });
        }
        if (path.endsWith('/n-1/test')) {
          tested = jsonDecode(request.body) as Map<String, dynamic>;
          return _json({'data': {'sent_to': 'me@example.com'}});
        }
        return base(request);
      });

      expect(find.byKey(const ValueKey('newsletter-audience-explainer')), findsOneWidget);
      await tester.ensureVisible(find.byKey(const ValueKey('newsletter-test-email')));
      await tester.enterText(find.byKey(const ValueKey('newsletter-test-email')), 'me@example.com');
      await tester.tap(find.byKey(const ValueKey('newsletter-test-send')));
      await tester.pumpAndSettle(const Duration(seconds: 1));

      expect(find.textContaining('Address and unsubscribe footer: 1 Main St'), findsOneWidget);
      expect(find.textContaining('From name: A-1 Chimney'), findsOneWidget);
      expect(tested, {'email': 'me@example.com'});
      expect(find.textContaining('Test sent to me@example.com'), findsOneWidget);
    });

    testWidgets('the chosen list shows which Mailchimp list it sends through', (
      tester,
    ) async {
      await _pumpComposer(
        tester,
        _handler(
          canEdit: true,
          newsletters: const [_draft],
          audiences: const [
            {'id': 'a-1', 'name': 'Customers', 'kind': 'customers', 'mailchimp_list_id': 'mc-9'},
          ],
        ),
      );

      expect(
        find.text('Customers (EzBizi, built from your customers) → Mailchimp list mc-9'),
        findsOneWidget,
      );
    });

    testWidgets('it lays out on a 375-point window without overflowing', (
      tester,
    ) async {
      await _pumpComposer(
        tester,
        _handler(canEdit: true, newsletters: const [_draft]),
        size: const Size(375, 2400),
      );

      expect(tester.takeException(), isNull);

      // Not merely "no exception" — a screen that rendered nothing throws
      // nothing either. The preview is still there, below rather than beside,
      // because a newsletter you cannot see is what this layout prevents.
      expect(find.text('What they will get'), findsOneWidget);
    });
  });


  /// The dashboard and the settings chip.
  ///
  /// Newsletter opens on the Dashboard, deliberately: "what is going out, and
  /// when" is what somebody arriving wants, and an empty composer answers no
  /// question. The calendar is the point — a scheduled mailing is invisible
  /// until it lands in an inbox, and this is the only place that shows the
  /// month.
  group('newsletter dashboard and settings', () {
    testWidgets('it opens on the dashboard with the numbers the server gave', (
      tester,
    ) async {
      await _pumpNewsletter(
        tester,
        _handler(
          canEdit: true,
          newsletters: const [_draft],
          stats: const {
            'drafts': 3,
            'scheduled': 2,
            'sent': 7,
            'recipients': 1240,
            'last_sent_at': '2026-09-01T10:00:00Z',
          },
        ),
      );

      expect(find.widgetWithText(ChoiceChip, 'Dashboard'), findsOneWidget);
      expect(find.widgetWithText(ChoiceChip, 'Composer'), findsOneWidget);
      expect(find.widgetWithText(ChoiceChip, 'Settings'), findsOneWidget);

      // The server's counts, not a count of the fifty rows the list carries.
      // Scoped to the stats card: a bare `find.text('7')` also matches the
      // calendar's seventh day, which is what this assertion tripped on first.
      // Scoped to the tiles (layout A, 2026-10-02): a bare `find.text('7')`
      // also matches the calendar's seventh day.
      expect(
        find.descendant(of: find.byKey(const Key('newsletters-tile-sent')), matching: find.text('7')),
        findsOneWidget,
      );
      expect(
        find.descendant(
          of: find.byKey(const Key('newsletters-fact-reached')),
          matching: find.text('1240'),
        ),
        findsOneWidget,
      );

      // Named honestly. EzBizi knows what the provider ACCEPTED; "delivered"
      // would be a claim it cannot stand behind.
      expect(find.text('ADDRESSES REACHED'), findsOneWidget);
      expect(find.textContaining('delivered'), findsNothing);
    });

    testWidgets('a scheduled mailing sits on its day and opens for editing', (
      tester,
    ) async {
      final now = DateTime.now();
      final day = DateTime(now.year, now.month, 15);
      final wall =
          '${day.year}-${_two(day.month)}-${_two(day.day)} 09:00';

      await _pumpNewsletter(
        tester,
        _handler(
          canEdit: true,
          newsletters: [
            {
              'id': 'n-9',
              'subject': 'Autumn sweeps',
              'status': 'scheduled',
              'scheduled_for': wall,
              'audience': {'id': 'a-1', 'name': 'Customers', 'kind': 'customers'},
              'blocks': const [
                {'type': 'text', 'text': 'Words.', 'align': 'left'},
              ],
            },
          ],
        ),
      );

      // On the calendar, in the current month, by subject.
      expect(find.text('Scheduled'), findsWidgets);
      // In the calendar; the table beside it carries the subject too.
      expect(_inCalendar('Autumn sweeps'), findsOneWidget);

      // "Review them or edit them" — tapping it lands in the composer with
      // that mailing open. A date with no way in is a poster.
      await tester.tap(_inCalendar('Autumn sweeps'));
      await tester.pumpAndSettle();

      expect(find.text('What they will get'), findsOneWidget);
      expect(
        tester.widget<TextField>(find.widgetWithText(TextField, 'Subject')).controller?.text,
        'Autumn sweeps',
      );
    });

    /// The composer used to promise the PLACE's clock and deliver whichever
    /// clock the cascade resolved.
    ///
    /// "The time you pick is the time it goes out there" is true at franchise
    /// scope, where the branch carries its own zone. Above it the default is
    /// `Etc/GMT+5` — a fixed offset that stands still while the place moves an
    /// hour in March, so the mailing goes out an hour late until November with
    /// the screen still saying it went out at nine.
    ///
    /// The fix is not to pick a zone here — that is a platform decision. It is
    /// to stop hiding which one you got.
    testWidgets('the composer names the clock a scheduled mailing is on', (
      tester,
    ) async {
      final now = DateTime.now();
      final day = DateTime(now.year, now.month, 15);
      final wall = '${day.year}-${_two(day.month)}-${_two(day.day)} 09:00';

      await _pumpNewsletter(
        tester,
        _handler(
          canEdit: true,
          newsletters: [
            {
              'id': 'n-9',
              'subject': 'Autumn sweeps',
              'status': 'scheduled',
              'scheduled_for': wall,
              'scheduled_timezone': 'Etc/GMT+5',
              'audience': {'id': 'a-1', 'name': 'Customers', 'kind': 'customers'},
              'blocks': const [
                {'type': 'text', 'text': 'Words.', 'align': 'left'},
              ],
            },
          ],
        ),
      );

      // The calendar carries it too, so the clock is readable without
      // opening the mailing at all. It lives in the cell's tooltip, which is
      // the only place a month grid has room for a time.
      expect(
        find.byWidgetPredicate(
          (w) =>
              w is Tooltip &&
              (w.message ?? '').contains('(UTC−5, no daylight saving)'),
        ),
        findsOneWidget,
      );

      await tester.tap(_inCalendar('Autumn sweeps'));
      await tester.pumpAndSettle();

      expect(
        find.textContaining(
          'This view’s clock — Everdoors, UTC−5, no daylight saving',
        ),
        findsOneWidget,
      );

      // And the promise it can no longer make.
      expect(find.textContaining('the time it goes out there'), findsNothing);
    });

    /// A draft has no clock yet, so the note may not name one — but it also may
    /// not go back to promising the place's time. It says where the answer will
    /// appear instead.
    testWidgets('a mailing with no clock yet promises no clock', (
      tester,
    ) async {
      await _pumpNewsletter(
        tester,
        _handler(
          canEdit: true,
          newsletters: [
            {
              'id': 'n-1',
              'subject': 'A draft',
              'status': 'draft',
              'audience': {'id': 'a-1', 'name': 'Customers', 'kind': 'customers'},
              'blocks': const [
                {'type': 'text', 'text': 'Words.', 'align': 'left'},
              ],
            },
          ],
        ),
      );

      await tester.tap(find.widgetWithText(ChoiceChip, 'Composer'));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Later'));
      await tester.pumpAndSettle();

      expect(
        find.textContaining('names which one once it is saved'),
        findsOneWidget,
      );
      expect(find.textContaining('the time it goes out there'), findsNothing);
    });

    testWidgets('an empty month says so instead of drawing blank squares', (
      tester,
    ) async {
      await _pumpNewsletter(tester, _handler(canEdit: true));

      expect(find.textContaining('Nothing is scheduled this month'), findsOneWidget);
    });

    testWidgets('settings offers Connect with nothing connected', (
      tester,
    ) async {
      await _pumpNewsletter(tester, _handler(canEdit: true));

      await tester.tap(find.widgetWithText(ChoiceChip, 'Settings'));
      await tester.pumpAndSettle();

      expect(find.text('Mailchimp API key'), findsOneWidget);

      // Dead until a key is typed, and it says why — an empty Connect that
      // fails on the round trip is a slower version of the same refusal.
      expect(
        _refusalOn(tester, 'Connect'),
        contains('Paste the API key'),
      );
    });

    testWidgets('settings shows the connected account and a way out', (
      tester,
    ) async {
      await _pumpNewsletter(
        tester,
        _handler(canEdit: true, connections: const [_mailchimp]),
      );

      await tester.tap(find.widgetWithText(ChoiceChip, 'Settings'));
      await tester.pumpAndSettle();

      expect(find.text('Everdoors Mail'), findsOneWidget);
      expect(find.textContaining('newsletters send through this account'), findsOneWidget);
      expect(find.widgetWithText(TextButton, 'Disconnect'), findsOneWidget);
      // No second Connect form beside a live connection.
      expect(find.text('Mailchimp API key'), findsNothing);
    });
  });

  /// Layout A (Mia, 2026-10-02): tiles over a table on the dashboard, and the
  /// email itself as the editor in the composer.
  group('newsletter layout A', () {
    const sent = {
      'id': 'n-5',
      'subject': 'September specials',
      'status': 'sent',
      'audience': {'id': 'a-1', 'name': 'Customers', 'kind': 'customers'},
      'last_send': {'status': 'sent', 'recipients': 412, 'sent_at': '2026-09-03T14:00:00Z'},
      'blocks': [
        {'type': 'heading', 'text': 'September specials', 'align': 'left'},
      ],
    };
    const scheduled = {
      'id': 'n-9',
      'subject': 'Fall chimney tips',
      'status': 'scheduled',
      'scheduled_for': '2099-10-08 09:00',
      'audience': null,
      'blocks': [],
    };

    // The server already counts and filters `failed`; the dashboard shows it.
    testWidgets('a Failed tile counts failed sends and filters to them', (
      tester,
    ) async {
      final asked = <String>[];
      final inner = _handler(
        canEdit: true,
        newsletters: const [_draft],
        stats: const {
          'drafts': 1,
          'scheduled': 0,
          'sent': 0,
          'failed': 2,
          'recipients': 0,
        },
      );
      await _pumpNewsletter(tester, (request) {
        final status = request.url.queryParameters['status'];
        if (status != null) asked.add(status);
        return inner(request);
      });

      expect(
        find.descendant(
          of: find.byKey(const Key('newsletters-tile-failed')),
          matching: find.text('2'),
        ),
        findsOneWidget,
      );
      await tester.tap(find.byKey(const Key('newsletters-tile-failed')));
      await tester.pumpAndSettle();
      expect(asked, contains('failed'));
    });

    testWidgets('a dashboard tile counts and filters the table', (tester) async {
      final asked = <String>[];
      final inner = _handler(
        canEdit: true,
        newsletters: const [_draft, sent, scheduled],
        stats: const {
          'drafts': 1,
          'scheduled': 1,
          'sent': 1,
          'recipients': 412,
          'last_sent_at': '2026-09-03T14:00:00Z',
        },
      );
      await _pumpNewsletter(tester, (request) {
        final status = request.url.queryParameters['status'];
        if (status != null) {
          asked.add(status);
          return Future.value(_json({
            'data': [
              for (final n in const [_draft, sent, scheduled])
                if (n['status'] == status) n,
            ],
            'sending': {'refused_because': null},
            'stats': const {},
          }));
        }
        return inner(request);
      });

      // Every newsletter, with what each row carries.
      for (final id in ['n-1', 'n-5', 'n-9']) {
        expect(find.byKey(ValueKey('newsletter-$id')), findsOneWidget);
      }
      final sentRow = find.byKey(const ValueKey('newsletter-n-5'));
      expect(find.descendant(of: sentRow, matching: find.text('412')), findsOneWidget);
      expect(find.descendant(of: sentRow, matching: find.text('Customers · built from your customers')),
          findsOneWidget);
      expect(find.descendant(of: sentRow, matching: find.text('View')), findsOneWidget);
      expect(
        find.descendant(of: find.byKey(const Key('newsletters-tile-sent')), matching: find.text('1')),
        findsOneWidget,
      );

      await tester.tap(find.byKey(const Key('newsletters-tile-sent')));
      await tester.pumpAndSettle();

      // Asked of the server: the list is capped at fifty, the tile is not.
      expect(asked, ['sent']);
      expect(find.byKey(const ValueKey('newsletter-n-5')), findsOneWidget);
      expect(find.byKey(const ValueKey('newsletter-n-1')), findsNothing);
      expect(find.byKey(const ValueKey('newsletter-n-9')), findsNothing);

      // Tapping it again clears it.
      await tester.tap(find.byKey(const Key('newsletters-tile-sent')));
      await tester.pumpAndSettle();
      expect(find.byKey(const ValueKey('newsletter-n-1')), findsOneWidget);
    });

    // Review 2026-11-09 C13 (A29): the image block takes a library picture.
    testWidgets('Choose picture puts a library picture in the image block', (
      tester,
    ) async {
      final saved = <Map<String, dynamic>>[];
      final draft = <String, Object?>{
        ..._draft,
        'blocks': [
          {'type': 'image', 'src': '', 'alt': ''},
        ],
      };
      final inner = _handler(canEdit: true, newsletters: [draft]);
      await _pumpComposer(tester, (request) {
        final path = request.url.path;
        if (request.method == 'PUT') {
          saved.add(jsonDecode(request.body) as Map<String, dynamic>);
          return Future.value(_json({'data': draft}));
        }
        if (path.endsWith('/social/library')) {
          return Future.value(
            _json({
              'data': [
                {
                  'id': 'lib-1',
                  'name': 'Van',
                  'kind': 'image',
                  'size': 1000,
                  'scope_type': 'company',
                  'created_at': '2026-10-16T12:00:00Z',
                },
              ],
            }),
          );
        }
        if (path.endsWith('/social/library/lib-1/file')) {
          return Future.value(http.Response('', 404));
        }
        if (path.endsWith('/newsletter/import/library/lib-1')) {
          return Future.value(
            _json({
              'data': {
                'url': 'https://app.example/api/v1/newsletter/artwork/o/x.jpg',
              },
            }, 201),
          );
        }
        return inner(request);
      });

      await tester.tap(find.byKey(const Key('newsletter-block-0')));
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const ValueKey('newsletter-image-library')));
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const ValueKey('library-item-lib-1')));
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const ValueKey('library-use')));
      await tester.pumpAndSettle();
      await tester.pump(const Duration(seconds: 1));
      await tester.pumpAndSettle();

      expect(
        find.widgetWithText(
          TextFormField,
          'https://app.example/api/v1/newsletter/artwork/o/x.jpg',
        ),
        findsOneWidget,
      );
      final blocks = saved.last['blocks'] as List;
      expect(
        (blocks.first as Map)['src'],
        'https://app.example/api/v1/newsletter/artwork/o/x.jpg',
      );
    });

    testWidgets('clicking a block edits it in place, and the edit autosaves', (tester) async {
      final saved = <Map<String, dynamic>>[];
      final inner = _handler(canEdit: true, newsletters: const [_draft]);
      await _pumpComposer(tester, (request) {
        if (request.method == 'PUT') {
          saved.add(jsonDecode(request.body) as Map<String, dynamic>);
          return Future.value(_json({'data': _draft}));
        }
        return inner(request);
      });

      final block = find.byKey(const Key('newsletter-block-0'));
      expect(find.descendant(of: block, matching: find.byType(TextFormField)), findsNothing);

      await tester.tap(block);
      await tester.pumpAndSettle();

      // The fields replace the block, where it was, with the moves beside them.
      final field = find.descendant(of: block, matching: find.widgetWithText(TextFormField, 'Heading'));
      expect(field, findsOneWidget);
      expect(find.descendant(of: block, matching: find.text('Remove')), findsOneWidget);
      expect(find.descendant(of: block, matching: find.text('Move down')), findsOneWidget);

      await tester.enterText(field, 'Winter sweeps');
      await tester.pump(const Duration(seconds: 1));
      await tester.pumpAndSettle();

      expect(saved, isNotEmpty, reason: 'Typing in place must autosave.');
      expect((saved.last['blocks'] as List).first['text'], 'Winter sweeps');

      // Done puts the drawn block back, showing the new words.
      await tester.tap(find.descendant(of: block, matching: find.text('Done')));
      await tester.pumpAndSettle();
      expect(find.descendant(of: block, matching: find.text('Winter sweeps')), findsOneWidget);
    });

    testWidgets('dragging a block by its handle reorders it, and the save follows', (tester) async {
      final saved = <Map<String, dynamic>>[];
      final inner = _handler(canEdit: true, newsletters: const [_draftOfThree]);
      await _pumpComposer(tester, (request) {
        if (request.method == 'PUT') {
          saved.add(jsonDecode(request.body) as Map<String, dynamic>);
          return Future.value(_json({'data': _draftOfThree}));
        }
        return inner(request);
      });

      // The first block's handle, dragged below the third.
      final handle = find.byKey(const Key('newsletter-block-handle-0'));
      final third = tester.getBottomLeft(find.byKey(const Key('newsletter-block-2')));
      final gesture = await tester.startGesture(tester.getCenter(handle));
      await gesture.moveTo(tester.getCenter(handle) + const Offset(0, 10));
      await tester.pump();
      await gesture.moveTo(Offset(tester.getCenter(handle).dx, third.dy + 20));
      await tester.pump(const Duration(milliseconds: 300));
      await gesture.up();
      await tester.pump(const Duration(seconds: 1));
      await tester.pumpAndSettle();

      expect(saved, isNotEmpty, reason: 'A drag must autosave.');
      expect([for (final b in saved.last['blocks'] as List) b['text']], ['Two', 'Three', 'One']);
    });

    testWidgets('a block dragged from "Add a block" lands where it is dropped', (tester) async {
      final saved = <Map<String, dynamic>>[];
      final inner = _handler(canEdit: true, newsletters: const [_draftOfThree]);
      await _pumpComposer(tester, (request) {
        if (request.method == 'PUT') {
          saved.add(jsonDecode(request.body) as Map<String, dynamic>);
          return Future.value(_json({'data': _draftOfThree}));
        }
        return inner(request);
      });

      // Dropped on the second block: it goes above it, not at the end.
      final source = tester.getCenter(find.byKey(const Key('newsletter-add-divider')));
      final target = tester.getCenter(find.byKey(const Key('newsletter-block-1')));
      final gesture = await tester.startGesture(source);
      await gesture.moveTo(source + const Offset(20, 0));
      await tester.pump();
      await gesture.moveTo(target);
      await tester.pump();
      await gesture.up();
      await tester.pump(const Duration(seconds: 1));
      await tester.pumpAndSettle();

      expect(saved, isNotEmpty, reason: 'A drop must autosave.');
      expect([for (final b in saved.last['blocks'] as List) b['type']], ['text', 'divider', 'text', 'text']);
    });

    // Review 2026-11-09 C13 (A32): the confirmation says how many it reaches.
    testWidgets('the send confirmation says how many people it goes to', (
      tester,
    ) async {
      final inner = _handler(canEdit: true, newsletters: const [_draft]);
      await _pumpComposer(tester, (request) {
        if (request.method == 'PUT') {
          return Future.value(_json({'data': _draft}));
        }
        if (request.url.path.endsWith('/preflight')) {
          return Future.value(
            _json({
              'data': {
                'checks': [],
                'recipients': 1284,
                'audience': 'Customers',
              },
            }),
          );
        }
        return inner(request);
      });

      await tester.tap(find.widgetWithText(FilledButton, 'Send now'));
      await tester.pumpAndSettle();

      expect(find.text('Send this newsletter?'), findsOneWidget);
      expect(
        tester
            .widget<Text>(find.byKey(const ValueKey('newsletter-send-reach')))
            .data,
        startsWith('Sends to 1284 people on “Customers”. '),
      );
    });

    testWidgets('Send stops with the error when the save before it fails', (tester) async {
      final posts = <String>[];
      final inner = _handler(canEdit: true, newsletters: const [_draft]);
      await _pumpComposer(tester, (request) {
        if (request.method == 'PUT') {
          return Future.value(_json({'message': 'That is not a time this can read.'}, 422));
        }
        if (request.method == 'POST') posts.add(request.url.path);
        return inner(request);
      });

      await tester.tap(find.widgetWithText(FilledButton, 'Send now'));
      await tester.pumpAndSettle();

      expect(find.text('Send this newsletter?'), findsNothing,
          reason: 'A failed save must not reach the confirm panel.');
      expect(posts.where((p) => p.endsWith('/send')), isEmpty);
      expect(find.textContaining('That is not a time this can read.'), findsWidgets);
    });

    testWidgets('saved custom HTML opens in the HTML box and is sent back on save', (tester) async {
      final saved = <Map<String, dynamic>>[];
      const custom = {..._draft, 'html_override': '<p>Mine</p>'};
      final inner = _handler(canEdit: true, newsletters: const [custom]);
      await _pumpComposer(tester, (request) {
        if (request.method == 'PUT') {
          saved.add(jsonDecode(request.body) as Map<String, dynamic>);
          return Future.value(_json({'data': custom}));
        }
        return inner(request);
      });

      expect(find.text('Back to blocks'), findsOneWidget);
      expect(find.widgetWithText(TextField, '<p>Mine</p>'), findsOneWidget);

      await tester.enterText(find.widgetWithText(TextField, 'Autumn sweeps').first, 'Renamed');
      await tester.pump(const Duration(seconds: 1));
      await tester.pumpAndSettle();

      expect(saved, isNotEmpty);
      expect(saved.last['html_override'], '<p>Mine</p>');
    });

    testWidgets('the dashboard lays out on a 375-point window', (tester) async {
      await _pumpNewsletter(
        tester,
        _handler(canEdit: true, newsletters: const [_draft, sent, scheduled]),
        size: const Size(375, 2400),
      );

      expect(tester.takeException(), isNull);
      expect(find.byKey(const ValueKey('newsletter-n-5')), findsOneWidget);
      expect(find.text('New newsletter'), findsOneWidget);
    });

    testWidgets('the Desktop / Phone switch narrows the email', (tester) async {
      await _pumpComposer(tester, _handler(canEdit: true, newsletters: const [_draft]));

      final email = find.byKey(const Key('newsletter-email'));
      expect(tester.getSize(email).width, NewsletterEmail.desktopWidth);

      await tester.tap(find.text('Phone'));
      await tester.pumpAndSettle();

      expect(tester.getSize(email).width, NewsletterEmail.phoneWidth);
    });

    // Review 2026-11-09 C14 (A28, A34): a scheduled one comes back to a
    // draft, and any one can be copied.
    testWidgets('Unschedule and Duplicate ask the server for each', (
      tester,
    ) async {
      final posts = <String>[];
      final scheduled = <String, Object?>{
        ..._draft,
        'status': 'scheduled',
        'scheduled_for': '2099-03-04 09:00',
      };
      final inner = _handler(canEdit: true, newsletters: [scheduled]);
      await _pumpComposer(tester, (request) {
        if (request.method == 'POST') {
          posts.add(request.url.path);
          return Future.value(_json({'data': _draft}, 201));
        }
        return inner(request);
      });

      expect(
        find.textContaining('Unschedule it to edit it again'),
        findsWidgets,
      );
      await tester.tap(find.byKey(const ValueKey('newsletter-unschedule')));
      await tester.pumpAndSettle();
      expect(posts.last, endsWith('/newsletters/n-1/unschedule'));

      await tester.tap(find.byKey(const ValueKey('newsletter-duplicate')));
      await tester.pumpAndSettle();
      expect(posts.last, endsWith('/newsletters/n-1/duplicate'));
    });

    testWidgets('a sent newsletter is read-only', (tester) async {
      await _pumpComposer(tester, _handler(canEdit: true, newsletters: const [sent]));

      expect(find.textContaining('has gone out, so it cannot be changed'), findsWidgets);

      await tester.tap(find.byKey(const Key('newsletter-block-0')));
      await tester.pumpAndSettle();
      expect(find.widgetWithText(TextFormField, 'Heading'), findsNothing);

      final add = tester.widget<OutlinedButton>(find.widgetWithText(OutlinedButton, 'Heading'));
      expect(add.onPressed, isNull);
    });
  });

  group('the tab is actually reachable from the sidebar', () {
    testWidgets('a role granted Marketing gets the destination, and it opens '
        'on Social Media Management rather than a blank body', (tester) async {
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

      // Landed on the sub-module and it drew something. The chips prove the
      // strip rendered; the card proves the tab under it did too, which a
      // blank body would not.
      expect(find.widgetWithText(ChoiceChip, 'Newsletter'), findsOneWidget);
      // Social Media is first and opens by default (Jose, 2026-10-21).
      expect(find.byType(SocialMediaTab), findsOneWidget);
      expect(find.byType(NewsletterTab), findsNothing);
    });
  });
}


/// Newsletter on its own, which is where the editor and preview live.
Future<void> _pumpNewsletter(
  WidgetTester tester,
  MockClientHandler handler, {
  Size size = const Size(1400, 1600),
}) =>
    _pump(tester, handler, size, (api, session) =>
        NewsletterTab(api: api, session: session));

/// Newsletter, on the Composer chip. It opens on Dashboard, so a test about
/// the editor has to walk there the way a person would.
Future<void> _pumpComposer(
  WidgetTester tester,
  MockClientHandler handler, {
  Size size = const Size(1400, 1600),
}) async {
  await _pumpNewsletter(tester, handler, size: size);
  await tester.tap(find.widgetWithText(ChoiceChip, 'Composer'));
  await tester.pumpAndSettle();
}

/// A calendar entry by its subject: the dashboard's table carries the same
/// subject, so a bare find.text is ambiguous since layout A.
Finder _inCalendar(String subject) =>
    find.descendant(of: find.byType(MonthCalendar), matching: find.text(subject));

/// The sentence on a disabled button, or null when it may be pressed.
String? _refusalOn(WidgetTester tester, String label) => tester
    .widget<Refused>(
      find.ancestor(
        of: find.widgetWithText(FilledButton, label),
        matching: find.byType(Refused),
      ),
    )
    .reason;

const _draft = {
  'id': 'n-1',
  'subject': 'Autumn sweeps',
  'status': 'draft',
  'audience': {'id': 'a-1', 'name': 'Customers', 'kind': 'customers'},
  'blocks': [
    {'type': 'heading', 'text': 'Autumn sweeps', 'align': 'left'},
  ],
};

const _draftOfThree = {
  'id': 'n-3',
  'subject': 'Three blocks',
  'status': 'draft',
  'audience': {'id': 'a-1', 'name': 'Customers', 'kind': 'customers'},
  'blocks': [
    {'type': 'text', 'text': 'One', 'align': 'left'},
    {'type': 'text', 'text': 'Two', 'align': 'left'},
    {'type': 'text', 'text': 'Three', 'align': 'left'},
  ],
};

const _draftWithHtml = {
  'id': 'n-1',
  'subject': 'Autumn sweeps',
  'status': 'draft',
  'audience': {'id': 'a-1', 'name': 'Customers', 'kind': 'customers'},
  'html_override': '<p>Mine</p>',
  'blocks': [
    {'type': 'heading', 'text': 'Autumn sweeps', 'align': 'left'},
  ],
};

const _draftWithoutAudience = {
  'id': 'n-2',
  'subject': 'No list yet',
  'status': 'draft',
  'audience': null,
  'blocks': [
    {'type': 'text', 'text': 'Words.', 'align': 'left'},
  ],
};

const _mailchimp = {
  'id': 'conn-mc',
  'display_name': 'Everdoors Mail',
  'provider': 'mailchimp',
  'provider_label': 'Mailchimp',
  'handle': null,
  'scope_type': 'company',
  'status': 'connected',
  'status_reason': null,
  'can_publish': false,
  'cannot_publish_because': null,
  'posting_note': null,
};

const _audience = {
  'id': 'a-1',
  'name': 'Customers',
  'kind': 'customers',
};

Future<void> _pumpScreen(
  WidgetTester tester,
  MockClientHandler handler, {
  Size size = const Size(1400, 1600),
}) =>
    _pump(tester, handler, size, (api, session) =>
        MarketingScreen(api: api, session: session));

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
/// Advance the poll timer, and let the fetch it starts come back.
///
/// Two pumps per tick on purpose: the first fires the timer, the second flushes
/// the await inside it. One pump leaves the response in flight and the screen
/// showing the state before it.
Future<void> _tick(WidgetTester tester, {int times = 1}) async {
  for (var i = 0; i < times; i++) {
    await tester.pump(const Duration(seconds: 3));
    await tester.pump();
  }
}

/// The Social tab on its own, so a test can replace the file picker.
///
/// Injected rather than reached: `Files.pickBytes` touches the platform, and a
/// preview that cannot be reached from a test is a preview whose whole purpose
/// — letting somebody confirm the file before it goes out under their own name
/// — is unverified.
Future<void> _pumpSocial(
  WidgetTester tester,
  MockClientHandler handler, {
  Future<PickedBytes?> Function()? pickMedia,
  VideoPlayerController? Function(Uri)? videoPlayer,
  Size size = const Size(1400, 1600),
}) =>
    _pump(tester, handler, size, (api, session) => SocialMediaTab(
          api: api,
          session: session,
          pickMedia: pickMedia ?? () async => null,
          videoPlayer: videoPlayer ?? (_) => null,
        ));

/// A player with no platform behind it: 16:9, 42 seconds, or a refusal.
class _FakeVideo extends VideoPlayerController {
  _FakeVideo(super.url, {this.fails = false}) : super.networkUrl();

  final bool fails;
  bool disposed = false;
  double? volume;

  @override
  Future<void> initialize() async {
    if (fails) throw Exception('cannot decode');
    value = value.copyWith(
      isInitialized: true,
      size: const Size(1920, 1080),
      duration: const Duration(seconds: 42),
    );
  }

  @override
  Future<void> setVolume(double volume) async => this.volume = volume;

  @override
  Future<void> play() async => value = value.copyWith(isPlaying: true);

  @override
  Future<void> pause() async => value = value.copyWith(isPlaying: false);

  @override
  Future<void> dispose() async {
    disposed = true;
    await super.dispose();
  }
}

/// Marketing → Social Media Management → Posts, which is where compose lives.
Future<void> _openPosts(WidgetTester tester) async {
  await tester.tap(find.widgetWithText(ChoiceChip, 'Social Media Management'));
  await tester.pumpAndSettle();
}

/// Social → Compose. Social opens on its Dashboard since UI queue 76, so a
/// test about writing a post walks there the way a person would.
Future<void> _openCompose(WidgetTester tester) async {
  await tester.tap(find.widgetWithText(ChoiceChip, 'Compose'));
  await tester.pumpAndSettle();
}

/// Posts → Calendar, where the week and month calendars (or the phone's
/// agenda) live since layout A (Mia, 2026-10-02).
Future<void> _openCalendar(WidgetTester tester) async {
  await tester.tap(find.widgetWithText(ChoiceChip, 'Calendar'));
  await tester.pumpAndSettle();
}

/// The sentence on the Publish button, or null when it may be pressed.
String? _publishRefusal(WidgetTester tester) => tester
    .widget<Refused>(
      find.ancestor(
        of: find.widgetWithText(FilledButton, 'Publish now'),
        matching: find.byType(Refused),
      ),
    )
    .reason;

/// A server that takes the post and refuses it in a sentence.
MockClientHandler _refusingHandler(String because) {
  final ok = _handler(canEdit: true, connections: const [_pinterestBoard]);

  return (request) async {
    if (request.method == 'POST' && request.url.path.endsWith('/social/posts')) {
      return _json({
        'message': 'The given data was invalid.',
        'errors': {
          'connections': [because],
        },
      }, 422);
    }

    return ok(request);
  };
}

/// Social Media Management → Accounts → the Connect panel.
Future<void> _openConnectPanel(WidgetTester tester) async {
  await tester.tap(find.widgetWithText(ChoiceChip, 'Social Media Management'));
  await tester.pumpAndSettle();
  await tester.tap(find.widgetWithText(ChoiceChip, 'Accounts'));
  await tester.pumpAndSettle();
  await tester.tap(
    find.widgetWithText(FilledButton, 'Connect a page to Everdoors'),
  );
  await tester.pumpAndSettle();
}

MockClientHandler _handler({
  int status = 200,
  String message = 'Marketing is unavailable.',
  bool sentIsRaised = false,
  bool pinterestUsable = false,
  /// Publishing needs `marketing.edit`; the default role here holds only
  /// `marketing.view`, so a test about composing has to ask for it.
  bool canEdit = false,
  /// Which module keys come back REACHABLE, for the tests that ask which grant
  /// draws which chip.
  ///
  /// Modules and not permissions: `Me.reaches` reads the `reachable` flag the
  /// server computed, never the permission list. A test that varied
  /// `permissions` here would change nothing on screen and pass or fail for
  /// reasons unrelated to what it claims to be about.
  List<String>? reachable,
  /// Which pane keys this role holds. Null is every pane.
  List<String>? tabs,
  List<Map<String, Object?>>? newsletters,
  List<Map<String, Object?>>? audiences,
  String? sendingRefusedBecause,
  String? previewHtml,
  Map<String, Object?>? stats,
  List<Map<String, Object?>>? posts,
  /// Called per GET, so a test can make the queue change its mind between
  /// polls — which is the whole behaviour under test.
  List<Map<String, Object?>> Function()? postsFor,
  void Function(http.Request)? onPublish,
  /// Bluesky is off by default so the tests written before it stay about what
  /// they were about. It is the only network here that connects by password,
  /// which is the whole reason its tile behaves differently.
  bool bluesky = false,
  List<Map<String, Object?>>? connections,
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

    if (path.endsWith('/me')) {
      return _json({
        'data': {
          ..._me,
          if (reachable != null)
            'modules': [
              for (final m in (_me['modules']! as List<dynamic>))
                {...(m as Map<String, Object?>), 'reachable': reachable.contains(m['key'])},
            ],
          'permissions': [
            'marketing.view',
            'newsletter.view',
            // Every pane, as the 2026-09-29 migration grants to any role that
            // held the module's view. `tabs` narrows it for the tests about
            // which pane a partial grant draws.
            ...(tabs ?? _allTabs),
            if (canEdit) 'marketing.edit',
            // Newsletter's own keys. `canEdit` stands for "this role can do
            // things in Marketing", and sending is one of them — a separate
            // flag per key would make every test declare five booleans to ask
            // one question.
            if (canEdit) 'newsletter.create',
            if (canEdit) 'newsletter.edit',
            if (canEdit) 'newsletter.send',
            if (canEdit) 'newsletter.delete',
          ],
        },
      });
    }

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
            'label': 'Facebook',
            'usable': false,
            // The length of the real server sentence, which ends in what to
            // do. A short stand-in would fit in two lines at phone width and
            // let a clipping subtitle pass the wrap test.
            'unusable_because':
                'EzBizi has no Meta app set up on this installation yet, so '
                'Facebook, Instagram and Messenger cannot be connected until '
                'somebody at EzBizi registers one and adds its keys.',
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
          if (bluesky)
            {
              'value': 'bluesky',
              'label': 'Bluesky',
              'usable': true,
              'unusable_because': null,
              'connects_by': 'password',
              'start_path': '/social/bluesky/start',
            },
        ],
      });
    }

    if (path.endsWith('/newsletters')) {
      return _json({
        'data': newsletters ?? <Map<String, Object?>>[],
        'sending': {'refused_because': sendingRefusedBecause},
        'stats': stats ??
            {
              'drafts': 0,
              'scheduled': 0,
              'sent': 0,
              'recipients': 0,
              'last_sent_at': null,
            },
      });
    }

    if (path.contains('/newsletters/') && path.endsWith('/preview')) {
      return _json({
        'data': {'html': previewHtml ?? '<p>Preview</p>'},
      });
    }

    if (path.endsWith('/newsletter/audiences')) {
      return _json({'data': audiences ?? <Map<String, Object?>>[]});
    }

    if (path.endsWith('/tiktok-creator')) {
      return _json({
        'data': {
          'nickname': 'EzBizi US',
          'privacy_level_options': ['PUBLIC_TO_EVERYONE', 'SELF_ONLY'],
          'comment_disabled': false,
          'duet_disabled': true,
          'stitch_disabled': false,
          'max_video_post_duration_sec': 600,
        },
      });
    }

    if (path.endsWith('/social/connections')) {
      return _json({'data': connections ?? <Map<String, Object?>>[]});
    }

    if (path.endsWith('/social/posts')) {
      if (request.method == 'POST') {
        onPublish?.call(request);

        return _json({
          'data': {
            'id': 'post-1',
            'targets': [
              {
                'id': 'target-1',
                'status': 'queued',
                'account': 'Everdoors Boards',
                'provider_label': 'Pinterest',
              },
            ],
          },
        }, 201);
      }

      // As the server answers: the tiles counted across the whole list, and
      // `status` narrowing the rows (SocialPostsController::index).
      // The calendar's own date-range read is not one of the list reads a
      // test counts through postsFor.
      final range = request.url.queryParameters.containsKey('from');
      final all = postsFor != null
          ? (range ? <Map<String, Object?>>[] : postsFor())
          : (posts ?? <Map<String, Object?>>[]);
      final status = request.url.queryParameters['status'];

      return _json({
        'data': [
          for (final post in all)
            if (status == null || _tileOf(post) == status) post,
        ],
        'meta': {
          'tiles': {
            for (final key in const ['scheduled', 'sent', 'failed'])
              key: all.where((p) => _tileOf(p) == key).length,
          },
        },
      });
    }

    return _json({'message': 'Unexpected ${request.method} $path'}, 500);
  };
}

/// The tile a post counts under, as the server reads it: any failed account
/// is failed, all scheduled is scheduled, anything still queued is in none.
String? _tileOf(Map<String, Object?> post) {
  final statuses = [
    for (final t in (post['targets'] as List<dynamic>? ?? const []))
      (t as Map<String, Object?>)['status'],
  ];
  if (statuses.contains('failed')) return 'failed';
  if (statuses.contains('queued')) return null;
  if (statuses.isNotEmpty && statuses.every((s) => s == 'scheduled')) {
    return 'scheduled';
  }

  return 'sent';
}

/// One post as GET /social/posts carries it, made today, to Everdoors Boards.
Map<String, Object?> _landed(
  String status, {
  String? failure,
  String? url,
  String? scheduledFor,
}) => {
  'id': 'post-1',
  'body': 'New flue liner, same day.',
  'has_media': false,
  'created_at': DateTime.now().toUtc().toIso8601String(),
  'scheduled_for': scheduledFor,
  'targets': [
    {
      'id': 'target-1',
      'connection_id': 'conn-2',
      'account': 'Everdoors Boards',
      'provider': 'pinterest',
      'provider_label': 'Pinterest',
      'status': status,
      'failure': failure,
      'url': url,
    },
  ],
};

/// Words, and Everdoors Boards ticked: a post ready to go.
Future<void> _write(WidgetTester tester) async {
  final words = find.widgetWithText(TextField, 'What should go out?');
  await tester.ensureVisible(words);
  await tester.enterText(words, 'New flue liner, same day.');
  final board = find.widgetWithText(FilterChip, 'Everdoors Boards');
  await tester.ensureVisible(board);
  await tester.pumpAndSettle();
  await tester.tap(board);
  await tester.pumpAndSettle();
}

/// The server as production answers a post filed for later: every target
/// `scheduled`, never `queued`.
MockClientHandler _scheduling(MockClientHandler inner) => (request) async {
  if (request.method == 'POST' && request.url.path.endsWith('/social/posts')) {
    return _json({
      'data': {
        'id': 'post-1',
        'targets': [
          {
            'id': 'target-1',
            'connection_id': 'conn-2',
            'status': 'scheduled',
            'account': 'Everdoors Boards',
            'provider': 'pinterest',
            'provider_label': 'Pinterest',
          },
        ],
      },
    }, 201);
  }

  return inner(request);
};

/// The server's Try again: the target goes back to the queue.
MockClientHandler _retrying(
  MockClientHandler inner, {
  required void Function() onRetry,
}) => (request) async {
  if (request.method == 'POST' &&
      request.url.path.endsWith('/social/posts/post-1/targets/target-1/retry')) {
    onRetry();
    return _json({
      'data': {
        'id': 'target-1',
        'connection_id': 'conn-2',
        'status': 'queued',
        'account': 'Everdoors Boards',
        'provider': 'pinterest',
        'provider_label': 'Pinterest',
      },
    });
  }

  return inner(request);
};

/// A genuine 1x1 PNG. `Image.memory` decodes bytes for real, so a handful of
/// arbitrary numbers would fail to decode and prove the fallback instead of
/// the preview.
final _onePixelPng = base64Decode(
  'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmM'
  'IQAAAABJRU5ErkJggg==',
);

String _two(int n) => n.toString().padLeft(2, '0');

/// Fill the Send-at field the way somebody would: the day, then the time, in
/// the fallback YYYY-MM-DD order (one field since Jose, 2026-11-03).
Future<void> _typeWhen(
  WidgetTester tester,
  DateTime day, {
  required String hour,
  required String minute,
}) async {
  await tester.enterText(
    find.byKey(const Key('calendar-day-text')),
    '${day.year}-${_two(day.month)}-${_two(day.day)} $hour:$minute',
  );
  await tester.pumpAndSettle();
}

http.Response _json(Object body, [int status = 200]) => http.Response(
      jsonEncode(body),
      status,
      headers: {'content-type': 'application/json'},
    );

/// Every Marketing and Newsletter pane, which is what a role holding each
/// module's view has after the 2026-09-29 grant migration.
const _allTabs = [
  // Posts split into three in UI queue 76.
  'marketing.dashboard.view',
  'marketing.compose.view',
  'marketing.calendar.view',
  'marketing.accounts.view',
  'newsletter.dashboard.view',
  'newsletter.composer.view',
  'newsletter.settings.view',
];

const _me = {
  'id': 'u-1',
  'name': 'Jose Silva',
  'email': 'jose@example.com',
  'has_avatar': false,
  'role': {'id': 'role-1', 'name': 'Administrator'},
  // Newsletter is a MODULE with its own grant, not a slice of marketing.view,
  // so the default fixture holds both — a role that reaches the tab in
  // production holds both too.
  'permissions': ['marketing.view', 'newsletter.view', ..._allTabs],
  'modules': [
    {
      'key': 'marketing',
      'label': 'Marketing',
      'available': true,
      'reachable': true,
    },
    {
      'key': 'newsletter',
      'label': 'Newsletter',
      'parent': 'marketing',
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

/// Pages already connected in this view, so the catalogue has something to
/// leave out. One per network the provider stub declares, which is what makes
/// the "everything is connected" case reachable at all.
const _facebookPage = {
  'id': 'conn-1',
  'display_name': 'Everdoors Doors',
  'provider': 'facebook_page',
  'provider_label': 'Facebook',
  'handle': 'everdoors',
  'can_publish': false,
  'cannot_publish_because':
      'Publishing to Facebook Pages is not built yet. The account is '
      'connected, and posting to it will work without reconnecting.',
  'posting_note': null,
};

const _pinterestBoard = {
  'id': 'conn-2',
  'display_name': 'Everdoors Boards',
  'provider': 'pinterest',
  'provider_label': 'Pinterest',
  'handle': 'everdoors_pins',
  'can_publish': true,
  'cannot_publish_because': null,
  'posting_note': null,
};

const _tiktokDrafts = {
  'id': 'conn-5',
  'display_name': 'ezbizi_us',
  'provider': 'tiktok',
  'provider_label': 'TikTok',
  'handle': 'ezbizi_us',
  'can_publish': true,
  'cannot_publish_because': null,
  'posting_note':
      'Goes to this account’s TikTok drafts, not straight to the profile. '
      'Open TikTok to finish and post it.',
};

const _tiktokDirect = {
  'id': 'conn-7',
  'display_name': 'ezbizi_us',
  'provider': 'tiktok',
  'provider_label': 'TikTok',
  'handle': 'ezbizi_us',
  'can_publish': true,
  'cannot_publish_because': null,
  'posting_note': null,
  'asks_tiktok_choices': true,
};

const _blueskyAccount = {
  'id': 'conn-4',
  'display_name': 'EzBizi',
  'provider': 'bluesky',
  'provider_label': 'Bluesky',
  'handle': 'ezbizi.bsky.social',
  'can_publish': false,
  'cannot_publish_because':
      'Publishing to that network is not built yet.',
  'posting_note': null,
};

const _xAccount = {
  'id': 'conn-3',
  'display_name': 'Everdoors HQ',
  'provider': 'x',
  'provider_label': 'X',
  'handle': 'everdoors_hq',
  'can_publish': false,
  'cannot_publish_because':
      'Publishing to that network is not built yet.',
  'posting_note': null,
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

// Jose 2026-11-16: the post panel edits in place; the calendar days answer the
// mouse and open their list; Comments is a tab of its own.
void _jose1116Tests() {
  group('Jose 2026-11-16', () {
    const longBody =
        'Fall is here and the first cold night is closer than it looks. '
        'Book your chimney sweep now, before the rush, and we will check the '
        'flue, the cap, the crown and the damper while we are up there. '
        'Line two of the draft.\nLine three.\nLine four.\nLine five.\n'
        'Line six.\nLine seven.\nThe very last words of the AI draft.';

    Map<String, Object?> draft() => {
      ..._landed('awaiting_approval'),
      'body': longBody,
    };

    testWidgets('the post panel shows the whole text in a field that saves '
        'before Approve', (tester) async {
      final calls = <String>[];
      final inner = _handler(
        canEdit: true,
        connections: const [_pinterestBoard],
        posts: [draft()],
      );
      await _pumpSocial(tester, (request) async {
        if (request.method == 'PATCH' &&
            request.url.path.endsWith('/social/posts/post-1')) {
          calls.add('PATCH ${jsonDecode(request.body)['body']}');
          return _json({
            'data': {'id': 'post-1'},
          });
        }
        if (request.method == 'POST' &&
            request.url.path.contains('/business-profile/post-queue/')) {
          calls.add('APPROVE');
          return _json({
            'data': {'id': 'target-1', 'status': 'queued'},
          });
        }
        return inner(request);
      });

      await _openCalendar(tester);
      await tester.tap(
        find
            .descendant(
              of: find.byKey(Key('month-day-${DateTime.now().day}')),
              matching: find.textContaining('Fall is here'),
            )
            .first,
      );
      await tester.pumpAndSettle();

      final field = find.byKey(const Key('social-post-text'));
      expect(field, findsOneWidget);
      expect(
        tester.widget<TextField>(field).controller!.text,
        longBody,
        reason: 'the whole text, not cut with an ellipsis',
      );
      expect(find.textContaining('The very last words'), findsWidgets);
      expect(
        tester.widget<Text>(find.byKey(const Key('social-post-count'))).data,
        '${longBody.characters.length} / 800',
      );

      await tester.enterText(field, 'Edited by hand.');
      await tester.pump();
      expect(
        tester.widget<Text>(find.byKey(const Key('social-post-count'))).data,
        '15 / 800',
      );
      await tester.tap(find.byKey(const Key('social-approve-target-1')));
      await tester.pumpAndSettle();

      expect(calls, ['PATCH Edited by hand.', 'APPROVE']);
    });

    testWidgets('a picture Google would refuse is refused before it is sent', (
      tester,
    ) async {
      final uploads = <String>[];
      final gbp = {
        ...draft(),
        'targets': [
          {
            'id': 'target-1',
            'connection_id': 'conn-9',
            'account': 'Dayton',
            'provider': 'google_business_profile',
            'provider_label': 'Google Business Profile',
            'status': 'awaiting_approval',
          },
        ],
      };
      final inner = _handler(
        canEdit: true,
        connections: const [_pinterestBoard],
        posts: [gbp],
      );
      await _pumpSocial(
        tester,
        (request) async {
          if (request.url.path.endsWith('/social/posts/post-1/media')) {
            uploads.add(request.method);
            return _json({
              'data': {'id': 'post-1', 'has_media': true},
            });
          }
          return inner(request);
        },
        pickMedia: () async => PickedBytes('tiny.gif', List.filled(50, 1)),
      );

      await _openCalendar(tester);
      await tester.tap(
        find
            .descendant(
              of: find.byKey(Key('month-day-${DateTime.now().day}')),
              matching: find.textContaining('Fall is here'),
            )
            .first,
      );
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const Key('social-post-upload')));
      await tester.pumpAndSettle();

      expect(
        find.text('Google Business Profile takes a JPG or PNG picture.'),
        findsOneWidget,
      );
      expect(find.byKey(const Key('social-post-image')), findsNothing);
      expect(uploads, isEmpty);
    });

    testWidgets('a calendar day tints under the mouse and shows the pointer', (
      tester,
    ) async {
      await _pumpSocial(
        tester,
        _handler(canEdit: true, connections: const [_pinterestBoard]),
      );
      await _openCalendar(tester);
      final day = find.byKey(Key('month-day-${DateTime.now().day}'));

      Color? fill() => (tester
                  .widget<Container>(
                    find
                        .descendant(of: day, matching: find.byType(Container))
                        .first,
                  )
                  .decoration!
              as BoxDecoration)
          .color;

      final region = tester.widget<MouseRegion>(
        find.descendant(of: day, matching: find.byType(MouseRegion)).first,
      );
      expect(region.cursor, SystemMouseCursors.click);

      final before = fill();
      final mouse = await tester.createGesture(kind: PointerDeviceKind.mouse);
      await mouse.addPointer(location: Offset.zero);
      addTearDown(mouse.removePointer);
      await mouse.moveTo(tester.getCenter(day));
      await tester.pump();
      expect(fill(), isNot(before));
      await mouse.moveTo(Offset.zero);
      await tester.pump();
      expect(fill(), before);
    });

    testWidgets('a day with more than fits opens its whole list, searchable, '
        'and a row opens the post', (tester) async {
      final now = DateTime.now();
      String two(int n) => n.toString().padLeft(2, '0');
      final busy = [
        for (var i = 0; i < 12; i++)
          {
            ..._landed(
              'scheduled',
              scheduledFor:
                  '${now.year}-${two(now.month)}-${two(now.day)} '
                  '${two(8 + i)}:00',
            ),
            'id': 'busy-$i',
            'body': 'Busy post number $i',
          },
      ];
      final ranges = <Map<String, String>>[];
      final inner = _handler(
        canEdit: true,
        connections: const [_pinterestBoard],
        posts: busy,
      );
      await _pumpSocial(tester, (request) async {
        if (request.url.path.endsWith('/social/posts') &&
            request.url.queryParameters.containsKey('from')) {
          ranges.add(request.url.queryParameters);
        }
        return inner(request);
      });
      await _openCalendar(tester);

      final more = find.byKey(Key('day-more-${now.month}-${now.day}'));
      expect(find.text('+10 more'), findsWidgets);
      await tester.tap(more.last);
      await tester.pumpAndSettle();

      final day = formatCalendarDayForTest(now);
      expect(ranges.last, {'from': day, 'to': day});
      expect(find.byKey(const Key('social-day-list')), findsOneWidget);
      expect(find.textContaining('12 posts'), findsOneWidget);
      expect(find.byKey(const Key('social-day-search')), findsOneWidget);

      await tester.enterText(
        find.descendant(
          of: find.byKey(const Key('social-day-search')),
          matching: find.byType(TextField),
        ),
        'number 11',
      );
      await tester.pump();
      expect(find.byKey(const Key('social-day-post-busy-11')), findsOneWidget);
      expect(find.byKey(const Key('social-day-post-busy-1')), findsNothing);

      await tester.tap(find.byKey(const Key('social-day-post-busy-11')));
      await tester.pumpAndSettle();
      expect(find.byType(SidePanel), findsOneWidget);
      expect(
        tester
            .widget<TextField>(find.byKey(const Key('social-post-text')))
            .controller!
            .text,
        'Busy post number 11',
      );
    });

    testWidgets('Comments is a tab: one inbox, a badge, filters, reply in '
        'place', (tester) async {
      final replies = <String>[];
      final inner = _handler(
        canEdit: true,
        connections: const [_facebookPage, _pinterestBoard],
        tabs: const [..._allTabs, 'marketing.comments.view'],
      );
      await _pumpSocial(tester, (request) async {
        final path = request.url.path;
        if (path.endsWith('/social/connections/conn-1/instagram-comments')) {
          return _json({
            'data': {
              'account': {'id': 'ig-1', 'username': 'everdoors'},
              'posts': [
                {
                  'id': 'm-1',
                  'caption': 'Our new oak door',
                  'comments': [
                    {
                      'id': 'c-old',
                      'text': 'Answered already',
                      'username': 'ann',
                      'timestamp': '2026-11-01T10:00:00+0000',
                      'hidden': false,
                      'replies': [
                        {'id': 'r1', 'text': 'Thanks!', 'username': 'everdoors'},
                      ],
                    },
                    {
                      'id': 'c-new',
                      'text': 'How much is it?',
                      'username': 'bob',
                      'timestamp': '2026-11-15T10:00:00+0000',
                      'hidden': false,
                      'replies': [],
                    },
                  ],
                },
              ],
            },
          });
        }
        if (path.contains('/instagram-comments/') &&
            path.endsWith('/replies')) {
          replies.add('$path ${jsonDecode(request.body)['message']}');
          return _json({
            'data': {'id': 'r2'},
          });
        }
        return inner(request);
      });

      // The badge: one unreplied comment, beside the tab's name.
      final tab = find.widgetWithText(ChoiceChip, 'Comments');
      expect(tab, findsOneWidget);
      expect(
        find.descendant(of: tab, matching: find.text('1')),
        findsOneWidget,
      );

      await tester.tap(tab);
      await tester.pumpAndSettle();
      expect(find.byKey(const Key('social-comments-inbox')), findsOneWidget);
      // Newest first, with the post each is on.
      expect(
        tester.getTopLeft(find.text('How much is it?')).dy,
        lessThan(tester.getTopLeft(find.text('Answered already')).dy),
      );
      expect(find.text('On: Our new oak door'), findsNWidgets(2));

      await tester.tap(find.byKey(const Key('social-comments-unreplied')));
      await tester.pumpAndSettle();
      expect(find.text('Answered already'), findsNothing);
      expect(find.text('How much is it?'), findsOneWidget);

      await tester.tap(find.byKey(const Key('social-comment-replybtn-c-new')));
      await tester.pumpAndSettle();
      await tester.enterText(
        find.byKey(const Key('social-comment-reply-c-new')),
        'It is \$900.',
      );
      await tester.tap(find.byKey(const Key('social-comment-send-c-new')));
      await tester.pumpAndSettle();
      expect(replies, [
        '/api/v1/social/connections/conn-1/instagram-comments/c-new/replies '
            'It is \$900.',
      ]);
    });
  });
}

String formatCalendarDayForTest(DateTime d) =>
    '${d.year}-${d.month.toString().padLeft(2, '0')}-'
    '${d.day.toString().padLeft(2, '0')}';
