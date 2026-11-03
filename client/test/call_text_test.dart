import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/widgets/call_text.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  testWidgets('each speaker label has its colour; the words keep the ink', (
    tester,
  ) async {
    await tester.pumpWidget(
      MaterialApp(
        theme: EzBiziTheme.light(),
        home: const Scaffold(
          body: CallTranscriptText(
            'IVR menu: Press 1.\nDispatcher: Hello.\nCustomer: Hi.',
          ),
        ),
      ),
    );

    final context = tester.element(find.byType(CallTranscriptText));
    final spans = <String, TextStyle?>{};
    (tester.widget<SelectableText>(find.byType(SelectableText)).textSpan!)
        .visitChildren((s) {
          if (s is TextSpan && s.text != null) spans[s.text!] = s.style;
          return true;
        });

    expect(spans['Dispatcher: ']?.color, Theme.of(context).colorScheme.primary);
    expect(spans['Customer: ']?.color, ToneColours.of(context, Tone.ok).ink);
    expect(spans['IVR menu: ']?.fontStyle, FontStyle.italic);
    expect(spans['Hello.'], isNull);

    await tester.tap(find.byTooltip('Copy'));
    await tester.pump();
    expect(find.text('Copied'), findsOneWidget);
  });
}
