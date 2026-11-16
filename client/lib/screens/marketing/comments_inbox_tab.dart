/// Marketing › Social Media Management › Comments: one inbox of the comments
/// on every connected account that has them, newest first (Jose,
/// 2026-11-16: "we should have a tab for comments, so we can see them and
/// reply to them, right now u can only do that on the sidebar").
///
/// Read through the existing per-account comment routes, one call per
/// account, so each answer is scoped by that route's own view and reach
/// check. Today that is Instagram, read through a connected Facebook Page:
/// it is the only network with a comments route. A network that gains one is
/// added to [CommentsInbox.sources].
library;

import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/theme.dart';
import '../../widgets/search_picker.dart';
import '../../widgets/social_network_mark.dart';
import '../../widgets/status_pill.dart';

/// One account the inbox reads: its connection id and the name to show.
typedef CommentSource = ({String id, String name, String provider});

/// One comment, with the post it is on.
class InboxComment {
  const InboxComment({
    required this.source,
    required this.id,
    required this.text,
    required this.username,
    required this.hidden,
    required this.replies,
    this.at,
    this.postCaption,
    this.postLink,
  });

  final CommentSource source;
  final String id;
  final String text;
  final String username;
  final bool hidden;
  final List<Map<String, dynamic>> replies;
  final DateTime? at;
  final String? postCaption;
  final String? postLink;

  bool get unreplied => replies.isEmpty && !hidden;
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

  /// `marketing.edit`: Reply and Hide. The routes refuse the same.
  final bool canAnswer;

  /// The unreplied count, for the tab's badge, each time it is read.
  final ValueChanged<int>? onUnreplied;

  /// The accounts that have a comments route: a Facebook Page, for its
  /// linked Instagram account.
  static bool hasComments(String provider) => provider == 'facebook_page';

  /// Every source's comments, newest first. A source that fails is named in
  /// [errors] and the rest still show.
  static Future<({List<InboxComment> comments, List<String> errors})> load(
    ApiClient api,
    List<CommentSource> sources,
  ) async {
    final comments = <InboxComment>[];
    final errors = <String>[];
    final answers = await Future.wait([
      for (final s in sources)
        api.get('/social/connections/${s.id}/instagram-comments'),
    ]);
    for (final (i, response) in answers.indexed) {
      final source = sources[i];
      if (!response.ok) {
        errors.add('${source.name}: ${response.reason}');
        continue;
      }
      final data = response.data;
      if (data is! Map<String, dynamic>) continue;
      for (final post
          in ((data['posts'] as List<dynamic>?) ?? const [])
              .whereType<Map<String, dynamic>>()) {
        for (final c
            in ((post['comments'] as List<dynamic>?) ?? const [])
                .whereType<Map<String, dynamic>>()) {
          comments.add(
            InboxComment(
              source: source,
              id: '${c['id']}',
              text: c['text'] as String? ?? '',
              username: c['username'] as String? ?? '',
              hidden: c['hidden'] as bool? ?? false,
              replies: ((c['replies'] as List<dynamic>?) ?? const [])
                  .whereType<Map<String, dynamic>>()
                  .toList(),
              at: DateTime.tryParse(c['timestamp'] as String? ?? ''),
              postCaption: post['caption'] as String?,
              postLink: post['permalink'] as String?,
            ),
          );
        }
      }
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

  String _base(InboxComment c) =>
      '/social/connections/${c.source.id}/instagram-comments';

  Future<void> _send(InboxComment c) async {
    final text = _reply.text.trim();
    if (text.isEmpty) return;
    setState(() => _busy = true);
    final response = await widget.api.post('${_base(c)}/${c.id}/replies', {
      'message': text,
    });
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

  Future<void> _hide(InboxComment c) async {
    setState(() => _busy = true);
    final response = await widget.api.post('${_base(c)}/${c.id}/hide', {
      'hide': !c.hidden,
    });
    if (!mounted) return;
    setState(() {
      _busy = false;
      _error = response.ok ? null : response.reason;
    });
    if (response.ok) await _load();
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
            'Facebook Page with a linked Instagram account in Accounts.',
            textAlign: TextAlign.center,
          ),
        ),
      );
    }

    final all = _comments;
    if (all == null) return const Center(child: CircularProgressIndicator());

    final shown = [
      for (final c in all)
        if ((_account == null || c.source.id == _account) &&
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
              const SocialNetworkMark(
                network: 'instagram_professional',
                size: 18,
              ),
              const SizedBox(width: 6),
              Expanded(
                child: Text(
                  '@${c.username} · ${c.source.name}',
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
            style: theme.textTheme.bodySmall?.copyWith(
              color: theme.colorScheme.onSurfaceVariant,
            ),
          ),
          for (final r in c.replies)
            Padding(
              padding: const EdgeInsets.only(left: 16, top: 4),
              child: Text(
                '@${r['username']}: ${r['text']}',
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
          if (widget.canAnswer)
            Row(
              children: [
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
                TextButton(
                  key: Key('social-comment-hide-${c.id}'),
                  onPressed: _busy ? null : () => _hide(c),
                  child: Text(c.hidden ? 'Show' : 'Hide'),
                ),
              ],
            ),
        ],
      ),
    );
  }
}
