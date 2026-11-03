import '../../widgets/list_search.dart';
import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/theme.dart';
import '../../core/timestamps.dart';
import '../../widgets/error_note.dart';
import '../../widgets/side_panel.dart';
import '../../widgets/status_pill.dart';

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
                      for (final (i, alert) in _sent.indexed) ...[
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

    return InkWell(
      key: ValueKey('alert-sent-$id'),
      onTap: () => setState(() => _open = open ? null : id),
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
                    '${alert['message'] ?? ''}',
                    maxLines: open ? null : 2,
                    overflow: open ? null : TextOverflow.ellipsis,
                    style: theme.textTheme.bodyMedium?.copyWith(
                      fontWeight: FontWeight.w600,
                    ),
                  ),
                ),
                const SizedBox(width: 12),
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
    final response = await widget.api.post('/alerts', {
      'user_ids': _picked,
      'message': _message.text.trim(),
    });
    if (!mounted) return;
    setState(() => _sending = false);
    if (!response.ok) {
      setState(() => _error = response.reason);
      return;
    }
    final count = _picked.length;
    setState(() {
      _picked.clear();
      _message.clear();
    });
    ScaffoldMessenger.maybeOf(context)?.showSnackBar(
      SnackBar(
        content: Text(
          'Alert sent to $count ${count == 1 ? 'person' : 'people'}.',
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
        !_sending && _picked.isNotEmpty && _message.text.trim().isNotEmpty;

    return Column(
      key: const Key('alert-form'),
      crossAxisAlignment: CrossAxisAlignment.stretch,
      mainAxisSize: MainAxisSize.min,
      children: [
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
          decoration: const InputDecoration(
            labelText: 'Message',
            hintText: 'What should they know?',
            border: OutlineInputBorder(),
            alignLabelWithHint: true,
          ),
          onChanged: (_) => setState(() {}),
        ),
        if (_error != null) ...[const SizedBox(height: 8), ErrorNote(_error!)],
        const SizedBox(height: 8),
        Row(
          children: [
            Expanded(
              child: Text(
                'It pops up for each of them until they dismiss it.',
                style: theme.textTheme.bodySmall,
              ),
            ),
            const SizedBox(width: 8),
            FilledButton.icon(
              key: const Key('alert-send'),
              onPressed: ready ? _send : null,
              icon: const Icon(Icons.campaign_outlined, size: 18),
              label: Text(_sending ? 'Sending…' : 'Send alert'),
            ),
          ],
        ),
      ],
    );
  }
}
