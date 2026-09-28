import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/widgets/address_fields.dart';
import 'package:flutter/material.dart';
import 'package:flutter/rendering.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  // The state box took a sixth of the row. On a phone that was narrower than
  // the box's own padding, so its ST label drew nothing at all.
  testWidgets('on a phone the state box shows its label', (tester) async {
    tester.view.physicalSize = const Size(375, 800);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);

    await tester.pumpWidget(
      MaterialApp(
        theme: EzBiziTheme.light(),
        home: Scaffold(
          body: AddressFields(
            api: ApiClient(),
            line1: TextEditingController(),
            line2: TextEditingController(),
            city: TextEditingController(),
            state: TextEditingController(),
            postal: TextEditingController(),
          ),
        ),
      ),
    );

    // The room the label is given against the room it needs.
    final label = tester.renderObject<RenderParagraph>(find.text('ST'));
    expect(
      label.constraints.maxWidth,
      greaterThanOrEqualTo(label.getMaxIntrinsicWidth(double.infinity)),
    );
  });
}
