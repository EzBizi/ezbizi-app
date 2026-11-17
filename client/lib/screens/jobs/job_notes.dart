import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../../core/api_client.dart';
import '../../core/timestamps.dart';
import '../../widgets/error_note.dart';
import '../../widgets/list_table.dart';
import '../../widgets/mention_field.dart';
import '../../widgets/refused.dart';

/// A job's notes (UI queue 122; option 1 and phone 4): the box on top, the
/// notes under it, newest first. The one notes panel for a job, wherever its
/// notes show: Jobs, Project Management.
///
/// Jose, 2026-11-17 ("i cannot edit notes on project management"): a note's
/// author, or an administrator, may change or delete it, and it then reads
/// "edited"; Workiz's own job notes are edited in place and written back to
/// Workiz.
///
/// The notes arrive with the job (`notes` on its detail). An added note is put
/// at the top through [onAdded] without reloading the panel.
class JobNotes extends StatefulWidget {
  const JobNotes({
    super.key,
    required this.api,
    required this.jobId,
    required this.notes,
    required this.draft,
    required this.formats,
    required this.onAdded,
    this.onChanged,
    this.workizNotes,
    this.addRefusal,
    this.now,
  });

  final ApiClient api;
  final String jobId;
  final List<Map<String, dynamic>> notes;

  /// Held by the panel, so a half-typed note survives a look at another tab.
  final TextEditingController draft;
  final DisplayFormats formats;
  final ValueChanged<Map<String, dynamic>> onAdded;

  /// The whole list after a note was edited or deleted here.
  final ValueChanged<List<Map<String, dynamic>>>? onChanged;

  /// Workiz's job notes (Jose, 2026-11-10: they were in the Notes column but
  /// not here), editable since 2026-11-17.
  final String? workizNotes;

  /// Why this reader may not add one (`jobs.edit`), or null when they may.
  final String? addRefusal;

  /// For tests: what "today" is.
  final DateTime? now;

  @override
  State<JobNotes> createState() => _JobNotesState();
}

class _JobNotesState extends State<JobNotes> {
  bool _sending = false;
  String? _error;
  final _mentions = MentionController();

  /// Workiz's notes as last saved here, else as the job sent them.
  late String? _workiz = widget.workizNotes;
  TextEditingController? _workizEdit;
  bool _workizSaving = false;
  String? _workizError;

  @override
  void initState() {
    super.initState();
    // Opening the notes reads them (unread notes, monday port 2026-11-09).
    if (widget.notes.isNotEmpty) {
      widget.api.post('/note-reads', {'type': 'job', 'id': widget.jobId});
    }
  }

  @override
  void didUpdateWidget(JobNotes old) {
    super.didUpdateWidget(old);
    if (old.workizNotes != widget.workizNotes && _workizEdit == null) {
      _workiz = widget.workizNotes;
    }
  }

  @override
  void dispose() {
    _workizEdit?.dispose();
    super.dispose();
  }

  bool get _canAdd => widget.addRefusal == null && !_sending;

  Future<void> _add() async {
    final body = widget.draft.text.trim();
    if (!_canAdd || body.isEmpty) return;

    setState(() {
      _sending = true;
      _error = null;
    });

    final mentions = _mentions.ids(body);
    final response = await widget.api.post('/jobs/${widget.jobId}/notes', {
      'body': body,
      if (mentions.isNotEmpty) 'mentions': mentions,
    });

    if (!mounted) return;

    if (!response.ok || response.data is! Map) {
      setState(() {
        _sending = false;
        _error = response.ok ? response.message : response.reason;
      });
      return;
    }

    widget.draft.clear();
    _mentions.clear();
    setState(() => _sending = false);
    widget.onAdded(Map<String, dynamic>.from(response.data as Map));
  }

