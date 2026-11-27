import 'package:flutter/material.dart';
import 'package:flutter_html/flutter_html.dart';

import '../../core/theme.dart';
import 'newsletter_rich_text.dart';

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
    BlockKind.heading => {
      'type': 'heading',
      'text': 'Heading',
      'size': 24,
      'align': 'left',
    },
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
///
/// ── Selection, not permanent controls (P1a, 2026-11-17) ─────────
///
/// A block shows nothing extra until it is pointed at (a faint outline) or
/// selected (an outline and a small toolbar from [chromeFor]: drag, up, down,
/// duplicate, delete). A selected heading or text is edited where it sits
/// through [editorFor]; every other setting lives in the inspector beside
/// the canvas. Blocks inside columns have no drag handle, so dragging cannot
/// pull one out of its column by accident.
class NewsletterEmail extends StatelessWidget {
  const NewsletterEmail({
    super.key,
    required this.blocks,
    required this.company,
    this.phone = false,
    this.deviceWidth,
    this.brand,
    this.selected,
    this.onSelect,
    this.editorFor,
    this.chromeFor,
    this.onReorder,
    this.onDropNew,
  });

  final List<Map<String, dynamic>> blocks;

  /// The name in the footer. The renderer writes the organization's.
  final String company;
  final bool phone;

  /// The phone width being designed for (320, 375 or 414, P1b); null uses
  /// [phoneWidth]. Only read when [phone] is true.
  final double? deviceWidth;

  /// The brand applied to this newsletter (P1b), drawn as the renderer
  /// applies it: only where a block sets nothing itself.
  final Map<String, dynamic>? brand;
  final int? selected;

  /// Null when the newsletter cannot be changed: nothing is tappable then.
  final ValueChanged<int>? onSelect;

  /// The selected block drawn as an in-place editor, or null to draw it as
  /// it is (its settings are then in the inspector).
  final Widget? Function(int index)? editorFor;

  /// The small toolbar over the selected block.
  final Widget Function(int index)? chromeFor;

