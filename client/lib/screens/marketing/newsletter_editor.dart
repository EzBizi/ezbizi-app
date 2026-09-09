import 'package:flutter/material.dart';

import 'clock_label.dart';
import '../../core/session.dart';
import '../../core/timestamps.dart';
import '../../widgets/calendar_day_field.dart';
import '../../widgets/dashboard_card.dart';
import '../../widgets/refused.dart';
import 'newsletter_blocks.dart';

/// The left half of Newsletter: subject, blocks, audience, schedule, Send.
///
/// ── Why the blocks are a list and not a canvas ──────────────────
///
/// An email IS a stack of rows — that is what every mail client renders and
/// what `BlockRenderer` emits. A drag-anywhere canvas would let somebody build
/// a layout that cannot survive Outlook, and then the composer would be lying
/// about the artefact. The list is the honest shape, and columns are the one
/// place two things sit side by side because that is the one place email
/// supports it.
class NewsletterEditor extends StatefulWidget {
  const NewsletterEditor({
    super.key,
    required this.subject,
    required this.blocks,
    required this.audiences,
    required this.audienceId,
    required this.when,
    required this.viewName,
    required this.scheduledTimezone,
    required this.session,
    required this.sendingRefusedBecause,
    required this.saveError,
    required this.onChanged,
    required this.onAudience,
    required this.onHtml,
    required this.onSend,
  });

  final TextEditingController subject;
  final List<Map<String, dynamic>> blocks;
  final List<NewsletterAudience> audiences;
  final String? audienceId;
  final TextEditingController when;
  final String viewName;

  /// The clock this mailing is already filed against, when it is already
  /// scheduled. Null on a draft, where nothing has picked a clock yet.
  final String? scheduledTimezone;

  final Session session;
  final String? sendingRefusedBecause;
  final String? saveError;

  final VoidCallback onChanged;
  final ValueChanged<String?> onAudience;
  final ValueChanged<String> onHtml;
  final Future<void> Function() onSend;

  @override
  State<NewsletterEditor> createState() => _NewsletterEditorState();
}

class _NewsletterEditorState extends State<NewsletterEditor> {
  bool _htmlMode = false;
  final TextEditingController _html = TextEditingController();
  bool _scheduling = false;

  @override
  void initState() {
    super.initState();
    _scheduling = widget.when.text.trim().isNotEmpty;
  }

  @override
  void dispose() {
    _html.dispose();
    super.dispose();
  }

  /// Why Send cannot be pressed, or null when it can.
  ///
  /// Permission first, because it is the one nothing on this screen can fix.
  /// Then the server's own sentence about the connection, then the things the
  /// person can actually do something about.
  String? get _sendRefusal {
    final unpermitted = widget.session.me?.refusal(
      'newsletter.send',
      'send a newsletter',
    );
    if (unpermitted != null) return unpermitted;

    if (widget.sendingRefusedBecause != null) return widget.sendingRefusedBecause;

    if (widget.audienceId == null) {
      return 'Choose who this goes to first.';
    }
    if (widget.subject.text.trim().isEmpty) {
      return 'A newsletter needs a subject line — it is the first thing anybody sees.';
    }
    if (widget.blocks.isEmpty) {
      return 'There is nothing in this newsletter yet.';
    }

    return null;
  }