  /// Saves Workiz's notes; a refusal keeps what was typed, open.
  Future<void> _saveWorkiz() async {
    final edit = _workizEdit;
    if (edit == null || _workizSaving) return;
    setState(() {
      _workizSaving = true;
      _workizError = null;
    });
    final response = await widget.api.put(
      '/jobs/${widget.jobId}/workiz-notes',
      // What was opened: Workiz refuses the write if its notes moved since.
      {'text': edit.text, 'was': _workiz ?? ''},
    );
    if (!mounted) return;
    if (!response.ok) {
      setState(() {
        _workizSaving = false;
        _workizError = response.reason;
      });
      return;
    }
    final data = response.data;
    setState(() {
      _workizSaving = false;
      _workiz = data is Map ? data['workiz_notes'] as String? : edit.text;
      _workizEdit = null;
    });
    edit.dispose();
  }

  Future<String?> _changeNote(Map<String, dynamic> note, String? body) async {
    final path = '/jobs/${widget.jobId}/notes/${note['id']}';
    final response = body == null
        ? await widget.api.delete(path)
        : await widget.api.patch(path, {'body': body});
    if (!mounted) return null;
    if (!response.ok) return response.reason;
    final next = [
      for (final n in widget.notes)
        if (n['id'] != note['id'])
          n
        else if (body != null && response.data is Map)
          Map<String, dynamic>.from(response.data as Map),
    ];
    widget.onChanged?.call(next);
    return null;
  }

  Widget _workizBlock(ThemeData theme, Color muted) {
    final edit = _workizEdit;
    final canEdit = widget.addRefusal == null;
    return Container(
      key: const Key('job-notes-workiz'),
      width: double.infinity,
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: theme.colorScheme.surfaceContainerHighest.withValues(alpha: 0.5),
        borderRadius: BorderRadius.circular(8),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Row(
            children: [
              Expanded(
                child: Text(
                  'FROM WORKIZ',
                  style: theme.textTheme.labelSmall?.copyWith(
                    color: muted,
                    fontWeight: FontWeight.w700,
                  ),
                ),
              ),
              if (edit == null && canEdit)
                TextButton.icon(
                  key: const Key('job-notes-workiz-edit'),
                  style: TextButton.styleFrom(
                    visualDensity: VisualDensity.compact,
                  ),
                  onPressed: () => setState(
                    () => _workizEdit = TextEditingController(text: _workiz),
                  ),
                  icon: const Icon(Icons.edit_outlined, size: 16),
                  label: const Text('Edit'),
                ),
            ],
          ),
          const SizedBox(height: 6),
          if (edit == null)
            SelectableText(_workiz ?? '')
          else ...[
            TextField(
              key: const Key('job-notes-workiz-box'),
              controller: edit,
              minLines: 3,
              maxLines: 14,
              keyboardType: TextInputType.multiline,
              enabled: !_workizSaving,
            ),
            if (_workizError != null) ...[
              const SizedBox(height: 8),
              ErrorNote(_workizError!, key: const Key('job-notes-workiz-error')),
            ],
            const SizedBox(height: 8),
            Row(
              mainAxisAlignment: MainAxisAlignment.end,
              children: [
                TextButton(
                  onPressed: _workizSaving
                      ? null
                      : () => setState(() {
                          _workizEdit = null;
                          _workizError = null;
                          edit.dispose();
                        }),
                  child: const Text('Cancel'),
                ),
                const SizedBox(width: 8),
                FilledButton(
                  key: const Key('job-notes-workiz-save'),
                  onPressed: _workizSaving ? null : _saveWorkiz,
                  child: Text(_workizSaving ? 'Saving…' : 'Save to Workiz'),
                ),
              ],
            ),
          ],
        ],
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final muted = theme.colorScheme.onSurfaceVariant;

    return LayoutBuilder(
      builder: (context, constraints) {
        final phone = constraints.maxWidth < 420;
        final add = FilledButton(
          key: const Key('job-note-add'),
          onPressed: _canAdd ? _add : null,
          child: const Text('Add note'),
        );

        return Column(
          key: const Key('job-notes'),
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Refused(
              reason: widget.addRefusal,
              child: CallbackShortcuts(
                bindings: {
                  const SingleActivator(
                    LogicalKeyboardKey.enter,
                    control: true,
                  ): _add,
                  const SingleActivator(LogicalKeyboardKey.enter, meta: true):
                      _add,
                },
                child: MentionField(
                  api: widget.api,
                  type: 'job',
                  id: widget.jobId,
                  controller: widget.draft,
                  mentions: _mentions,
                  child: TextField(
                    key: const Key('job-note-box'),
                    controller: widget.draft,
                    enabled: _canAdd,
                    minLines: phone ? 2 : 3,
                    maxLines: 8,
                    keyboardType: TextInputType.multiline,
                    decoration: const InputDecoration(
                      hintText: 'Add a note for the team… (@ to mention)',
                    ),
                  ),
                ),
              ),
            ),
            const SizedBox(height: 8),
            Row(
              children: [
                if (!phone)
                  Expanded(
                    child: Text(
                      'Ctrl+Enter adds it',
                      style: theme.textTheme.bodySmall?.copyWith(color: muted),
                    ),
                  )
                else
                  const Spacer(),
                Refused(reason: widget.addRefusal, child: add),
              ],
            ),
            if (_error != null) ...[
              const SizedBox(height: 8),
              ErrorNote(_error!, key: const Key('job-note-error')),
            ],
            const SizedBox(height: 16),
            if (_workiz != null || _workizEdit != null) ...[
              _workizBlock(theme, muted),
              const SizedBox(height: 12),
            ],
            if (widget.notes.isEmpty && _workiz == null)
              Text(
                'No notes yet',
                key: const Key('job-notes-empty'),
                style: theme.textTheme.bodyMedium?.copyWith(color: muted),
              )
            else
              for (final note in widget.notes)
                _NoteRow(
                  key: ValueKey('job-note-${note['id']}'),
                  note: note,
                  formats: widget.formats,
                  now: widget.now,
                  phone: phone,
                  onChange: note['can_change'] == true && widget.addRefusal == null
                      ? (body) => _changeNote(note, body)
                      : null,
                ),
          ],
        );
      },
    );
  }
}

