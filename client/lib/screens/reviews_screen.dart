import 'package:flutter/material.dart';

import '../core/api_client.dart';
import '../core/session.dart';
import '../widgets/async_body.dart';
import '../widgets/connect_account.dart';

/// Reviews — what the public said, and the answer.
///
/// ── Unanswered is the filter that ships switched on ──────────
///
/// Somebody opening this tab is almost never browsing. They are looking for
/// the ones nobody has replied to, because an unanswered one-star sits at the
/// top of a Google listing being the first thing a stranger reads. The list
/// therefore opens on Unanswered rather than on everything.
///
/// ── Draft and Publish are two buttons for one reason ─────────
///
/// Draft writes text nobody outside the building can see. Publish puts it on
/// Google under the business's name, in public, permanently. They carry
/// different permissions and the screen keeps them visibly apart — a single
/// Save that sometimes published would be the worst possible affordance.
class ReviewsScreen extends StatefulWidget {
  const ReviewsScreen({super.key, required this.api, required this.session});

  final ApiClient api;
  final Session session;

  @override
  State<ReviewsScreen> createState() => _ReviewsScreenState();
}

class _ReviewsScreenState extends State<ReviewsScreen> {
  bool _loading = true;
  bool _syncing = false;
  String? _error;
  String? _notice;

  List<Map<String, dynamic>> _reviews = [];
  List<Map<String, dynamic>> _connections = [];
  int _total = 0;
  int _unanswered = 0;

  /// Opens on unanswered — see the class note.
  bool _onlyUnanswered = true;
  int? _rating;

  int _request = 0;
  String? _openId;
  final _draft = TextEditingController();
  bool _busy = false;

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _draft.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    final request = ++_request;

    setState(() {
      _loading = _reviews.isEmpty;
      _error = null;
    });

    final response = await widget.api.get('/reviews', {
      if (_onlyUnanswered) 'answered': 'no',
      if (_rating != null) 'rating': '$_rating',
    });

    if (!mounted || request != _request) return;

