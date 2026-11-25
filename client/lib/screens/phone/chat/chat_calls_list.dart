import 'dart:async';

import 'package:flutter/material.dart';

import '../../../core/api_client.dart';
import '../../../core/timestamps.dart';
import '../../../widgets/list_search.dart';
import 'chat_calls.dart';

/// EzChat's Calls tab (design 213 B1): my recent calls across chats, newest
/// first (GET chat/calls, Chat b21ee5a59). A tap calls back, or joins the
/// call when it is still going.
class ChatCallsList extends StatefulWidget {
  const ChatCallsList({super.key, required this.api});

  final ApiClient api;

  @override
  State<ChatCallsList> createState() => _ChatCallsListState();
}

class _ChatCallsListState extends State<ChatCallsList> {
  List<Map<String, dynamic>>? _calls;
  String? _error;
  String _query = '';

  @override
  void initState() {
    super.initState();
    unawaited(_load());
  }

  Future<void> _load() async {
    final r = await widget.api.get('/chat/calls');
    if (!mounted) return;
    setState(() {
      if (r.ok) {
        _calls = [
          for (final c in (r.data as List? ?? const []))
            (c as Map).cast<String, dynamic>(),
        ];
        _error = null;
      } else {
        _error = r.reason;
      }
    });
  }

  void _again(Map<String, dynamic> c) {
    final meta = (c['meta'] as Map?) ?? const {};
    final chat = (c['chat'] as Map?) ?? const {};
    final video = meta['video'] == true;
    final title = '${chat['name'] ?? ''}';
    if (['ringing', 'ongoing'].contains(meta['status'])) {
      unawaited(
        ChatCalls.instance.join('${c['id']}', video: video, title: title),
      );
    } else if (chat['id'] != null) {
      unawaited(
        ChatCalls.instance.call('${chat['id']}', video: video, title: title),
      );
    }
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final muted = theme.textTheme.bodySmall;
    final calls = _calls;
    final q = _query.toLowerCase();
    final shown = [
      for (final c in calls ?? const <Map<String, dynamic>>[])
        if (q.isEmpty ||
            '${c['chat_name'] ?? (c['chat'] as Map?)?['name'] ?? ''}'
                .toLowerCase()
                .contains(q))
          c,
    ];
    return Column(
      key: const ValueKey('ezchat-calls'),
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(16, 12, 16, 4),
          child: Text('Calls', style: theme.textTheme.headlineSmall),
        ),
        Padding(
          padding: const EdgeInsets.fromLTRB(12, 4, 12, 4),
          child: ListSearch(
            key: const ValueKey('ezchat-calls-search'),
            hint: 'Search calls',
            width: double.infinity,
            onChanged: (v) => setState(() => _query = v.trim()),
            onClear: () => setState(() => _query = ''),
          ),
        ),
        Expanded(
          child: _error != null
              ? Center(child: Text(_error!))
              : calls == null
              ? const Center(child: CircularProgressIndicator())
              : RefreshIndicator(
                  onRefresh: _load,
                  child: shown.isEmpty
                      ? ListView(
                          children: [
                            Padding(
                              padding: const EdgeInsets.all(24),
                              child: Text(
                                calls.isEmpty
                                    ? 'No calls yet. Call from any chat with '
                                          'the phone or camera at its top.'
                                    : 'No call matches "$_query".',
                                textAlign: TextAlign.center,
                                style: muted,
                              ),
                            ),
                          ],
                        )
                      : ListView.builder(
                          itemCount: shown.length,
                          itemBuilder: (context, i) {
                            final c = shown[i];
                            final meta = (c['meta'] as Map?) ?? const {};
                            final chat = (c['chat'] as Map?) ?? const {};
                            final name =
                                '${c['chat_name'] ?? chat['name'] ?? ''}';
                            final mine =
                                c['direction'] == 'out' ||
                                c['direction'] == null && c['mine'] == true;
                            final missed =
                                c['missed'] == true ||
                                !mine &&
                                    [
                                      'missed',
                                      'declined',
                                    ].contains(meta['status']);
                            final live = [
                              'ringing',
                              'ongoing',
                            ].contains(meta['status']);
                            final video =
                                c['video'] == true || meta['video'] == true;
                            final seconds = c['duration_s'] ?? meta['duration'];
                            final when = DateTime.tryParse(
                              '${c['started_at'] ?? c['sent_at'] ?? ''}',
                            )?.toLocal();
                            return ListTile(
                              key: ValueKey('ezchat-call-${c['id']}'),
                              leading: CircleAvatar(
                                backgroundColor:
                                    theme.colorScheme.primaryContainer,
                                foregroundColor:
                                    theme.colorScheme.onPrimaryContainer,
                                child: Text(
                                  name.isEmpty ? '?' : name[0].toUpperCase(),
                                ),
                              ),
                              title: Text(
                                name,
                                maxLines: 1,
                                overflow: TextOverflow.ellipsis,
                                style: missed
                                    ? TextStyle(color: theme.colorScheme.error)
                                    : null,
                              ),
                              subtitle: Row(
                                children: [
                                  Icon(
                                    mine
                                        ? Icons.call_made
                                        : Icons.call_received,
                                    size: 14,
                                    color: missed
                                        ? theme.colorScheme.error
                                        : theme.colorScheme.primary,
                                  ),
                                  const SizedBox(width: 4),
                                  Flexible(
                                    child: Text(
                                      [
                                        if (live)
                                          'Going on now'
                                        else if (missed)
                                          'Missed',
                                        if (when != null)
                                          formatCalendarDay(when) ==
                                                  formatCalendarDay(
                                                    DateTime.now(),
                                                  )
                                              ? 'Today ${TimeOfDay.fromDateTime(when).format(context)}'
                                              : formatCalendarDay(when),
                                        if (seconds is num && seconds > 0)
                                          formatClock(
                                            Duration(seconds: seconds.round()),
                                          ),
                                      ].join(' · '),
                                      maxLines: 1,
                                      overflow: TextOverflow.ellipsis,
                                      style: muted,
                                    ),
                                  ),
                                ],
                              ),
                              trailing: IconButton(
                                key: ValueKey('ezchat-call-again-${c['id']}'),
                                tooltip: live
                                    ? 'Join'
                                    : video
                                    ? 'Video call'
                                    : 'Call',
                                icon: Icon(
                                  video
                                      ? Icons.videocam_outlined
                                      : Icons.call_outlined,
                                  color: theme.colorScheme.primary,
                                ),
                                onPressed: () => _again(c),
                              ),
                            );
                          },
                        ),
                ),
        ),
      ],
    );
  }
}
