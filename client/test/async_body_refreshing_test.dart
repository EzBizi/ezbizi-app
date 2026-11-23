import 'package:ezbizi_client/widgets/async_body.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  testWidgets(
    'refreshing dims the old rows and blocks taps under a progress line',
    (tester) async {
      var taps = 0;
      Widget body(bool refreshing) => MaterialApp(
        home: Scaffold(
          body: AsyncBody(
            loading: false,
            refreshing: refreshing,
            error: null,
            onRetry: () {},
            child: TextButton(
              onPressed: () => taps++,
              child: const Text('row'),
            ),
          ),
        ),
      );

      await tester.pumpWidget(body(true));
      expect(find.byType(LinearProgressIndicator), findsOneWidget);
      await tester.tap(find.text('row'), warnIfMissed: false);
      expect(taps, 0);

      await tester.pumpWidget(body(false));
      expect(find.byType(LinearProgressIndicator), findsNothing);
      await tester.tap(find.text('row'));
      expect(taps, 1);
    },
  );
}
