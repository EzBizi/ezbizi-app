import '../../core/mask.dart';
import '../../widgets/list_search.dart';
import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/theme.dart';
import '../../core/timestamps.dart';
import '../../widgets/alert_popup.dart' show questionAnswerLine;
import '../../widgets/error_note.dart';
import '../../widgets/side_panel.dart';
import '../../widgets/status_pill.dart';
import 'phone_search.dart';

/// Communication › Alerts: send chosen people an alert they must dismiss (UI
/// queue 65).
///
/// Jose, 2026-10-07: "This sort of alert notifications should be a pop up on
/// the screen, that they can dismiss", and of the drawn layouts, "2": this tab
/// lists the alerts sent, each with who has dismissed it, and New alert opens
/// the side panel. On a phone (layout 5) the form sits above the list.
///
/// The pop-up itself is [AlertPopups], raised by the bell. Who may send is
/// the tab's own permission, phone.alerts.view; the server checks it too.
class AlertsTab extends StatefulWidget {
  const AlertsTab({
    super.key,
    required this.api,
    this.formats = DisplayFormats.fallback,
  });

  final ApiClient api;

  /// Which way round a date is written: "Oct 2" or "2 Oct".
  final DisplayFormats formats;

  @override
  State<AlertsTab> createState() => _AlertsTabState();
}

class _AlertsTabState extends State<AlertsTab> {
  bool _loading = true;
  String? _error;
  List<Map<String, dynamic>> _sent = const [];

  /// The alert whose people are shown.
  String? _open;

  /// A question's answers, read when it is opened (UI queue 200).
  final _answers = <String, Map<String, dynamic>>{};

  Future<void> _loadAnswers(String id) async {
    final response = await widget.api.get('/alerts/$id/replies');
    if (!mounted || !response.ok) return;
    setState(
      () => _answers[id] = Map<String, dynamic>.from(response.data as Map),
    );
  }

