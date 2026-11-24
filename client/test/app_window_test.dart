import 'package:ezbizi_client/core/app_window.dart';
import 'package:ezbizi_client/core/deep_links.dart';
import 'package:ezbizi_client/widgets/tab_bar_like.dart';
import 'package:flutter/gestures.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

/// "Open in new window" (Jose, 2026-11-24): a module or tab opens in a second
/// EzBizi window on its own path, and that path lands on the same tab.
void main() {
  test('a module or tab has a path, and the path reads back as its tab', () {
    expect(AppWindow.pathFor('jobs'), '/jobs');
    expect(AppWindow.pathFor('hr', tab: 'records'), '/hr?tab=records');

    final hr = DeepLinks.fromUri(
      Uri.parse(AppWindow.pathFor('hr', tab: 'records')),
    )!;
    expect((hr.module, hr.type, hr.id), ('hr', 'tab', 'records'));

    // Inspection knows its tabs by their words.
    final words = DeepLinks.fromUri(
      Uri.parse(
        AppWindow.pathFor('inspection', tab: 'Reports', label: 'Reports'),
      ),
    )!;
    expect((words.type, words.label), ('tab', 'Reports'));

    // A plain module path is not a link; the address bar selects it.
    expect(DeepLinks.fromUri(Uri.parse('/jobs')), isNull);
  });

  test('the first window is the first: nothing set, nothing secondary', () {
    expect(AppWindow.secondary, isFalse);
  });

  testWidgets('right-click on a tab opens that tab in a new window', (
    tester,
  ) async {
    final opened = <String>[];
    AppWindow.opener = (path) async => opened.add(path);
    addTearDown(() => AppWindow.opener = (_) async {});
    await tester.pumpWidget(
      MaterialApp(
        home: Scaffold(
          body: TabBarLike(
            labels: const [
              ('Records', Icons.badge),
              ('Invitations', Icons.mail),
            ],
            selected: 0,
            onSelected: (_) {},
            windowPaths: [
              AppWindow.pathFor('hr', tab: 'records'),
              AppWindow.pathFor('hr', tab: 'invitations'),
            ],
          ),
        ),
      ),
    );

    await tester.tap(find.text('Invitations'), buttons: kSecondaryButton);
    await tester.pumpAndSettle();
    await tester.tap(find.text('Open in new window'));
    await tester.pumpAndSettle();

    expect(opened, ['/hr?tab=invitations']);
  });
}
