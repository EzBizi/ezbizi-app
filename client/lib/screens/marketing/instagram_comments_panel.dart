/// Comments on the Instagram account linked to a connected Facebook Page:
/// read them, answer as the business, hide one (2026-09-28).
///
/// Read live from Instagram each time the panel opens; nothing is stored, so
/// a comment deleted on Instagram is gone here too.
library;

import 'package:flutter/material.dart';

import '../../core/api_client.dart';

class InstagramCommentsPanel extends StatefulWidget {
  const InstagramCommentsPanel({
    super.key,
    required this.api,
    required this.connectionId,
    this.canAnswer = false,
  });

  final ApiClient api;
  final String connectionId;

  /// `marketing.edit`: reply and hide. Without it the comments are read-only,
  /// and the server refuses the same.
  final bool canAnswer;

  @override
  State<InstagramCommentsPanel> createState() => _InstagramCommentsPanelState();
}

class _InstagramCommentsPanelState extends State<InstagramCommentsPanel> {
  Map<String, dynamic>? _data;
  String? _error;

  /// One reply box open at a time, keyed by comment id.
  String? _replyingTo;
  final _reply = TextEditingController();
  bool _busy = false;

  String get _base =>
      '/social/connections/${widget.connectionId}/instagram-comments';

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
    final response = await widget.api.get(_base);
    if (!mounted) return;
    setState(() {
      _data = response.ok ? response.data as Map<String, dynamic> : null;
      _error = response.ok ? null : response.reason;
    });
  }

  Future<void> _send(String commentId) async {
    final text = _reply.text.trim();
    if (text.isEmpty) return;
    setState(() => _busy = true);
    final response = await widget.api.post('$_base/$commentId/replies', {
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

  Future<void> _hide(String commentId, bool hide) async {
    setState(() => _busy = true);
    final response = await widget.api.post('$_base/$commentId/hide', {
      'hide': hide,
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
    final data = _data;

    if (data == null) {
      return _error != null
          ? Text(_error!)
          : const Center(child: CircularProgressIndicator());
    }

    final account = data['account'] as Map<String, dynamic>?;
    if (account == null) {
      return const Text(
        'This Page has no Instagram professional account linked. Link one in '
        'the Page’s settings on Facebook, then open this again.',
      );
    }

    final posts = (data['posts'] as List<dynamic>).cast<Map<String, dynamic>>();

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Text('@${account['username']}', style: theme.textTheme.titleSmall),
        if (_error != null)
          Padding(
            padding: const EdgeInsets.only(top: 8),
            child: Text(
              _error!,
              style: TextStyle(color: theme.colorScheme.error),
            ),
          ),
        if (posts.every((p) => (p['comments'] as List).isEmpty))
          const Padding(
            padding: EdgeInsets.only(top: 12),
            child: Text('No comments on your recent posts.'),
          ),
        for (final post in posts)
          if ((post['comments'] as List).isNotEmpty) ...[
            const Divider(height: 24),
            Text(
              (post['caption'] as String?)?.trim().isNotEmpty == true
                  ? post['caption'] as String
                  : 'A post',
              maxLines: 2,
              overflow: TextOverflow.ellipsis,
              style: theme.textTheme.bodySmall,
            ),
            for (final comment
                in (post['comments'] as List).cast<Map<String, dynamic>>())
              _comment(theme, comment),
          ],
      ],
    );
  }

  Widget _comment(ThemeData theme, Map<String, dynamic> comment) {
    final id = comment['id'] as String;
    final hidden = comment['hidden'] as bool? ?? false;
    final replies = (comment['replies'] as List).cast<Map<String, dynamic>>();

    return Padding(
      padding: const EdgeInsets.only(top: 8),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            '@${comment['username']}${hidden ? ' · hidden' : ''}',
            style: theme.textTheme.labelMedium,
          ),
          Text(comment['text'] as String),
          for (final r in replies)
            Padding(
              padding: const EdgeInsets.only(left: 16, top: 4),
              child: Text(
                '@${r['username']}: ${r['text']}',
                style: theme.textTheme.bodySmall,
              ),
            ),
          if (_replyingTo == id)
            Padding(
              padding: const EdgeInsets.only(top: 4),
              child: Row(
                children: [
                  Expanded(
                    child: TextField(
                      controller: _reply,
                      autofocus: true,
                      decoration: const InputDecoration(
                        hintText: 'Reply as the business',
                      ),
                      onSubmitted: (_) => _send(id),
                    ),
                  ),
                  TextButton(
                    onPressed: _busy ? null : () => _send(id),
                    child: const Text('Send'),
                  ),
                ],
              ),
            ),
          if (widget.canAnswer)
            Row(
              children: [
                TextButton(
                  onPressed: _busy
                      ? null
                      : () => setState(() {
                          _replyingTo = _replyingTo == id ? null : id;
                          _reply.clear();
                        }),
                  child: const Text('Reply'),
                ),
                TextButton(
                  onPressed: _busy ? null : () => _hide(id, !hidden),
                  child: Text(hidden ? 'Show' : 'Hide'),
                ),
              ],
            ),
        ],
      ),
    );
  }
}