  /// Every list has a search (deep dive 2026-11-02 #12): the message, the
  /// sender or a recipient.
  String _query = '';

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    final response = await widget.api.get('/alerts');
    if (!mounted) return;
    setState(() {
      _loading = false;
      _error = response.ok ? null : response.reason;
      if (response.ok) {
        _sent = [
          for (final row in (response.data as List? ?? const []))
            if (row is Map) Map<String, dynamic>.from(row),
        ];
      }
    });
  }

  Future<void> _newAlert() => showSidePanel<void>(
    context,
    builder: (context) => SidePanel(
      title: 'New alert',
      onClose: () => Navigator.of(context).pop(),
      child: AlertForm(
        api: widget.api,
        onSent: () {
          Navigator.of(context).pop();
          _load();
        },
      ),
    ),
  );

  @override
  Widget build(BuildContext context) {
    if (_loading) return const Center(child: CircularProgressIndicator());

    return LayoutBuilder(
      builder: (context, box) {
        final phone = box.maxWidth < 600;

        return ListView(
          padding: EdgeInsets.all(phone ? 12 : 24),
          children: [
            if (_sent.isNotEmpty) ...[
              Align(
                alignment: Alignment.centerLeft,
                child: phoneSearchBox(
                  key: const ValueKey('alerts-search'),
                  hint: 'Search alerts',
                  onChanged: (v) => setState(() => _query = v),
                ),
              ),
              const SizedBox(height: 12),
            ],
            if (phone)
              Card(
                child: Padding(
                  padding: const EdgeInsets.all(16),
                  child: AlertForm(api: widget.api, onSent: _load),
                ),
              )
            else
              Align(
                alignment: Alignment.centerRight,
                child: FilledButton.icon(
                  key: const Key('alert-new'),
                  onPressed: _newAlert,
                  icon: const Icon(Icons.add, size: 18),
                  label: const Text('New alert'),
                ),
              ),
            const SizedBox(height: 12),
            if (_error != null)
              ErrorNote(_error!)
            else if (_sent.isEmpty)
              Padding(
                key: const ValueKey('tour-phone-alerts-empty'),
                padding: const EdgeInsets.all(8),
                child: Text(
                  'No alerts sent yet. An alert stays on each person\'s '
                  'screen until they dismiss it.',
                  style: Theme.of(context).textTheme.bodyMedium,
                ),
              )
            else
              Card(
                key: const ValueKey('tour-phone-alerts-sent'),
                child: Padding(
                  padding: const EdgeInsets.symmetric(horizontal: 16),
                  child: Column(
                    children: [
                      for (final (i, alert) in _sent.where(_hit).indexed) ...[
                        if (i > 0) const Divider(height: 1),
                        _row(context, alert),
                      ],
                    ],
                  ),
                ),
              ),
          ],
        );
      },
    );
  }

  bool _hit(Map<String, dynamic> alert) => phoneSearchHit(_query, [
    alert['message'] as String?,
    alert['body'] as String?,
    (alert['sender'] as Map?)?['name'] as String?,
    for (final r in (alert['recipients'] as List? ?? const []))
      if (r is Map) r['name'] as String?,
  ]);

  Widget _row(BuildContext context, Map<String, dynamic> alert) {
    final theme = Theme.of(context);
    final id = '${alert['id']}';
    final people = [
      for (final row in (alert['recipients'] as List? ?? const []))
        if (row is Map) row,
    ];
    final done = people.where((p) => p['dismissed_at'] != null).length;
    final names = people.map((p) => '${p['name']}').toList();
    final to = names.length <= 3
        ? 'To ${_join(names)}'
        : 'To ${names.length} people';
    final open = _open == id;
    final question = alert['kind'] == 'question';
    final answered = (alert['answered'] as num?)?.toInt() ?? 0;
    final answers = {
      for (final r in (_answers[id]?['replies'] as List? ?? const []))
        if (r is Map) '${r['user_id']}': Map<String, dynamic>.from(r),
    };
    final choices = _answers[id]?['choices'] as List? ?? const [];

    return InkWell(
      key: ValueKey('alert-sent-$id'),
      onTap: () {
        setState(() => _open = open ? null : id);
        if (!open && question) _loadAnswers(id);
      },
      child: Padding(
        padding: const EdgeInsets.symmetric(vertical: 10),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Expanded(
                  child: Text(
                    open
                        ? '${alert['message'] ?? ''}'
                        : maskContacts('${alert['message'] ?? ''}'),
                    maxLines: open ? null : 2,
                    overflow: open ? null : TextOverflow.ellipsis,
                    style: theme.textTheme.bodyMedium?.copyWith(
                      fontWeight: FontWeight.w600,
                    ),
                  ),
                ),
                const SizedBox(width: 12),
                // Sent to nobody: "Dismissed by all 0" in green read as done.
                if (people.isEmpty)
                  const StatusPill('Sent to nobody', tone: Tone.neutral)
                else if (question)
                  StatusPill(
                    'Answered by $answered of ${people.length}',
                    tone: answered >= people.length ? Tone.ok : Tone.caution,
                  )
                else
                  StatusPill(
                    done == people.length
                        ? 'Dismissed by all ${people.length}'
                        : 'Dismissed by $done of ${people.length}',
                    tone: done == people.length ? Tone.ok : Tone.caution,
                  ),
              ],
            ),
            Text(
              '$to · ${formatListStamp(alert['created_at'], '', formats: widget.formats)}',
              style: theme.textTheme.bodySmall,
            ),
            if (open) ...[
              const SizedBox(height: 6),
              for (final person in people)
                Padding(
                  padding: const EdgeInsets.symmetric(vertical: 2),
                  child: Row(
                    children: [
                      Expanded(child: Text('${person['name']}')),
                      if (question)
                        Flexible(
                          flex: 2,
                          child: Text(
                            switch (answers['${person['id']}']) {
                              final Map<String, dynamic> a =>
                                questionAnswerLine(a, choices),
                              _ => 'Not answered yet',
                            },
                            key: ValueKey('alert-answer-$id-${person['id']}'),
                            textAlign: TextAlign.end,
                            style: theme.textTheme.bodySmall,
                          ),
                        )
                      else
                        Text(
                          person['dismissed_at'] == null
                              ? 'Not yet'
                              : 'Dismissed ${formatListStamp(person['dismissed_at'], '', formats: widget.formats)}',
                          style: theme.textTheme.bodySmall?.copyWith(
                            color: person['dismissed_at'] == null
                                ? ToneColours.of(context, Tone.caution).ink
                                : null,
                            fontWeight: person['dismissed_at'] == null
                                ? FontWeight.w700
                                : null,
                          ),
                        ),
                    ],
                  ),
                ),
            ],
          ],
        ),
      ),
    );
  }

  static String _join(List<String> names) => switch (names.length) {
    0 => 'nobody',
    1 => names.single,
    _ => '${names.sublist(0, names.length - 1).join(', ')} and ${names.last}',
  };
}

