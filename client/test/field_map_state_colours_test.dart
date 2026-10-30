import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/screens/field/map_overlays.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  testWidgets('unassigned job pin is the outline grey, not blue or yellow', (
    tester,
  ) async {
    late Color colour;
    late Color outline;
    await tester.pumpWidget(
      MaterialApp(
        home: Builder(
          builder: (context) {
            colour = fieldUnassignedJob(context);
            outline = Theme.of(context).colorScheme.outline;
            return const SizedBox();
          },
        ),
      ),
    );
    expect(colour, outline);
    expect(colour, isNot(EzBiziTheme.fieldBlue));
    expect(colour, isNot(EzBiziTheme.fieldYellow));
  });
}
