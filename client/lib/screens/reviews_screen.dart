import 'package:flutter/material.dart';

import '../core/api_client.dart';
import '../core/deep_links.dart';
import '../core/session.dart';
import '../core/theme.dart';
import '../widgets/ai_mark.dart';
import '../widgets/async_body.dart';
import '../widgets/connect_account.dart';
import '../widgets/connected_account.dart';
import '../widgets/search_picker.dart';
import '../widgets/status_pill.dart';

/// Reviews — what the public said, the answer, and the work behind it.
///
/// ── UI queue 101 (Jose, 2026-10-16: "as you recommend", 1 and 4, then
/// "table should be the default though not board") ──────────────────
///
/// This took over A1Leads GBP: filters for rating, reply, time, sort, location
/// and customer match; a Table (the default) or Board; and a review opened in
/// a side panel (in place on a phone) with the reply, the matched customer and
/// their job, a Status, Notes and Report to Google. All EzBizi's own data.
///
/// ── Unanswered is the filter that ships switched on ──────────
///
/// Somebody opening this tab is almost never browsing. They are looking for
/// the ones nobody has replied to, because an unanswered one-star sits at the
/// top of a Google listing being the first thing a stranger reads.
///
/// ── Draft and Publish are two buttons for one reason ─────────
///
/// Draft writes text nobody outside the building can see. Publish puts it on
/// Google under the business's name, in public, permanently. They carry
/// different permissions and the screen keeps them visibly apart.
class ReviewsScreen extends StatefulWidget {
  const ReviewsScreen({super.key, required this.api, required this.session});

  final ApiClient api;
  final Session session;

  /// Wider than this the opened review sits beside the list; narrower it
  /// replaces the list, with a back arrow.
  static const double sideAbove = 900;

  @override
  State<ReviewsScreen> createState() => _ReviewsScreenState();
}

/// Where working a review has got to, as the server stores it.
const _statuses = {
  'cant_locate': ("Can't locate", Tone.caution),
  'working': ('Working on it', Tone.info),
  'awaiting_report': ('Awaiting report', Tone.review),
  'done': ('Done', Tone.ok),
};

class _ReviewsScreenState extends State<ReviewsScreen> {
  bool _loading = true;
  bool _syncing = false;
  String? _error;
  String? _notice;

  List<Map<String, dynamic>> _reviews = [];
  List<Map<String, dynamic>> _connections = [];
  List<Map<String, dynamic>> _locations = [];
  int _total = 0;
  int _unanswered = 0;

  /// Opens on unanswered — see the class note.
  bool _onlyUnanswered = true;
  String _rating = 'any';
  int? _days;
  String _sort = 'newest';
  String? _location;
  String _matched = 'any';
  String? _status;
  bool _board = false;

  int _request = 0;

  /// The review open in the panel, refreshed from GET /reviews/{id}.
  Map<String, dynamic>? _open;
  bool _editing = false;
  bool _confirmingPublish = false;
  final _draft = TextEditingController();
  final _note = TextEditingController();
  final _reason = TextEditingController();
  final _match = TextEditingController();
  List<Map<String, dynamic>>? _suggestions;
  bool _reporting = false;

  /// The words AI suggested, while the box still holds them unchanged.
  String? _aiDraft;
  bool _busy = false;

  bool _can(String key) => widget.session.me?.can(key) ?? false;

  @override
  void initState() {
    super.initState();
    _load();
    _takeLink();
    DeepLinks.pending.addListener(_takeLink);
  }

  /// A tapped notification about a review opens that review.
  void _takeLink() {
    final link = DeepLinks.take('reviews');
    if (link == null || link.type != 'review') return;
    _openReview({'id': link.id});
  }

