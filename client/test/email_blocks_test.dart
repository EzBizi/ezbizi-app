import 'package:ezbizi_client/screens/email/mail_data.dart';
import 'package:ezbizi_client/screens/email/message_blocks.dart';
import 'package:flutter_test/flutter_test.dart';

/// Reading the body the server sanitised.
///
/// ── What is actually being defended ──────────────────────────────
///
/// One rule, and it is the reason the module is shaped the way it is: the body
/// of an email is written by a stranger, so it never reaches this client as
/// markup. The server sanitises it into blocks and this reads blocks.
///
/// The tests below are the two halves of keeping that true. First, that a
/// string of HTML smuggled into the payload is not read by anything — no key
/// here carries markup, and the day somebody adds one these fail. Second, that
/// tolerating an unknown block never costs a sentence: this client and the
/// endpoint ship separately, and a reader that dropped what it did not
/// recognise would quietly lose paragraphs for the length of a deploy.
void main() {
  test('a paragraph is read as its runs, with the emphasis it arrived with', () {
    final blocks = readMailBlocks([
      {
        'kind': 'paragraph',
        'spans': [
          {'text': 'Could somebody look at '},
          {'text': 'the flue', 'strong': true},
          {'text': ' this week?', 'emphasis': true},
        ],
      },
    ]);

    expect(blocks, hasLength(1));
    expect(blocks.single.kind, MailBlockKind.paragraph);
    expect(blocks.single.text, 'Could somebody look at the flue this week?');
    expect(blocks.single.spans[1].strong, isTrue);
    expect(blocks.single.spans[2].emphasis, isTrue);
  });

  /// The whole design in one assertion. A block carrying markup is read for its
  /// SPANS and nothing else; there is no field here that a string of HTML can
  /// arrive in, and no widget downstream that could draw one.
  test('markup smuggled alongside the spans is not read at all', () {
    final blocks = readMailBlocks([
      {
        'kind': 'paragraph',
        'spans': [
          {'text': 'Hello'},
        ],
        'html': '<img src=x onerror=alert(1)>',
        'content': '<script>alert(1)</script>',
      },
    ]);

    // The CONTROL: the block was genuinely read, so "the markup is absent" is
    // not the vacuous truth of nothing having been produced.
    expect(blocks.single.text, 'Hello');

    expect(
      blocks.single.spans.any((span) => span.text.contains('<')),
      isFalse,
      reason: 'A span was built out of a markup key. There is no safe way to '
          'draw that, which is why the server sends blocks.',
    );
  });

  /// A newer server, a kind this bundle has never met. The words survive —
  /// losing a sentence because it arrived wrapped in something new is the one
  /// failure a mail window must not have.
  test('an unknown kind keeps its words, as a paragraph', () {
    final blocks = readMailBlocks([
      {
        'kind': 'callout',
        'spans': [
          {'text': 'Your appointment is confirmed.'},
        ],
      },
    ]);

    expect(blocks.single.kind, MailBlockKind.paragraph);
    expect(blocks.single.text, 'Your appointment is confirmed.');
  });

  test('a picture keeps its token and its description', () {
    final blocks = readMailBlocks([
      {
        'kind': 'image',
        'alt': 'Everdoors banner',
        'src': 'sealed-token',
        'spans': [
          {'text': 'Everdoors banner'},
        ],
      },
    ]);

    expect(blocks.single.kind, MailBlockKind.image);
    expect(blocks.single.alt, 'Everdoors banner');
    expect(blocks.single.src, 'sealed-token');
  });

  test('a picture keeps the width the message asked for', () {
    final blocks = readMailBlocks([
      {
        'kind': 'image',
        'src': 'sealed-token',
        'width': 140,
        'height': 40,
      },
    ]);

    expect(blocks.single.width, 140);
    expect(blocks.single.height, 40);
  });

  test('a blocked image keeps what it was and where it would have come from',
      () {
    final blocks = readMailBlocks([
      {
        'kind': 'blocked_image',
        'alt': 'Everdoors banner',
        'host': 'trk.example.com',
      },
      {'kind': 'blocked_image'},
    ]);

    expect(blocks.first.kind, MailBlockKind.blockedImage);
    expect(blocks.first.alt, 'Everdoors banner');
    expect(blocks.first.host, 'trk.example.com');

    expect(blocks.last.alt, isNull);
    expect(blocks.last.host, isNull);
  });

  /// A rule has no spans and must not be swept up by the "nothing to draw"
  /// filter that removes empty paragraphs.
  test('a rule survives having no text', () {
    final blocks = readMailBlocks([
      {'kind': 'rule'},
      {
        'kind': 'paragraph',
        'spans': [
          {'text': '   '},
        ],
      },
    ]);

    expect(blocks, hasLength(1));
    expect(blocks.single.kind, MailBlockKind.rule);
  });

  test('a body that is not a list at all is no blocks rather than a crash', () {
    expect(readMailBlocks(null), isEmpty);
    expect(readMailBlocks('<p>hello</p>'), isEmpty);
    expect(readMailBlocks({'kind': 'paragraph'}), isEmpty);
  });

  test('a span with no text is skipped and its neighbours are not', () {
    final blocks = readMailBlocks([
      {
        'kind': 'paragraph',
        'spans': [
          {'text': 'Before'},
          {'strong': true},
          {'text': ' after'},
        ],
      },
    ]);

    expect(blocks.single.text, 'Before after');
  });

  /// The link is carried whole, because the screen shows it: "click here" over
  /// an address nobody can see is the shape of a phishing email.
  test('a link keeps its address', () {
    final blocks = readMailBlocks([
      {
        'kind': 'paragraph',
        'spans': [
          {'text': 'Click here', 'link': 'https://not-your-bank.example.com/x'},
        ],
      },
    ]);

    expect(
      blocks.single.spans.single.link,
      'https://not-your-bank.example.com/x',
    );
  });

  test('sanitised html on the opened message is kept as a string', () {
    final detail = MessageDetail.fromJson({
      'id': 'm-1',
      'subject': 'Trial',
      'from': {'name': 'Google Cloud', 'address': 'cloud@google.com'},
      'blocks': [
        {
          'kind': 'paragraph',
          'spans': [
            {'text': 'fallback'},
          ],
        },
      ],
      'html':
          '<!DOCTYPE html><html><body><table width="600"><tr><td>Hello</td></tr></table></body></html>',
      'reply': {
        'as': {'address': 'info@example.com', 'name': 'Info'},
        'to': ['cloud@google.com'],
        'subject': 'Re: Trial',
      },
    });

    expect(detail.html, contains('<table'));
    expect(detail.html, contains('Hello'));
    expect(detail.blocks.single.text, 'fallback');
  });
}
