import 'package:flutter/material.dart';
import 'package:flutter_html/flutter_html.dart';

import '../../core/theme.dart';

/// The block vocabulary, and the email drawn from it.
///
/// ── Two renderings, and which one is the truth ──────────────────
///
/// The email that goes out is rendered by `App\Support\Newsletter\BlockRenderer`
/// on the server, and nothing here writes a line of HTML. Until 2026-10-02 the
/// composer only showed that server HTML, so a block was edited in one place
/// and read in another. Mia asked for the email itself to be the editor
/// (layout A): click a heading, change it where it sits. So [NewsletterEmail]
/// draws the blocks in Flutter with the renderer's own sizes and colours, and
/// the composer keeps a "Server preview" switch beside it so the exact document
/// is one tap away. When the two disagree, the server preview is the truth and
/// this file is the bug.
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
    this.tag,
    this.mailchimpListId,
  });

  /// The Mailchimp list this EzBizi list sends through.
  final String? mailchimpListId;

  final String id;
  final String name;
  final String kind;

  /// Narrows the send: a Mailchimp tag, or (customers) a tag on their jobs.
  final String? tag;

  /// The picker's label: "Name · tag" when the list is narrowed.
  String get label => tag == null || tag!.isEmpty ? name : '$name · $tag';

  String get where =>
      kind == 'mailchimp' ? 'in Mailchimp' : 'built from your customers';

  factory NewsletterAudience.fromJson(Map<String, dynamic> json) =>
      NewsletterAudience(
        id: json['id'] as String? ?? '',
        name: json['name'] as String? ?? '',
        kind: json['kind'] as String? ?? '',
        tag: json['tag'] as String?,
        mailchimpListId: json['mailchimp_list_id'] as String?,
      );
}

/// The email, drawn from its blocks the way `BlockRenderer` lays them out.
///
/// [phone] narrows the paper to a phone's width and stacks columns, which is
/// what the renderer's media query does in a mail client that honours it.
/// Tapping a block calls [onSelect]; the selected block is outlined and drawn
/// by [editorFor] instead, so it is edited where it sits.
class NewsletterEmail extends StatelessWidget {
  const NewsletterEmail({
    super.key,
    required this.blocks,
    required this.company,
    this.phone = false,
    this.selected,
    this.onSelect,
    this.editorFor,
    this.onReorder,
    this.onDropNew,
  });

  final List<Map<String, dynamic>> blocks;

  /// The name in the footer. The renderer writes the organization's.
  final String company;
  final bool phone;
  final int? selected;

  /// Null when the newsletter cannot be changed: nothing is tappable then.
  final ValueChanged<int>? onSelect;
  final Widget Function(int index)? editorFor;

  /// Moves a block, `to` already counted after the removal. Null hides the
  /// drag handles.
  final void Function(int from, int to)? onReorder;

  /// Inserts a block dragged from the palette at an index. Null accepts none.
  final void Function(BlockKind kind, int at)? onDropNew;

  /// The renderer's 600px table, and a phone's width less its margins.
  static const desktopWidth = 600.0;
  static const phoneWidth = 340.0;