  @override
  void dispose() {
    DeepLinks.pending.removeListener(_takeLink);
    _draft.dispose();
    _note.dispose();
    _reason.dispose();
    _match.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    final request = ++_request;

    setState(() {
      _loading = _reviews.isEmpty;
      _error = null;
    });

    final from = _days == null
        ? null
        : DateTime.now().subtract(Duration(days: _days!));
    final response = await widget.api.get('/reviews', {
      if (_onlyUnanswered) 'answered': 'no',
      if (_rating != 'any') 'rating': _rating,
      if (from != null)
        'from':
            '${from.year}-${'${from.month}'.padLeft(2, '0')}-${'${from.day}'.padLeft(2, '0')}',
      if (_sort != 'newest') 'sort': _sort,
      'location': ?_location,
      if (_matched != 'any') 'matched': _matched,
      'status': ?_status,
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
      _locations = List<Map<String, dynamic>>.from(
        (meta['locations'] as List? ?? const []).cast<Map<String, dynamic>>(),
      );
      // Keep the open one current with what the list now says.
      final open = _open;
      if (open != null) {
        final fresh = _reviews.where((r) => r['id'] == open['id']);
        if (fresh.isNotEmpty) _open = {...open, ...fresh.first};
      }
    });
  }

  void _refilter(VoidCallback change) {
    setState(change);
    _load();
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

    setState(
      () => _notice = created > 0
          ? '$created new ${created == 1 ? 'review' : 'reviews'}.'
          : 'Nothing new.',
    );

    await _load();
  }

  // ------------------------------------------------------------- the panel

  Future<void> _openReview(Map<String, dynamic> review) async {
    setState(() {
      _open = review;
      _editing = false;
      _confirmingPublish = false;
      _reporting = false;
      _suggestions = null;
      _aiDraft = null;
      _draft.text =
          (review['reply'] as Map<String, dynamic>?)?['body'] as String? ?? '';
    });
    await _refresh();
  }

  /// The review's whole record, notes included.
  Future<void> _refresh() async {
    final id = _open?['id'];
    if (id == null) return;
    final response = await widget.api.get('/reviews/$id');
    if (!mounted || _open?['id'] != id) return;
    _take(response);
  }

  /// Every write returns the same body as GET /reviews/{id}.
  void _take(ApiResponse response) {
    if (!response.ok) {
      setState(() => _notice = response.message);
      return;
    }
    final data = response.data;
    if (data is Map<String, dynamic> && data['id'] == _open?['id']) {
      setState(() => _open = {..._open!, ...data});
    }
  }

  Future<void> _write(Future<ApiResponse> call) async {
    setState(() => _busy = true);
    final response = await call;
    if (!mounted) return;
    setState(() => _busy = false);
    _take(response);
    if (response.ok) await _load();
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
    final response = await widget.api.put('/reviews/${review['id']}/reply', {
      'body': _draft.text.trim(),
    });
    if (!mounted) return;
    setState(() => _busy = false);

    if (!response.ok) {
      setState(() => _notice = response.message);
      return;
    }

    setState(
      () => _notice = 'Draft saved. Nothing is public until you publish it.',
    );
    await _load();
    await _refresh();
  }

  Future<void> _publish(Map<String, dynamic> review) async {
    setState(() {
      _confirmingPublish = false;
      _busy = true;
    });
    final response = await widget.api.post(
      '/reviews/${review['id']}/reply/publish',
      const {},
    );
    if (!mounted) return;
    setState(() {
      _busy = false;
      _notice = response.ok ? 'Reply published.' : response.message;
    });

    await _load();
    await _refresh();
  }

  Future<void> _findCustomers(Map<String, dynamic> review) async {
    final response = await widget.api.get(
      '/reviews/${review['id']}/customer-suggestions',
      {if (_match.text.trim().isNotEmpty) 'q': _match.text.trim()},
    );
    if (!mounted) return;
    setState(
      () => _suggestions = List<Map<String, dynamic>>.from(
        (response.data as List? ?? const []).cast<Map<String, dynamic>>(),
      ),
    );
  }

  bool get _connected => _connections.any((c) => c['status'] == 'connected');

