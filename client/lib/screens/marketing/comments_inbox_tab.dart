/// Marketing › Social Media Management › Comments: one inbox of the comments
/// on every connected account that has them, newest first (Jose,
/// 2026-11-16: "we should have a tab for comments, so we can see them and
/// reply to them, right now u can only do that on the sidebar").
///
/// Read through GET social/comments, which merges every network the server
/// has a comment source for (Facebook Page posts, Instagram through a Page,
/// YouTube videos, X replies) and scopes each account by view and reach. A
/// network that fails is named in that answer's `sources`, and the rest show.
///
/// Each row names the actions it supports; only those are drawn. An action
/// the network or its granted permissions cannot do shows a short muted note
/// instead (e.g. "X needs moderation permission to hide replies").
library;

import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/theme.dart';
import '../../widgets/search_picker.dart';
import '../../widgets/social_network_mark.dart';
import '../../widgets/status_pill.dart';

/// One account the inbox reads: its connection id and the name to show.
typedef CommentSource = ({String id, String name, String provider});

/// One comment, with the post it is on and what can be done to it.
class InboxComment {
  const InboxComment({
    required this.connectionId,
    required this.network,
    required this.account,
    required this.id,
    required this.text,
    required this.username,
    required this.hidden,
    required this.replies,
    this.actions = const [],
    this.unavailable = const {},
    this.at,
    this.postCaption,
    this.postLink,
  });

  final String connectionId;

  /// facebook, instagram, youtube or x.
  final String network;
  final String account;
  final String id;
  final String text;
  final String username;
  final bool hidden;
  final List<Map<String, dynamic>> replies;

  /// What the server says this comment supports: reply, hide, delete.
  final List<String> actions;

  /// Why an action is not offered, by action.
  final Map<String, String> unavailable;
  final DateTime? at;
  final String? postCaption;
  final String? postLink;

  bool get unreplied => replies.isEmpty && !hidden;

  bool can(String action) => actions.contains(action);
}

class CommentsInbox extends StatefulWidget {
  const CommentsInbox({
    super.key,
    required this.api,
    required this.sources,
    this.canAnswer = false,
    this.onUnreplied,
  });

  final ApiClient api;
  final List<CommentSource> sources;

  /// `marketing.edit`: Reply, Hide and Delete. The routes refuse the same.
  final bool canAnswer;

  /// The unreplied count, for the tab's badge, each time it is read.
  final ValueChanged<int>? onUnreplied;

  /// The accounts with a comment source on the server: a Facebook Page (its
  /// posts and its linked Instagram account), a YouTube channel, X.
  static bool hasComments(String provider) =>
      const {'facebook_page', 'youtube', 'x'}.contains(provider);

  static const _networkNames = {
    'facebook': 'Facebook',
    'instagram': 'Instagram',
    'youtube': 'YouTube',
    'x': 'X',
  };

  /// Every network's comments, newest first. A network that fails is named
  /// in [errors] and the rest still show.
  static Future<({List<InboxComment> comments, List<String> errors})> load(
    ApiClient api,
    List<CommentSource> sources,
  ) async {
    final comments = <InboxComment>[];
    final errors = <String>[];
    if (sources.isEmpty) return (comments: comments, errors: errors);

    final response = await api.get('/social/comments');
    if (!response.ok) {
      return (comments: comments, errors: ['Comments: ${response.reason}']);
    }
    final body = response.body;

    for (final s
        in ((body['sources'] as List<dynamic>?) ?? const [])
            .whereType<Map<String, dynamic>>()) {
      final error = s['error'] as String?;
      if (error == null || error.isEmpty) continue;
      final network = _networkNames[s['network']] ?? '${s['network']}';
      errors.add('${s['account']} ($network): $error');
    }

    for (final c
        in ((body['data'] as List<dynamic>?) ?? const [])
            .whereType<Map<String, dynamic>>()) {
      final post = c['post'] is Map<String, dynamic>
          ? c['post'] as Map<String, dynamic>
          : const <String, dynamic>{};
      final unavailable = c['unavailable'] is Map
          ? {
              for (final e in (c['unavailable'] as Map).entries)
                '${e.key}': '${e.value}',
            }
          : const <String, String>{};
      comments.add(
        InboxComment(
          connectionId: '${c['connection_id']}',
          network: c['network'] as String? ?? '',
          account: c['account'] as String? ?? '',
          id: '${c['id']}',
          text: c['text'] as String? ?? '',
          username: c['author'] as String? ?? '',
          hidden: c['hidden'] as bool? ?? false,
          replies: ((c['replies'] as List<dynamic>?) ?? const [])
              .whereType<Map<String, dynamic>>()
              .toList(),
          actions: ((c['actions'] as List<dynamic>?) ?? const [])
              .map((a) => '$a')
              .toList(),
          unavailable: unavailable,
          at: DateTime.tryParse(c['timestamp'] as String? ?? ''),
          postCaption: post['text'] as String?,
          postLink: post['permalink'] as String?,
        ),
      );
    }
    comments.sort(
      (a, b) => (b.at ?? DateTime(0)).compareTo(a.at ?? DateTime(0)),
    );
    return (comments: comments, errors: errors);
  }