  @override
  Widget build(BuildContext context) {
    final primary = Theme.of(context).colorScheme.primary;

    return Container(
      color: EmailInk.page,
      padding: EdgeInsets.symmetric(vertical: 18, horizontal: phone ? 8 : 18),
      alignment: Alignment.topCenter,
      child: ConstrainedBox(
        key: const Key('newsletter-email'),
        constraints: BoxConstraints(
          maxWidth: phone ? phoneWidth : desktopWidth,
        ),
        child: ClipRRect(
          borderRadius: BorderRadius.circular(6),
          child: ColoredBox(
            color: EmailInk.paper,
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                if (blocks.isEmpty)
                  const Padding(
                    padding: EdgeInsets.all(20),
                    child: Text(
                      'Add a block to start writing.',
                      style: TextStyle(fontSize: 15, color: EmailInk.body),
                    ),
                  ),
                if (onReorder == null)
                  for (var i = 0; i < blocks.length; i++) _block(i, primary)
                else
                  ReorderableListView(
                    shrinkWrap: true,
                    physics: const NeverScrollableScrollPhysics(),
                    buildDefaultDragHandles: false,
                    onReorder: (from, to) => onReorder!(from, to > from ? to - 1 : to),
                    children: [
                      for (var i = 0; i < blocks.length; i++)
                        KeyedSubtree(key: ObjectKey(blocks[i]), child: _draggable(i, primary)),
                    ],
                  ),
                _dropAt(blocks.length, primary, _footer()),
              ],
            ),
          ),
        ),
      ),
    );
  }

  Widget _block(int i, Color primary) {
    if (i == selected && editorFor != null) {
      return Container(
        key: Key('newsletter-block-$i'),
        margin: const EdgeInsets.all(4),
        decoration: BoxDecoration(
          border: Border.all(color: primary, width: 2),
          borderRadius: BorderRadius.circular(6),
        ),
        child: editorFor!(i),
      );
    }
    return _tappable(i, EmailBlockView(block: blocks[i], phone: phone));
  }

  /// A block with its drag handle in the corner, and a new block from the
  /// palette dropped on it lands above it.
  Widget _draggable(int i, Color primary) {
    return _dropAt(
      i,
      primary,
      Stack(
        children: [
          _block(i, primary),
          Positioned(
            top: 4,
            right: 4,
            child: ReorderableDragStartListener(
              index: i,
              child: MouseRegion(
                cursor: SystemMouseCursors.grab,
                child: Tooltip(
                  message: 'Drag to move',
                  child: Icon(
                    Icons.drag_indicator,
                    key: Key('newsletter-block-handle-$i'),
                    size: 18,
                    color: EmailInk.body,
                  ),
                ),
              ),
            ),
          ),
        ],
      ),
    );
  }

  /// Accepts a [BlockKind] dragged from the palette, inserting it at [index];
  /// a line above [child] shows where it will land.
  Widget _dropAt(int index, Color primary, Widget child) {
    if (onDropNew == null) return child;
    return DragTarget<BlockKind>(
      onAcceptWithDetails: (details) => onDropNew!(details.data, index),
      builder: (context, candidates, _) => Container(
        key: Key('newsletter-drop-$index'),
        decoration: BoxDecoration(
          border: Border(
            top: BorderSide(
              color: candidates.isEmpty ? Colors.transparent : primary,
              width: 3,
            ),
          ),
        ),
        child: child,
      ),
    );
  }

  Widget _tappable(int i, Widget child) {
    if (onSelect == null) {
      return KeyedSubtree(key: Key('newsletter-block-$i'), child: child);
    }

    return Material(
      key: Key('newsletter-block-$i'),
      color: Colors.transparent,
      child: InkWell(onTap: () => onSelect!(i), child: child),
    );
  }

  // The footer the renderer always adds. Not a block, because it cannot be
  // removed: marketing mail without a way out is unlawful where this sells.
  Widget _footer() {
    return Container(
      color: EmailInk.footer,
      padding: const EdgeInsets.symmetric(horizontal: 20, vertical: 16),
      child: Column(
        children: [
          Text(
            company,
            textAlign: TextAlign.center,
            style: const TextStyle(
              fontSize: 11.5,
              height: 1.5,
              color: EmailInk.footerInk,
            ),
          ),
          const Text(
            'Unsubscribe from these emails',
            textAlign: TextAlign.center,
            style: TextStyle(
              fontSize: 11.5,
              height: 1.5,
              color: EmailInk.footerInk,
              decoration: TextDecoration.underline,
            ),
          ),
        ],
      ),
    );
  }
}

/// One block, as the renderer draws it: its padding, size, colour and
/// alignment.
class EmailBlockView extends StatelessWidget {
  const EmailBlockView({super.key, required this.block, this.phone = false});

  final Map<String, dynamic> block;
  final bool phone;