  /// Moves a block, `to` already counted after the removal. Null: no drag.
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
          maxWidth: phone ? (deviceWidth ?? phoneWidth) : desktopWidth,
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
                      'Add content from the left to start writing.',
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
                    // A text field being typed in cannot travel with the
                    // drag (its caret is anchored where it was).
                    onReorderStart: (_) =>
                        FocusManager.instance.primaryFocus?.unfocus(),
                    onReorder: (from, to) =>
                        onReorder!(from, to > from ? to - 1 : to),
                    children: [
                      for (var i = 0; i < blocks.length; i++)
                        KeyedSubtree(
                          key: ObjectKey(blocks[i]),
                          child: _dropAt(i, primary, _block(i, primary)),
                        ),
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
    final isSelected = i == selected && onSelect != null;
    final inline = isSelected ? editorFor?.call(i) : null;
    return _BlockFrame(
      key: Key('newsletter-block-$i'),
      selected: isSelected,
      interactive: onSelect != null,
      primary: primary,
      onTap: onSelect == null ? null : () => onSelect!(i),
      chrome: isSelected ? chromeFor?.call(i) : null,
      child: inline ?? EmailBlockView(block: blocks[i], phone: phone, brand: brand),
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

/// One block on the canvas: a faint outline while pointed at, an outline and
/// [chrome] while selected, and nothing at all otherwise.
class _BlockFrame extends StatefulWidget {
  const _BlockFrame({
    super.key,
    required this.selected,
    required this.interactive,
    required this.primary,
    required this.onTap,
    required this.chrome,
    required this.child,
  });

  final bool selected;
  final bool interactive;
  final Color primary;
  final VoidCallback? onTap;
  final Widget? chrome;
  final Widget child;

  @override
  State<_BlockFrame> createState() => _BlockFrameState();
}

class _BlockFrameState extends State<_BlockFrame> {
  bool _hover = false;

  @override
  Widget build(BuildContext context) {
    final outline = widget.selected
        ? Border.all(color: widget.primary, width: 2)
        : _hover && widget.interactive
        ? Border.all(color: widget.primary.withValues(alpha: 0.35))
        : Border.all(color: Colors.transparent);

    // ColoredBox hit-tests its whole area, so a divider or spacer, which
    // draws almost nothing, can still be clicked.
    Widget body = ColoredBox(
      color: Colors.transparent,
      child: DecoratedBox(
        position: DecorationPosition.foreground,
        decoration: BoxDecoration(border: outline),
        child: widget.child,
      ),
    );

    if (widget.interactive && !widget.selected) {
      body = Material(
        color: Colors.transparent,
        child: InkWell(onTap: widget.onTap, child: body),
      );
    }

    final chrome = widget.chrome;
    return MouseRegion(
      onEnter: (_) => setState(() => _hover = true),
      onExit: (_) => setState(() => _hover = false),
      child: chrome == null
          ? body
          : Stack(
              clipBehavior: Clip.none,
              children: [
                body,
                Positioned(top: 4, right: 4, child: chrome),
              ],
            ),
    );
  }
}

/// One block, as the renderer draws it: its spacing, size, colour and
/// alignment, and formatted words.
class EmailBlockView extends StatelessWidget {
  const EmailBlockView({
    super.key,
    required this.block,
    this.phone = false,
    this.brand,
    this.resolved = false,
  });

  final Map<String, dynamic> block;
  final bool phone;
  final Map<String, dynamic>? brand;

  /// Brand and mobile overrides already applied to [block].
  final bool resolved;

  @override
  Widget build(BuildContext context) {
    if (!resolved) {
      if (phone && block['mobile'] is Map && (block['mobile'] as Map)['hidden'] == true) {
        return Opacity(
          key: const ValueKey('newsletter-hidden-on-mobile'),
          opacity: 0.45,
          child: Container(
            color: EmailInk.rule,
            padding: const EdgeInsets.symmetric(horizontal: 20, vertical: 6),
            child: const Text(
              'Hidden on mobile',
              style: TextStyle(fontSize: 12, color: EmailInk.footerInk),
            ),
          ),
        );
      }
      if (block['type'] == 'section_ref') return _linked();
      return EmailBlockView(
        block: effectiveBlock(block, brand: brand, phone: phone),
        phone: phone,
        brand: brand,
        resolved: true,
      );
    }
    // Kept exactly as typed on the server; drawn here close enough to edit
    // by, and exactly under Server preview.
    if (isHandWritten(block)) {
      return _row(Html(data: block['html'] as String));
    }

    final align = _align(block);

    return switch (BlockKind.forType(block['type'] as String? ?? '')) {
      BlockKind.heading => _row(
        _words(
          headingStyle(block),
          align,
        ),
      ),
      BlockKind.text => _row(_words(textStyle(block), align)),
      BlockKind.image => _row(_image()),
      BlockKind.button => _row(_button(align)),
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

  /// A linked saved section: its pinned version's blocks, marked as linked.
  Widget _linked() {
    final inner = [
      for (final b in (block['preview_blocks'] as List? ?? const []))
        if (b is Map<String, dynamic>) b,
    ];
    return Container(
      key: const ValueKey('newsletter-linked-section'),
      decoration: const BoxDecoration(
        border: Border(left: BorderSide(color: EmailInk.button, width: 3)),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(20, 4, 20, 0),
            child: Text(
              'Linked section · ${block['name'] ?? ''} · v${block['version'] ?? '?'}',
              style: const TextStyle(fontSize: 11, color: EmailInk.footerInk),
            ),
          ),
          for (final b in inner) EmailBlockView(block: b, phone: phone, brand: brand),
        ],
      ),
    );
  }

  /// A heading's style, as the renderer writes it.
  static TextStyle headingStyle(Map<String, dynamic> b) => TextStyle(
    fontSize: _int(b, 'size', 24).toDouble(),
    height: _lineHeight(b, 1.25),
    fontWeight: FontWeight.bold,
    color: blockColor(b, 'color', EmailInk.heading),
  );

  /// A text block's style, as the renderer writes it.
  static TextStyle textStyle(Map<String, dynamic> b) => TextStyle(
    fontSize: _int(b, 'size', 15).toDouble(),
    height: _lineHeight(b, 1.55),
    color: blockColor(b, 'color', EmailInk.body),
  );

  Widget _words(TextStyle style, TextAlign align) {
    final text = block['text'] as String? ?? '';
    if (block['rich'] != true) {
      return Text(text, textAlign: align, style: style);
    }
    return Text.rich(
      TextSpan(style: style, children: markupSpans(text, style)),
      textAlign: align,
    );
  }

  Widget _row(Widget child) {
    final raw = block['spacing'];
    final v = raw is num ? raw.toDouble().clamp(0, 64).toDouble() : 14.0;
    return Padding(
      padding: EdgeInsets.symmetric(horizontal: 20, vertical: v),
      child: child,
    );
  }

  Widget _button(TextAlign align) {
    final full = block['full_width'] == true;
    final pill = DecoratedBox(
      decoration: BoxDecoration(
        color: blockColor(block, 'background', EmailInk.button),
        borderRadius: BorderRadius.circular(
          block['radius'] is num ? (block['radius'] as num).toDouble().clamp(0, 40).toDouble() : 6,
        ),
      ),
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 20, vertical: 11),
        child: Text(
          block['text'] as String? ?? '',
          textAlign: TextAlign.center,
          style: TextStyle(
            fontSize: 15,
            fontWeight: FontWeight.w600,
            color: blockColor(block, 'color', EmailInk.paper),
          ),
        ),
      ),
    );
    if (full) return pill;
    return Align(
      alignment: switch (align) {
        TextAlign.center => Alignment.center,
        TextAlign.right => Alignment.centerRight,
        _ => Alignment.centerLeft,
      },
      child: pill,
    );
  }

  Widget _image() {
    final src = (block['src'] as String? ?? '').trim();

    // No address yet: a grey plate the shape of a picture, so an added image
    // block is visible before anything is typed into it.
    final plate = Container(
      height: phone ? 90 : 160,
      color: EmailInk.rule,
      alignment: Alignment.center,
      child: Text(
        src.isEmpty
            ? 'Image · upload or choose a picture'
            : (block['alt'] as String? ?? src),
        style: const TextStyle(fontSize: 13, color: EmailInk.footerInk),
      ),
    );

    if (src.isEmpty) return plate;

    // Displayed width and alignment, as the renderer sends them.
    final width = block['width'] is num && (block['width'] as num) > 0
        ? (block['width'] as num).toDouble()
        : null;
    final picture = Image.network(
      src,
      width: width ?? double.infinity,
      fit: BoxFit.fitWidth,
      semanticLabel: block['decorative'] == true
          ? null
          : block['alt'] as String?,
      excludeFromSemantics: block['decorative'] == true,
      errorBuilder: (_, _, _) => plate,
    );
    if (width == null) return picture;
    return Align(
      alignment: switch (block['align']) {
        'center' => Alignment.center,
        'right' => Alignment.centerRight,
        _ => Alignment.centerLeft,
      },
      child: ConstrainedBox(
        constraints: BoxConstraints(maxWidth: width),
        child: picture,
      ),
    );
  }

  /// Side by side on a computer, stacked on a phone unless stacking is off,
  /// at the chosen ratio, as the renderer does it. Reads the composer's
  /// `{blocks: [...]}` and the bare list the renderer also accepts.
  Widget _columns() {
    final columns = [
      for (final c in (block['columns'] as List<dynamic>?) ?? const [])
        if (c is Map && c['blocks'] is List)
          (c['blocks'] as List).whereType<Map<String, dynamic>>().toList()
        else if (c is List)
          c.whereType<Map<String, dynamic>>().toList(),
    ];
    if (columns.isEmpty) return const SizedBox.shrink();

    final gap = block['padding'] is num
        ? (block['padding'] as num).toDouble().clamp(0, 40).toDouble()
        : 0.0;
    final flex = columns.length == 2
        ? switch (block['ratio']) {
            '1:2' => [1, 2],
            '2:1' => [2, 1],
            _ => [1, 1],
          }
        : [for (final _ in columns) 1];

    Widget column(List<Map<String, dynamic>> inner) => Padding(
      padding: EdgeInsets.symmetric(horizontal: gap),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          for (final child in inner) EmailBlockView(block: child, phone: phone, brand: brand),
        ],
      ),
    );

