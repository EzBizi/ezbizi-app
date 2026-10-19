import 'package:ezbizi_client/widgets/get_the_apps.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

/// UI queue 106 (Jose, 2026-10-19: "the offer to download the app, desktop -
/// android - ios"): a phone sees its own store, a computer all four with its
/// own platform first. The web only.
void main() {
  Future<List<String>> keys(
    WidgetTester tester,
    TargetPlatform platform,
  ) async {
    await tester.pumpWidget(const SizedBox());
    debugDefaultTargetPlatformOverride = platform;
    await tester.pumpWidget(
      const MaterialApp(home: Scaffold(body: GetTheAppRow(web: true))),
    );
    final found = [
      for (final e
          in find
              .byWidgetPredicate(
                (w) =>
                    '${w.key}'.contains('get-the-app-') &&
                    w is ButtonStyleButton,
              )
              .evaluate())
        '${e.widget.key}',
    ];
    debugDefaultTargetPlatformOverride = null;
    return found;
  }

  testWidgets('a phone sees only its own store', (tester) async {
    expect(await keys(tester, TargetPlatform.android), [
      "[<'get-the-app-android'>]",
    ]);
    expect(await keys(tester, TargetPlatform.iOS), ["[<'get-the-app-ios'>]"]);
  });

  testWidgets('a computer sees all four, its own first', (tester) async {
    final mac = await keys(tester, TargetPlatform.macOS);
    expect(mac.first, "[<'get-the-app-mac'>]");
    expect(mac, hasLength(4));
    expect(
      (await keys(tester, TargetPlatform.windows)).first,
      "[<'get-the-app-windows'>]",
    );
  });

  testWidgets('not drawn outside the web', (tester) async {
    await tester.pumpWidget(
      const MaterialApp(home: Scaffold(body: GetTheAppRow(web: false))),
    );
    expect(find.text('Get the app'), findsNothing);
  });
}
