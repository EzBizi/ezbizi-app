import 'package:flutter/material.dart';

/// The block vocabulary, and the one place the client knows it.
///
/// ── Why this file holds no HTML ─────────────────────────────────
///
/// The email is rendered by `App\Support\Newsletter\BlockRenderer` on the
/// server, and nothing here reproduces a line of it. Email HTML is table
/// layouts, attribute widths beside CSS widths, and a media query Outlook
/// ignores — a second renderer in Dart would drift from the first within a
/// week, and the drift would show as an email that looked right in the
/// composer and broke in somebody's inbox.
///
/// So this file describes what a block IS (its fields, its name, its icon) and
/// the preview pane asks the server what it LOOKS like.
enum BlockKind {
  heading('heading', 'Heading', Icons.title),
  text('text', 'Text', Icons.notes_outlined),
  image('image', 'Image', Icons.image_outlined),
  button('button', 'Button', Icons.smart_button_outlined),
  columns('columns', 'Columns', Icons.view_column_outlined),
  divider('divider', 'Divider', Icons.horizontal_rule),
  spacer('spacer', 'Spacer', Icons.height);

  const BlockKind(this.type, this.label, this.icon);

  final String type;
  final String label;
  final IconData icon;

  static BlockKind? forType(String type) {
    for (final kind in BlockKind.values) {
      if (kind.type == type) return kind;
    }
    return null;
  }

  /// A new block of this kind, with defaults that render to something visible.
  ///
  /// An empty block is worse than no block: it renders as nothing, so somebody
  /// who adds one and sees no change in the preview concludes the editor is
  /// broken rather than that they have not typed yet.
  Map<String, dynamic> blank() => switch (this) {
    BlockKind.heading => {'type': 'heading', 'text': 'Heading', 'size': 24, 'align': 'left'},
    BlockKind.text => {
      'type': 'text',
      'text': 'Write something here.',
      'align': 'left',
    },
    BlockKind.image => {'type': 'image', 'src': '', 'alt': ''},
    BlockKind.button => {
      'type': 'button',
      'text': 'Book now',
      'url': '',
      'align': 'left',
    },
    BlockKind.columns => {
      'type': 'columns',
      'columns': [
        {
          'blocks': [
            {'type': 'text', 'text': 'Left', 'align': 'left'},
          ],
        },
        {
          'blocks': [
            {'type': 'text', 'text': 'Right', 'align': 'left'},
          ],
        },
      ],
    },
    BlockKind.divider => {'type': 'divider'},
    BlockKind.spacer => {'type': 'spacer', 'height': 16},
  };
}

/// Has this block been hand-edited as HTML?
///
/// The server keeps such a block exactly as written rather than rebuilding it
/// from fields, so the editor must stop offering the fields — showing a Text
/// box whose value is ignored is the worst of both.
bool isHandWritten(Map<String, dynamic> block) {
  final html = block['html'];
  return html is String && html.trim().isNotEmpty;
}

/// A mailing list, as the server describes it.
///
/// `kind` is `customers` (built from the customer book) or `mailchimp` (one of
/// the audiences that already exists in the connected account). The editor
/// shows the difference because it changes who is really on the list.
class NewsletterAudience {
  const NewsletterAudience({
    required this.id,
    required this.name,
    required this.kind,
  });

  final String id;
  final String name;
  final String kind;

  String get where =>
      kind == 'mailchimp' ? 'in Mailchimp' : 'built from your customers';

  factory NewsletterAudience.fromJson(Map<String, dynamic> json) =>
      NewsletterAudience(
        id: json['id'] as String? ?? '',
        name: json['name'] as String? ?? '',
        kind: json['kind'] as String? ?? '',
      );
}
