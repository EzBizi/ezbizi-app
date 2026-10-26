import 'dart:io';

import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/core/version_history.dart';
import 'package:ezbizi_client/widgets/build_stamp.dart';
import 'package:ezbizi_client/widgets/version_history_panel.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

/// The version stamp is how you remember what shipped, and when.
///
/// A number in the corner that is not a door is a number nobody opens. The
/// panel has to come from a tap on that stamp — the two places it already
/// lives, sign-in and the sidebar — and it has to name versions in the order
/// they landed, newest first, so "when did we do that?" has one answer.
void main() {
  test('releases are newest first, every version once, every note written', () {
    final releases = VersionHistory.releases;

    expect(releases, isNotEmpty);

    // Both operands are read from DISK, deliberately, and this used to be the
    // suite's one intermittent failure.
    //
    // The old assertion compared `releases.first.version` — a constant compiled
    // when the run STARTED — against `VERSION`, read when the test EXECUTED.
    // The deploy desk bumps both files together in a tree other sessions are
    // running tests in, so a cut landing between those two moments made them
    // disagree while neither file was wrong. It went red once, passed in
    // isolation, and did not recur: the signature of a race, not a defect.
    //
    // Reading both files here closes the window from minutes to microseconds,
    // and it is also the truer statement of the rule — the two FILES must be
    // bumped in the same commit. The compiled constant is still what every
    // assertion below checks, where nothing races.
    final versionFile = File('VERSION').readAsStringSync().trim();
    final source = File('lib/core/version_history.dart').readAsStringSync();
    final topOfSource = RegExp(r"version: '([^']+)'").firstMatch(source)?.group(1);

    expect(
      topOfSource,
      isNotNull,
      reason: 'version_history.dart names no version at all. If its shape has '
          'changed, this check needs changing with it rather than deleting.',
    );
    expect(
      topOfSource,
      versionFile,
      reason:
          'A VERSION bump that forgets a note is a date nobody can remember. '
          'Add the row in the same commit.',
    );
    expect(releases.last.version, '0.0.0001');

    final versions = [for (final r in releases) r.version];
    expect(versions.toSet().length, versions.length);

    // NO HOLE IN THE MIDDLE. The check above compares the TOP note to VERSION,
    // so it sees a bump that forgot its note only while that bump is the newest
    // one. The moment the next release lands, both files agree again and the
    // missing row is invisible to every assertion here.
    //
    // That is not hypothetical: 0.0.0240 shipped with no row at all, this test
    // went red on main for a day, and bumping to 0.0.0241 made it GREEN again
    // while the hole stayed. Jose found it by opening the sidebar and reading
    // 0.0.0239 under a heading that said v0.0.0240.
    final numbers = [
      for (final v in versions) int.parse(v.split('.').last),
    ]..sort();
    const neverBuilt = <int>{
      492, // pinned at 44a9eada and stopped in its backend gate: pto.allow_negative
      // had no Work Hours section. Its notes shipped in 0.0.0493.
      473, // pinned at ebd660b8 and stopped in its backend gate: InvoicesTest ordered
      // three same-second events by occurred_at. Its note shipped in 0.0.0474.
      457, // pinned at 7e4d9aee and stopped in its backend gate: two merges each
      // counted RetentionPolicy's tables as 38. Its notes shipped in 0.0.0459.
      458, // pinned at 51854ac7 behind 457 and stopped with it; notes in 0.0.0459.
      72, // no commit ever set VERSION to it and no artefact exists
      111, // BUILT -- Distribution/Apple/EzBizi-0.0.0111-4bbbea5.ipa -- and its
      // notes were never written. Left named rather than invented: reconstructing
      // a month-old release from its commits would put words in it that nobody
      // shipped, which is worse than an admitted hole.
      261, // pinned at f33e8a9b and stopped in its gate: a8cd0c9f turned
      // no_popup_dropdown_test red. No artefact exists, and its one note -
      // Time slots - shipped in 0.0.0262 instead.
      266, // pinned at cc0baa54 and stopped in its backend gate:
      // ai_assistance declared an action requirement RolesApiTest did not
      // know. No artefact exists; its notes shipped in 0.0.0267.
      292, // pinned at cbad3ccd and never started: its social reach fix
      // turned MarketingGlanceTest red. No artefact exists; its note shipped
      // in 0.0.0293.
      445, // pinned three times (a4a51667, 05a42380) and stopped in its
      // backend gate each time: the downwards-only view fix turned 14, then 20,
      // reach tests red. No artefact exists; its notes shipped in 0.0.0446.
    };
    final holes = [
      for (var n = numbers.first; n <= numbers.last; n++)
        if (!numbers.contains(n) && !neverBuilt.contains(n)) n,
    ];
    expect(
      holes,
      isEmpty,
      reason: 'These versions have no note and were not recorded as unbuilt. A release nobody wrote a line for is invisible the moment the next one ships.',
    );

    for (final release in releases) {
      expect(release.date, isNotEmpty);
      expect(release.items, isNotEmpty);
      for (final item in release.items) {
        expect(item.trim(), isNotEmpty);
      }
    }
  });

  testWidgets('tapping the stamp opens what shipped, newest first', (
    tester,
  ) async {
    await tester.pumpWidget(
      MaterialApp(
        theme: EzBiziTheme.light(),
        home: const Scaffold(body: BuildStamp()),
      ),
    );

    await tester.tap(find.byType(BuildStamp));
    await tester.pumpAndSettle();

    expect(find.byType(VersionHistoryPanel), findsOneWidget);
    expect(find.text('What\'s been built'), findsOneWidget);
    expect(find.text('v0.0.0097'), findsOneWidget);
    expect(
      find.textContaining(
        'Date fields are month, day and year',
      ),
      findsOneWidget,
    );

    // Newest above oldest: the 0097 heading is above the first-build heading.
    final newest = tester.getTopLeft(find.text('v0.0.0097'));
    final oldest = tester.getTopLeft(find.text('v0.0.0001'));
    expect(newest.dy, lessThan(oldest.dy));
  });

  testWidgets('the panel is a side panel, not a centred box over the page', (
    tester,
  ) async {
    await tester.pumpWidget(
      MaterialApp(
        theme: EzBiziTheme.light(),
        home: const Scaffold(body: BuildStamp()),
      ),
    );

    await tester.tap(find.byType(BuildStamp));
    await tester.pumpAndSettle();

    expect(find.byTooltip('Close What\'s been built'), findsOneWidget);
  });
}
