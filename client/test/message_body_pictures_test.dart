import 'dart:convert';

import 'package:ezbizi_client/core/api_client.dart';
import 'package:ezbizi_client/screens/email/message_blocks.dart';
import 'package:ezbizi_client/screens/email/message_body.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Pictures in the body, which is the half of the desktop path nothing tested.
///
/// ── WHY THIS IS WHERE THE BLANK BODY WAS LOOKED FOR ──────────────
///
/// Measured against production on 2026-09-23: of 150 real messages, **128
/// carry HTML and 22 do not**. `_body()` takes the iframe only when `kIsWeb`
/// AND the response has non-empty html, so on WEB roughly 85% of messages
/// never touch this widget at all -- and the 22 that do are tiny, 1 to 5
/// blocks of plain text.
///
/// That kills the comparison the investigation had been resting on. "Web is
/// fine and desktop is blank" was read as one platform rendering the same
/// content worse. It is not: the two platforms render DIFFERENT CONTENT. Every
/// message on desktop comes through these blocks, including the 128 whose
/// blocks were derived from rich HTML and carry the pictures that come with
/// it. Web almost never sees that case.
///
/// So the untested surface is not the platform, it is the pictures. Earlier
/// tests here passed `api: null`, which sends every `_MailPicture` straight to
/// its "could not be shown" placeholder without one byte being decoded -- the
/// image path looked covered and had never run.
///
/// These feed it bytes.
void main() {
  /// An ApiClient whose byte fetches return exactly what the test says.
  ApiClient clientReturning(List<int>? bytes) => ApiClient(
    client: MockClient((request) async {
      if (bytes == null) return http.Response('no', 404);

      return http.Response.bytes(
        bytes,
        200,
        headers: {'content-type': 'image/png'},
      );
    }),
  );

  Widget pane(List<MailBlock> blocks, ApiClient api) => MaterialApp(
    theme: ThemeData(platform: TargetPlatform.windows),
    home: Scaffold(
      body: SizedBox(
        width: 700,
        height: 420,
        child: SingleChildScrollView(
          child: MessageBody(
            blocks: blocks,
            api: api,
            picturePath: '/email/mailboxes/mb-1/messages/m-1/picture',
          ),
        ),
      ),
    ),
  );

  /// A paragraph, then a picture, then another paragraph — the ordinary shape
  /// of the 128. The text either side is what the assertions watch: the report
  /// is that the WORDS vanish, so a picture that takes its own failure quietly
  /// is fine and a picture that takes the paragraphs with it is the bug.
  List<MailBlock> blocksAround(String src) => [
    const MailBlock(
      MailBlockKind.paragraph,
      spans: [MailSpan('Before the picture')],
    ),
    MailBlock(MailBlockKind.image, src: src, alt: 'A logo', width: 600),
    const MailBlock(
      MailBlockKind.paragraph,
      spans: [MailSpan('After the picture')],
    ),
  ];

  testWidgets('bytes that are not an image do not take the words with them', (
    tester,
  ) async {
    // 200 OK, content-type image/png, and the body is not a PNG. This is the
    // realistic failure: a tracking pixel, an expired CDN object answering
    // with an HTML error page, a truncated response.
    await tester.pumpWidget(
      pane(
        blocksAround('https://cdn.example.net/logo.png'),
        clientReturning(utf8.encode('<html>not a picture at all</html>')),
      ),
    );
    await tester.pumpAndSettle();

    expect(find.text('Before the picture'), findsOneWidget);
    expect(find.text('After the picture'), findsOneWidget);
  });

  testWidgets('and neither does a fetch that refuses', (tester) async {
    await tester.pumpWidget(
      pane(blocksAround('https://cdn.example.net/logo.png'), clientReturning(null)),
    );
    await tester.pumpAndSettle();

    expect(find.text('Before the picture'), findsOneWidget);
    expect(find.text('After the picture'), findsOneWidget);
  });

  testWidgets('nor an empty 200, which is not the same as a refusal', (
    tester,
  ) async {
    // Zero bytes with a success status. `_MailPicture` checks `isNotEmpty`
    // before drawing, and this is the case that would slip past a null check.
    await tester.pumpWidget(
      pane(blocksAround('https://cdn.example.net/logo.png'), clientReturning(const [])),
    );
    await tester.pumpAndSettle();

    expect(find.text('Before the picture'), findsOneWidget);
    expect(find.text('After the picture'), findsOneWidget);
  });

  testWidgets('a data: URI that is not valid base64 is survivable too', (
    tester,
  ) async {
    // This one never reaches the network: `_decodeDataUri` runs in initState,
    // so a throw here happens during the first build rather than after it.
    await tester.pumpWidget(
      pane(
        blocksAround('data:image/png;base64,!!!! not base64 !!!!'),
        clientReturning(null),
      ),
    );
    await tester.pumpAndSettle();

    expect(find.text('Before the picture'), findsOneWidget);
    expect(find.text('After the picture'), findsOneWidget);
  });

  testWidgets('seventeen failing pictures still leave every paragraph', (
    tester,
  ) async {
    // The captured production message has 17 pictures and 42 paragraphs. One
    // failure is a placeholder; the question is whether seventeen of them,
    // each rebuilding as its fetch lands, disturb the column around them.
    await tester.pumpWidget(
      pane(
        [
          for (var i = 0; i < 17; i++) ...[
            MailBlock(MailBlockKind.paragraph, spans: [MailSpan('Line $i')]),
            MailBlock(
              MailBlockKind.image,
              src: 'https://cdn.example.net/$i.png',
              width: 600,
            ),
          ],
        ],
        clientReturning(utf8.encode('not a picture')),
      ),
    );
    await tester.pumpAndSettle();

    expect(find.textContaining('Line '), findsWidgets);
    expect(find.text('Line 16'), findsOneWidget);
  });
}