  @override
  State<CommentsInbox> createState() => _CommentsInboxState();
}

class _CommentsInboxState extends State<CommentsInbox> {
  List<InboxComment>? _comments;
  List<String> _errors = const [];
  String? _account;
  bool _unrepliedOnly = false;
  bool _hiddenOnly = false;

  /// One reply box open at a time, keyed by comment id.
  String? _replyingTo;
  final _reply = TextEditingController();
  bool _busy = false;
  String? _error;

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
    final read = await CommentsInbox.load(widget.api, widget.sources);
    if (!mounted) return;
    setState(() {
      _comments = read.comments;
      _errors = read.errors;
    });
    widget.onUnreplied?.call(read.comments.where((c) => c.unreplied).length);
  }

  Future<void> _after(ApiResponse response) async {
    if (!mounted) return;
    setState(() {
      _busy = false;
      _error = response.ok ? null : response.reason;
      if (response.ok) {
        _replyingTo = null;
        _reply.clear();
      }
    });
    if (response.ok) await _load();
  }

  Future<void> _send(InboxComment c) async {
    final text = _reply.text.trim();
    if (text.isEmpty) return;
    setState(() => _busy = true);
    await _after(
      await widget.api.post(
        '/social/connections/${c.connectionId}/comments/${c.network}/${c.id}/replies',
        {'message': text},
      ),
    );
  }

  Future<void> _hide(InboxComment c) async {
    setState(() => _busy = true);
    await _after(
      await widget.api.post(
        '/social/connections/${c.connectionId}/comments/${c.network}/${c.id}/hide',
        {'hide': !c.hidden},
      ),
    );
  }

  Future<void> _delete(InboxComment c) async {
    setState(() => _busy = true);
    await _after(
      await widget.api.delete(
        '/social/connections/${c.connectionId}/comments/${c.network}/${c.id}',
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    if (widget.sources.isEmpty) {
      return const Center(
        child: Padding(
          padding: EdgeInsets.all(24),
          child: Text(
            'No connected account has comments to read here yet. Connect a '
            'Facebook Page, a YouTube channel or an X account in Accounts.',
            textAlign: TextAlign.center,
          ),
        ),
      );
    }

    final all = _comments;
    if (all == null) return const Center(child: CircularProgressIndicator());

    final shown = [
      for (final c in all)
        if ((_account == null || c.connectionId == _account) &&
            (!_unrepliedOnly || c.unreplied) &&
            (!_hiddenOnly || c.hidden))
          c,
    ];

    return ListView(
      key: const Key('social-comments-inbox'),
      padding: const EdgeInsets.all(16),
      children: [
        Wrap(
          spacing: 8,
          runSpacing: 8,
          crossAxisAlignment: WrapCrossAlignment.center,
          children: [
            if (widget.sources.length > 1)
              SizedBox(
                width: 260,
                child: SearchPickerField<String?>(
                  key: const Key('social-comments-account'),
                  value: _account,
                  label: 'Account',
                  allowClear: true,
                  options: [
                    for (final s in widget.sources)
                      SearchOption(value: s.id, label: s.name),
                  ],
                  onChanged: (v) => setState(() => _account = v),
                ),
              ),
            FilterChip(
              key: const Key('social-comments-unreplied'),
              label: const Text('Unreplied'),
              selected: _unrepliedOnly,
              onSelected: (v) => setState(() => _unrepliedOnly = v),
            ),
            FilterChip(
              key: const Key('social-comments-hidden'),
              label: const Text('Hidden'),
              selected: _hiddenOnly,
              onSelected: (v) => setState(() => _hiddenOnly = v),
            ),
          ],
        ),
        for (final e in [..._errors, ?_error])
          Padding(
            padding: const EdgeInsets.only(top: 8),
            child: Text(
              e,
              style: theme.textTheme.bodySmall?.copyWith(
                color: theme.colorScheme.error,
              ),
            ),
          ),
        if (shown.isEmpty)
          Padding(
            padding: const EdgeInsets.only(top: 24),
            child: Text(
              all.isEmpty
                  ? 'No comments on your recent posts.'
                  : 'Nothing matches.',
              textAlign: TextAlign.center,
            ),
          ),
        for (final c in shown) _row(theme, c),
      ],
    );
  }

  Widget _row(ThemeData theme, InboxComment c) {
    final caption = c.postCaption?.trim() ?? '';
    final muted = theme.textTheme.bodySmall?.copyWith(
      color: theme.colorScheme.onSurfaceVariant,
    );
    // Why Reply or Hide is missing, once per distinct reason.
    final notes = {
      for (final a in const ['reply', 'hide'])
        if (!c.can(a) && c.unavailable[a] != null) c.unavailable[a]!,
    };

    return Container(
      key: Key('social-comment-${c.id}'),
      margin: const EdgeInsets.only(top: 12),
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: theme.colorScheme.surfaceContainerLowest,
        borderRadius: BorderRadius.circular(10),
        border: Border.all(color: theme.colorScheme.outlineVariant),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              SocialNetworkMark(network: c.network, size: 18),
              const SizedBox(width: 6),
              Expanded(
                child: Text(
                  '@${c.username} · ${c.account}',
                  overflow: TextOverflow.ellipsis,
                  style: theme.textTheme.labelMedium,
                ),
              ),
              if (c.hidden)
                const StatusPill('Hidden', dense: true)
              else if (c.unreplied)
                const StatusPill('Unreplied', tone: Tone.info, dense: true),
            ],
          ),
          const SizedBox(height: 4),
          Text(c.text),
          const SizedBox(height: 4),
          Text(
            'On: ${caption.isEmpty ? 'a post' : caption}',
            maxLines: 2,
            overflow: TextOverflow.ellipsis,
            style: muted,
          ),
          for (final r in c.replies)
            Padding(
              padding: const EdgeInsets.only(left: 16, top: 4),
              child: Text(
                '@${r['author'] ?? r['username']}: ${r['text']}',
                style: theme.textTheme.bodySmall,
              ),
            ),
          if (_replyingTo == c.id)
            Padding(
              padding: const EdgeInsets.only(top: 4),
              child: Row(
                children: [
                  Expanded(
                    child: TextField(
                      key: Key('social-comment-reply-${c.id}'),
                      controller: _reply,
                      autofocus: true,
                      decoration: const InputDecoration(
                        hintText: 'Reply as the business',
                      ),
                      onSubmitted: (_) => _send(c),
                    ),
                  ),
                  TextButton(
                    key: Key('social-comment-send-${c.id}'),
                    onPressed: _busy ? null : () => _send(c),
                    child: const Text('Send'),
                  ),
                ],
              ),
            ),
          if (widget.canAnswer) ...[
            Row(
              children: [
                if (c.can('reply'))
                  TextButton(
                    key: Key('social-comment-replybtn-${c.id}'),
                    onPressed: _busy
                        ? null
                        : () => setState(() {
                            _replyingTo = _replyingTo == c.id ? null : c.id;
                            _reply.clear();
                          }),
                    child: const Text('Reply'),
                  ),
                if (c.can('hide'))
                  TextButton(
                    key: Key('social-comment-hide-${c.id}'),
                    onPressed: _busy ? null : () => _hide(c),
                    child: Text(c.hidden ? 'Show' : 'Hide'),
                  ),
                if (c.can('delete'))
                  TextButton(
                    key: Key('social-comment-delete-${c.id}'),
                    onPressed: _busy ? null : () => _delete(c),
                    child: const Text('Delete'),
                  ),
              ],
            ),
            for (final n in notes)
              Text(n, key: Key('social-comment-note-${c.id}'), style: muted),
          ],
        ],
      ),
    );
  }
}
