import 'package:ezbizi_client/core/timestamps.dart';
import 'package:ezbizi_client/widgets/setting_editors.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

/// Audit 2026-11-27: editing a day then pressing Cancel changed the screen's
/// value, because the editor wrote into the same day maps.
void main() {
  testWidgets('Cancel leaves the value it was given untouched', (tester) async {
    final value = <String, dynamic>{
      'monday': {'open': '08:00', 'close': '17:00'},
    };
    Map<String, dynamic>? saved;

    await tester.pumpWidget(
      MaterialApp(
        home: Scaffold(
          body: HoursButton(
            label: 'Business hours',
            value: value,
            editable: true,
            formats: DisplayFormats.platform,
            onChanged: (v) => saved = v,
          ),
        ),
      ),
    );
    await tester.tap(find.byType(HoursButton));
    await tester.pumpAndSettle();

    tester.widget<TimeField>(find.byType(TimeField).first).onChanged('09:30');
    await tester.pump();
    await tester.tap(find.text('Cancel'));
    await tester.pumpAndSettle();

    expect(saved, isNull);
    expect(value['monday'], {'open': '08:00', 'close': '17:00'});
  });
}
