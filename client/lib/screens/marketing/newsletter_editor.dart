import 'dart:convert';

import 'package:flutter/material.dart';

import '../../widgets/search_picker.dart';
import '../email/message_html.dart';
import 'clock_label.dart';
import 'media_library_panel.dart';
import '../../core/session.dart';
import '../../core/timestamps.dart';
import '../../widgets/calendar_day_field.dart';
import '../../widgets/dashboard_card.dart';
import '../../widgets/refused.dart';
import '../../core/api_client.dart';
import 'newsletter_blocks.dart';
import 'newsletter_import_panel.dart';
import 'newsletter_preflight.dart';

/// Newsletter › Composer: blocks to add, the email itself, and who/when/Send.
///
/// ── Layout A, and why the email is the editor ───────────────────
///
/// Mia's pick, 2026-10-02: three columns. Blocks to add on the left, the email
/// in the middle where a click on a block edits it IN PLACE, and Subject / Who
/// gets it / When / Send on the right. The old layout put a form on one side
/// and the server's HTML on the other, so changing a heading meant finding its
/// card in a list and then looking across for the result. Now the thing being
/// changed and the thing being read are the same thing.
///
/// Below 1000 points the three stack, in the same order.
///
/// ── Why the blocks are still a stack ────────────────────────────
///
/// An email IS a stack of rows — that is what every mail client renders and
/// what `BlockRenderer` emits. A drag-anywhere canvas would let somebody build
/// a layout that cannot survive Outlook. Columns are the one place two things
/// sit side by side, because that is the one place email supports it.
///
/// ── Read-only once it has gone ──────────────────────────────────
///
/// The server refuses edits to a scheduled, sending or sent newsletter. The
/// screen says so up front ([readOnlyBecause]) rather than letting somebody
/// type a paragraph that the next autosave throws away.
class NewsletterEditor extends StatefulWidget {
  const NewsletterEditor({
    super.key,
    required this.subject,
    required this.blocks,
    required this.audiences,
    required this.audienceId,
    required this.when,
    required this.viewName,
    required this.company,
    this.initialHtml,
    required this.scheduledTimezone,
    required this.session,
    required this.sendingRefusedBecause,
    required this.readOnlyBecause,
    required this.saveError,
    required this.saveState,
    required this.previewHtml,
    required this.onChanged,
    required this.onAudience,
    this.onNewList,
    required this.onHtml,
    required this.onSend,
    this.api,
    this.newsletterId,
    this.onSaveNow,
    this.rasterise = rasteriseSvg,
  });

  /// For the import panel and the preflight; null hides both.
  final ApiClient? api;
  final String? newsletterId;

  /// Saves what is on screen now, before a preflight or test reads it.
  final Future<void> Function()? onSaveNow;
  final SvgRasteriser rasterise;

  final TextEditingController subject;
  final List<Map<String, dynamic>> blocks;
  final List<NewsletterAudience> audiences;
  final String? audienceId;
  final TextEditingController when;
  final String viewName;

  /// The name the footer carries, as the renderer writes it.
  final String company;

  /// The custom HTML already saved, which opens the HTML box filled.
  final String? initialHtml;

  /// The clock this mailing is already filed against, when it is already
  /// scheduled. Null on a draft, where nothing has picked a clock yet.
  final String? scheduledTimezone;

  final Session session;
  final String? sendingRefusedBecause;

  /// Why nothing here can be changed, or null for a draft.
  final String? readOnlyBecause;
  final String? saveError;

  /// "Saved", "Saving…" or "Unsaved", for the open newsletter.
  final String saveState;

  /// GET /newsletters/{id}/preview: the exact document that will go out.
  final String previewHtml;

  final VoidCallback onChanged;
  final ValueChanged<String?> onAudience;

  /// Opens the new-list panel (UI queue 173); null hides "+ New list".
  final VoidCallback? onNewList;
  final ValueChanged<String> onHtml;
  final Future<void> Function() onSend;

  @override
  State<NewsletterEditor> createState() => _NewsletterEditorState();
}

class _NewsletterEditorState extends State<NewsletterEditor> {
  bool _htmlMode = false;
  final TextEditingController _html = TextEditingController();
  bool _scheduling = false;

  /// The block being edited in place, by position.
  int? _selected;
  bool _phone = false;

  /// Show the server's HTML instead of the Flutter drawing, so the exact
  /// output stays checkable.
  bool _serverPreview = false;

