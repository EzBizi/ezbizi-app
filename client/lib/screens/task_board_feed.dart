import 'package:flutter/material.dart';

import '../core/api_client.dart';
import '../core/deep_links.dart';
import '../core/timestamps.dart';
import '../widgets/side_panel.dart';

/// Monday's update feed (gap review P1, 11-24): the updates on every board
/// this person sees, newest first. All or Mentions, unread only, Mark all
/// as read, a board filter with counts, and Like and Reply on each one.
class UpdatesFeedPanel extends StatefulWidget {
  const UpdatesFeedPanel({
    super.key,
    required this.api,
    required this.formats,
    this.onOpenTask,
  });

  final ApiClient api;
  final DisplayFormats formats;

  /// Opens a task by id; null opens it through its link.
  final void Function(String taskId)? onOpenTask;

  @override
  State<UpdatesFeedPanel> createState() => _UpdatesFeedPanelState();
}

class _UpdatesFeedPanelState extends State<UpdatesFeedPanel> {
  String _filter = 'all';
  bool _unread = false;
  String? _board;
  List<Map<String, dynamic>>? _rows;
  List<Map<String, dynamic>> _boards = const [];
  String? _error;
  String? _replyTo;
  final _reply = TextEditingController();

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _reply.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    setState(() => _rows = null);
    final res = await widget.api.get('/task-updates', {
      'filter': _filter,
      if (_unread) 'unread': '1',
      'board_id': ?_board,
    });
    if (!mounted) return;
    setState(() {
      _error = res.ok ? null : res.reason;
      _rows = res.ok
          ? [
              for (final r in (res.body['data'] as List).whereType<Map>())
                r.cast<String, dynamic>(),
            ]
          : const [];
      _boards = res.ok
          ? [
              for (final b
                  in ((res.body['meta'] as Map?)?['boards'] as List? ??
                          const [])
                      .whereType<Map>())
                b.cast<String, dynamic>(),
            ]
          : const [];
    });
  }

  Future<void> _readAll() async {
    final res = await widget.api.post('/task-updates/read-all', const {});
    if (!mounted) return;
    if (!res.ok) return setState(() => _error = res.reason);
    await _load();
  }

  Future<void> _like(Map<String, dynamic> u) async {
    final res = await widget.api.post(
      '/tasks/${u['task_id']}/updates/${u['id']}/like',
      const {},
    );
    if (!mounted || !res.ok) return;
    final d = res.body['data'] as Map;
    setState(() {
      u['likes'] = d['likes'];
      u['liked'] = d['liked'];
    });
  }

  Future<void> _send(Map<String, dynamic> u) async {
    final body = _reply.text.trim();
    if (body.isEmpty) return;
    final res = await widget.api.post('/tasks/${u['task_id']}/updates', {
      'body': body,
      'parent_id': u['parent_id'] ?? u['id'],
    });
    if (!mounted) return;
    if (!res.ok) return setState(() => _error = res.reason);
    _reply.clear();
    setState(() => _replyTo = null);
    await _load();
  }

  void _open(Map<String, dynamic> u) {
    final id = '${u['task_id']}';
    if (widget.onOpenTask != null) return widget.onOpenTask!(id);
    DeepLinks.open(
      DeepLink(module: 'tasks', type: 'task', id: id, update: '${u['id']}'),
    );
    DeepLinks.openModule('tasks');
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final muted = theme.textTheme.bodySmall?.copyWith(
      color: theme.colorScheme.onSurfaceVariant,
    );
    return SidePanel(
      title: 'Updates feed',
      subtitle: 'Every board you can see, newest first.',
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Wrap(
            spacing: 6,
            runSpacing: 6,
            crossAxisAlignment: WrapCrossAlignment.center,
            children: [
              for (final (key, label) in const [
                ('all', 'All updates'),
                ('mentions', 'Mentions'),
              ])
                ChoiceChip(
                  key: ValueKey('feed-$key'),
                  label: Text(label),
                  selected: _filter == key,
                  onSelected: (_) {
                    setState(() => _filter = key);
                    _load();
                  },
                ),
              FilterChip(
                key: const Key('feed-unread'),
                label: const Text('Unread only'),
                selected: _unread,
                onSelected: (v) {
                  setState(() => _unread = v);
                  _load();
                },
              ),
              TextButton(
                key: const Key('feed-read-all'),
                onPressed: _readAll,
                child: const Text('Mark all as read'),
              ),
            ],
          ),
          if (_boards.length > 1) ...[
            const SizedBox(height: 6),
            Wrap(
              spacing: 6,
              runSpacing: 6,
              children: [
                ChoiceChip(
                  key: const Key('feed-board-all'),
                  label: const Text('All boards'),
                  selected: _board == null,
                  onSelected: (_) {
                    setState(() => _board = null);
                    _load();
                  },
                ),
                for (final b in _boards)
                  ChoiceChip(
                    key: ValueKey('feed-board-${b['id']}'),
                    label: Text('${b['name']} · ${b['count']}'),
                    selected: _board == b['id'],
                    onSelected: (_) {
                      setState(() => _board = '${b['id']}');
                      _load();
                    },
                  ),
              ],
            ),
          ],
          const SizedBox(height: 10),
          if (_error != null)
            Text(_error!, style: TextStyle(color: theme.colorScheme.error)),
          if (_rows == null)
            const LinearProgressIndicator()
          else if (_rows!.isEmpty)
            Text('Nothing here.', style: muted)
          else
            for (final u in _rows!)
              Card(
                key: ValueKey('feed-update-${u['id']}'),
                margin: const EdgeInsets.only(bottom: 10),
                child: Padding(
                  padding: const EdgeInsets.all(10),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      InkWell(
                        key: ValueKey('feed-open-${u['id']}'),
                        onTap: () => _open(u),
                        child: Text(
                          '${u['task']} · ${u['board']}',
                          style: TextStyle(
                            color: theme.colorScheme.primary,
                            fontWeight: FontWeight.w700,
                          ),
                        ),
                      ),
                      const SizedBox(height: 4),
                      Text.rich(
                        TextSpan(
                          children: [
                            TextSpan(
                              text:
                                  '${(u['author'] as Map?)?['name'] ?? 'Somebody'}  ',
                              style: const TextStyle(
                                fontWeight: FontWeight.w700,
                              ),
                            ),
                            TextSpan(
                              text: formatStamp(
                                u['created_at'],
                                '',
                                formats: widget.formats,
                              ),
                              style: muted,
                            ),
                          ],
                        ),
                      ),
                      const SizedBox(height: 4),
                      Text(
                        '${u['body'] ?? ''}',
                        maxLines: 6,
                        overflow: TextOverflow.ellipsis,
                      ),
                      Row(
                        children: [
                          TextButton.icon(
                            key: ValueKey('feed-like-${u['id']}'),
                            onPressed: () => _like(u),
                            icon: Icon(
                              u['liked'] == true
                                  ? Icons.thumb_up
                                  : Icons.thumb_up_outlined,
                              size: 16,
                            ),
                            label: Text(
                              (u['likes'] as num? ?? 0) > 0
                                  ? 'Like · ${u['likes']}'
                                  : 'Like',
                            ),
                          ),
                          TextButton.icon(
                            key: ValueKey('feed-reply-${u['id']}'),
                            onPressed: () => setState(() {
                              _replyTo = _replyTo == u['id']
                                  ? null
                                  : '${u['id']}';
                              _reply.clear();
                            }),
                            icon: const Icon(Icons.reply, size: 16),
                            label: const Text('Reply'),
                          ),
                        ],
                      ),
                      if (_replyTo == u['id'])
                        Row(
                          children: [
                            Expanded(
                              child: TextField(
                                key: ValueKey('feed-reply-box-${u['id']}'),
                                controller: _reply,
                                autofocus: true,
                                decoration: const InputDecoration(
                                  isDense: true,
                                  hintText: 'Write a reply',
                                  border: OutlineInputBorder(),
                                ),
                              ),
                            ),
                            const SizedBox(width: 8),
                            FilledButton(
                              key: ValueKey('feed-reply-send-${u['id']}'),
                              onPressed: () => _send(u),
                              child: const Text('Reply'),
                            ),
                          ],
                        ),
                    ],
                  ),
                ),
              ),
        ],
      ),
    );
  }
}
