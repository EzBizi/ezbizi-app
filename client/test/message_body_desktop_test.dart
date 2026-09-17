import 'dart:convert';
import 'dart:io';

import 'package:ezbizi_client/screens/email/message_blocks.dart';
import 'package:ezbizi_client/screens/email/message_body.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

/// The body drawn the way the DESKTOP build draws it, from a real message.
///
/// ── Why a captured payload and not a written one ─────────────────
///
/// Jose, 2026-09-17: a lot of emails show blank, "content loads for a second
/// and then vanishes", on desktop, where web is fine. Web takes the iframe
/// path and desktop takes this one, so the difference is real and this is the
/// half under suspicion.
///
/// Every fixture in email_blocks_test.dart was written here, and eleven of
/// them are green while the screen is blank — which is the whole lesson: a
/// fixture I invent proves the reader agrees with me, not that it agrees with
/// the server. `test/fixtures/real_message_blocks.json` is the biggest message
/// in the two production inboxes as the server actually published it (59
/// blocks, 42 paragraphs, 17 pictures), with every word replaced by x's of the
/// same length and every URL reduced to its host before it left the box. The
/// SHAPE is real; none of anybody's mail is in this repository.
///
/// ── And why the platform override ────────────────────────────────
///
/// SelectionArea, scrollbars and text-selection gestures all branch on the
/// target platform, and the report is platform-shaped. A test that runs as the
/// default fake platform is not testing the build that is broken.
void main() {
  late List<MailBlock> blocks;

  setUpAll(() {
    final json = jsonDecode(
      File('test/fixtures/real_message_blocks.json').readAsStringSync(),
    ) as Map<String, dynamic>;

    blocks = readMailBlocks(json['blocks']);
  });

  /// The screen's own arrangement: MessageBody inside the scroll view inside
  /// the bounded pane. Testing the widget alone would miss anything that only
  /// happens when it is laid out where it actually lives.
  ///
  /// The platform is carried on the theme rather than by
  /// `debugDefaultTargetPlatformOverride`, which the test framework asserts
  /// has been put back before the body returns. SelectionArea, the scrollbar
  /// and the text-selection gestures all read `Theme.of(context).platform`,
  /// so this is the same switch reached the supported way.
  Widget pane(List<MailBlock> blocks) => MaterialApp(
    theme: ThemeData(platform: TargetPlatform.windows),
    home: Scaffold(
      body: SizedBox(
        width: 700,
        height: 420,
        child: SingleChildScrollView(
          padding: const EdgeInsets.fromLTRB(20, 4, 20, 28),
          child: MessageBody(blocks: blocks),
        ),
      ),
    ),
  );

  testWidgets('a real message survives being read', (tester) async {
    expect(blocks.length, greaterThan(40), reason: 'fixture lost its blocks');

    await tester.pumpWidget(pane(blocks));
    await tester.pumpAndSettle();

    expect(tester.takeException(), isNull);
    expect(find.byType(Text), findsWidgets);
  });

  testWidgets('and survives being scrolled to the end', (tester) async {
    await tester.pumpWidget(pane(blocks));
    await tester.pumpAndSettle();

    // The report is that it appears and then goes. A first frame that draws is
    // therefore not the assertion that matters; what happens on the frames
    // after it is.
    await tester.drag(find.byType(SingleChildScrollView), const Offset(0, -4000));
    await tester.pumpAndSettle();

    expect(tester.takeException(), isNull);
    expect(find.byType(Text), findsWidgets);
  });

  testWidgets('and survives being selected across', (tester) async {
    await tester.pumpWidget(pane(blocks));
    await tester.pumpAndSettle();

    // SelectionArea is the one thing in this widget that is desktop-shaped:
    // a drag across the text on Windows is a selection, and computing the
    // geometry of one walks every span in the column.
    final gesture = await tester.startGesture(const Offset(60, 60));
    await tester.pump(const Duration(milliseconds: 100));
    await gesture.moveTo(const Offset(640, 380));
    await tester.pump();
    await gesture.up();
    await tester.pumpAndSettle();

    expect(tester.takeException(), isNull);
    expect(find.byType(Text), findsWidgets);
  });

  testWidgets('every link keeps its address beside it', (tester) async {
    // The server publishes a span's address as `href` (Span::toArray). This
    // asserts the client reads whatever the server sends, against a payload
    // the server sent -- the check email_blocks_test.dart cannot make, because
    // its fixtures are written to match the reader.
    final linked = [
      for (final block in blocks)
        for (final span in block.spans)
          if (span.link != null) span,
    ];

    final raw = jsonDecode(
      File('test/fixtures/real_message_blocks.json').readAsStringSync(),
    ) as Map<String, dynamic>;

    final addressed = [
      for (final block in (raw['blocks'] as List))
        for (final span in ((block as Map)['spans'] as List? ?? const []))
          if ((span as Map)['href'] is String &&
              (span['href'] as String).isNotEmpty)
            span,
    ];

    expect(
      addressed,
      isNotEmpty,
      reason: 'the captured message has no links, so this proves nothing',
    );
    expect(linked.length, addressed.length);
  });
}
