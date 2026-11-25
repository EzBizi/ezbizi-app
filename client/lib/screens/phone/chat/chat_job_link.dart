import 'dart:async';

import 'package:flutter/material.dart';

import '../../../core/api_client.dart';
import '../../../core/soft_keyboard.dart';
import '../../../widgets/list_search.dart';
import '../../../widgets/side_panel.dart';
import 'chat_store.dart';

/// Tie a chat to a job, or untie it (design 213 B1; Chat 9820ac23a): a side
/// panel that searches GET jobs?search= (already narrowed to the person's
/// view) and answers the picked job, or `null` for "No job". Closing it
/// answers nothing and changes nothing.
Future<void> linkChatToJob(
  BuildContext context, {
  required ApiClient api,
  required String chatId,
  required Map? current,
  required Future<void> Function() reload,
}) async {
  final picked = await showSidePanel<_Pick>(
    context,
    builder: (panel) => ChatPanel(
      title: const Text('Link to job'),
      content: _JobPicker(api: api, current: current),
    ),
  );
  if (picked == null) return;
  final r = await api.patch('/chat/chats/$chatId/job', {
    'job_id': picked.jobId,
  });
  if (!r.ok && context.mounted) {
    ScaffoldMessenger.of(
      context,
    ).showSnackBar(SnackBar(content: Text(r.reason)));
  }
  await reload();
}

class _Pick {
  const _Pick(this.jobId);
  final String? jobId;
}

class _JobPicker extends StatefulWidget {
  const _JobPicker({required this.api, required this.current});

  final ApiClient api;
  final Map? current;

  @override
  State<_JobPicker> createState() => _JobPickerState();
}

class _JobPickerState extends State<_JobPicker> {
  List<Map<String, dynamic>> _jobs = const [];
  bool _loading = false;
  Timer? _wait;

  @override
  void initState() {
    super.initState();
    unawaited(_search(''));
  }

  @override
  void dispose() {
    _wait?.cancel();
    super.dispose();
  }

  Future<void> _search(String q) async {
    setState(() => _loading = true);
    final r = await widget.api.get(
      '/jobs?per_page=20${q.trim().isEmpty ? '' : '&search=${Uri.encodeQueryComponent(q.trim())}'}',
    );
    if (!mounted) return;
    setState(() {
      _loading = false;
      _jobs = r.ok
          ? [
              for (final j in (r.data as List? ?? const []))
                (j as Map).cast<String, dynamic>(),
            ]
          : const [];
    });
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final muted = theme.textTheme.bodySmall;
    final current = widget.current;
    return Column(
      key: const ValueKey('chat-job-picker'),
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        ListSearch(
          key: const ValueKey('chat-job-search'),
          hint: 'Job number, title or customer',
          width: double.infinity,
          autofocus: autofocusOnOpen,
          onChanged: (v) {
            _wait?.cancel();
            _wait = Timer(
              const Duration(milliseconds: 300),
              () => unawaited(_search(v)),
            );
          },
          onClear: () => unawaited(_search('')),
        ),
        const SizedBox(height: 8),
        if (current != null)
          ListTile(
            key: const ValueKey('chat-job-unlink'),
            leading: const Icon(Icons.link_off),
            title: Text('No job (now ${current['number'] ?? ''})'),
            onTap: () => Navigator.of(context).pop(const _Pick(null)),
          ),
        if (_loading)
          const Padding(
            padding: EdgeInsets.all(16),
            child: Center(child: CircularProgressIndicator()),
          )
        else if (_jobs.isEmpty)
          Padding(
            padding: const EdgeInsets.all(16),
            child: Text('No job matches.', style: muted),
          )
        else
          for (final j in _jobs)
            ListTile(
              key: ValueKey('chat-job-${j['id']}'),
              selected: current?['id'] == j['id'],
              title: Text(
                [
                  '${j['number'] ?? ''}',
                  '${(j['customer'] as Map?)?['name'] ?? ''}',
                ].where((s) => s.isNotEmpty).join(' · '),
              ),
              subtitle: Text(
                [
                  '${j['title'] ?? ''}',
                  '${j['status_label'] ?? ''}',
                ].where((s) => s.isNotEmpty).join(' · '),
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
              ),
              onTap: () => Navigator.of(context).pop(_Pick('${j['id']}')),
            ),
      ],
    );
  }
}