/// Who to alert and what to say: in the side panel on a desk, above the list
/// on a phone.
class AlertForm extends StatefulWidget {
  const AlertForm({super.key, required this.api, required this.onSent});

  final ApiClient api;
  final VoidCallback onSent;

  @override
  State<AlertForm> createState() => _AlertFormState();
}

class _AlertFormState extends State<AlertForm> {
  final _message = TextEditingController();
  final _search = TextEditingController();

  /// A question rather than an alert (UI queue 200): context, up to eight
  /// choices and/or a reply box.
  bool _question = false;
  final _context = TextEditingController();
  final _choiceInput = TextEditingController();
  final _choices = <String>[];
  bool _allowText = false;

  List<Map<String, dynamic>> _people = const [];
  final _picked = <String>[];
  bool _sending = false;
  String? _error;

  @override
  void initState() {
    super.initState();
    _loadPeople();
  }

  @override
  void dispose() {
    _message.dispose();
    _search.dispose();
    _context.dispose();
    _choiceInput.dispose();
    super.dispose();
  }

  Future<void> _loadPeople() async {
    final response = await widget.api.get('/alerts/recipients');
    if (!mounted) return;
    setState(() {
      if (!response.ok) {
        _error = response.reason;
        return;
      }
      _people = [
        for (final row in (response.data as List? ?? const []))
          if (row is Map) Map<String, dynamic>.from(row),
      ];
    });
  }

  String _name(String id) =>
      '${_people.firstWhere((p) => '${p['id']}' == id, orElse: () => const {})['name'] ?? ''}';