class _NoteRow extends StatefulWidget {
  const _NoteRow({
    super.key,
    required this.note,
    required this.formats,
    required this.phone,
    this.now,
    this.onChange,
  });

  final Map<String, dynamic> note;
  final DisplayFormats formats;
  final bool phone;
  final DateTime? now;

  /// Saves new words, or deletes with null; answers a refusal, or null.
  final Future<String?> Function(String? body)? onChange;

  @override
  State<_NoteRow> createState() => _NoteRowState();
}

class _NoteRowState extends State<_NoteRow> {
  TextEditingController? _edit;
  bool _busy = false;
  String? _error;

  @override
  void dispose() {
    _edit?.dispose();
    super.dispose();
  }

  Future<void> _run(String? body) async {
    setState(() {
      _busy = true;
      _error = null;
    });
    final refused = await widget.onChange!(body);
    if (!mounted) return;
    setState(() {
      _busy = false;
      _error = refused;
      if (refused == null) {
        _edit?.dispose();
        _edit = null;
      }
    });
  }

  @override
  Widget build(BuildContext context) {
    final note = widget.note;
    final theme = Theme.of(context);
    final who = noteAuthor(note);
    final when = [
      noteWhen(note['created_at'], formats: widget.formats, now: widget.now),
      if (note['edited_at'] != null) 'edited',
    ].join(' · ');
    final full = formatStamp(note['created_at'], '', formats: widget.formats);
    final edit = _edit;
    final Widget body = edit == null
        ? SelectableText(
            '${note['body'] ?? ''}',
            style: theme.textTheme.bodyMedium,
          )
        : Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              TextField(
                key: ValueKey('job-note-edit-${note['id']}'),
                controller: edit,
                minLines: 2,
                maxLines: 10,
                enabled: !_busy,
                keyboardType: TextInputType.multiline,
              ),
              const SizedBox(height: 6),
              Row(
                mainAxisAlignment: MainAxisAlignment.end,
                children: [
                  TextButton(
                    onPressed: _busy
                        ? null
                        : () => setState(() {
                            _edit = null;
                            _error = null;
                            edit.dispose();
                          }),
                    child: const Text('Cancel'),
                  ),
                  const SizedBox(width: 8),
                  FilledButton(
                    key: ValueKey('job-note-save-${note['id']}'),
                    onPressed: _busy || edit.text.trim().isEmpty
                        ? null
                        : () => _run(edit.text.trim()),
                    child: const Text('Save'),
                  ),
                ],
              ),
            ],
          );
    final menu = widget.onChange == null || edit != null
        ? null
        : MenuAnchor(
            menuChildren: [
              MenuItemButton(
                key: ValueKey('job-note-edit-item-${note['id']}'),
                leadingIcon: const Icon(Icons.edit_outlined, size: 18),
                onPressed: () => setState(
                  () => _edit = TextEditingController(text: '${note['body']}'),
                ),
                child: const Text('Edit'),
              ),
              MenuItemButton(
                key: ValueKey('job-note-delete-${note['id']}'),
                leadingIcon: Icon(
                  Icons.delete_outline,
                  size: 18,
                  color: theme.colorScheme.error,
                ),
                onPressed: () => _run(null),
                child: const Text('Delete'),
              ),
            ],
            builder: (context, controller, _) => IconButton(
              key: ValueKey('job-note-menu-${note['id']}'),
              tooltip: 'More',
              visualDensity: VisualDensity.compact,
              icon: const Icon(Icons.more_horiz, size: 18),
              onPressed: () =>
                  controller.isOpen ? controller.close() : controller.open(),
            ),
          );

    return Container(
      padding: const EdgeInsets.symmetric(vertical: 10),
      decoration: BoxDecoration(
        border: Border(
          top: BorderSide(color: theme.colorScheme.outlineVariant),
        ),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Expanded(
                child: Tooltip(
                  message: full,
                  child: widget.phone
                      ? Text(
                          '$who · $when',
                          style: theme.textTheme.bodySmall?.copyWith(
                            fontWeight: FontWeight.w700,
                          ),
                        )
                      : PersonCell(name: who, sub: when),
                ),
              ),
              ?menu,
            ],
          ),
          const SizedBox(height: 6),
          if (widget.phone)
            body
          else
            Padding(padding: const EdgeInsets.only(left: 42), child: body),
          if (_error != null) ...[
            const SizedBox(height: 6),
            ErrorNote(_error!),
          ],
        ],
      ),
    );
  }
}

