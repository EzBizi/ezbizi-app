import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/session.dart';
import '../../widgets/error_note.dart';
import '../../widgets/refused.dart';

/// Phone › Settings › Tags — what calls get counted by.
///
/// ── Why a list and not free text ─────────────────────────────────
///
/// The point of tagging is counting. Free text cannot be counted: "no answer",
/// "No Answer" and "noanswer" are three answers to one question, and a report
/// built on them is wrong in a way nobody notices. A named list means renaming
/// a tag renames it everywhere and last month still adds up.
///
/// ── Why removing a used tag retires it ───────────────────────────
///
/// Deleting one would blank the label on every call it described and quietly
/// change totals that have already been read. So a tag that has been used is
/// retired: off the picker, still on its calls. The screen says which, and how
/// many, before anybody presses it.
class TagsPane extends StatefulWidget {
  const TagsPane({super.key, required this.api, required this.session});

  final ApiClient api;
  final Session session;

  @override
  State<TagsPane> createState() => _TagsPaneState();
}

class _TagsPaneState extends State<TagsPane> {
  bool _loading = true;
  String? _error;
  String? _note;

  List<_Tag> _tags = const [];

  final _name = TextEditingController();
  bool _saving = false;
  String? _formError;

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _name.dispose();
    super.dispose();
  }

  Me get _me => widget.session.me!;

  bool get _canEdit => _me.can('phone.edit');

  String? get _editRefusal => _me.refusal('phone.edit', 'change the call tags');

  Future<void> _load() async {
    setState(() {
      _loading = _tags.isEmpty;
      _error = null;
    });

    final response = await widget.api.get('/phone/tags');

    if (!mounted) return;

    setState(() {
      _loading = false;
      if (!response.ok) {
        _error = response.reason;
        return;
      }
      _error = null;
      _tags = _Tag.listFrom(response.body);
    });
  }

  Future<void> _add() async {
    final name = _name.text.trim();

    if (name.isEmpty) {
      setState(() => _formError = 'Give the tag a name.');
      return;
    }

    setState(() {
      _saving = true;
      _formError = null;
    });

    final response = await widget.api.post('/phone/tags', {'name': name});

    if (!mounted) return;

    if (!response.ok) {
      setState(() {
        _saving = false;
        _formError = response.reason;
      });
      return;
    }

    _name.clear();
    setState(() => _saving = false);
    await _load();
  }

  Future<void> _setActive(_Tag tag, bool active) async {
    final response = await widget.api.patch('/phone/tags/${tag.id}', {
      'is_active': active,
    });

    if (!mounted) return;

    if (!response.ok) {
      setState(() => _formError = response.reason);
      return;
    }

    await _load();
  }

  Future<void> _remove(_Tag tag) async {
    final response = await widget.api.delete('/phone/tags/${tag.id}');

    if (!mounted) return;

    if (!response.ok) {
      setState(() => _formError = response.reason);
      return;
    }

    setState(() => _note = response.body['message'] as String?);
    await _load();
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    if (_loading) {
      return const Center(child: CircularProgressIndicator());
    }

    if (_error != null) {
      return Padding(
        padding: const EdgeInsets.all(24),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          mainAxisSize: MainAxisSize.min,
          children: [
            ErrorNote(_error!),
            const SizedBox(height: 12),
            TextButton(onPressed: _load, child: const Text('Try again')),
          ],
        ),
      );
    }

    final live = _tags.where((t) => t.isActive).toList();
    final retired = _tags.where((t) => !t.isActive).toList();

    return ListView(
      key: const ValueKey('tags-pane'),
      padding: const EdgeInsets.all(24),
      children: [
        Text('Tags', style: theme.textTheme.titleMedium),
        const SizedBox(height: 4),
        Text(
          'What a call was about. Whoever answers picks one when the call ends, '
          'and reports count by them.',
          style: theme.textTheme.bodySmall,
        ),

        if (_note != null) ...[
          const SizedBox(height: 12),
          Text(_note!, style: theme.textTheme.bodySmall),
        ],

        if (_formError != null) ...[
          const SizedBox(height: 12),
          ErrorNote(_formError!),
        ],

        const SizedBox(height: 16),

        Refused(
          reason: _canEdit ? null : _editRefusal,
          child: Row(
            children: [
              Expanded(
                child: TextField(
                  key: const ValueKey('tag-name'),
                  controller: _name,
                  enabled: _canEdit,
                  decoration: const InputDecoration(
                    labelText: 'A new tag',
                    hintText: 'Warranty',
                    border: OutlineInputBorder(),
                    isDense: true,
                  ),
                  onSubmitted: (_) => _canEdit ? _add() : null,
                ),
              ),
              const SizedBox(width: 12),
              FilledButton(
                onPressed: (!_canEdit || _saving) ? null : _add,
                child: Text(_saving ? 'Adding…' : 'Add'),
              ),
            ],
          ),
        ),

        const SizedBox(height: 24),

        if (live.isEmpty)
          Text(
            'No tags yet. Until there is at least one, a call cannot be said to '
            'be about anything.',
            style: theme.textTheme.bodySmall,
          )
        else
          for (final tag in live) _row(tag, theme, retired: false),

        if (retired.isNotEmpty) ...[
          const SizedBox(height: 24),
          Text('Retired', style: theme.textTheme.labelLarge),
          const SizedBox(height: 4),
          Text(
            'Still naming the calls they were used on. Not offered any more.',
            style: theme.textTheme.bodySmall,
          ),
          const SizedBox(height: 8),
          for (final tag in retired) _row(tag, theme, retired: true),
        ],
      ],
    );
  }

  Widget _row(_Tag tag, ThemeData theme, {required bool retired}) {
    return Card(
      margin: const EdgeInsets.only(bottom: 8),
      child: ListTile(
        key: ValueKey('tag:${tag.id}'),
        title: Text(tag.name),
        subtitle: Text(
          tag.callCount == 0
              ? 'Not used yet'
              : 'On ${tag.callCount} call${tag.callCount == 1 ? '' : 's'}',
          style: theme.textTheme.bodySmall,
        ),
        trailing: !_canEdit
            ? null
            : Row(
                mainAxisSize: MainAxisSize.min,
                children: [
                  TextButton(
                    onPressed: () => _setActive(tag, retired),
                    child: Text(retired ? 'Offer again' : 'Retire'),
                  ),
                  if (tag.callCount == 0)
                    TextButton(
                      onPressed: () => _remove(tag),
                      child: const Text('Remove'),
                    ),
                ],
              ),
      ),
    );
  }
}

@immutable
class _Tag {
  const _Tag({
    required this.id,
    required this.name,
    required this.isActive,
    required this.callCount,
  });

  final String id;
  final String name;
  final bool isActive;
  final int callCount;

  static List<_Tag> listFrom(dynamic payload) {
    final data = payload is Map ? payload['data'] : payload;
    if (data is! List) return const [];

    return data
        .whereType<Map<String, dynamic>>()
        .map(
          (r) => _Tag(
            id: r['id'].toString(),
            name: r['name']?.toString() ?? '',
            isActive: r['is_active'] != false,
            callCount: (r['call_count'] as num?)?.toInt() ?? 0,
          ),
        )
        .toList(growable: false);
  }
}