    final stack = phone && block['stack'] != false;
    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: 4),
      child: stack
          ? Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [for (final c in columns) column(c)],
            )
          : Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                for (var i = 0; i < columns.length; i++)
                  Expanded(flex: flex[i], child: column(columns[i])),
              ],
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

  static double _lineHeight(Map<String, dynamic> b, double fallback) {
    final raw = b['line_height'];
    final v = raw is num ? raw.toDouble() : double.tryParse('$raw');
    return v != null && v >= 1 && v <= 2.5 ? v : fallback;
  }
}

/// A block's `#RRGGBB` colour, or [fallback] when it has none the renderer
/// would accept.
Color blockColor(Map<String, dynamic> b, String key, Color fallback) {
  final raw = '${b[key] ?? ''}';
  if (!RegExp(r'^#[0-9a-fA-F]{6}$').hasMatch(raw)) return fallback;
  return Color(int.parse('FF${raw.substring(1)}', radix: 16));
}

/// Mobile override keys (P1b) and the desktop property each one overrides.
const mobileOverrideOf = {
  'font_size': 'size',
  'padding': 'spacing',
  'align': 'align',
  'button_full_width': 'full_width',
  'stack': 'stack',
};

/// A block as the renderer draws it: the applied [brand] fills what the
/// block leaves unset (explicit block values win), and on a phone its
/// `mobile` overrides replace the desktop values they name.
Map<String, dynamic> effectiveBlock(
  Map<String, dynamic> block, {
  Map<String, dynamic>? brand,
  bool phone = false,
}) {
  final out = Map<String, dynamic>.from(block);
  if (brand != null) {
    final defaults = switch (block['type']) {
      'heading' || 'text' => {'color': brand['text']},
      'button' => {
        'background': brand['button_fill'] ?? brand['primary'],
        'color': brand['button_text'],
        'full_width': brand['button_full_width'],
        'radius': brand['button_radius'],
      },
      _ => const <String, Object?>{},
    };
    defaults.forEach((k, v) {
      if (v != null && !out.containsKey(k)) out[k] = v;
    });
  }
  final mobile = block['mobile'];
  if (phone && mobile is Map) {
    mobileOverrideOf.forEach((m, d) {
      if (mobile.containsKey(m) && mobile[m] != null) out[d] = mobile[m];
    });
  }
  return out;
}
