import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/files.dart';
import 'package:ezbizi_client/core/session.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/main.dart';
import 'package:ezbizi_client/screens/marketing/social_media_tab.dart';
import 'package:ezbizi_client/screens/marketing_screen.dart';
import 'package:ezbizi_client/widgets/dashboard_card.dart';
import 'package:ezbizi_client/widgets/refused.dart';
import 'package:ezbizi_client/widgets/side_panel.dart';
import 'package:ezbizi_client/widgets/social_network_mark.dart';
import 'package:flutter/material.dart';
import 'package:flutter_svg/flutter_svg.dart';
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
      // `canEdit` so the reason under test is the one this names. Without it
      // the first refusal is the missing permission, which is a different test.
      await _pumpScreen(tester, _handler(canEdit: true));

      await tester.tap(find.widgetWithText(ChoiceChip, 'Social Media Management'));
      await tester.pumpAndSettle();

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

      expect(tester.takeException(), isNull);
      expect(find.text('Compose'), findsOneWidget);
      expect(find.text('Weekly Calendar'), findsOneWidget);
      expect(find.text('Monthly Calendar'), findsOneWidget);
    });

    testWidgets(
      'the month calendar fits a 1400×900 Posts pane without scrolling',
      (tester) async {
        await _pumpScreen(tester, _handler(), size: const Size(1400, 900));

        await tester.tap(
          find.widgetWithText(ChoiceChip, 'Social Media Management'),
        );
        await tester.pumpAndSettle();

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

      // The tickbox is present and dead rather than absent: somebody looking
      // for the Facebook page they just connected finds it, and finds out why.
      final box = tester.widget<CheckboxListTile>(
        find.widgetWithText(CheckboxListTile, 'Everdoors Doors'),
      );
      expect(box.onChanged, isNull);

      expect(
        tester
            .widget<Refused>(
              find.ancestor(
                of: find.widgetWithText(CheckboxListTile, 'Everdoors Doors'),
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

      expect(_publishRefusal(tester), contains('Tick at least one account'));

      await tester.tap(
        find.widgetWithText(CheckboxListTile, 'Everdoors Boards'),
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

      await tester.enterText(
        find.widgetWithText(TextField, 'What should go out?'),
        'New flue liner, same day.',
      );
      await tester.tap(
        find.widgetWithText(CheckboxListTile, 'Everdoors Boards'),
      );
      await tester.pumpAndSettle();

      expect(_publishRefusal(tester), isNull);

      await tester.tap(find.widgetWithText(FilledButton, 'Publish now'));
      await tester.pumpAndSettle();

      expect(sent, isNotNull, reason: 'Publish sent nothing.');

      final body = jsonDecode(sent!.body) as Map<String, dynamic>;
      expect(body['body'], 'New flue liner, same day.');
      expect(body['connections'], ['conn-2']);

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

      await tester.enterText(
        find.widgetWithText(TextField, 'What should go out?'),
        'Just words',
      );
      await tester.tap(
        find.widgetWithText(CheckboxListTile, 'Everdoors Boards'),
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

      expect(find.text('Sent'), findsOneWidget);
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

  group('what posting somewhere actually does is said beforehand', () {
    testWidgets('the TikTok drafts detour is on the account, before Publish', (
      tester,
    ) async {
      await _pumpSocial(
        tester,
        _handler(canEdit: true, connections: const [_tiktokDrafts]),
      );

      // The server's sentence, on the row, while the post can still be changed.
      expect(
        find.textContaining('TikTok drafts, not straight to the profile'),
        findsOneWidget,
      );
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
  Size size = const Size(1400, 1600),
}) =>
    _pump(tester, handler, size, (api, session) => SocialMediaTab(
          api: api,
          session: session,
          pickMedia: pickMedia ?? () async => null,
        ));

/// Marketing → Social Media Management → Posts, which is where compose lives.
Future<void> _openPosts(WidgetTester tester) async {
  await tester.tap(find.widgetWithText(ChoiceChip, 'Social Media Management'));
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
          'permissions': [
            'marketing.view',
            if (canEdit) 'marketing.edit',
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

      return _json({
        'data': postsFor != null ? postsFor() : (posts ?? <Map<String, Object?>>[]),
      });
    }

    return _json({'message': 'Unexpected ${request.method} $path'}, 500);
  };
}

/// A genuine 1x1 PNG. `Image.memory` decodes bytes for real, so a handful of
/// arbitrary numbers would fail to decode and prove the fallback instead of
/// the preview.
final _onePixelPng = base64Decode(
  'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmM'
  'IQAAAABJRU5ErkJggg==',
);

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
