import 'package:ezbizi_client/widgets/async_body.dart';
import 'package:ezbizi_client/widgets/loading_skeleton.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

/// Mobile review 2026-11-13, P2: a first load draws grey rows shaped like the
/// list, not a lone spinner, on every screen that loads through AsyncBody.
void main() {
  testWidgets('a first load draws the skeleton without being asked', (
    tester,
  ) async {
    await tester.pumpWidget(
      MaterialApp(
        home: Scaffold(
          body: AsyncBody(
            loading: true,
            error: null,
            onRetry: () {},
            child: const Text('rows'),
          ),
        ),
      ),
    );
    expect(find.byType(LoadingSkeleton), findsOneWidget);
    expect(find.byType(CircularProgressIndicator), findsNothing);
  });
}