  /// The connected profile itself, so the tab can name it.
  Map<String, dynamic>? get _profile =>
      _connections.isEmpty ? null : _connections.first;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return LayoutBuilder(
      builder: (context, box) {
        final side = box.maxWidth >= ReviewsScreen.sideAbove;
        final open = _open;

        // Phone 4: the review opens in place, with a back arrow.
        if (!side && open != null) return _panel(theme, open, inPlace: true);

        final list = Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            _filters(theme),
            if (_profile != null)
              ConnectedAccount(
                key: const ValueKey('tour-reviews-profile'),
                connection: _profile!,
              ),
            if (_notice != null)
              Padding(
                padding: const EdgeInsets.fromLTRB(16, 0, 16, 8),
                child: Row(
                  children: [
                    Expanded(
                      child: Text(_notice!, style: theme.textTheme.bodySmall),
                    ),
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
                child: _reviews.isEmpty
                    ? _empty(theme)
                    : (_board ? _boardView(theme) : _table(theme, box)),
              ),
            ),
          ],
        );

        if (!side || open == null) return list;

        return Row(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Expanded(child: list),
            const VerticalDivider(width: 1),
            SizedBox(width: 380, child: _panel(theme, open, inPlace: false)),
          ],
        );
      },
    );
  }

  Widget _picker<T>({
    required Key key,
    required T value,
    required List<SearchOption<T>> options,
    required ValueChanged<T> onChanged,
    double width = 150,
  }) => SizedBox(
    width: width,
    child: SearchPickerField<T>(
      key: key,
      value: value,
      border: InputBorder.none,
      isDense: true,
      options: options,
      onChanged: (v) => _refilter(() => onChanged(v as T)),
    ),
  );

  Widget _filters(ThemeData theme) {
    return Padding(
      padding: const EdgeInsets.fromLTRB(16, 12, 16, 8),
      // Wraps rather than a Row, so a phone takes it in two lines.
      child: Wrap(
        spacing: 12,
        runSpacing: 8,
        crossAxisAlignment: WrapCrossAlignment.center,
        children: [
          FilterChip(
            key: const ValueKey('tour-customer_experience-unanswered'),
            label: Text(
              _unanswered > 0 ? 'Unanswered ($_unanswered)' : 'Unanswered',
            ),
            selected: _onlyUnanswered,
            onSelected: (v) => _refilter(() => _onlyUnanswered = v),
          ),
          _picker<String>(
            key: const ValueKey('tour-customer_experience-rating'),
            value: _rating,
            options: [
              const SearchOption(value: 'any', label: 'Any rating'),
              const SearchOption(value: 'negative', label: 'Negative (1–3★)'),
              const SearchOption(value: 'positive', label: 'Positive (4–5★)'),
              for (var s = 5; s >= 1; s--)
                SearchOption(value: '$s', label: '$s star${s == 1 ? '' : 's'}'),
            ],
            onChanged: (v) => _rating = v,
          ),
          _picker<int?>(
            key: const ValueKey('reviews-time'),
            value: _days,
            width: 140,
            options: const [
              SearchOption(value: null, label: 'All time'),
              SearchOption(value: 7, label: 'Last 7 days'),
              SearchOption(value: 30, label: 'Last 30 days'),
              SearchOption(value: 91, label: 'Last 3 months'),
              SearchOption(value: 182, label: 'Last 6 months'),
              SearchOption(value: 365, label: 'Last 12 months'),
            ],
            onChanged: (v) => _days = v,
          ),
          _picker<String>(
            key: const ValueKey('reviews-sort'),
            value: _sort,
            width: 140,
            options: const [
              SearchOption(value: 'newest', label: 'Newest first'),
              SearchOption(value: 'oldest', label: 'Oldest first'),
              SearchOption(value: 'highest', label: 'Highest rated'),
              SearchOption(value: 'lowest', label: 'Lowest rated'),
            ],
            onChanged: (v) => _sort = v,
          ),
          if (_locations.length > 1)
            _picker<String?>(
              key: const ValueKey('reviews-location'),
              value: _location,
              width: 180,
              options: [
                const SearchOption(value: null, label: 'All locations'),
                for (final l in _locations)
                  SearchOption(value: '${l['id']}', label: '${l['name']}'),
              ],
              onChanged: (v) => _location = v,
            ),
          _picker<String>(
            key: const ValueKey('reviews-matched'),
            value: _matched,
            width: 140,
            options: const [
              SearchOption(value: 'any', label: 'Any match'),
              SearchOption(value: 'yes', label: 'Matched'),
              SearchOption(value: 'no', label: 'Unverified'),
            ],
            onChanged: (v) => _matched = v,
          ),
          _picker<String?>(
            key: const ValueKey('reviews-status'),
            value: _status,
            width: 150,
            options: [
              const SearchOption(value: null, label: 'Any status'),
              for (final e in _statuses.entries)
                SearchOption(value: e.key, label: e.value.$1),
            ],
            onChanged: (v) => _status = v,
          ),
          SegmentedButton<bool>(
            key: const ValueKey('reviews-view'),
            showSelectedIcon: false,
            segments: const [
              ButtonSegment(
                value: false,
                icon: Icon(Icons.table_rows_outlined, size: 18),
                label: Text('Table'),
              ),
              ButtonSegment(
                value: true,
                icon: Icon(Icons.view_agenda_outlined, size: 18),
                label: Text('Board'),
              ),
            ],
            selected: {_board},
            onSelectionChanged: (v) => setState(() => _board = v.single),
          ),
          FilledButton.tonalIcon(
            key: const ValueKey('tour-customer_experience-fetch'),
            onPressed: _syncing ? null : _sync,
            icon: _syncing
                ? const SizedBox(
                    width: 16,
                    height: 16,
                    child: CircularProgressIndicator(strokeWidth: 2),
                  )
                : const Icon(Icons.sync, size: 18),
            label: Text(_syncing ? 'Fetching…' : 'Fetch new'),
          ),
        ],
      ),
    );
  }

  /// Three different nothings, and they must not read the same.
  Widget _empty(ThemeData theme) {
    final String title;
    final String body;

    if (!_connected) {
      title = 'No Business Profile connected';
      body =
          'Reviews come from a Google Business Profile this organization '
          'connects itself. Until one is connected there is nothing to show — '
          'this is not a business without reviews.';
    } else if (_onlyUnanswered) {
      title = 'Everything has been answered';
      body =
          'No review is waiting for a reply. Turn off the Unanswered filter '
          'to read the rest.';
    } else {
      title = 'No reviews yet';
      body =
          'Nothing has been left on the connected profile for these filters.';
    }

    return Center(
      key: const ValueKey('tour-reviews-empty'),
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
            Text(
              title,
              style: theme.textTheme.titleMedium,
              textAlign: TextAlign.center,
            ),
            const SizedBox(height: 8),
            Text(
              body,
              style: theme.textTheme.bodySmall?.copyWith(
                color: theme.colorScheme.onSurfaceVariant,
              ),
              textAlign: TextAlign.center,
            ),
            // §4, same as Leads: do not draw a button whose only outcome
            // is a 403.
            if (!_connected && _can('reviews.edit')) ...[
              const SizedBox(height: 18),
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

  String _who(Map<String, dynamic> review) =>
      (review['reviewer_name'] as String?)?.trim().isNotEmpty == true
      ? review['reviewer_name'] as String
      : 'A Google user';

  String _where(Map<String, dynamic> review) {
    final place = (review['franchise'] as Map?)?['name'] as String?;
    final company = (review['company'] as Map?)?['name'] as String?;
    final location = review['location'] as String?;
    final named = _locations.where((l) => l['id'] == location);
    return place ??
        (named.isNotEmpty ? '${named.first['name']}' : null) ??
        company ??
        '';
  }

  String _date(Map<String, dynamic> review) {
    final at = DateTime.tryParse(review['reviewed_at'] as String? ?? '');
    if (at == null) return '';
    final l = at.toLocal();
    return '${l.month}/${l.day}/${l.year}';
  }

  bool _published(Map<String, dynamic> review) =>
      (review['reply'] as Map?)?['status'] == 'published';

  Widget _statusPill(Map<String, dynamic> review) {
    final s = _statuses[review['status']];
    return s == null ? const SizedBox.shrink() : StatusPill(s.$1, tone: s.$2);
  }

  Widget _matchLine(ThemeData theme, Map<String, dynamic> review) {
    final customer = review['customer'] as Map<String, dynamic>?;
    final job = review['job'] as Map<String, dynamic>?;
    final small = theme.textTheme.bodySmall;
    if (customer == null) {
      return Text(
        'Unverified · no customer match',
        style: small?.copyWith(color: theme.colorScheme.onSurfaceVariant),
      );
    }
    return Text(
      [
        'Matched: ${customer['name']}',
        if (job?['number'] != null) 'Job #${job!['number']}',
        if (job?['tech'] != null) 'Tech: ${job!['tech']}',
      ].join(' · '),
      style: small?.copyWith(color: ToneColours.of(context, Tone.ok).ink),
      maxLines: 2,
      overflow: TextOverflow.ellipsis,
    );
  }

  // ------------------------------------------------------------- the table

  Widget _table(ThemeData theme, BoxConstraints box) {
    final head = theme.textTheme.labelSmall?.copyWith(
      color: theme.colorScheme.onSurfaceVariant,
      fontWeight: FontWeight.w800,
    );
    // A phone keeps the table, folded to who, stars and status (phone 6 was
    // not picked; 4 is "tap opens in place", which works from either view).
    final narrow = box.maxWidth < 700;

    Widget cell(int flex, Widget child) => Expanded(
      flex: flex,
      child: Padding(padding: const EdgeInsets.only(right: 10), child: child),
    );

    return ListView.separated(
      key: const ValueKey('tour-customer_experience-reviews'),
      padding: const EdgeInsets.only(bottom: 24),
      itemCount: _reviews.length + 2,
      separatorBuilder: (_, _) => const Divider(height: 1),
      itemBuilder: (context, index) {
        if (index == 0) {
          return Padding(
            padding: const EdgeInsets.fromLTRB(16, 8, 16, 8),
            child: Row(
              children: [
                cell(3, Text('CUSTOMER · LOCATION', style: head)),
                if (!narrow) cell(5, Text('REVIEW', style: head)),
                cell(2, Text('DATE', style: head)),
                cell(2, Text('RATING', style: head)),
                cell(2, Text('STATUS', style: head)),
                if (!narrow) cell(2, Text('REPLY', style: head)),
                if (!narrow) cell(1, Text('NOTES', style: head)),
              ],
            ),
          );
        }
        if (index == _reviews.length + 1) return _count(theme);

        final review = _reviews[index - 1];
        final reply = review['reply'] as Map<String, dynamic>?;
        final selected = _open?['id'] == review['id'];
        final small = theme.textTheme.bodySmall;

        return InkWell(
          key: ValueKey('review-row-${review['id']}'),
          onTap: () => _openReview(review),
          child: Container(
            color: selected ? theme.colorScheme.primaryContainer : null,
            padding: const EdgeInsets.fromLTRB(16, 10, 16, 10),
            child: Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                cell(
                  3,
                  Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(_who(review), style: theme.textTheme.titleSmall),
                      if (_where(review).isNotEmpty)
                        Text(_where(review), style: small),
                      _matchLine(theme, review),
                    ],
                  ),
                ),
                if (!narrow)
                  cell(
                    5,
                    Text(
                      (review['comment'] as String?)?.trim() ?? '',
                      maxLines: 3,
                      overflow: TextOverflow.ellipsis,
                      style: small,
                    ),
                  ),
                cell(2, Text(_date(review), style: small)),
                cell(
                  2,
                  // Five stars shrink rather than overflow a phone's column.
                  FittedBox(
                    fit: BoxFit.scaleDown,
                    alignment: Alignment.centerLeft,
                    child: _stars(theme, review['rating'] as int?),
                  ),
                ),
                cell(
                  2,
                  Align(
                    alignment: Alignment.centerLeft,
                    child: _statusPill(review),
                  ),
                ),
                if (!narrow)
                  cell(
                    2,
                    Text(
                      _published(review)
                          ? 'Replied'
                          : reply != null
                          ? 'Draft'
                          : 'No reply',
                      style: small,
                    ),
                  ),
                if (!narrow)
                  cell(
                    1,
                    Text(
                      '${review['note_count'] ?? 0}' == '0'
                          ? '—'
                          : '${review['note_count']}',
                      style: small,
                    ),
                  ),
              ],
            ),
          ),
        );
      },
    );
  }

  Widget _count(ThemeData theme) => Padding(
    padding: const EdgeInsets.all(16),
    child: Text(
      '$_total ${_total == 1 ? 'review' : 'reviews'}',
      style: theme.textTheme.bodySmall?.copyWith(
        color: theme.colorScheme.onSurfaceVariant,
      ),
    ),
  );

  // ------------------------------------------------------------- the board

  Widget _boardView(ThemeData theme) {
    return ListView.separated(
      key: const ValueKey('tour-customer_experience-reviews'),
      padding: const EdgeInsets.only(bottom: 24),
      itemCount: _reviews.length + 1,
      separatorBuilder: (_, _) => const Divider(height: 1),
      itemBuilder: (context, index) {
        if (index == _reviews.length) return _count(theme);
        final review = _reviews[index];
        return InkWell(
          key: ValueKey('review-row-${review['id']}'),
          onTap: () => _openReview(review),
          child: Container(
            color: _open?['id'] == review['id']
                ? theme.colorScheme.primaryContainer
                : null,
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
                        _who(review),
                        style: theme.textTheme.titleSmall,
                      ),
                    ),
                    _statusPill(review),
                    const SizedBox(width: 8),
                    if (_published(review))
                      Icon(
                        Icons.check_circle,
                        size: 16,
                        color: theme.colorScheme.primary,
                      ),
                  ],
                ),
                if ((review['comment'] as String?)?.trim().isNotEmpty ==
                    true) ...[
                  const SizedBox(height: 6),
                  Text(
                    review['comment'] as String,
                    style: theme.textTheme.bodyMedium,
                  ),
                ],
                const SizedBox(height: 4),
                Text(
                  [
                    _where(review),
                    _date(review),
                  ].where((s) => s.isNotEmpty).join(' · '),
                  style: theme.textTheme.bodySmall,
                ),
                _matchLine(theme, review),
              ],
            ),
          ),
        );
      },
    );
  }

  // ------------------------------------------------------------- the panel

  Widget _section(ThemeData theme, String label) => Padding(
    padding: const EdgeInsets.only(top: 16, bottom: 6),
    child: Text(
      label.toUpperCase(),
      style: theme.textTheme.labelSmall?.copyWith(
        color: theme.colorScheme.onSurfaceVariant,
        fontWeight: FontWeight.w800,
        letterSpacing: 0.6,
      ),
    ),
  );

  Widget _panel(
    ThemeData theme,
    Map<String, dynamic> review, {
    required bool inPlace,
  }) {
    final manage = _can('reviews.manage');
    final customer = review['customer'] as Map<String, dynamic>?;
    final job = review['job'] as Map<String, dynamic>?;
    final notes = (review['notes'] as List? ?? const [])
        .whereType<Map<String, dynamic>>()
        .toList();
    final small = theme.textTheme.bodySmall;

    return Material(
      key: const ValueKey('review-panel'),
      color: theme.colorScheme.surface,
      child: ListView(
        padding: const EdgeInsets.fromLTRB(16, 8, 16, 24),
        children: [
          Row(
            children: [
              IconButton(
                key: const ValueKey('review-panel-close'),
                tooltip: inPlace ? 'Back to the reviews' : 'Close',
                icon: Icon(inPlace ? Icons.arrow_back : Icons.close),
                onPressed: () => setState(() => _open = null),
              ),
              Expanded(
                child: Text(_who(review), style: theme.textTheme.titleMedium),
              ),
              _stars(theme, review['rating'] as int?),
            ],
          ),
          Text(
            [
              _where(review),
              _date(review),
            ].where((s) => s.isNotEmpty).join(' · '),
            style: small,
          ),
          if (review['was_edited'] == true)
            // A reply written against the original wording can read as a
            // non-sequitur once it is rewritten.
            Text('edited', style: small),
          if ((review['comment'] as String?)?.trim().isNotEmpty == true) ...[
            const SizedBox(height: 10),
            Text(
              review['comment'] as String,
              style: theme.textTheme.bodyMedium,
            ),
          ],

          _section(theme, 'Reply'),
          _reply(theme, review),

          _section(theme, 'Customer'),
          _matchLine(theme, review),
          if (customer != null) ...[
            if ('${customer['phone'] ?? ''}'.isNotEmpty)
              Text('${customer['phone']}', style: small),
            if (job != null)
              Text(
                [
                  if (job['number'] != null) 'Job #${job['number']}',
                  if (job['starts_at'] != null)
                    _date({'reviewed_at': job['starts_at']}),
                  if (job['tech'] != null) 'Tech: ${job['tech']}',
                ].join(' · '),
                style: small,
              ),
          ],
          if (manage) ...[
            const SizedBox(height: 6),
            if (_suggestions == null)
              Align(
                alignment: Alignment.centerLeft,
                child: OutlinedButton(
                  key: const ValueKey('review-change-match'),
                  onPressed: _busy ? null : () => _findCustomers(review),
                  child: Text(
                    customer == null ? 'Find the customer' : 'Change match',
                  ),
                ),
              )
            else ...[
              TextField(
                key: const ValueKey('review-match-search'),
                controller: _match,
                decoration: const InputDecoration(
                  isDense: true,
                  labelText: 'Search customers',
                ),
                onSubmitted: (_) => _findCustomers(review),
              ),
              if (_suggestions!.isEmpty)
                Padding(
                  padding: const EdgeInsets.only(top: 6),
                  child: Text('No customer by that name.', style: small),
                ),
              for (final c in _suggestions!)
                ListTile(
                  key: ValueKey('review-match-${c['id']}'),
                  dense: true,
                  contentPadding: EdgeInsets.zero,
                  title: Text('${c['name']}'),
                  subtitle: Text(
                    [
                      c['phone'],
                      c['email'],
                    ].where((v) => v != null && '$v'.isNotEmpty).join(' · '),
                  ),
                  onTap: () {
                    setState(() => _suggestions = null);
                    _write(
                      widget.api.put('/reviews/${review['id']}/customer', {
                        'customer_id': c['id'],
                      }),
                    );
                  },
                ),
              Row(
                children: [
                  TextButton(
                    onPressed: () => setState(() => _suggestions = null),
                    child: const Text('Cancel'),
                  ),
                  if (customer != null)
                    TextButton(
                      key: const ValueKey('review-clear-match'),
                      onPressed: () {
                        setState(() => _suggestions = null);
                        _write(
                          widget.api.put('/reviews/${review['id']}/customer', {
                            'customer_id': null,
                          }),
                        );
                      },
                      child: const Text('Clear match'),
                    ),
                ],
              ),
            ],
          ],

          _section(theme, 'Status'),
          if (manage)
            Align(
              alignment: Alignment.centerLeft,
              child: SizedBox(
                width: 220,
                child: SearchPickerField<String?>(
                  key: const ValueKey('review-status'),
                  value: review['status'] as String?,
                  isDense: true,
                  hint: 'No status',
                  options: [
                    const SearchOption(value: null, label: 'No status'),
                    for (final e in _statuses.entries)
                      SearchOption(value: e.key, label: e.value.$1),
                  ],
                  onChanged: (v) => _write(
                    widget.api.patch('/reviews/${review['id']}', {'status': v}),
                  ),
                ),
              ),
            )
          else
            Align(alignment: Alignment.centerLeft, child: _statusPill(review)),

          _section(theme, 'Notes'),
          if (notes.isEmpty)
            Text('No notes yet.', style: small)
          else
            for (final n in notes)
              Padding(
                padding: const EdgeInsets.only(bottom: 6),
                child: Text(
                  '${_date({'reviewed_at': n['at']})}'
                  '${n['by'] == null ? '' : ' · ${n['by']}'}: ${n['text']}',
                  style: small,
                ),
              ),
          if (manage) ...[
            TextField(
              key: const ValueKey('review-note'),
              controller: _note,
              minLines: 1,
              maxLines: 4,
              decoration: const InputDecoration(
                isDense: true,
                labelText: 'Add a note',
              ),
              onChanged: (_) => setState(() {}),
            ),
            Align(
              alignment: Alignment.centerRight,
              child: TextButton(
                key: const ValueKey('review-note-add'),
                onPressed: _busy || _note.text.trim().isEmpty
                    ? null
                    : () {
                        final text = _note.text.trim();
                        _note.clear();
                        _write(
                          widget.api.post('/reviews/${review['id']}/notes', {
                            'text': text,
                          }),
                        );
                      },
                child: const Text('Add note'),
              ),
            ),
          ],

          if (review['reported_at'] != null) ...[
            _section(theme, 'Reported to Google'),
            Text(
              '${_date({'reviewed_at': review['reported_at']})}'
              '${review['report_reason'] == null ? '' : ': ${review['report_reason']}'}',
              style: small,
            ),
          ] else if (manage) ...[
            const SizedBox(height: 16),
            if (!_reporting)
              Align(
                alignment: Alignment.centerLeft,
                child: OutlinedButton.icon(
                  key: const ValueKey('review-report'),
                  style: OutlinedButton.styleFrom(
                    foregroundColor: theme.colorScheme.error,
                  ),
                  icon: const Icon(Icons.flag_outlined, size: 18),
                  label: const Text('Report to Google'),
                  onPressed: () => setState(() => _reporting = true),
                ),
              )
            else ...[
              // Google offers no way to flag a review through its API, so the
              // report is made in Google itself; this records that, and why.
              Text(
                'Report it in Google: open the review on your Business Profile '
                'and choose Report review. Then record it here.',
                style: small,
              ),
              const SizedBox(height: 6),
              TextField(
                key: const ValueKey('review-report-reason'),
                controller: _reason,
                decoration: const InputDecoration(
                  isDense: true,
                  labelText: 'Why (for example: not a customer)',
                ),
                onChanged: (_) => setState(() {}),
              ),
              Row(
                children: [
                  TextButton(
                    onPressed: () => setState(() => _reporting = false),
                    child: const Text('Cancel'),
                  ),
                  const Spacer(),
                  FilledButton(
                    key: const ValueKey('review-report-save'),
                    onPressed: _busy || _reason.text.trim().isEmpty
                        ? null
                        : () {
                            final reason = _reason.text.trim();
                            _reason.clear();
                            setState(() => _reporting = false);
                            _write(
                              widget.api.post(
                                '/reviews/${review['id']}/report',
                                {'reason': reason},
                              ),
                            );
                          },
                    child: const Text('Record the report'),
                  ),
                ],
              ),
            ],
          ],
        ],
      ),
    );
  }

  Widget _reply(ThemeData theme, Map<String, dynamic> review) {
    final reply = review['reply'] as Map<String, dynamic>?;
    final published = reply?['status'] == 'published';

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        if (published)
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
                Text(
                  reply!['body'] as String? ?? '',
                  style: theme.textTheme.bodySmall,
                ),
              ],
            ),
          ),
        if (reply != null && reply['failure_reason'] != null)
          Padding(
            padding: const EdgeInsets.only(top: 8),
            child: Text(
              reply['failure_reason'] as String,
              style: theme.textTheme.bodySmall?.copyWith(
                color: theme.colorScheme.error,
              ),
            ),
          ),
        if (!published && !_editing)
          Align(
            alignment: Alignment.centerLeft,
            child: TextButton.icon(
              icon: const Icon(Icons.reply, size: 16),
              label: Text(reply == null ? 'Write a reply' : 'Edit draft'),
              onPressed: () => setState(() {
                _editing = true;
                _draft.text = reply?['body'] as String? ?? '';
              }),
            ),
          ),
        if (!published && _editing) ...[
          if (_aiDraft != null && _draft.text == _aiDraft)
            const Padding(
              padding: EdgeInsets.only(bottom: 6),
              child: Align(
                alignment: Alignment.centerLeft,
                child: AiMark.badge(
                  'Draft by AI',
                  tooltip:
                      'Written by AI from the review. Edit it before you publish.',
                ),
              ),
            ),
          TextField(
            controller: _draft,
            onChanged: (_) => setState(() {}),
            maxLines: 5,
            maxLength: 4096,
            decoration: const InputDecoration(
              border: OutlineInputBorder(),
              isDense: true,
              hintText: 'Write the reply this business will publish…',
            ),
          ),
          Wrap(
            spacing: 4,
            alignment: WrapAlignment.end,
            crossAxisAlignment: WrapCrossAlignment.center,
            children: [
              TextButton(
                onPressed: _busy
                    ? null
                    : () => setState(() => _editing = false),
                child: const Text('Cancel'),
              ),
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
              FilledButton(
                onPressed: _busy || (reply == null)
                    ? null
                    : () => setState(() => _confirmingPublish = true),
                child: const Text('Publish'),
              ),
            ],
          ),
          // The confirm is inline, not a dialog: the house rule.
          if (_confirmingPublish)
            Padding(
              padding: const EdgeInsets.only(top: 8),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    'This goes live on Google under your business name, where '
                    'anybody reading the review will see it. Google allows one '
                    'reply per review, so publishing again replaces it.',
                    style: theme.textTheme.bodySmall,
                  ),
                  const SizedBox(height: 8),
                  Row(
                    children: [
                      TextButton(
                        onPressed: _busy
                            ? null
                            : () => setState(() => _confirmingPublish = false),
                        child: const Text('Not yet'),
                      ),
                      const Spacer(),
                      FilledButton(
                        onPressed: _busy ? null : () => _publish(review),
                        // Names the act rather than saying "Confirm".
                        child: const Text('Publish it'),
                      ),
                    ],
                  ),
                ],
              ),
            ),
        ],
      ],
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