/// The job's notes as the detail sent them, newest first.
List<Map<String, dynamic>> jobNotes(Object? raw) => [
  if (raw is List)
    for (final n in raw)
      if (n is Map) Map<String, dynamic>.from(n),
];

/// Who wrote it. The server sets the author on every note it stores, and the
/// column is nulled only when that user is deleted (`nullOnDelete`): no
/// automation writes notes. So a null author is a person who is gone.
String noteAuthor(Map<String, dynamic> note) {
  final author = note['author'];
  final name = author is Map ? author['name'] : null;

  return name is String && name.trim().isNotEmpty ? name : 'Former user';
}

/// "today 9:40", "yesterday 16:05", or the date for anything older.
String noteWhen(Object? iso, {required DisplayFormats formats, DateTime? now}) {
  final at = iso is String ? DateTime.tryParse(iso)?.toLocal() : null;
  if (at == null) return '';

  final today = (now ?? DateTime.now()).toLocal();
  final day = DateTime(at.year, at.month, at.day);
  final offset = DateTime(
    today.year,
    today.month,
    today.day,
  ).difference(day).inHours;
  final time = formats.formatTime(at.hour, at.minute);

  // Hours, rounded, rather than inDays: a day across a clock change is 23 or
  // 25 hours long.
  return switch ((offset / 24).round()) {
    0 => 'today $time',
    1 => 'yesterday $time',
    _ => formats.formatDate(at),
  };
}

/// The first line of a note, for the one-line preview on the Job tab.
String noteFirstLine(Map<String, dynamic> note) =>
    '${note['body'] ?? ''}'.trim().split('\n').first.trim();
