import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../widgets/search_picker.dart';
import '../../widgets/side_panel.dart';
import 'newsletter_blocks.dart';

/// Newsletter › New list (UI queue 173, design 1): a mailing list made in a
/// side panel. Returns the list created, or null. Both kinds need a Mailchimp
/// list, since that is where the send goes; the optional tag narrows it.
Future<NewsletterAudience?> showNewListPanel(
  BuildContext context, {
  required ApiClient api,
}) => showSidePanel<NewsletterAudience>(
  context,
  builder: (panel) => _NewListPanel(api: api),
);

class _NewListPanel extends StatefulWidget {
  const _NewListPanel({required this.api});

  final ApiClient api;

  @override
  State<_NewListPanel> createState() => _NewListPanelState();
}

class _NewListPanelState extends State<_NewListPanel> {
  final _name = TextEditingController();
  final _tag = TextEditingController();
  String _kind = 'customers';
  String? _listId;
  List<SearchOption<String>>? _lists;
  String? _listsNote;
  String? _error;
  bool _saving = false;

  @override
  void initState() {
    super.initState();
    _loadLists();
  }

  @override
  void dispose() {
    _name.dispose();
    _tag.dispose();
    super.dispose();
  }

  Future<void> _loadLists() async {
    final r = await widget.api.get('/newsletter/mailchimp/lists');
    if (!mounted) return;
    final data = r.ok
        ? (r.body['data'] as List<dynamic>? ?? const [])
        : const [];
    setState(() {
      _lists = [
        for (final l in data.whereType<Map<String, dynamic>>())
          SearchOption(value: '${l['id']}', label: '${l['name']}'),
      ];
      _listsNote = r.body['message'] as String?;
    });
  }

  bool get _ready =>
      !_saving && _name.text.trim().isNotEmpty && _listId != null;

  Future<void> _create() async {
    setState(() {
      _saving = true;
      _error = null;
    });
    final tag = _tag.text.trim();
    final r = await widget.api.post('/newsletter/audiences', {
      'name': _name.text.trim(),
      'kind': _kind,
      'mailchimp_list_id': _listId,
      if (tag.isNotEmpty) 'tag': tag,
    });
    if (!mounted) return;
    if (!r.ok) {
      setState(() {
        _saving = false;
        _error = r.message;
      });
      return;
    }
    final d = r.body['data'] as Map<String, dynamic>? ?? const {};
    Navigator.of(context).pop(
      NewsletterAudience(
        id: '${d['id'] ?? ''}',
        name: d['name'] as String? ?? _name.text.trim(),
        kind: d['kind'] as String? ?? _kind,
        tag: tag.isEmpty ? null : tag,
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final mailchimp = _kind == 'mailchimp';
    return SidePanel(
      title: 'New mailing list',
      actions: [
        TextButton(
          onPressed: () => Navigator.of(context).pop(),
          child: const Text('Cancel'),
        ),
        FilledButton(
          key: const ValueKey('new-list-create'),
          onPressed: _ready ? _create : null,
          child: const Text('Create list'),
        ),
      ],
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          TextField(
            key: const ValueKey('new-list-name'),
            controller: _name,
            maxLength: 120,
            decoration: const InputDecoration(labelText: 'Name'),
            onChanged: (_) => setState(() {}),
          ),
          const SizedBox(height: 8),
          Align(
            alignment: Alignment.centerLeft,
            child: SegmentedButton<String>(
              segments: const [
                ButtonSegment(value: 'customers', label: Text('Customers')),
                ButtonSegment(value: 'mailchimp', label: Text('Mailchimp')),
              ],
              selected: {_kind},
              showSelectedIcon: false,
              onSelectionChanged: (s) => setState(() => _kind = s.first),
            ),
          ),
          const SizedBox(height: 12),
          if (_lists == null)
            const LinearProgressIndicator()
          else
            SearchPickerField<String>(
              key: const ValueKey('new-list-mailchimp'),
              value: _listId,
              label: 'Mailchimp list',
              helperText: _lists!.isEmpty ? _listsNote : null,
              options: _lists!,
              onChanged: (v) => setState(() => _listId = v),
            ),
          const SizedBox(height: 12),
          TextField(
            key: const ValueKey('new-list-tag'),
            controller: _tag,
            maxLength: 100,
            decoration: InputDecoration(
              labelText: 'Only people tagged (optional)',
              helperText: mailchimp
                  ? 'A Mailchimp tag on this list. Only its subscribed members get the send.'
                  : 'A job tag. Only customers with a job carrying it get the send.',
              helperMaxLines: 2,
            ),
          ),
          if (_error != null) ...[
            const SizedBox(height: 8),
            Text(
              _error!,
              style: TextStyle(color: Theme.of(context).colorScheme.error),
            ),
          ],
        ],
      ),
    );
  }
}