  bool get _locked => widget.readOnlyBecause != null;

  /// The import panel is open.
  bool _importing = false;

  /// What was there before the last import or switch, for Undo.
  ({String html, List<Map<String, dynamic>> blocks, bool htmlMode})? _undo;

  /// Custom HTML is what goes out whenever it is set, whichever pane is open.
  bool get _htmlActive => _html.text.trim().isNotEmpty;

  void _snapshot() {
    _undo = (
      html: _html.text,
      blocks: [
        for (final b in widget.blocks)
          Map<String, dynamic>.from(jsonDecode(jsonEncode(b)) as Map),
      ],
      htmlMode: _htmlMode,
    );
  }

  void _useHtml(String html) {
    setState(() {
      _snapshot();
      _html.text = html;
      _htmlMode = true;
      _importing = false;
      _selected = null;
    });
    widget.onHtml(html);
  }

  void _useBlocks(List<Map<String, dynamic>> blocks) {
    setState(() {
      _snapshot();
      widget.blocks
        ..clear()
        ..addAll(blocks);
      _html.clear();
      _htmlMode = false;
      _importing = false;
      _selected = null;
    });
    widget.onHtml('');
    widget.onChanged();
  }

  /// Back to blocks clears the HTML, so the blocks are what goes out. The
  /// HTML is kept for Undo rather than silently discarded.
  void _switchToBlocks() {
    final hadHtml = _htmlActive;
    setState(() {
      if (hadHtml) _snapshot();
      _html.clear();
      _htmlMode = false;
      _selected = null;
    });
    if (hadHtml) widget.onHtml('');
  }

  void _restore() {
    final undo = _undo;
    if (undo == null) return;
    setState(() {
      widget.blocks
        ..clear()
        ..addAll(undo.blocks);
      _html.text = undo.html;
      _htmlMode = undo.htmlMode;
      _undo = null;
      _selected = null;
    });
    widget.onHtml(undo.html);
    widget.onChanged();
  }

  @override
  void initState() {
    super.initState();
    _scheduling = widget.when.text.trim().isNotEmpty;
    final html = widget.initialHtml ?? '';
    if (html.isNotEmpty) {
      _html.text = html;
      _htmlMode = true;
    }
  }

  @override
  void dispose() {
    _html.dispose();
    super.dispose();
  }

  /// Why Send cannot be pressed, or null when it can.
  ///
  /// Permission first, because it is the one nothing on this screen can fix.
  /// Then whether it has already gone, then the server's own sentence about
  /// the connection, then the things the person can actually do something
  /// about.
  String? get _sendRefusal {
    final unpermitted = widget.session.me?.refusal(
      'newsletter.send',
      'send a newsletter',
    );
    if (unpermitted != null) return unpermitted;

    if (widget.readOnlyBecause != null) return widget.readOnlyBecause;

    if (widget.sendingRefusedBecause != null) return widget.sendingRefusedBecause;

    if (widget.audienceId == null) {
      return 'Choose who this goes to first.';
    }
    if (widget.subject.text.trim().isEmpty) {
      return 'A newsletter needs a subject line — it is the first thing anybody sees.';
    }
    if (widget.blocks.isEmpty && !_htmlActive) {
      return 'There is nothing in this newsletter yet.';
    }

    return null;
  }

