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
    expect(
      releases.first.version,
      File('VERSION').readAsStringSync().trim(),
      reason:
          'A VERSION bump that forgets a note is a date nobody can remember. '
          'Add the row in the same commit.',
    );
    expect(releases.last.version, '0.0.0001');

    final versions = [for (final r in releases) r.version];
    expect(versions.toSet().length, versions.length);

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
    expect(find.text('v0.0.0065'), findsOneWidget);
    expect(find.textContaining('uses the full pane width'), findsOneWidget);

    // Newest above oldest: the 0065 heading is above the first-build heading.
    final newest = tester.getTopLeft(find.text('v0.0.0065'));
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
