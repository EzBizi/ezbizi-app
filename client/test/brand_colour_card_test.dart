import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/core/theme.dart';
import 'package:ezbizi_client/widgets/brand_colour_card.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Organization → Brand colour (UI/UX layouts 1 + 6, chosen 2026-09-09).
void main() {
  testWidgets('not set: says so, and still shows what the site renders', (
    tester,
  ) async {
    await _pump(tester, own: null, effective: '#0b5cad', on: '#ffffff');

    expect(find.textContaining('You have not set a colour'), findsOneWidget);
    expect(find.byKey(BrandColourCard.previewKey), findsOneWidget);
    expect(find.textContaining('white on this colour'), findsOneWidget);
  });

  testWidgets('set: the not-set sentence goes away', (tester) async {
    await _pump(tester, own: '#00838f', effective: '#00838f', on: '#000000');

    expect(find.textContaining('You have not set a colour'), findsNothing);
    expect(find.textContaining('black on this colour'), findsOneWidget);
  });

  /// The whole point of reading `on_effective` instead of computing it.
  ///
  /// The server here returns a deliberately WRONG pairing — white text on a
  /// near-white colour. A card that derived the text itself would draw black
  /// and quietly disagree with the widget on the customer's website. This
  /// asserts the card draws what it was told.
  testWidgets('the text colour comes from the server, not from arithmetic here', (
    tester,
  ) async {
    await _pump(tester, own: '#fefefe', effective: '#fefefe', on: '#ffffff');

    final label = tester.widget<Text>(
      find.descendant(
        of: find.byKey(BrandColourCard.previewKey),
        matching: find.text('Send'),
      ),
    );
    expect(
      label.style?.color,
      const Color(0xFFFFFFFF),
      reason: 'The card computed its own text colour instead of using the '
          'server\'s on_effective, which is how two definitions of readable '
          'start to drift.',
    );
    expect(find.textContaining('white on this colour'), findsOneWidget);
  });

  testWidgets('tapping a preset saves it and takes the new pair back', (
    tester,
  ) async {
    final sent = <String?>[];
    await _pump(
      tester,
      own: null,
      effective: '#0b5cad',
      on: '#ffffff',
      onPut: (colour) {
        sent.add(colour);
        return {
          'organization': colour,
          'effective': colour,
          'on_effective': '#000000',
        };
      },
    );

    final swatch = find.descendant(
      of: find.byKey(BrandColourCard.swatchesKey),
      matching: find.byType(InkWell),
    );
    expect(swatch, findsNWidgets(7));

    await tester.tap(swatch.first);
    await tester.pumpAndSettle();

    expect(sent, ['#00838f']);
    expect(find.textContaining('You have not set a colour'), findsNothing);
    expect(find.textContaining('black on this colour'), findsOneWidget);
  });

  testWidgets('a reader cannot change it', (tester) async {
    final sent = <String?>[];
    await _pump(
      tester,
      own: '#00838f',
      effective: '#00838f',
      on: '#000000',
      mayEdit: false,
      onPut: (colour) {
        sent.add(colour);
        return {'organization': colour, 'effective': colour, 'on_effective': '#000000'};
      },
    );

    await tester.tap(
      find
          .descendant(
            of: find.byKey(BrandColourCard.swatchesKey),
            matching: find.byType(InkWell),
          )
          .first,
    );
    await tester.pumpAndSettle();

    expect(sent, isEmpty);
    expect(
      tester.widget<TextFormField>(find.byType(TextFormField)).enabled,
      isFalse,
    );
  });

  /// Layout 6: on a phone the swatches come FIRST, so the common act does not
  /// open a keyboard. Layout 1 puts the field first beside the preview.
  testWidgets('on a phone the swatches sit above the hex field', (tester) async {
    await _pump(
      tester,
      own: '#00838f',
      effective: '#00838f',
      on: '#000000',
      width: 400,
    );

    expect(
      tester.getTopLeft(find.byKey(BrandColourCard.swatchesKey)).dy,
      lessThan(tester.getTopLeft(find.byType(TextFormField)).dy),
    );
  });

  testWidgets('on a desktop the hex field sits above the swatches', (
    tester,
  ) async {
    await _pump(tester, own: '#00838f', effective: '#00838f', on: '#000000');

    expect(
      tester.getTopLeft(find.byType(TextFormField)).dy,
      lessThan(tester.getTopLeft(find.byKey(BrandColourCard.swatchesKey)).dy),
    );
  });

  /// Nothing invented: with no derived colour there is no honest button.
  testWidgets('no derived colour means no button rather than a guessed one', (
    tester,
  ) async {
    await _pump(tester, own: '#00838f', effective: '#00838f', on: null);

    expect(find.byKey(BrandColourCard.previewKey), findsNothing);
    expect(find.byKey(BrandColourCard.swatchesKey), findsOneWidget);
  });
}

Future<void> _pump(
  WidgetTester tester, {
  required String? own,
  required String? effective,
  required String? on,
  bool mayEdit = true,
  double width = 1200,
  Map<String, Object?> Function(String? colour)? onPut,
}) async {
  tester.view.physicalSize = Size(width, 1000);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  final api = ApiClient(
    client: MockClient((request) async {
      if (request.method == 'PUT') {
        final body = jsonDecode(request.body) as Map<String, dynamic>;
        final data =
            onPut?.call(body['colour'] as String?) ??
            {'organization': own, 'effective': effective, 'on_effective': on};
        return http.Response(
          jsonEncode({'data': data}),
          200,
          headers: {'content-type': 'application/json'},
        );
      }
      return http.Response(
        jsonEncode({
          'data': {
            'organization': own,
            'effective': effective,
            'on_effective': on,
          },
        }),
        200,
        headers: {'content-type': 'application/json'},
      );
    }),
  );

  await tester.pumpWidget(
    MaterialApp(
      theme: EzBiziTheme.light(),
      home: Scaffold(
        body: SingleChildScrollView(
          child: BrandColourCard(api: api, mayEdit: mayEdit),
        ),
      ),
    ),
  );
  await tester.pumpAndSettle();
}
