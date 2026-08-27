import 'package:flutter/material.dart';

import 'task_api.dart';
import 'task_models.dart';

/// Governed links are a server-authorized projection, not a local record
/// picker. This widget deliberately knows no record routes or scope rules.
class TaskRecordLinks extends StatefulWidget {
  const TaskRecordLinks({
    super.key,
    required this.api,
    required this.snapshot,
    required this.onChanged,
    required this.onOpenRoute,
  });

  final TaskApi api;
  final TaskDetailSnapshot snapshot;
  final ValueChanged<TaskDetailSnapshot> onChanged;
  final ValueChanged<String> onOpenRoute;

  @override
  State<TaskRecordLinks> createState() => _TaskRecordLinksState();
}

class _TaskRecordLinksState extends State<TaskRecordLinks> {
  final _search = TextEditingController();
  List<TaskRecordLinkType> _types = const [];
  List<TaskRecordLinkCandidate> _matches = const [];
  String? _selectedType;
  String? _notice;
  bool _pickerOpen = false;
  bool _busy = false;
  int _searchGeneration = 0;

  @override
  void dispose() {
    _search.dispose();
    super.dispose();
  }

  @override
  void didUpdateWidget(covariant TaskRecordLinks oldWidget) {
    super.didUpdateWidget(oldWidget);
    // A freshly authorized snapshot replaces the list rather than merging it.
    // That is what drops identity fields immediately after target access loss.
    if (oldWidget.snapshot != widget.snapshot) {
      _searchGeneration++;
      _notice = null;
      _matches = const [];
      _search.clear();
    }
  }

  Future<void> _openPicker() async {
    if (_busy) return;
    setState(() {
      _pickerOpen = true;
      _notice = null;
      _matches = const [];
    });
    final result = await widget.api.recordLinkTypes(widget.snapshot.task.id);
    if (!mounted) return;
    if (result is TaskApiSuccess<List<TaskRecordLinkType>>) {
      setState(() {
        _types = result.data;
        _selectedType = result.data.isEmpty ? null : result.data.first.key;
      });
      return;
    }
    setState(() => _notice = result.message ?? 'Record links are unavailable.');
  }

  Future<void> _searchFor(String text) async {
    final snapshot = widget.snapshot;
    final type = _selectedType;
    final query = text.trim();
    final generation = ++_searchGeneration;
    if (type == null || query.isEmpty) {
      setState(() => _matches = const []);
      return;
    }
    final result = await widget.api.searchRecordLinks(
      snapshot.task.id,
      type: type,
      query: query,
    );
    if (!mounted ||
        generation != _searchGeneration ||
        !identical(widget.snapshot, snapshot)) {
      return;
    }
    if (result is TaskApiSuccess<List<TaskRecordLinkCandidate>>) {
      setState(() => _matches = result.data);
    } else {
      setState(() {
        _matches = const [];
        _notice = result.message ?? 'Record search is unavailable.';
      });
    }
  }

  Future<void> _link(TaskRecordLinkCandidate candidate) async {
    if (_busy) return;
    setState(() {
      _busy = true;
      _notice = null;
    });
    final result = await widget.api.linkRecord(
      widget.snapshot.task.id,
      expectedTaskVersion: widget.snapshot.task.version,
      recordType: candidate.type,
      recordId: candidate.id,
    );
    if (!mounted) return;
    if (result is TaskApiSuccess<TaskRecordLink>) {
      await _refresh();
      if (!mounted) return;
      setState(() => _pickerOpen = false);
    } else {
      setState(
        () => _notice = result.message ?? 'The record could not be linked.',
      );
    }
    if (mounted) setState(() => _busy = false);
  }

  Future<void> _unlink(TaskRecordLink link) async {
    if (_busy) return;
    setState(() {
      _busy = true;
      _notice = null;
    });
    final result = await widget.api.unlinkRecord(
      widget.snapshot.task.id,
      link.linkId,
      expectedTaskVersion: widget.snapshot.task.version,
    );
    if (!mounted) return;
    if (result is TaskApiSuccess<bool> && result.data) {
      await _refresh();
    } else {
      setState(
        () => _notice = result.message ?? 'The record could not be unlinked.',
      );
    }
    if (mounted) setState(() => _busy = false);
  }

  Future<void> _refresh() async {
    final result = await widget.api.taskDetail(
      widget.snapshot.task.boardId,
      widget.snapshot.task.id,
    );
    if (!mounted) return;
    if (result is TaskApiSuccess<TaskDetailSnapshot>) {
      widget.onChanged(result.data);
    } else {
      setState(
        () => _notice =
            result.message ?? 'The record links could not be refreshed.',
      );
    }
  }

  @override
  Widget build(BuildContext context) => Column(
    crossAxisAlignment: CrossAxisAlignment.stretch,
    children: [
      Row(
        children: [
          const Expanded(
            child: Text(
              'Linked records',
              style: TextStyle(fontWeight: FontWeight.w700),
            ),
          ),
          TextButton.icon(
            onPressed: _busy ? null : _openPicker,
            icon: const Icon(Icons.add_link),
            label: const Text('Link record'),
          ),
        ],
      ),
      for (final link in widget.snapshot.recordLinks) _linkRow(link),
      if (widget.snapshot.recordLinks.isEmpty && !_pickerOpen)
        const Text('No linked records yet.'),
      if (_pickerOpen) _picker(),
      if (_notice case final notice?) ...[
        const SizedBox(height: 8),
        Text(
          notice,
          style: TextStyle(color: Theme.of(context).colorScheme.error),
        ),
      ],
    ],
  );

  Widget _linkRow(TaskRecordLink link) {
    if (!link.available) {
      return ListTile(
        contentPadding: EdgeInsets.zero,
        leading: const Icon(Icons.link_off_outlined),
        title: Text('${link.typeLabel} unavailable'),
      );
    }
    final route = link.route;
    return ListTile(
      contentPadding: EdgeInsets.zero,
      leading: const Icon(Icons.link_outlined),
      title: Text(link.label!),
      subtitle: link.subtitle == null ? null : Text(link.subtitle!),
      onTap: route == null ? null : () => widget.onOpenRoute(route),
      trailing: IconButton(
        tooltip: 'Unlink ${link.typeLabel}',
        icon: const Icon(Icons.link_off_outlined),
        onPressed: _busy ? null : () => _unlink(link),
      ),
    );
  }

  Widget _picker() => Column(
    crossAxisAlignment: CrossAxisAlignment.stretch,
    children: [
      const SizedBox(height: 8),
      DropdownButtonFormField<String>(
        initialValue: _selectedType,
        decoration: const InputDecoration(labelText: 'Record type'),
        items: _types
            .map(
              (type) =>
                  DropdownMenuItem(value: type.key, child: Text(type.label)),
            )
            .toList(growable: false),
        onChanged: _busy
            ? null
            : (type) {
                setState(() {
                  _selectedType = type;
                  _matches = const [];
                });
                _searchFor(_search.text);
              },
      ),
      const SizedBox(height: 8),
      TextField(
        controller: _search,
        enabled: !_busy && _selectedType != null,
        onChanged: _searchFor,
        decoration: const InputDecoration(
          labelText: 'Search authorized records',
        ),
      ),
      for (final candidate in _matches)
        ListTile(
          contentPadding: EdgeInsets.zero,
          title: Text(candidate.label),
          subtitle: candidate.subtitle == null
              ? null
              : Text(candidate.subtitle!),
          onTap: _busy ? null : () => _link(candidate),
        ),
    ],
  );
}
