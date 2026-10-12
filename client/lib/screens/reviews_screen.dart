import 'package:flutter/material.dart';

import '../core/api_client.dart';
import '../core/session.dart';
import '../widgets/ai_mark.dart';
import '../widgets/async_body.dart';
import '../widgets/connect_account.dart';
import '../widgets/connected_account.dart';
import '../widgets/search_picker.dart';

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

  /// Which review's Publish is armed. One at a time: an inline confirm that
  /// could be open on several rows at once is a dialog with extra steps.
  String? _confirmingPublishId;
  final _draft = TextEditingController();

  /// The words AI suggested, while the box still holds them unchanged.
  String? _aiDraft;
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

  /// Grok's words into the box, for a person to edit (AI item 11).
  Future<void> _suggest(Map<String, dynamic> review) async {
    setState(() => _busy = true);
    final response = await widget.api.post(
      '/reviews/${review['id']}/reply/suggest',
      const {},
    );
    if (!mounted) return;
    setState(() => _busy = false);
    if (!response.ok) {
      setState(() => _notice = response.message);
      return;
    }
    final body = (response.data as Map)['body'] as String? ?? '';
    setState(() {
      _draft.text = body;
      _aiDraft = body;
    });
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

  /// Arm the confirm, rather than open a dialog.
  ///
  /// The house rule is no modals, and a confirmation is the case that collapses
  /// cleanly: it needs nowhere to type, only somewhere to state the consequence
  /// and offer two answers. An inline row does that AND works at 375px, which
  /// an AlertDialog does not — it was the wrong control on a phone before it
  /// was a rule violation.
  void _armPublish(Map<String, dynamic> review) {
    setState(() => _confirmingPublishId = review['id'] as String?);
  }

  Future<void> _publish(Map<String, dynamic> review) async {
    setState(() => _confirmingPublishId = null);

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

  /// The connected profile itself, so the tab can name it and remove it.
  Map<String, dynamic>? get _profile =>
      _connections.isEmpty ? null : _connections.first;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(16, 12, 16, 8),
          // Wraps rather than a Row: on a phone the chip, the picker and the
          // button overflowed the screen by 60px. Same as A1Leads GBP's filters.
          child: Wrap(
            spacing: 12,
            runSpacing: 8,
            crossAxisAlignment: WrapCrossAlignment.center,
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
              SizedBox(
                width: 150,
                child: SearchPickerField<int?>(
                  value: _rating,
                  border: InputBorder.none,
                  isDense: true,
                  hint: 'Any rating',
                  options: [
                    const SearchOption<int?>(value: null, label: 'Any rating'),
                    for (var s = 5; s >= 1; s--)
                      SearchOption<int?>(
                        value: s,
                        label: '$s star${s == 1 ? '' : 's'}',
                      ),
                  ],
                  onChanged: (v) {
                    setState(() => _rating = v);
                    _load();
                  },
                ),
              ),
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
        if (_profile != null)
          ConnectedAccount(
            api: widget.api,
            connection: _profile!,
            onDisconnected: _load,
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
            // §4, same as Leads: do not draw a button whose only outcome
            // is a 403.
            if (!_connected && (widget.session.me?.can('reviews.edit') ?? false)) ...[
              const SizedBox(height: 18),
              // Same reasoning as Leads: the module that reads the profile is
              // the one that offers to connect it, gated on `reviews.edit`.
              ConnectAccount(
                api: widget.api,
                label: 'Business Profile',
                startPath: '/social/business-profile/start',
                onConnected: _load,
              ),
            ] else if (!_connected) ...[
              const SizedBox(height: 16),
              Text(
                'Somebody who can manage Reviews needs to connect the profile.',
                style: theme.textTheme.bodySmall,
                textAlign: TextAlign.center,
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
              if (_aiDraft != null && _draft.text == _aiDraft)
                const Padding(
                  padding: EdgeInsets.only(bottom: 6),
                  child: AiMark.badge(
                    'Draft by AI',
                    tooltip:
                        'Written by AI from the review. Edit it before you publish.',
                  ),
                ),
              TextField(
                controller: _draft,
                onChanged: (_) => setState(() {}),
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
                  TextButton.icon(
                    key: ValueKey('review-ai-draft-${review['id']}'),
                    icon: const Icon(Icons.auto_awesome, size: 16),
                    label: const Text('Draft with AI'),
                    onPressed: _busy ? null : () => _suggest(review),
                  ),
                  TextButton(
                    onPressed: _busy ? null : () => _saveDraft(review),
                    child: const Text('Save draft'),
                  ),
                  const SizedBox(width: 8),
                  FilledButton(
                    onPressed: _busy || (reply == null)
                        ? null
                        : () => _armPublish(review),
                    child: const Text('Publish'),
                  ),
                ],
              ),
              if (_confirmingPublishId == review['id'])
                Padding(
                  padding: const EdgeInsets.only(top: 8),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        'This goes live on Google under your business name, where '
                        'anybody reading the review will see it. Google allows one '
                        'reply per review, so publishing again replaces it.',
                        style: Theme.of(context).textTheme.bodySmall,
                      ),
                      const SizedBox(height: 8),
                      Row(
                        children: [
                          TextButton(
                            onPressed: _busy
                                ? null
                                : () => setState(
                                    () => _confirmingPublishId = null),
                            child: const Text('Not yet'),
                          ),
                          const Spacer(),
                          FilledButton(
                            onPressed: _busy ? null : () => _publish(review),
                            // Names the act rather than saying "Confirm", so the
                            // button somebody presses last says what it does.
                            child: const Text('Publish it'),
                          ),
                        ],
                      ),
                    ],
                  ),
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