  @override
  Widget build(BuildContext context) {
    return LayoutBuilder(
      builder: (context, constraints) {
        if (constraints.maxWidth < 1000) {
          return ListView(
            padding: const EdgeInsets.all(12),
            children: [
              _palette(context, wrap: true),
              const SizedBox(height: 12),
              _email(context, bounded: false),
              const SizedBox(height: 12),
              _settings(context),
            ],
          );
        }

        return Padding(
          padding: const EdgeInsets.all(12),
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              SizedBox(
                width: 190,
                child: SingleChildScrollView(
                  child: _palette(context, wrap: false),
                ),
              ),
              const SizedBox(width: 12),
              Expanded(child: _email(context, bounded: true)),
              const SizedBox(width: 12),
              SizedBox(
                width: 300,
                child: SingleChildScrollView(child: _settings(context)),
              ),
            ],
          ),
        );
      },
    );
  }

  // ── left: what can be added ──────────────────────────────────

  Widget _palette(BuildContext context, {required bool wrap}) {
    final adding = !_locked && !_htmlMode;
    final buttons = [
      for (final kind in BlockKind.values)
        _dragNew(
          kind,
          adding,
          OutlinedButton.icon(
            onPressed: adding ? () => _add(kind) : null,
            icon: Icon(kind.icon, size: 16),
            label: Text(kind.label),
            style: wrap ? null : OutlinedButton.styleFrom(alignment: Alignment.centerLeft),
          ),
        ),
    ];

    return DashboardCard(
      key: const ValueKey('tour-newsletter-blocks'),
      title: 'Add a block',
      icon: Icons.add_box_outlined,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          if (wrap)
            Wrap(spacing: 6, runSpacing: 6, children: buttons)
          else
            for (final b in buttons)
              Padding(padding: const EdgeInsets.only(bottom: 6), child: b),
          const SizedBox(height: 8),
          Text('Or', style: Theme.of(context).textTheme.labelMedium),
          const SizedBox(height: 6),
          OutlinedButton.icon(
            onPressed: _locked
                ? null
                : _htmlMode
                ? _switchToBlocks
                : () => setState(() {
                    _htmlMode = true;
                    _selected = null;
                  }),
            icon: Icon(_htmlMode ? Icons.dashboard_outlined : Icons.code, size: 16),
            label: Text(_htmlMode ? 'Back to blocks' : 'Write HTML'),
          ),
          if (widget.api != null) ...[
            const SizedBox(height: 6),
            OutlinedButton.icon(
              key: const ValueKey('newsletter-import'),
              onPressed: _locked
                  ? null
                  : () => setState(() => _importing = true),
              icon: const Icon(Icons.upload_file, size: 16),
              label: const Text('Import HTML or SVG'),
            ),
          ],
        ],
      ),
    );
  }

  /// A new block goes where it was dropped, else under the selected one, or at
  /// the end, and is selected so its fields are open where it landed.
  void _add(BlockKind kind, [int? dropped]) {
    setState(() {
      final at = dropped ?? (_selected == null ? widget.blocks.length : _selected! + 1);
      widget.blocks.insert(at, kind.blank());
      _selected = at;
      _serverPreview = false;
    });
    widget.onChanged();
  }

  /// A palette button that can also be dragged into the email, landing above
  /// the block it is dropped on (or at the end, on the footer).
  Widget _dragNew(BlockKind kind, bool adding, Widget button) {
    if (!adding) return button;
    return Draggable<BlockKind>(
      key: Key('newsletter-add-${kind.name}'),
      data: kind,
      feedback: Material(
        elevation: 4,
        borderRadius: BorderRadius.circular(6),
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
          child: Row(
            mainAxisSize: MainAxisSize.min,
            children: [Icon(kind.icon, size: 16), const SizedBox(width: 6), Text(kind.label)],
          ),
        ),
      ),
      child: button,
    );
  }

  // ── middle: the email ────────────────────────────────────────

  Widget _email(BuildContext context, {required bool bounded}) {
    final controls = Wrap(
      spacing: 8,
      runSpacing: 6,
      alignment: WrapAlignment.center,
      crossAxisAlignment: WrapCrossAlignment.center,
      children: [
        SegmentedButton<bool>(
          segments: const [
            ButtonSegment(
              value: false,
              label: Text('Desktop'),
              icon: Icon(Icons.desktop_windows_outlined, size: 16),
            ),
            ButtonSegment(
              value: true,
              label: Text('Phone'),
              icon: Icon(Icons.phone_iphone, size: 16),
            ),
          ],
          selected: {_phone},
          onSelectionChanged: (s) => setState(() => _phone = s.first),
          showSelectedIcon: false,
        ),
        if (!_htmlMode)
          FilterChip(
            tooltip: 'Shows the email exactly as the server will send it',
            label: const Text('Server preview'),
            selected: _serverPreview,
            onSelected: (on) => setState(() {
              _serverPreview = on;
              _selected = null;
            }),
          ),
      ],
    );

    final Widget body;
    if (_htmlMode) {
      body = _htmlPane(context, bounded: bounded);
    } else if (_serverPreview) {
      body = bounded
          ? Expanded(child: _serverView())
          : SizedBox(height: 520, child: _serverView());
    } else {
      final email = NewsletterEmail(
        blocks: widget.blocks,
        company: widget.company,
        phone: _phone,
        selected: _selected,
        onSelect: _locked ? null : (i) => setState(() => _selected = i),
        editorFor: _locked ? null : (i) => _inPlace(context, i),
        onReorder: _locked ? null : _move,
        onDropNew: _locked ? null : _add,
      );
      body = bounded
          ? Expanded(child: SingleChildScrollView(child: email))
          : email;
    }

    return DashboardCard(
      key: const ValueKey('tour-newsletter-email'),
      title: 'What they will get',
      icon: Icons.mark_email_read_outlined,
      /*
       * `expand` is what makes the server preview VISIBLE on the web: the
       * viewer is an iframe in `SizedBox.expand`, which collapses to nothing
       * when its height is unbounded. Wide, the card fills its column and the
       * body takes the leftover; narrow, the preview gets a fixed 520 instead.
       */
      expand: bounded,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        mainAxisSize: bounded ? MainAxisSize.max : MainAxisSize.min,
        children: [
          _modeBar(context),
          const SizedBox(height: 8),
          controls,
          const SizedBox(height: 10),
          if (_importing && !_locked) ...[
            NewsletterImportPanel(
              api: widget.api,
              rasterise: widget.rasterise,
              onUseHtml: _useHtml,
              onUseBlocks: _useBlocks,
              onClose: () => setState(() => _importing = false),
            ),
            const SizedBox(height: 10),
          ],
          if (_locked) ...[
            DashboardNote(widget.readOnlyBecause!),
            const SizedBox(height: 10),
          ],
          body,
        ],
      ),
    );
  }

  /// Which version goes out — blocks or custom HTML — and the way back.
  ///
  /// Audit 2026-11-10, item 2: an imported document silently replaced the
  /// blocks with nothing on screen saying which one a send would use.
  Widget _modeBar(BuildContext context) {
    final theme = Theme.of(context);
    final active = _htmlActive ? 'custom HTML' : 'the blocks';

    return Container(
      key: const ValueKey('newsletter-mode'),
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
      decoration: BoxDecoration(
        color: theme.colorScheme.surfaceContainerHighest,
        borderRadius: BorderRadius.circular(6),
      ),
      child: Wrap(
        spacing: 8,
        runSpacing: 6,
        crossAxisAlignment: WrapCrossAlignment.center,
        children: [
          SegmentedButton<bool>(
            segments: const [
              ButtonSegment(
                value: false,
                label: Text('Blocks'),
                icon: Icon(Icons.dashboard_outlined, size: 16),
              ),
              ButtonSegment(
                value: true,
                label: Text('Custom HTML'),
                icon: Icon(Icons.code, size: 16),
              ),
            ],
            selected: {_htmlMode},
            showSelectedIcon: false,
            onSelectionChanged: _locked
                ? null
                : (s) => s.first
                      ? setState(() {
                          _htmlMode = true;
                          _selected = null;
                        })
                      : _switchToBlocks(),
          ),
          Text(
            'Sending: $active',
            key: const ValueKey('newsletter-mode-active'),
            style: theme.textTheme.labelLarge,
          ),
          if (_undo != null && !_locked)
            TextButton.icon(
              key: const ValueKey('newsletter-undo'),
              onPressed: _restore,
              icon: const Icon(Icons.undo, size: 16),
              label: const Text('Undo'),
            ),
        ],
      ),
    );
  }

  /// The server's HTML, at the width the Desktop/Phone switch picked.
  Widget _serverView() {
    if (widget.previewHtml.isEmpty) {
      return const Center(child: DashboardNote('Nothing to show yet.'));
    }

    return Align(
      alignment: Alignment.topCenter,
      child: SizedBox(
        width: _phone ? 375 : double.infinity,
        child: MessageHtmlView(html: widget.previewHtml, ownHtml: true),
      ),
    );
  }

  /// Raw HTML for the whole mailing, under the server's rendering of it.
  ///
  /// It replaces the blocks rather than sitting beside them, and says so —
  /// two sources of truth for one email is how somebody edits the markup, adds
  /// a block, and silently loses the markup.
  Widget _htmlPane(BuildContext context, {required bool bounded}) {
    final box = TextField(
      controller: _html,
      minLines: 6,
      maxLines: 12,
      style: const TextStyle(fontFamily: 'monospace', fontSize: 13),
      decoration: const InputDecoration(
        hintText: '<table>…</table>',
        alignLabelWithHint: true,
      ),
      onChanged: (html) {
        setState(() {});
        widget.onHtml(html);
      },
    );

    final children = <Widget>[
      const DashboardNote(
        'Write the email yourself. While this is set it is what goes out — '
        'the blocks are kept but not used. Back to blocks clears this HTML, '
        'and Undo brings it back. The address and unsubscribe footer is added '
        'unless the HTML has its own *|UNSUB|* link.',
      ),
      const SizedBox(height: 10),
      if (bounded)
        Expanded(child: _serverView())
      else
        SizedBox(height: 520, child: _serverView()),
      const SizedBox(height: 10),
      box,
    ];

    return bounded
        ? Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: children,
            ),
          )
        : Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: children,
          );
  }

  /// The selected block's fields, where the block was, with the moves.
  Widget _inPlace(BuildContext context, int index) {
    final block = widget.blocks[index];
    final kind = BlockKind.forType(block['type'] as String? ?? '');
    final theme = Theme.of(context);

    // The theme's surface, not the email's white: these are controls, and in
    // dark mode they must read as controls.
    return Container(
      color: theme.colorScheme.surface,
      padding: const EdgeInsets.fromLTRB(10, 6, 10, 10),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Wrap(
            spacing: 2,
            crossAxisAlignment: WrapCrossAlignment.center,
            children: [
              Icon(kind?.icon ?? Icons.help_outline, size: 16),
              const SizedBox(width: 4),
              Text(
                kind?.label ?? (block['type'] as String? ?? 'Block'),
                style: theme.textTheme.labelLarge,
              ),
              const SizedBox(width: 8),
              // Arrows rather than drag: this sits in a scrolling pane on a
              // touch screen, and a drag that competes with the scroll is the
              // gesture people lose. Two taps always work.
              TextButton(
                onPressed: index == 0 ? null : () => _move(index, index - 1),
                child: const Text('Move up'),
              ),
              TextButton(
                onPressed: index == widget.blocks.length - 1
                    ? null
                    : () => _move(index, index + 1),
                child: const Text('Move down'),
              ),
              TextButton(
                onPressed: () {
                  setState(() {
                    widget.blocks.removeAt(index);
                    _selected = null;
                  });
                  widget.onChanged();
                },
                style: TextButton.styleFrom(foregroundColor: theme.colorScheme.error),
                child: const Text('Remove'),
              ),
              TextButton(
                onPressed: () => setState(() => _selected = null),
                child: const Text('Done'),
              ),
            ],
          ),
          if (isHandWritten(block))
            // Its fields would be ignored, so they are not offered. Showing a
            // Text box whose value never reaches the email is worse than
            // showing nothing.
            const Padding(
              padding: EdgeInsets.only(top: 6),
              child: DashboardNote(
                'This block was written as HTML and is kept exactly as typed.',
              ),
            )
          else
            ..._fieldsFor(context, block, kind),
        ],
      ),
    );
  }

  List<Widget> _fieldsFor(
    BuildContext context,
    Map<String, dynamic> block,
    BlockKind? kind,
  ) {
    return switch (kind) {
      BlockKind.heading => [
        _text(block, 'text', 'Heading'),
        _align(block),
      ],
      BlockKind.text => [
        _text(block, 'text', 'Words', lines: 4),
        _align(block),
      ],
      BlockKind.image => [
        // Keyed by each pick, so the field shows the address a pick wrote.
        KeyedSubtree(
          key: ValueKey('newsletter-image-src-$_picks'),
          child: _text(block, 'src', 'Image address'),
        ),
        if (widget.api != null && widget.readOnlyBecause == null)
          Padding(
            padding: const EdgeInsets.only(top: 6),
            child: Align(
              alignment: Alignment.centerLeft,
              child: OutlinedButton.icon(
                key: const ValueKey('newsletter-image-library'),
                onPressed: () => _chooseFromLibrary(context, block),
                icon: const Icon(Icons.photo_library_outlined, size: 18),
                label: const Text('Choose picture'),
              ),
            ),
          ),
        _text(block, 'alt', 'Describe the picture'),
      ],
      BlockKind.button => [
        _text(block, 'text', 'Label'),
        _text(block, 'url', 'Where it goes'),
        _align(block),
      ],
      BlockKind.spacer => [_number(block, 'height', 'Height in points')],
      BlockKind.columns => [_columns(context, block)],
      BlockKind.divider => const [
        Padding(
          padding: EdgeInsets.only(top: 6),
          child: DashboardNote('A line across the email. Nothing to fill in.'),
        ),
      ],
      _ => const [],
    };
  }

  /// A row of columns, each holding its own blocks.
  ///
  /// Two levels deep and no further. Columns inside columns is a layout email
  /// clients disagree about, and the preview would stop being a promise.
  Widget _columns(BuildContext context, Map<String, dynamic> block) {
    final columns = ((block['columns'] as List<dynamic>?) ?? [])
        .whereType<Map<String, dynamic>>()
        .toList();

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        const SizedBox(height: 6),
        const DashboardNote(
          'Side by side on a computer, stacked on a phone.',
        ),
        const SizedBox(height: 8),
        for (var c = 0; c < columns.length; c++)
          _column(context, block, columns, c),
        Align(
          alignment: Alignment.centerLeft,
          child: TextButton.icon(
            onPressed: columns.length >= 3
                ? null
                : () {
                    setState(() {
                      columns.add({'blocks': <Map<String, dynamic>>[]});
                      block['columns'] = columns;
                    });
                    widget.onChanged();
                  },
            icon: const Icon(Icons.add, size: 16),
            // Three is where a 600-point email runs out of room; a fourth
            // column is 130 points wide and unreadable on a phone.
            label: Text(columns.length >= 3 ? 'Three columns is the most' : 'Add a column'),
          ),
        ),
      ],
    );
  }

  Widget _column(
    BuildContext context,
    Map<String, dynamic> parent,
    List<Map<String, dynamic>> columns,
    int index,
  ) {
    final inner = ((columns[index]['blocks'] as List<dynamic>?) ?? [])
        .whereType<Map<String, dynamic>>()
        .toList();

    return Container(
      margin: const EdgeInsets.only(bottom: 8),
      padding: const EdgeInsets.all(8),
      decoration: BoxDecoration(
        color: Theme.of(context).colorScheme.surfaceContainerHighest,
        borderRadius: BorderRadius.circular(6),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Row(
            children: [
              Expanded(
                child: Text(
                  'Column ${index + 1}',
                  style: Theme.of(context).textTheme.labelMedium,
                ),
              ),
              IconButton(
                tooltip: 'Remove column',
                onPressed: columns.length <= 1
                    ? null
                    : () {
                        setState(() {
                          columns.removeAt(index);
                          parent['columns'] = columns;
                        });
                        widget.onChanged();
                      },
                icon: const Icon(Icons.close, size: 14),
              ),
            ],
          ),
          for (var i = 0; i < inner.length; i++)
            Padding(
              padding: const EdgeInsets.only(bottom: 6),
              child: Row(
                children: [
                  Expanded(child: _text(inner[i], 'text', BlockKind.forType(inner[i]['type'] as String? ?? '')?.label ?? 'Text')),
                  IconButton(
                    tooltip: 'Remove',
                    onPressed: () {
                      setState(() {
                        inner.removeAt(i);
                        columns[index]['blocks'] = inner;
                      });
                      widget.onChanged();
                    },
                    icon: const Icon(Icons.close, size: 14),
                  ),
                ],
              ),
            ),
          Align(
            alignment: Alignment.centerLeft,
            child: Wrap(
              spacing: 4,
              children: [
                for (final kind in [BlockKind.heading, BlockKind.text, BlockKind.image, BlockKind.button])
                  TextButton(
                    onPressed: () {
                      setState(() {
                        inner.add(kind.blank());
                        columns[index]['blocks'] = inner;
                      });
                      widget.onChanged();
                    },
                    child: Text('+ ${kind.label}', style: const TextStyle(fontSize: 12)),
                  ),
              ],
            ),
          ),
        ],
      ),
    );
  }

  void _move(int from, int to) {
    setState(() {
      final block = widget.blocks.removeAt(from);
      widget.blocks.insert(to, block);
      _selected = to;
    });
    widget.onChanged();
  }

  /// How many pictures were chosen from the library; keys the address field.
  int _picks = 0;

  /// A picture from the media library, copied to an address an inbox can
  /// load (review 2026-11-09 A29), instead of a typed URL.
  Future<void> _chooseFromLibrary(
    BuildContext context,
    Map<String, dynamic> block,
  ) async {
    final api = widget.api!;
    final item = await showMediaLibraryPanel(
      context,
      api: api,
      pickMedia: () async => null,
      canEdit: false,
    );
    if (item == null || !mounted) return;
    final response = await api.post(
      '/newsletter/import/library/${item['id']}',
      const {},
    );
    if (!mounted) return;
    final data = response.ok ? response.data : null;
    final url = data is Map ? data['url'] as String? : null;
    if (url == null) {
      ScaffoldMessenger.maybeOf(
        this.context,
      )?.showSnackBar(SnackBar(content: Text(response.reason)));
      return;
    }
    setState(() {
      block['src'] = url;
      final name = '${item['name'] ?? ''}'.trim();
      if ('${block['alt'] ?? ''}'.trim().isEmpty && name.isNotEmpty) {
        block['alt'] = name;
      }
      _picks++;
    });
    widget.onChanged();
  }

  Widget _text(Map<String, dynamic> block, String field, String label, {int lines = 1}) {
    return Padding(
      padding: const EdgeInsets.only(top: 6),
      child: TextFormField(
        initialValue: block[field] as String? ?? '',
        minLines: lines,
        maxLines: lines == 1 ? 1 : lines + 4,
        decoration: InputDecoration(labelText: label, isDense: true),
        onChanged: (v) {
          // setState so the drawing under the cursor follows the typing.
          setState(() => block[field] = v);
          widget.onChanged();
        },
      ),
    );
  }

  Widget _number(Map<String, dynamic> block, String field, String label) {
    return Padding(
      padding: const EdgeInsets.only(top: 6),
      child: TextFormField(
        initialValue: '${block[field] ?? ''}',
        keyboardType: TextInputType.number,
        decoration: InputDecoration(labelText: label, isDense: true),
        onChanged: (v) {
          setState(() => block[field] = int.tryParse(v) ?? 0);
          widget.onChanged();
        },
      ),
    );
  }

  Widget _align(Map<String, dynamic> block) {
    const options = ['left', 'center', 'right'];
    final current = options.contains(block['align']) ? block['align'] as String : 'left';

    return Padding(
      padding: const EdgeInsets.only(top: 8),
      child: Align(
        alignment: Alignment.centerLeft,
        child: SegmentedButton<String>(
          segments: const [
            ButtonSegment(value: 'left', icon: Icon(Icons.format_align_left, size: 15)),
            ButtonSegment(value: 'center', icon: Icon(Icons.format_align_center, size: 15)),
            ButtonSegment(value: 'right', icon: Icon(Icons.format_align_right, size: 15)),
          ],
          selected: {current},
          showSelectedIcon: false,
          onSelectionChanged: (s) {
            setState(() => block['align'] = s.first);
            widget.onChanged();
          },
        ),
      ),
    );
  }

  // ── right: subject, who, when, Send ──────────────────────────

  Widget _settings(BuildContext context) {
    final refusal = _sendRefusal;
    final theme = Theme.of(context);

    Widget kind(String label) => Padding(
      padding: const EdgeInsets.only(top: 14, bottom: 6),
      child: Text(
        label.toUpperCase(),
        style: theme.textTheme.labelSmall?.copyWith(
          letterSpacing: 0.8,
          fontWeight: FontWeight.w700,
          color: theme.colorScheme.onSurfaceVariant,
        ),
      ),
    );

    return DashboardCard(
      key: const ValueKey('tour-newsletter-details'),
      title: 'Newsletter',
      icon: Icons.drafts_outlined,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          TextField(
            controller: widget.subject,
            readOnly: _locked,
            decoration: const InputDecoration(
              labelText: 'Subject',
              hintText: 'What lands in the inbox',
            ),
            onChanged: (_) => widget.onChanged(),
          ),
          kind('Who gets it'),
          const DashboardNote(
            'A Mailchimp list lives in your Mailchimp account and is who the '
            'email actually goes to. An EzBizi list says which people to pick: '
            'a Mailchimp list as it is, or your EzBizi customers, who are '
            'added to its Mailchimp list just before sending.',
            key: ValueKey('newsletter-audience-explainer'),
          ),
          const SizedBox(height: 8),
          if (widget.audiences.isEmpty)
            DashboardNote(
              'No mailing list in ${widget.viewName} yet. A list is either built '
              'from your customers or picked from the mailing account this '
              'company connected.',
            )
          else
            SearchPickerField<String>(
              value: widget.audienceId,
              label: 'Mailing list',
              isDense: true,
              options: [
                for (final a in widget.audiences)
                  SearchOption(value: a.id, label: '${a.label} · ${a.where}'),
              ],
              onChanged: _locked ? null : widget.onAudience,
            ),
          for (final a in widget.audiences)
            if (a.id == widget.audienceId)
              Padding(
                padding: const EdgeInsets.only(top: 6),
                child: DashboardNote(
                  a.mailchimpListId == null || a.mailchimpListId!.isEmpty
                      ? '${a.name} has no Mailchimp list behind it yet.'
                      : '${a.name} (EzBizi, ${a.where}) → Mailchimp list '
                            '${a.mailchimpListId}',
                  key: const ValueKey('newsletter-audience-mapping'),
                ),
              ),
          if (widget.onNewList != null && !_locked)
            Align(
              alignment: Alignment.centerLeft,
              child: TextButton.icon(
                key: const ValueKey('newsletter-new-list'),
                onPressed: widget.onNewList,
                icon: const Icon(Icons.add, size: 18),
                label: const Text('New list'),
              ),
            ),
          kind('When'),
          Align(
            alignment: Alignment.centerLeft,
            child: SegmentedButton<bool>(
              segments: const [
                ButtonSegment(value: false, label: Text('Now')),
                ButtonSegment(value: true, label: Text('Later')),
              ],
              selected: {_scheduling},
              showSelectedIcon: false,
              onSelectionChanged: _locked
                  ? null
                  : (s) {
                      setState(() => _scheduling = s.first);
                      if (!_scheduling) {
                        widget.when.clear();
                        widget.onChanged();
                      }
                    },
            ),
          ),
          if (_scheduling) ...[
            const SizedBox(height: 8),
            CalendarDayField(
              controller: widget.when,
              formats: widget.session.me?.display ?? DisplayFormats.fallback,
              label: 'Send at',
              withTime: true,
              enabled: !_locked,
              firstDate: DateTime.now(),
              onChanged: (_) => widget.onChanged(),
            ),
            const SizedBox(height: 6),
            /*
             * What this note may and may not promise.
             *
             * It used to say the time you pick "is the time it goes out
             * there", naming the PLACE. That holds when the view's clock IS
             * the place's clock. It does not hold when the cascade resolves a
             * fixed offset, which stands still while the place it is named
             * after moves an hour in March - same wall time, an hour late
             * until November, and nothing on screen admitting the clock had a
             * say.
             *
             * Do not re-pin this comment to whatever the platform default
             * happens to be. It has been wrong three times for that reason in
             * two days - named as permanent, then named as changed when the
             * change had not shipped, then named as unchanged when it had. The
             * default is not the point and never was.
             *
             * The note below is correct under every default because it
             * promises a wall time on the clock the SERVER picked and then
             * names that clock, rather than promising a particular clock. That
             * is load-bearing for as long as a fixed offset is selectable at
             * all, which it still is.
             *
             * Fixed offsets remain selectable, so this stays load-bearing.
             * Saying which one you got costs nothing - the server has been
             * sending `scheduled_timezone` on every mailing all along and this
             * client simply never read it.
             */
            DashboardNote(
              widget.scheduledTimezone == null
                  ? 'This view’s clock — ${widget.viewName}. The time you pick is '
                        'filed against this view’s own clock, and the schedule '
                        'names which one once it is saved.'
                  : 'This view’s clock — ${widget.viewName}, '
                        '${clockLabel(widget.scheduledTimezone)}. The time you pick '
                        'is a wall time on that clock.',
            ),
          ],
          if (widget.api != null &&
              widget.newsletterId != null &&
              !_locked) ...[
            kind('Check and test'),
            NewsletterPreflight(
              api: widget.api!,
              newsletterId: widget.newsletterId!,
              onSaveNow: widget.onSaveNow ?? () async {},
              mayTest: widget.session.me?.refusal(
                'newsletter.send',
                'send a test newsletter',
              ),
            ),
          ],
          const SizedBox(height: 16),
          Row(
            children: [
              Expanded(
                child: Text(widget.saveState, style: theme.textTheme.bodySmall),
              ),
              Refused(
                reason: refusal,
                child: FilledButton.icon(
                  key: const ValueKey('tour-newsletter-send'),
                  onPressed: refusal == null ? () => widget.onSend() : null,
                  icon: const Icon(Icons.send, size: 18),
                  label: Text(
                    _scheduling && widget.when.text.trim().isNotEmpty
                        ? 'Schedule'
                        : 'Send now',
                  ),
                ),
              ),
            ],
          ),
          if (widget.saveError != null) ...[
            const SizedBox(height: 10),
            DashboardNote(widget.saveError!),
          ],
        ],
      ),
    );
  }
}