    setState(() {
      _loading = false;

      if (!response.ok) {
        _error = response.message;
        return;
      }

      _reviews = List<Map<String, dynamic>>.from(
        (response.data as List? ?? const []).cast<Map<String, dynamic>>(),
      );
      final meta = response.body['meta'] as Map<String, dynamic>? ?? const {};
      _total = (meta['total'] as num?)?.toInt() ?? _reviews.length;
      _unanswered = (meta['unanswered'] as num?)?.toInt() ?? 0;
      _connections = List<Map<String, dynamic>>.from(
        (meta['connections'] as List? ?? const []).cast<Map<String, dynamic>>(),
      );
    });
  }

  Future<void> _sync() async {
    setState(() {
      _syncing = true;
      _notice = null;
    });

    final response = await widget.api.post('/reviews/sync', const {});

    if (!mounted) return;
    setState(() => _syncing = false);

    if (!response.ok) {
      setState(() => _notice = response.message);
      return;
    }

    final data = response.data as Map<String, dynamic>? ?? const {};
    final created = (data['created'] as num?)?.toInt() ?? 0;

    setState(() => _notice = created > 0
        ? '$created new ${created == 1 ? 'review' : 'reviews'}.'
        : 'Nothing new.');

    await _load();
  }

  Future<void> _saveDraft(Map<String, dynamic> review) async {
    if (_draft.text.trim().isEmpty) return;

    setState(() => _busy = true);
    final response = await widget.api
        .put('/reviews/${review['id']}/reply', {'body': _draft.text.trim()});
    if (!mounted) return;
    setState(() => _busy = false);

    if (!response.ok) {
      setState(() => _notice = response.message);
      return;
    }

    setState(() => _notice = 'Draft saved. Nothing is public until you publish it.');
    await _load();
  }

  Future<void> _publish(Map<String, dynamic> review) async {
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('Publish this reply?'),
        // Says what actually happens, in the words that matter: public, under
        // the business's name, and not easily taken back.
        content: const Text(
          'It goes live on Google under your business name, where anybody '
          'reading the review will see it. Google allows one reply per review, '
          'so publishing again replaces it.',
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context, false),
            child: const Text('Cancel'),
          ),
          FilledButton(
            onPressed: () => Navigator.pop(context, true),
            child: const Text('Publish'),
          ),
        ],
      ),
    );

    if (confirmed != true || !mounted) return;

    setState(() => _busy = true);
    final response =
        await widget.api.post('/reviews/${review['id']}/reply/publish', const {});
    if (!mounted) return;
    setState(() => _busy = false);

    setState(() => _notice = response.ok
        ? 'Reply published.'
        : response.message);

    await _load();
  }

  bool get _connected => _connections.any((c) => c['status'] == 'connected');

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(16, 12, 16, 8),
          child: Row(
            children: [
              FilterChip(
                label: Text(_unanswered > 0
                    ? 'Unanswered ($_unanswered)'
                    : 'Unanswered'),
                selected: _onlyUnanswered,
                onSelected: (v) {
                  setState(() => _onlyUnanswered = v);
                  _load();
                },
              ),
              const SizedBox(width: 12),
              DropdownButton<int?>(
                value: _rating,
                underline: const SizedBox.shrink(),
                hint: const Text('Any rating'),
                items: [
                  const DropdownMenuItem(value: null, child: Text('Any rating')),
                  for (var s = 5; s >= 1; s--)
                    DropdownMenuItem(value: s, child: Text('$s star${s == 1 ? '' : 's'}')),
                ],
                onChanged: (v) {
                  setState(() => _rating = v);
                  _load();
                },
              ),
              const Spacer(),
              FilledButton.tonalIcon(
                onPressed: _syncing ? null : _sync,
                icon: _syncing
                    ? const SizedBox(
                        width: 16, height: 16,
                        child: CircularProgressIndicator(strokeWidth: 2))
                    : const Icon(Icons.sync, size: 18),
                label: Text(_syncing ? 'Fetching…' : 'Fetch new'),
              ),
            ],
          ),
        ),
        if (_notice != null)
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 0, 16, 8),
            child: Row(
              children: [
                Expanded(child: Text(_notice!, style: theme.textTheme.bodySmall)),
                IconButton(
                  icon: const Icon(Icons.close, size: 16),
                  onPressed: () => setState(() => _notice = null),
                ),
              ],
            ),
          ),
        const Divider(height: 1),
        Expanded(
          child: AsyncBody(
            loading: _loading,
            error: _error,
            onRetry: _load,
            child: _reviews.isEmpty ? _empty(theme) : _list(theme),
          ),
        ),
      ],
    );
  }

  /// Three different nothings, and they must not read the same.
  Widget _empty(ThemeData theme) {
    final String title;
    final String body;

    if (!_connected) {
      title = 'No Business Profile connected';
      body = 'Reviews come from a Google Business Profile this organization '
          'connects itself. Until one is connected there is nothing to show — '
          'this is not a business without reviews.';
    } else if (_onlyUnanswered) {
      title = 'Everything has been answered';
      body = 'No review is waiting for a reply. Turn off the Unanswered filter '
          'to read the rest.';
    } else {
      title = 'No reviews yet';
      body = 'Nothing has been left on the connected profile for these filters.';
    }

    return Center(
      child: ConstrainedBox(
        constraints: const BoxConstraints(maxWidth: 420),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Icon(
              _connected ? Icons.reviews_outlined : Icons.link_off,
              size: 40,
              color: theme.colorScheme.outline,
            ),
            const SizedBox(height: 12),
            Text(title, style: theme.textTheme.titleMedium, textAlign: TextAlign.center),
            const SizedBox(height: 8),
            Text(
              body,
              style: theme.textTheme.bodySmall
                  ?.copyWith(color: theme.colorScheme.onSurfaceVariant),
              textAlign: TextAlign.center,
            ),
            if (!_connected) ...[
              const SizedBox(height: 18),
              // Same reasoning as Leads: the module that reads the profile is
              // the one that offers to connect it, gated on `reviews.edit`.
              ConnectAccount(
                api: widget.api,
                label: 'Business Profile',
                startPath: '/social/business-profile/start',
                onConnected: _load,
              ),
            ],
          ],
        ),
      ),
    );
  }

  Widget _list(ThemeData theme) {
    return ListView.separated(
      padding: const EdgeInsets.only(bottom: 24),
      itemCount: _reviews.length + 1,
      separatorBuilder: (_, _) => const Divider(height: 1),
      itemBuilder: (context, index) {
        if (index == _reviews.length) {
          return Padding(
            padding: const EdgeInsets.all(16),
            child: Text(
              '$_total ${_total == 1 ? 'review' : 'reviews'}',
              style: theme.textTheme.bodySmall
                  ?.copyWith(color: theme.colorScheme.onSurfaceVariant),
            ),
          );
        }

        return _row(theme, _reviews[index]);
      },
    );
  }

  Widget _row(ThemeData theme, Map<String, dynamic> review) {
    final reply = review['reply'] as Map<String, dynamic>?;
    final published = reply?['status'] == 'published';
    final open = _openId == review['id'];

    return Padding(
      padding: const EdgeInsets.fromLTRB(16, 14, 16, 14),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              _stars(theme, review['rating'] as int?),
              const SizedBox(width: 10),
              Expanded(
                child: Text(
                  (review['reviewer_name'] as String?)?.trim().isNotEmpty == true
                      ? review['reviewer_name'] as String
                      : 'A Google user',
                  style: theme.textTheme.titleSmall,
                ),
              ),
              if (review['was_edited'] == true)
                Padding(
                  padding: const EdgeInsets.only(right: 8),
                  // Worth surfacing: a reply written against the original
                  // wording can read as a non-sequitur once it is rewritten.
                  child: Text('edited', style: theme.textTheme.bodySmall),
                ),
              if (published)
                Icon(Icons.check_circle, size: 16, color: theme.colorScheme.primary)
              else if (reply != null)
                Icon(Icons.edit_note, size: 18, color: theme.colorScheme.outline),
            ],
          ),
          if ((review['comment'] as String?)?.trim().isNotEmpty == true) ...[
            const SizedBox(height: 6),
            Text(review['comment'] as String, style: theme.textTheme.bodyMedium),
          ],
          if (published) ...[
            const SizedBox(height: 10),
            Container(
              padding: const EdgeInsets.all(10),
              decoration: BoxDecoration(
                color: theme.colorScheme.surfaceContainerHighest,
                borderRadius: BorderRadius.circular(8),
              ),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text('Your published reply', style: theme.textTheme.labelSmall),
                  const SizedBox(height: 4),
                  Text(reply!['body'] as String? ?? '',
                      style: theme.textTheme.bodySmall),
                ],
              ),
            ),
          ],
          if (reply != null && reply['failure_reason'] != null) ...[
            const SizedBox(height: 8),
            Text(
              reply['failure_reason'] as String,
              style: theme.textTheme.bodySmall
                  ?.copyWith(color: theme.colorScheme.error),
            ),
          ],
          if (!published) ...[
            const SizedBox(height: 8),
            if (!open)
              Align(
                alignment: Alignment.centerLeft,
                child: TextButton.icon(
                  icon: const Icon(Icons.reply, size: 16),
                  label: Text(reply == null ? 'Write a reply' : 'Edit draft'),
                  onPressed: () => setState(() {
                    _openId = review['id'] as String;
                    _draft.text = reply?['body'] as String? ?? '';
                  }),
                ),
              )
            else ...[
              TextField(
                controller: _draft,
                maxLines: 4,
                maxLength: 4096,
                decoration: const InputDecoration(
                  border: OutlineInputBorder(),
                  isDense: true,
                  hintText: 'Write the reply this business will publish…',
                ),
              ),
              Row(
                children: [
                  TextButton(
                    onPressed: _busy ? null : () => setState(() => _openId = null),
                    child: const Text('Cancel'),
                  ),
                  const Spacer(),
                  TextButton(
                    onPressed: _busy ? null : () => _saveDraft(review),
                    child: const Text('Save draft'),
                  ),
                  const SizedBox(width: 8),
                  FilledButton(
                    onPressed: _busy || (reply == null)
                        ? null
                        : () => _publish(review),
                    child: const Text('Publish'),
                  ),
                ],
              ),
            ],
          ],
        ],
      ),
    );
  }

  /// Stars, and an honest blank when Google sent no rating.
  Widget _stars(ThemeData theme, int? rating) {
    if (rating == null) {
      return Text('no rating', style: theme.textTheme.bodySmall);
    }

    return Row(
      mainAxisSize: MainAxisSize.min,
      children: [
        for (var i = 1; i <= 5; i++)
          Icon(
            i <= rating ? Icons.star : Icons.star_border,
            size: 15,
            color: i <= rating
                ? theme.colorScheme.primary
                : theme.colorScheme.outlineVariant,
          ),
      ],
    );
  }
}