  @override
  Widget build(BuildContext context) {
    // Kept exactly as typed on the server; drawn here close enough to edit
    // by, and exactly under Server preview.
    if (isHandWritten(block)) {
      return _row(Html(data: block['html'] as String));
    }

    final align = _align(block);

    return switch (BlockKind.forType(block['type'] as String? ?? '')) {
      BlockKind.heading => _row(
        Text(
          block['text'] as String? ?? '',
          textAlign: align,
          style: TextStyle(
            fontSize: _int(block, 'size', 24).toDouble(),
            height: 1.25,
            fontWeight: FontWeight.bold,
            color: EmailInk.heading,
          ),
        ),
      ),
      BlockKind.text => _row(
        Text(
          block['text'] as String? ?? '',
          textAlign: align,
          style: const TextStyle(
            fontSize: 15,
            height: 1.55,
            color: EmailInk.body,
          ),
        ),
      ),
      BlockKind.image => _row(_image()),
      BlockKind.button => _row(
        Align(
          alignment: switch (align) {
            TextAlign.center => Alignment.center,
            TextAlign.right => Alignment.centerRight,
            _ => Alignment.centerLeft,
          },
          child: DecoratedBox(
            decoration: BoxDecoration(
              color: EmailInk.button,
              borderRadius: BorderRadius.circular(6),
            ),
            child: Padding(
              padding: const EdgeInsets.symmetric(horizontal: 20, vertical: 11),
              child: Text(
                block['text'] as String? ?? '',
                style: const TextStyle(
                  fontSize: 15,
                  fontWeight: FontWeight.w600,
                  color: EmailInk.paper,
                ),
              ),
            ),
          ),
        ),
      ),
      BlockKind.divider => const Padding(
        padding: EdgeInsets.symmetric(horizontal: 20, vertical: 8),
        child: Divider(height: 1, thickness: 1, color: EmailInk.rule),
      ),
      BlockKind.spacer => SizedBox(
        height: _int(block, 'height', 16).toDouble(),
      ),
      BlockKind.columns => _columns(),
      // The renderer draws nothing for a type it does not know, so neither
      // does this.
      null => const SizedBox.shrink(),
    };
  }

  Widget _row(Widget child) => Padding(
    padding: const EdgeInsets.symmetric(horizontal: 20, vertical: 14),
    child: child,
  );

  Widget _image() {
    final src = (block['src'] as String? ?? '').trim();

    // No address yet: a grey plate the shape of a picture, so an added image
    // block is visible before anything is typed into it.
    final plate = Container(
      height: phone ? 90 : 160,
      color: EmailInk.rule,
      alignment: Alignment.center,
      child: Text(
        src.isEmpty ? 'Image · add its address' : (block['alt'] as String? ?? src),
        style: const TextStyle(fontSize: 13, color: EmailInk.footerInk),
      ),
    );

    if (src.isEmpty) return plate;

    return Image.network(
      src,
      width: double.infinity,
      fit: BoxFit.fitWidth,
      semanticLabel: block['alt'] as String?,
      errorBuilder: (_, _, _) => plate,
    );
  }

  /// Side by side on a computer, stacked on a phone, as the renderer's media
  /// query does it. Reads the composer's `{blocks: [...]}` and the bare list
  /// the renderer also accepts.
  Widget _columns() {
    final columns = [
      for (final c in (block['columns'] as List<dynamic>?) ?? const [])
        if (c is Map && c['blocks'] is List)
          (c['blocks'] as List).whereType<Map<String, dynamic>>().toList()
        else if (c is List)
          c.whereType<Map<String, dynamic>>().toList(),
    ];
    if (columns.isEmpty) return const SizedBox.shrink();

    Widget column(List<Map<String, dynamic>> inner) => Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        for (final child in inner) EmailBlockView(block: child, phone: phone),
      ],
    );

    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: 4),
      child: phone
          ? Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [for (final c in columns) column(c)],
            )
          : Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [for (final c in columns) Expanded(child: column(c))],
            ),
    );
  }

  static TextAlign _align(Map<String, dynamic> b) => switch (b['align']) {
    'center' => TextAlign.center,
    'right' => TextAlign.right,
    _ => TextAlign.left,
  };

  /// The renderer's bounds: outside 1-399 it uses the default.
  static int _int(Map<String, dynamic> b, String key, int fallback) {
    final raw = b[key];
    final v = raw is num ? raw.toInt() : int.tryParse('$raw') ?? fallback;
    return v > 0 && v < 400 ? v : fallback;
  }
}
