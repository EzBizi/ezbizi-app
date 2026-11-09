import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../../core/api_client.dart';
import '../../core/timestamps.dart';
import '../../widgets/error_note.dart';
import '../../widgets/list_table.dart';
import '../../widgets/mention_field.dart';
import '../../widgets/refused.dart';

/// A job's notes (UI queue 122; option 1 and phone 4): the box on top, the
/// notes under it, newest first. Append-only, as the server is: no edit, no
/// delete, the next note corrects the last.
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

  @override
  void initState() {
    super.initState();
    // Opening the notes reads them (unread notes, monday port 2026-11-09).
    if (widget.notes.isNotEmpty) {
      widget.api.post('/note-reads', {'type': 'job', 'id': widget.jobId});
    }
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
            if (widget.notes.isEmpty)
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
                ),
          ],
        );
      },
    );
  }
}

class _NoteRow extends StatelessWidget {
  const _NoteRow({
    super.key,
    required this.note,
    required this.formats,
    required this.phone,
    this.now,
  });

  final Map<String, dynamic> note;
  final DisplayFormats formats;
  final bool phone;
  final DateTime? now;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final who = noteAuthor(note);
    final when = noteWhen(note['created_at'], formats: formats, now: now);
    final full = formatStamp(note['created_at'], '', formats: formats);
    final body = SelectableText(
      '${note['body'] ?? ''}',
      style: theme.textTheme.bodyMedium,
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
          Tooltip(
            message: full,
            child: phone
                ? Text(
                    '$who · $when',
                    style: theme.textTheme.bodySmall?.copyWith(
                      fontWeight: FontWeight.w700,
                    ),
                  )
                : PersonCell(name: who, sub: when),
          ),
          const SizedBox(height: 6),
          if (phone)
            body
          else
            Padding(padding: const EdgeInsets.only(left: 42), child: body),
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