  @override
  Widget build(BuildContext context) {
    final refusal = _sendRefusal;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        DashboardCard(
          title: 'Newsletter',
          icon: Icons.drafts_outlined,
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              TextField(
                controller: widget.subject,
                decoration: const InputDecoration(
                  labelText: 'Subject',
                  hintText: 'What lands in the inbox',
                ),
                onChanged: (_) => widget.onChanged(),
              ),
              const SizedBox(height: 14),
              _modeSwitch(context),
              const SizedBox(height: 10),
              if (_htmlMode) _htmlPane(context) else _blockList(context),
            ],
          ),
        ),
        const SizedBox(height: 12),
        _audienceCard(context),
        const SizedBox(height: 12),
        _sendCard(context, refusal),
      ],
    );
  }

  Widget _modeSwitch(BuildContext context) {
    return Row(
      children: [
        SegmentedButton<bool>(
          segments: const [
            ButtonSegment(value: false, label: Text('Blocks'), icon: Icon(Icons.dashboard_outlined, size: 16)),
            ButtonSegment(value: true, label: Text('HTML'), icon: Icon(Icons.code, size: 16)),
          ],
          selected: {_htmlMode},
          onSelectionChanged: (s) => setState(() => _htmlMode = s.first),
          showSelectedIcon: false,
        ),
      ],
    );
  }

  /// Raw HTML for the whole mailing.
  ///
  /// It replaces the blocks rather than sitting beside them, and says so —
  /// two sources of truth for one email is how somebody edits the markup, adds
  /// a block, and silently loses the markup.
  Widget _htmlPane(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        const DashboardNote(
          'Write the email yourself. While this is set it is what goes out — '
          'the blocks below it are kept but not used, so you can clear this '
          'and get them back.',
        ),
        const SizedBox(height: 10),
        TextField(
          controller: _html,
          minLines: 12,
          maxLines: 26,
          style: const TextStyle(fontFamily: 'monospace', fontSize: 13),
          decoration: const InputDecoration(
            hintText: '<table>…</table>',
            alignLabelWithHint: true,
          ),
          onChanged: widget.onHtml,
        ),
      ],
    );
  }

  Widget _blockList(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        if (widget.blocks.isEmpty)
          const DashboardNote('Add a block to start writing.'),
        for (var i = 0; i < widget.blocks.length; i++)
          _blockCard(context, i, widget.blocks[i]),
        const SizedBox(height: 10),
        _addRow(context, (block) => setState(() => widget.blocks.add(block))),
      ],
    );
  }

  Widget _addRow(BuildContext context, ValueChanged<Map<String, dynamic>> onAdd) {
    return Wrap(
      spacing: 6,
      runSpacing: 6,
      children: [
        for (final kind in BlockKind.values)
          OutlinedButton.icon(
            onPressed: () {
              onAdd(kind.blank());
              widget.onChanged();
            },
            icon: Icon(kind.icon, size: 16),
            label: Text(kind.label),
          ),
      ],
    );
  }

  Widget _blockCard(BuildContext context, int index, Map<String, dynamic> block) {
    final kind = BlockKind.forType(block['type'] as String? ?? '');
    final theme = Theme.of(context);

    return Container(
      margin: const EdgeInsets.only(bottom: 8),
      decoration: BoxDecoration(
        border: Border.all(color: theme.dividerColor),
        borderRadius: BorderRadius.circular(8),
      ),
      padding: const EdgeInsets.fromLTRB(10, 8, 10, 10),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Row(
            children: [
              Icon(kind?.icon ?? Icons.help_outline, size: 16),
              const SizedBox(width: 8),
              Expanded(
                child: Text(
                  kind?.label ?? (block['type'] as String? ?? 'Block'),
                  style: theme.textTheme.labelLarge,
                ),
              ),
              IconButton(
                tooltip: 'Move up',
                onPressed: index == 0 ? null : () => _move(index, index - 1),
                icon: const Icon(Icons.arrow_upward, size: 16),
              ),
              IconButton(
                tooltip: 'Move down',
                onPressed: index == widget.blocks.length - 1
                    ? null
                    : () => _move(index, index + 1),
                icon: const Icon(Icons.arrow_downward, size: 16),
              ),
              IconButton(
                tooltip: 'Remove',
                onPressed: () => setState(() {
                  widget.blocks.removeAt(index);
                  widget.onChanged();
                }),
                icon: const Icon(Icons.close, size: 16),
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
        _text(block, 'src', 'Image address'),
        _text(block, 'alt', 'Describe the picture'),
      ],
      BlockKind.button => [
        _text(block, 'text', 'Label'),
        _text(block, 'url', 'Where it goes'),
        _align(block),
      ],
      BlockKind.spacer => [_number(block, 'height', 'Height in points')],
      BlockKind.columns => [_columns(context, block)],
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
                : () => setState(() {
                    columns.add({'blocks': <Map<String, dynamic>>[]});
                    block['columns'] = columns;
                    widget.onChanged();
                  }),
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
                    : () => setState(() {
                        columns.removeAt(index);
                        parent['columns'] = columns;
                        widget.onChanged();
                      }),
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
                    onPressed: () => setState(() {
                      inner.removeAt(i);
                      columns[index]['blocks'] = inner;
                      widget.onChanged();
                    }),
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
                    onPressed: () => setState(() {
                      inner.add(kind.blank());
                      columns[index]['blocks'] = inner;
                      widget.onChanged();
                    }),
                    child: Text(kind.label, style: const TextStyle(fontSize: 12)),
                  ),
              ],
            ),
          ),
        ],
      ),
    );
  }

  /// Reorder without drag-and-drop.
  ///
  /// Arrows rather than a drag handle because this list lives inside a
  /// scrolling pane on a touch screen, and a drag that competes with the scroll
  /// is the gesture people lose. Two taps always work.
  void _move(int from, int to) {
    setState(() {
      final block = widget.blocks.removeAt(from);
      widget.blocks.insert(to, block);
      widget.onChanged();
    });
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
          block[field] = v;
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
          block[field] = int.tryParse(v) ?? 0;
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
          onSelectionChanged: (s) => setState(() {
            block['align'] = s.first;
            widget.onChanged();
          }),
        ),
      ),
    );
  }

  Widget _audienceCard(BuildContext context) {
    return DashboardCard(
      title: 'Who gets it',
      icon: Icons.group_outlined,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          if (widget.audiences.isEmpty)
            DashboardNote(
              'No mailing list in ${widget.viewName} yet. A list is either built '
              'from your customers or picked from the mailing account this '
              'company connected.',
            )
          else
            DropdownButtonFormField<String>(
              initialValue: widget.audienceId,
              decoration: const InputDecoration(labelText: 'Mailing list', isDense: true),
              items: [
                for (final a in widget.audiences)
                  DropdownMenuItem(
                    value: a.id,
                    child: Text('${a.name} · ${a.where}'),
                  ),
              ],
              onChanged: widget.onAudience,
            ),
        ],
      ),
    );
  }

  Widget _sendCard(BuildContext context, String? refusal) {
    return DashboardCard(
      title: 'Send',
      icon: Icons.send_outlined,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Align(
            alignment: Alignment.centerLeft,
            child: TextButton.icon(
              onPressed: () => setState(() {
                _scheduling = !_scheduling;
                if (!_scheduling) {
                  widget.when.clear();
                  widget.onChanged();
                }
              }),
              icon: Icon(
                _scheduling ? Icons.schedule : Icons.schedule_outlined,
                size: 18,
              ),
              label: Text(_scheduling ? 'Send it now instead' : 'Send it later'),
            ),
          ),
          if (_scheduling) ...[
            const SizedBox(height: 8),
            CalendarDayField(
              controller: widget.when,
              formats: widget.session.me?.display ?? DisplayFormats.fallback,
              label: 'Send at',
              withTime: true,
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
             * happens to be. It has now been wrong twice for that reason: once
             * naming `Etc/GMT+5` as though it were permanent, and once saying
             * it had become `America/New_York` when that value lived only in
             * the shared working tree and was cut back before it shipped. The
             * note below is correct under every default because it promises a
             * wall time on the clock the SERVER picked and then names that
             * clock, rather than promising a particular clock.
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
          const SizedBox(height: 12),
          Align(
            alignment: Alignment.centerLeft,
            child: Refused(
              reason: refusal,
              child: FilledButton.icon(
                onPressed: refusal == null ? () => widget.onSend() : null,
                icon: const Icon(Icons.send, size: 18),
                label: Text(
                  _scheduling && widget.when.text.trim().isNotEmpty
                      ? 'Schedule'
                      : 'Send now',
                ),
              ),
            ),
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