  Future<void> _send() async {
    setState(() {
      _sending = true;
      _error = null;
    });
    final about = _context.text.trim();
    final response = await widget.api.post('/alerts', {
      'user_ids': _picked,
      'message': _message.text.trim(),
      if (_question) ...{
        'kind': 'question',
        if (about.isNotEmpty) 'context': about,
        if (_choices.isNotEmpty) 'choices': _choices,
        'allow_text': _allowText,
      },
    });
    if (!mounted) return;
    setState(() => _sending = false);
    if (!response.ok) {
      setState(() => _error = response.reason);
      return;
    }
    final count = _picked.length;
    final what = _question ? 'Question' : 'Alert';
    setState(() {
      _picked.clear();
      _message.clear();
      _context.clear();
      _choices.clear();
    });
    ScaffoldMessenger.maybeOf(context)?.showSnackBar(
      SnackBar(
        content: Text(
          '$what sent to $count ${count == 1 ? 'person' : 'people'}.',
        ),
      ),
    );
    widget.onSent();
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final query = _search.text.trim().toLowerCase();
    final matches = [
      for (final person in _people)
        if (!_picked.contains('${person['id']}') &&
            '${person['name']}'.toLowerCase().contains(query))
          person,
    ];
    final ready =
        !_sending &&
        _picked.isNotEmpty &&
        _message.text.trim().isNotEmpty &&
        // A question needs something to answer with.
        (!_question || _choices.isNotEmpty || _allowText);

    return Column(
      key: const Key('alert-form'),
      crossAxisAlignment: CrossAxisAlignment.stretch,
      mainAxisSize: MainAxisSize.min,
      children: [
        Align(
          alignment: Alignment.centerLeft,
          child: SegmentedButton<bool>(
            key: const Key('alert-kind'),
            showSelectedIcon: false,
            segments: const [
              ButtonSegment(value: false, label: Text('Alert')),
              ButtonSegment(value: true, label: Text('Question')),
            ],
            selected: {_question},
            onSelectionChanged: (v) => setState(() => _question = v.single),
          ),
        ),
        const SizedBox(height: 12),
        Text('To', style: theme.textTheme.labelMedium),
        const SizedBox(height: 4),
        if (_picked.isNotEmpty) ...[
          Wrap(
            spacing: 6,
            runSpacing: 6,
            children: [
              for (final id in _picked)
                InputChip(
                  key: ValueKey('alert-to-$id'),
                  label: Text(_name(id)),
                  onDeleted: () => setState(() => _picked.remove(id)),
                ),
            ],
          ),
          const SizedBox(height: 6),
        ],
        ListSearch(
          key: const Key('alert-people-search'),
          controller: _search,
          hint: 'Add people…',
          onChanged: (_) => setState(() {}),
        ),
        if (query.isNotEmpty)
          ConstrainedBox(
            constraints: const BoxConstraints(maxHeight: 220),
            child: ListView(
              shrinkWrap: true,
              children: [
                for (final person in matches)
                  ListTile(
                    key: ValueKey('alert-person-${person['id']}'),
                    dense: true,
                    title: Text('${person['name']}'),
                    onTap: () => setState(() {
                      _picked.add('${person['id']}');
                      _search.clear();
                    }),
                  ),
                if (matches.isEmpty)
                  Padding(
                    padding: const EdgeInsets.all(12),
                    child: Text(
                      'Nobody by that name.',
                      style: theme.textTheme.bodySmall,
                    ),
                  ),
              ],
            ),
          ),
        const SizedBox(height: 12),
        TextField(
          key: const Key('alert-message'),
          controller: _message,
          maxLength: 500,
          minLines: 3,
          maxLines: 6,
          decoration: InputDecoration(
            labelText: _question ? 'Question' : 'Message',
            hintText: _question
                ? 'What do you want to ask?'
                : 'What should they know?',
            border: const OutlineInputBorder(),
            alignLabelWithHint: true,
          ),
          onChanged: (_) => setState(() {}),
        ),
        if (_question) ...[
          const SizedBox(height: 4),
          TextField(
            key: const Key('alert-context'),
            controller: _context,
            maxLength: 4000,
            minLines: 2,
            maxLines: 4,
            decoration: const InputDecoration(
              labelText: 'Context (optional)',
              hintText: 'What they need to know to answer',
              border: OutlineInputBorder(),
              alignLabelWithHint: true,
            ),
          ),
          const SizedBox(height: 4),
          Text('Choices · up to 8', style: theme.textTheme.labelMedium),
          const SizedBox(height: 4),
          if (_choices.isNotEmpty) ...[
            Wrap(
              spacing: 6,
              runSpacing: 6,
              children: [
                for (final (i, c) in _choices.indexed)
                  InputChip(
                    key: ValueKey('alert-choice-$i'),
                    avatar: CircleAvatar(
                      child: Text(String.fromCharCode(97 + i)),
                    ),
                    label: Text(c),
                    onDeleted: () => setState(() => _choices.removeAt(i)),
                  ),
              ],
            ),
            const SizedBox(height: 6),
          ],
          if (_choices.length < 8)
            TextField(
              key: const Key('alert-choice-input'),
              controller: _choiceInput,
              maxLength: 200,
              decoration: const InputDecoration(
                hintText: 'Add a choice, then Enter',
                border: OutlineInputBorder(),
                isDense: true,
                counterText: '',
              ),
              onSubmitted: (value) {
                final c = value.trim();
                setState(() {
                  if (c.isNotEmpty && !_choices.contains(c)) _choices.add(c);
                  _choiceInput.clear();
                });
              },
            ),
          SwitchListTile(
            key: const Key('alert-allow-text'),
            contentPadding: EdgeInsets.zero,
            title: const Text('They can also write a reply'),
            value: _allowText,
            onChanged: (on) => setState(() => _allowText = on),
          ),
        ],
        if (_error != null) ...[const SizedBox(height: 8), ErrorNote(_error!)],
        const SizedBox(height: 8),
        Row(
          children: [
            Expanded(
              child: Text(
                !_question
                    ? 'It pops up for each of them until they dismiss it.'
                    : _choices.isEmpty && !_allowText
                    ? 'Add a choice or let them reply.'
                    : 'It pops up for each of them until they answer it or '
                          'choose Later.',
                style: theme.textTheme.bodySmall,
              ),
            ),
            const SizedBox(width: 8),
            FilledButton.icon(
              key: const Key('alert-send'),
              onPressed: ready ? _send : null,
              icon: Icon(
                _question ? Icons.help_outline : Icons.campaign_outlined,
                size: 18,
              ),
              label: Text(
                _sending
                    ? 'Sending…'
                    : _question
                    ? 'Send question'
                    : 'Send alert',
              ),
            ),
          ],
        ),
      ],
    );
  }
}
